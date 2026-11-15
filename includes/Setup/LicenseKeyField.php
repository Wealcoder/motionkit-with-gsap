<?php

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

namespace MotionKitConnector\Setup;

use MotionKitConnector\Auth\LicenseStatus;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A license key saved by hand in the Connector's Plugins-list row.
 *
 * Updates no longer need a key, so the field that asked for one is gone. A site
 * that saved one earlier keeps using it, and this row is how it can be removed.
 *
 * @package MotionKitConnector
 * @since 1.0.1
 */
final class LicenseKeyField
{
    private const SAVE_ACTION = 'motionkit_connector_save_key';
    private const RESULT_ARG = 'motionkit_key_result';

    private string $basename;

    public function __construct(string $plugin_file)
    {
        $this->basename = plugin_basename($plugin_file);
    }

    public function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('after_plugin_row_' . $this->basename, [$this, 'render_row'], 10, 2);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_remove']);
        add_action('admin_notices', [$this, 'render_notice']);
    }

    /**
     * @param string $plugin_file
     * @param array  $plugin_data
     */
    public function render_row($plugin_file, $plugin_data): void
    {
        $manual = LicenseStatus::get_manual_key();

        if ('' === $manual || !current_user_can('update_plugins')) {
            return;
        }

        $active = is_plugin_active($this->basename) ? ' active' : '';
        $columns = _get_list_table('WP_Plugins_List_Table')->get_column_count();

        printf('<tr class="plugin-update-tr%s"><td colspan="%d" class="plugin-update colspanchange">', esc_attr($active), (int) $columns);
        echo '<div class="notice inline notice-info notice-alt"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        printf(
            /* translators: %s: last four characters of the saved license key. */
            '<p>' . esc_html__('This site has a saved license key ending in %s.', 'motionkit-with-gsap') . ' ',
            '<code>' . esc_html(substr($manual, -4)) . '</code>'
        );
        submit_button(__('Remove key', 'motionkit-with-gsap'), 'link-delete small', 'motionkit_remove_key', false);
        echo '</p></form></div></td></tr>';
    }

    public function handle_remove(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'motionkit-with-gsap'));
        }

        check_admin_referer(self::SAVE_ACTION);
        LicenseStatus::set_manual_key('');

        wp_safe_redirect(add_query_arg(self::RESULT_ARG, 'removed', self_admin_url('plugins.php')));
        exit;
    }

    public function render_notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the value is matched against a fixed string.
        $result = isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';

        if ('removed' !== $result || !current_user_can('update_plugins')) {
            return;
        }

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('License key removed.', 'motionkit-with-gsap'));
    }
}
