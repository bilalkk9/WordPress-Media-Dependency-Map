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
require_once __DIR__ . '/persistence/class-scan-repository.php';
require_once __DIR__ . '/index/class-engine.php';
require_once __DIR__ . '/index/class-changes.php';
require_once __DIR__ . '/persistence/class-browser-repository.php';
require_once __DIR__ . '/admin/class-csv.php';
require_once __DIR__ . '/admin/class-controller.php';
