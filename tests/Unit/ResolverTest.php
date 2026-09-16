<?php
use Bilal\MediaDependencyMap\Matching\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase {
	/** @dataProvider paths */
	public function test_upload_normalization( $url, $expected ) {
		$this->assertSame( $expected, Resolver::upload_path( $url, 'https://example.test/wp-content/uploads' ) );
	}
	public static function paths() {
		return array(
			array( 'http://EXAMPLE.test/wp-content/uploads/2026/A%20B.jpg?v=1&amp;x=2#view', '2026/A B.jpg' ),
			array( '//example.test/wp-content/uploads/a.jpg', 'a.jpg' ),
			array( '/wp-content/uploads/a/../Photo.JPG', 'Photo.JPG' ),
			array( 'https://other.test/wp-content/uploads/a.jpg', null ),
			array( '/wp-content/uploads-evil/a.jpg', null ),
			array( '/wp-content/uploads/%2e%2e/secrets', null ),
			array( 'https://example.test:9999/wp-content/uploads/a.jpg', null ),
			array( 'https://user:secret@example.test/wp-content/uploads/a.jpg', null ),
			array( 'data:image/png;base64,abc', null ),
			array( '/wp-content/uploads/a%00.jpg', null ),
			array( '42', null ),
		);
	}
}
