<?php

/**
 * Plugin Name: MotionKit With Gsap
 * Plugin URI: https://motionkit.io
 * Description: MotionKit with gsap — Animation Engine, GSAP Enqueue, ScrollSmoother, Page Transition, and Preloader Engine for MotionKit.
 * Version: 1.7.3
 * Author: Wealcoder
 * Author URI: https://motionkit.io
 * License: Proprietary — see LICENSE.txt
 * Text Domain: motionkit-with-gsap
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * @package   MotionKitConnector
 * @author    Wealcoder
 * @copyright Copyright (c) 2024-2026 Wealcoder / MotionKit. All rights reserved.
 * @license   Proprietary — Unauthorized copying, modification, distribution,
 *            or reverse engineering of this software, via any medium, is
 *            strictly prohibited. This is licensed, not sold. See LICENSE.txt.
 * @link      https://motionkit.io
 * @since     1.0.0
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  MotionKit Connector — Proprietary Software
 *
 *  This source code is the confidential and proprietary property of Wealcoder.
 *  It is distributed to licensed customers under the MotionKit Terms of Service
 *  solely for use with an active MotionKit subscription. No license, express or
 *  implied, is granted to copy, reproduce, republish, decompile, disassemble,
 *  reverse engineer, sublicense, sell, or create derivative works from any part
 *  of this code. Automated extraction (including by AI/ML systems) for the
 *  purpose of reproducing its functionality is expressly forbidden.
 *
 *  Violations will be pursued to the fullest extent permitted by law.
 *  Report license abuse: legal@motionkit.io
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'MOTIONKIT_CONNECTOR_VERSION', '1.7.3' );
define( 'MOTIONKIT_CONNECTOR_PLUGIN_FILE', __FILE__ );
define( 'MOTIONKIT_CONNECTOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOTIONKIT_CONNECTOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOTIONKIT_CONNECTOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// The SaaS editor this build talks to — every launch link, connect endpoint, license call and allowed origin derives from it, so this is the only line that names the host. Guarded rather than unconditional so a test site can pin a different editor from wp-config.php without rebuilding, and `motionkit-connector build --dev` rewrites it in its staged copy only. The committed value stays production on every branch, staging included: a branch that carried a different host would put a wrong-environment release one careless merge away.
if ( ! defined( 'MOTIONKIT_CONNECTOR_EDITOR_ORIGIN' ) ) {
	define( 'MOTIONKIT_CONNECTOR_EDITOR_ORIGIN', 'https://editor.motionkit.io' );
}

// Compatibility aliases
if ( ! defined( 'MOTIONKIT_EXTENSION_VERSION' ) ) {
	define( 'MOTIONKIT_EXTENSION_VERSION', MOTIONKIT_CONNECTOR_VERSION );
	define( 'MOTIONKIT_EXTENSION_PLUGIN_FILE', MOTIONKIT_CONNECTOR_PLUGIN_FILE );
	define( 'MOTIONKIT_EXTENSION_PLUGIN_DIR', MOTIONKIT_CONNECTOR_PLUGIN_DIR );
	define( 'MOTIONKIT_EXTENSION_PLUGIN_URL', MOTIONKIT_CONNECTOR_PLUGIN_URL );
	define( 'MOTIONKIT_EXTENSION_PLUGIN_BASENAME', MOTIONKIT_CONNECTOR_PLUGIN_BASENAME );
}

// Check if plugin is already loaded
if ( defined( 'MOTIONKIT_CONNECTOR_LOADED' ) ) {
	return;
}

define( 'MOTIONKIT_CONNECTOR_LOADED', true );

// Public API for other plugins and themes. Loaded before the plugin class so
// the functions exist from the moment this file is included.
require_once MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/api-functions.php';

// Load the main plugin class
require_once MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/Plugin.php';

// Initialize the plugin
MotionKitConnector\Plugin::get_instance( MOTIONKIT_CONNECTOR_PLUGIN_FILE );
