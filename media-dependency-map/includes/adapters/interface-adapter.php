<?php
/**
 * Read-only extraction contract.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;

defined( 'ABSPATH' ) || exit;

/** Extracts one bounded consumer; enumeration will belong to the scan engine. */
interface Adapter {

	/**
	 * Stable adapter name.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Extract the complete supported consumer or throw without publishing partial data.
	 *
	 * @param int $consumer_id Post ID, or zero for site settings.
	 * @return Reference[]
	 */
	public function scan( $consumer_id );
}
