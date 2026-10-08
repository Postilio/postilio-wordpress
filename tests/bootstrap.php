<?php
/**
 * Loads the plug-in's classes, the prefixed SDK and a stand-in for WP_Error; WordPress's functions are stubbed per test
 * with Brain Monkey.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
require __DIR__ . '/WpError.php';
