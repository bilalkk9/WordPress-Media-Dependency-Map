<?php
use Bilal\MediaDependencyMap\Domain\Reference;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase {
	public function test_logical_identity_is_stable_but_fingerprint_tracks_value() {
		$a = new Reference( 'core', 'post', '7', 'image', 'id', 12, 'exact', '12' );
		$b = new Reference( 'core', 'post', '7', 'image', 'id', 12, 'exact', '012' );
		$this->assertSame( $a->to_array()['reference_key'], $b->to_array()['reference_key'] );
		$this->assertNotSame( $a->to_array()['value_hash'], $b->to_array()['value_hash'] );
		$this->assertSame( 'read-only', $a->to_array()['replaceability'] );
	}
	public function test_confirmed_reference_requires_attachment() {
		$this->expectException( InvalidArgumentException::class );
		new Reference( 'core', 'post', '7', 'image', 'id', null, 'exact', 'secret' );
	}
	public function test_unresolved_reference_does_not_retain_sensitive_raw_value() {
		$ref = new Reference( 'core', 'post', '7', 'image', 'url', null, 'unresolved', 'secret' );
		$this->assertStringNotContainsString( 'secret', json_encode( $ref->to_array() ) );
	}
}
