<?php
use Bilal\MediaDependencyMap\Admin\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase {
	/** @dataProvider cells */
	public function test_untrusted_cells( $input, $expected ) { $this->assertSame( $expected, Csv::cell( $input ) ); }
	public static function cells() {
		return array( array( '=1+1', "'=1+1" ), array( '  +cmd', "'  +cmd" ), array( '@SUM(A1)', "'@SUM(A1)" ), array( '-2+3', "'-2+3" ), array( "\tformula", "'\tformula" ), array( 'Normal title', 'Normal title' ), array( '123', '123' ) );
	}
}
