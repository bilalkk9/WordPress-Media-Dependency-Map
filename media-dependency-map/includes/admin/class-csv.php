<?php
/**
 * Spreadsheet-safe export values.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Admin;

defined( 'ABSPATH' ) || exit;

/** Avoids spreadsheet formula execution from user-authored titles. */
final class Csv {

	/**
	 * Escape dangerous leading characters including whitespace-prefixed formulas.
	 *
	 * @param string $value Untrusted cell text.
	 * @return string
	 */
	public static function cell( $value ) {
		return preg_match( '/^[\s]*[=+@\-]|^[\t\r\n]/u', $value ) ? "'" . $value : $value;
	}
}
