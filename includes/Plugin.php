<?php

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

namespace MotionKitConnector;

/**
 * Main Plugin Class
 *
 * @package MotionKitConnector
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use MotionKitConnector\Includes\Autoloader;
use MotionKitConnector\Common\EditorEndpoint;
use MotionKitConnector\Factory\ComponentFactory;
use MotionKitConnector\Frontend\Frontend;

/**
 * Main Plugin Class
 *
 * Handles plugin initialization, activation, and deactivation.
 */
final class Plugin
{
    /**
     * Plugin version
     */
    public const VERSION = '1.7.3';

    /**
     * Plugin name
     */
    public const PLUGIN_NAME = 'MotionKit With Gsap';

    /**
     * Plugin slug
     */
    public const PLUGIN_SLUG = 'motionkit-with-gsap';

    /**
     * Plugin instance
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Plugin file path
     *
     * @var string
     */
    private string $plugin_file;

    /**
     * Plugin directory path
     *
     * @var string
     */
    private string $plugin_dir;

    /**
     * Plugin URL
     *
     * @var string
     */
    private string $plugin_url;

    /**
     * Autoloader instance
     *
     * @var Autoloader
     */
    private Autoloader $autoloader;

    /**
     * Frontend instance
     *
     * @var Frontend|null
     */
    private ?Frontend $frontend = null;

