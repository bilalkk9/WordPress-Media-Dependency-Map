<?php
/**
 * Explicit dependency loading without runtime Composer requirements.
 *
 * @package Bilal\MediaDependencyMap
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/domain/class-reference.php';
require_once __DIR__ . '/persistence/class-schema.php';
require_once __DIR__ . '/persistence/class-reference-repository.php';
require_once __DIR__ . '/persistence/class-attachment-repository.php';
require_once __DIR__ . '/matching/class-resolver.php';
require_once __DIR__ . '/adapters/interface-adapter.php';
require_once __DIR__ . '/adapters/class-core.php';
require_once __DIR__ . '/adapters/class-site-identity.php';
