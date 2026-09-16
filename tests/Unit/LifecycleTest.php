<?php
use Bilal\MediaDependencyMap\Lifecycle;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {
	public function test_activation_preserves_existing_settings_and_does_not_autoload() {
		$GLOBALS['mdm_test_options'] = array();
		Lifecycle::activate();
		$this->assertFalse( $GLOBALS['mdm_test_options']['mdm_settings'][1] );
		$this->assertCount( 4, $GLOBALS['mdm_test_role']->capabilities );
		$GLOBALS['mdm_test_options']['mdm_settings'][0]['custom'] = 'preserve';
		Lifecycle::activate();
		$this->assertSame( 'preserve', $GLOBALS['mdm_test_options']['mdm_settings'][0]['custom'] );
	}

	public function test_deactivation_preserves_settings_and_clears_work() {
		Lifecycle::activate();
		$before = $GLOBALS['mdm_test_options'];
		Lifecycle::deactivate();
		$this->assertSame( $before, $GLOBALS['mdm_test_options'] );
		$this->assertSame( 'mdm_process_queue', $GLOBALS['mdm_cleared_hook'] );
	}
}