    /**
     * Constructor
     *
     * @param string $plugin_file The main plugin file path
     */
    private function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_dir = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);

        $this->init_autoloader();
        $this->init_hooks();
    }

    /**
     * Get plugin instance
     *
     * @param string $plugin_file The main plugin file path
     * @return Plugin The plugin instance
     */
    public static function get_instance(string $plugin_file): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self($plugin_file);
        }

        return self::$instance;
    }

    /**
     * Initialize autoloader
     *
     * @return void
     */
    private function init_autoloader(): void
    {
        require_once $this->plugin_dir . 'includes/Autoloader.php';
        
        $this->autoloader = new Autoloader($this->plugin_dir . 'includes/');
        $this->autoloader->register();
    }

    /**
     * Initialize hooks
     *
     * @return void
     */
    private function init_hooks(): void
    {
        // The main MotionKit plugin is a separate repo with its own build, and its four "Build Animation" / "Launch MotionKit" call sites hardcode the production editor — but every one of them runs the URL through this filter first. Registering it here is what makes a dev connector alone enough to move those buttons: no dev build of the dashboard, and no edit to that repo. Nothing is registered on a production build, so the shipped plugin carries no extra callback.
        if (!EditorEndpoint::is_default()) {
            add_filter('motionkit/editor/url', [EditorEndpoint::class, 'retarget']);
        }

        add_action('plugins_loaded', [$this, 'init'], 10);
        // Clean up legacy motionkit-connector plugin if present on disk
        add_action('admin_init', [$this, 'maybe_cleanup_legacy_connector'], 1);
        // One-shot autoload migration — flips legacy option rows that were saved with autoload=no but are read on every frontend request, so they join the alloptions cache instead of triggering a SELECT per page load. Sentinel-guarded to run exactly once per install. Lives here (not the dashboard plugin) because the hot options are this plugin's own.
        add_action('admin_init', [$this, 'maybe_fix_autoload_flags'], 5);
        // One-shot status rename — the stored word `draft` used to mean "saved but not published" and now means "parked", so records written under the old meaning are relabelled `saved`. Without it every animation a user had saved would go quiet in their editor on update. The REST save dispatcher calls the same guarded method, which is what covers a site whose wp-admin is never opened.
        add_action('admin_init', [Migrations\StatusSavedMigration::class, 'maybe_run'], 5);

        register_activation_hook($this->plugin_file, [$this, 'activate']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);
    }

    /**
     * Remove legacy motionkit-connector plugin if present on disk.
     *
     * @return void
     */
    public function maybe_cleanup_legacy_connector(): void
    {
        // Don't interfere while WordPress upgrader is actively installing/updating plugins
        if ((defined('DOING_AJAX') && DOING_AJAX) || isset($_GET['action'])) {
            return;
        }

        if (!is_admin() || !defined('WP_PLUGIN_DIR')) {
            return;
        }

        $legacy_dir = WP_PLUGIN_DIR . '/motionkit-connector';
        $legacy_file = $legacy_dir . '/motionkit-connector.php';

        // Never touch the directory this plugin is itself running from: installed under the legacy name, every branch below deletes it mid-request and WordPress then deactivates it with "Plugin file does not exist".
        $own_dir = defined('MOTIONKIT_CONNECTOR_PLUGIN_DIR') ? untrailingslashit(MOTIONKIT_CONNECTOR_PLUGIN_DIR) : '';
        if ($own_dir !== '' && ($legacy_dir === $own_dir || realpath($legacy_dir) === realpath($own_dir))) {
            return;
        }

        // A symlink here is a developer's checkout linked into wp-content/plugins, and $wp_filesystem->delete() follows it — emptying the real repository instead of removing a stale copy.
        if (is_link($legacy_dir)) {
            return;
        }

        if (!is_dir($legacy_dir) && !file_exists(WP_PLUGIN_DIR . '/motionkit-connector.php')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $legacy_basenames = [
            'motionkit-connector/motionkit-connector.php',
            'motionkit-connector.php',
        ];

        foreach ($legacy_basenames as $basename) {
            if (is_plugin_active($basename)) {
                deactivate_plugins($basename, true);
            }
        }

        // Delete legacy plugin files and directory cleanly
        delete_plugins($legacy_basenames);

        // Fallback manual filesystem delete if directory still remains
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (\WP_Filesystem()) {
            global $wp_filesystem;
            if ($wp_filesystem) {
                if (is_dir($legacy_dir)) {
                    $wp_filesystem->delete($legacy_dir, true);
                }
                if (file_exists(WP_PLUGIN_DIR . '/motionkit-connector.php')) {
                    $wp_filesystem->delete(WP_PLUGIN_DIR . '/motionkit-connector.php');
                }
            }
        }
    }

    /**
     * Flip autoload=yes on hot-path options that were originally written with
     * autoload=no. WP only honors the flag on first-create, so existing rows
     * stay autoload=no forever unless we update wp_options.autoload directly.
     *
     * Cheap: one indexed UPDATE against wp_options, gated by a sentinel.
     *
     * @return void
     */
    public function maybe_fix_autoload_flags(): void
    {
        if (get_option('motionkit_autoload_fixed_v1') === '1') {
            return;
        }

        global $wpdb;
        $hot_options = ['motionkit_page_settings_updated_at'];
        // Dynamic %s,%s,... placeholder list sized to $hot_options — this IS the prepare() placeholder syntax, not unescaped SQL; $hot_options is a hardcoded literal above, never external input. The sniff can't trace that {$placeholders} expands to valid %s placeholders before prepare() consumes them via the variadic ...$hot_options args below.
        $placeholders = implode(',', array_fill(0, count($hot_options), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload = 'yes'
                 WHERE option_name IN ({$placeholders}) AND autoload != 'yes'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                ...$hot_options
            )
        );
        wp_cache_delete('alloptions', 'options');
        update_option('motionkit_autoload_fixed_v1', '1', true);
    }

    /**
     * Initialize plugin
     *
     * @return void
     */
    public function init(): void
    {
        $this->init_auth();
        $this->init_admin_tools();
        $this->init_frontend();
        $this->init_rest_api();
        $this->init_dependency_installer();
        $this->init_plugin_row_links();
        $this->init_updater();

        do_action('MOTIONKIT_EXTENSION_LOADED');
    }

    /**
     * Register the store updater, which serves Connector updates to licensed sites.
     *
     * @return void
     */
    private function init_updater(): void
    {
        (new \MotionKitConnector\Setup\PluginUpdater($this->plugin_file))->init();
        (new \MotionKitConnector\Setup\LicenseKeyField($this->plugin_file))->init();
    }

    /**
     * Register the animation-data tools handlers (Tools tab data layer:
     * list/delete AJAX + delete_one POST) and the mark beside animated posts
     * in the post lists. The tab's UI lives in the MotionKit dashboard
     * plugin; admin-ajax requests run with is_admin() true, so the guard
     * covers them too.
     *
     * @return void
     */
    private function init_admin_tools(): void
    {
        if (is_admin()) {
            (new \MotionKitConnector\Admin\AnimationDataTools())->init();
            (new \MotionKitConnector\Admin\PostListBadge())->init();
        }
    }

    /**
     * Register the admin-side dependency installer that fetches the main
     * MotionKit plugin when only the connector is installed.
     *
     * @return void
     */
    private function init_dependency_installer(): void
    {
        (new \MotionKitConnector\Setup\DependencyInstaller())->init();
    }

    /**
     * Register the connector's Plugins-list action links and row meta
     * (Settings · Editor · Docs · Visit site).
     *
     * @return void
     */
    private function init_plugin_row_links(): void
    {
        (new \MotionKitConnector\Setup\PluginRowLinks($this->plugin_file))->init();
    }

    /**
     * Initialize auth & license handlers
     *
     * @return void
     */
    private function init_auth(): void
    {
        $oauth = new \MotionKitConnector\Auth\OAuthHandler();
        $oauth->init();

        $license = new \MotionKitConnector\Auth\LicenseStatus();
        $license->init();
    }

    /**
     * Initialize frontend functionality
     *
     * @return void
     */
    private function init_frontend(): void
    {
        $this->frontend = ComponentFactory::create_frontend();
        $this->frontend->init();
    }

    /**
     * Initialize REST API endpoints
     *
     * @return void
     */
    private function init_rest_api(): void
    {
        $rest_api = new \MotionKitConnector\RestApi\RestApi();
        $rest_api->init();
    }

    /**
     * Plugin activation
     *
     * @return void
     */
    public function activate(): void
    {
        $this->maybe_cleanup_legacy_connector();

        if (!get_option('motionkit_extension_version')) {
            update_option('motionkit_extension_version', self::VERSION);
            update_option('motionkit_extension_creation_date', current_time('mysql'));
        }

        // Flag the main MotionKit plugin for auto-install on the next admin
        // request. Kept out of the activation context itself (see
        // DependencyInstaller) so a network/filesystem hiccup can't abort the
        // connector's own activation.
        (new \MotionKitConnector\Setup\DependencyInstaller())->flag_pending_install();

        do_action('motionkit_extension_activated');
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public function deactivate(): void
    {
        // Clear the license-refresh cron this plugin schedules (LicenseStatus).
        // Without this, deactivating while the sibling plugin stays active left
        // a handler-less daily event firing forever.
        $timestamp = wp_next_scheduled('motionkit_license_refresh');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'motionkit_license_refresh');
        }
        wp_clear_scheduled_hook('motionkit_license_refresh');

        do_action('motionkit_extension_deactivated');
    }

    /**
     * Prevent cloning of the singleton.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton.
     *
     * @throws \Exception
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize ' . self::class);
    }

    /**
     * Get plugin file path
     *
     * @return string
     */
    public function get_plugin_file(): string
    {
        return $this->plugin_file;
    }

    /**
     * Get plugin directory path
     *
     * @return string
     */
    public function get_plugin_dir(): string
    {
        return $this->plugin_dir;
    }

    /**
     * Get plugin URL
     *
     * @return string
     */
    public function get_plugin_url(): string
    {
        return $this->plugin_url;
    }

    /**
     * Get frontend instance
     *
     * @return Frontend|null
     */
    public function get_frontend(): ?Frontend
    {
        return $this->frontend;
    }
}
