<?php
/**
 * Bounded scan orchestration shared by Cron, CLI and admin requests.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Index;

use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

defined( 'ABSPATH' ) || exit;

/** Owns scan progress without knowing integration storage formats. */
final class Engine {

	/**
	 * Coordination repository.
	 *
	 * @var Scan_Repository
	 */
	private $store;

	/**
	 * Generation-aware adapter factory.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Compose the engine.
	 *
	 * @param Scan_Repository $store Scan storage.
	 * @param callable        $factory Returns adapter descriptors keyed by ID.
	 */
	public function __construct( Scan_Repository $store, callable $factory ) {
		$this->store   = $store;
		$this->factory = $factory;
	}

	/**
	 * Start or resume one site-level full scan without parsing content.
	 *
	 * @throws \RuntimeException For missing permissions, schema or a busy worker.
	 * @return int Run ID.
	 */
	public function start() {
		if ( ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'manage_options' ) || Schema::VERSION !== get_option( 'mdm_schema_version' ) ) {
			throw new \RuntimeException( 'Scan requires an authorized administrator and current schema.' );
		}
		$token = $this->store->acquire();
		if ( null === $token ) {
			throw new \RuntimeException( 'Another scan worker is running.' ); }
		try {
			$adapters = call_user_func( $this->factory, 0 );
			$id       = $this->store->atomic(
				$token,
				function () use ( $adapters ) {
					return $this->store->start( get_current_user_id(), array_keys( $adapters ) );
				}
			);
			$this->schedule();
			return $id;
		} finally {
			$this->store->release( $token );
		}
	}

	/** Register a watchdog which survives interrupted requests. */
	public function schedule() {
		if ( ! wp_next_scheduled( 'mdm_process_queue' ) ) {
			wp_schedule_event( time() + 30, 'mdm_minute', 'mdm_process_queue' );
		}
	}

	/**
	 * Execute a bounded batch. Cron intentionally has no browser nonce; saved owner is revalidated.
	 *
	 * @param int $limit Maximum consumers in this invocation.
	 */
	public function tick( $limit = 10 ) {
		if ( Schema::VERSION !== get_option( 'mdm_schema_version' ) ) {
			return; }
		$token = $this->store->acquire();
		if ( null === $token ) {
			return; }
		$previous_user = get_current_user_id();
		try {
			$control = $this->store->control();
			if ( ! $control->owner_id ) {
				return; }
			wp_set_current_user( (int) $control->owner_id );
			if ( ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'manage_options' ) ) {
				$run = $this->store->run( (int) $control->active_run );
				if ( $run ) {
					$state = $run->state;
					$this->error( $state, 'authorization', 0 );
					$this->store->atomic(
						$token,
						function () use ( $run, $state ) {
							$this->store->finish( (int) $run->id, $state );
						}
					);
				}
				wp_clear_scheduled_hook( 'mdm_process_queue' );
				return;
			}
			$this->schedule();
			$deadline = microtime( true ) + 8;
			$limit    = max( 1, min( 20, (int) $limit ) );
			for ( $i = 0; $i < $limit; ++$i ) {
				if ( microtime( true ) >= $deadline ) {
					break; }
				$control = $this->store->control();
				if ( $control->active_run ) {
					$this->full_step( $token, (int) $control->active_run );
					continue;
				}
				$item = $this->store->next_item();
				if ( $item && ( 'full' === $item->source || ! $control->active_generation ) ) {
					$adapters = call_user_func( $this->factory, 0 );
					$this->store->atomic(
						$token,
						function () use ( $adapters ) {
							$this->store->start( get_current_user_id(), array_keys( $adapters ) );
						}
					);
					continue;
				}
				if ( $item ) {
					$this->incremental( $token, $item, (int) $control->active_generation );
					continue;
				}
				$this->store->atomic(
					$token,
					function () {
						$this->store->cleanup();
					}
				);
				break;
			}
			delete_option( 'mdm_worker_error' );
		} catch ( \Throwable $error ) {
			// The saved cursor remains resumable; do not expose scanned values or SQL errors.
			update_option( 'mdm_worker_error', gmdate( 'Y-m-d H:i:s' ), false );
		} finally {
			wp_set_current_user( $previous_user );
			$this->store->release( $token );
		}
	}

	/**
	 * Process one source consumer or transition one phase.
	 *
	 * @throws \RuntimeException For an invalid run.
	 * @param string $token Worker token.
	 * @param int    $run_id Active run.
	 */
	private function full_step( $token, $run_id ) {
		$run = $this->store->run( $run_id );
		if ( ! $run || 'running' !== $run->status ) {
			throw new \RuntimeException( 'Active run is invalid.' ); }
		$state    = $run->state;
		$adapters = call_user_func( $this->factory, $run_id );
		if ( array_keys( $adapters ) !== $state['adapters'] ) {
			$this->error( $state, 'adapter_configuration_changed', 0 );
			$this->store->atomic(
				$token,
				function () use ( $run_id, $state ) {
					$this->store->finish( $run_id, $state );
				}
			);
			return;
		}
		$is_paths = 'paths' === $state['phase'];
		if ( ! $is_paths && $state['adapter'] >= count( $state['adapters'] ) ) {
			$this->store->atomic(
				$token,
				function () use ( $run_id, $state ) {
					$this->store->finish( $run_id, $state );
				}
			);
			return;
		}
		$adapter_id = $is_paths ? 'paths' : $state['adapters'][ $state['adapter'] ];
		$descriptor = $is_paths ? null : $adapters[ $adapter_id ];
		$source     = $is_paths ? 'attachment' : $descriptor['source'];
		$ids        = $this->store->page( $source, $state['cursor'], $state['max_id'] );
		if ( ! $ids ) {
			$state['cursor'] = 0;
			if ( $is_paths ) {
				$state['phase'] = 'adapters';
			} else {
				++$state['adapter']; }
			$this->store->atomic(
				$token,
				function () use ( $run_id, $state ) {
					$this->store->checkpoint( $run_id, $state );
				}
			);
			return;
		}
		$id              = $ids[0];
		$state['cursor'] = $id;
		++$state['processed'];
		try {
			if ( 'post' === $source && ! current_user_can( 'edit_post', $id ) ) {
				throw new \RuntimeException( 'Consumer is not authorized.' ); }
			if ( $is_paths ) {
				$this->store->atomic(
					$token,
					function () use ( $run_id, $id, $state ) {
						$this->store->index_paths( $run_id, $id );
						$this->store->checkpoint( $run_id, $state );
					}
				);
			} else {
				$refs = $descriptor['scanner']->scan( $id );
				$this->store->atomic(
					$token,
					function () use ( $run_id, $adapter_id, $source, $id, $refs, $state ) {
						$this->store->references( $run_id )->reconcile( $run_id, $adapter_id, $source, (string) $id, $refs, false );
						$this->store->checkpoint( $run_id, $state );
					}
				);
			}
		} catch ( \Throwable $error ) {
			$this->error( $state, $adapter_id, $id );
			$this->store->atomic(
				$token,
				function () use ( $run_id, $state ) {
					$this->store->checkpoint( $run_id, $state );
				}
			);
		}
	}

	/**
	 * Reconcile one versioned change against the current published generation.
	 *
	 * @throws \RuntimeException For unauthorized consumers.
	 * @param string $token Lease token.
	 * @param object $item Queued change.
	 * @param int    $generation Active generation.
	 */
	private function incremental( $token, $item, $generation ) {
		$adapters = call_user_func( $this->factory, $generation );
		try {
			$snapshots = array();
			foreach ( $adapters as $adapter_id => $descriptor ) {
				if ( $item->source !== $descriptor['source'] ) {
					continue; }
				$id      = (int) $item->consumer_id;
				$deleted = 'post' === $item->source && ! get_post( $id );
				if ( ! $deleted && 'post' === $item->source && ! current_user_can( 'edit_post', $id ) ) {
					throw new \RuntimeException( 'Consumer is not authorized.' ); }
				$snapshots[ $adapter_id ] = $deleted ? array() : $descriptor['scanner']->scan( $id );
			}
			$this->store->atomic(
				$token,
				function () use ( $item, $snapshots, $generation ) {
					$references = $this->store->references( $generation );
					$run_id     = $references->start_run();
					foreach ( $snapshots as $adapter_id => $refs ) {
						$references->reconcile( $run_id, $adapter_id, $item->source, (string) $item->consumer_id, $refs, false );
					}
					$references->finish_run( $run_id, true );
					$this->store->finish_item( $item, true );
				}
			);
		} catch ( \Throwable $error ) {
			$this->store->atomic(
				$token,
				function () use ( $item ) {
					$this->store->finish_item( $item, false );
				}
			);
		}
	}

	/**
	 * Record a small diagnostic sample without content or database errors.
	 *
	 * @param array  $state Run state.
	 * @param string $adapter Adapter ID.
	 * @param int    $id Consumer ID.
	 */
	private function error( array &$state, $adapter, $id ) {
		++$state['error_count'];
		if ( count( $state['errors'] ) < 20 ) {
			$state['errors'][] = array(
				'adapter'     => $adapter,
				'consumer_id' => $id,
				'code'        => 'consumer_failed',
			); }
	}
}
