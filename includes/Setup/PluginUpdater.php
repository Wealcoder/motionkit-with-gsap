<?php

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

namespace MotionKitConnector\Setup;

use MotionKitConnector\Auth\LicenseStatus;
use MotionKitConnector\Auth\OAuthHandler;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves Connector updates to every site, with or without a license.
 *
 * The MotionKit store (EDD Software Licensing) says which version is current,
 * and hands out a package for a license valid on this site's URL. Every other
 * site gets the public GitHub release zip, the one motionkit.io's download
 * button serves.
 *
 * @package MotionKitConnector
 * @since 1.0.0
 */
final class PluginUpdater
{
    public const STORE_URL = 'https://my.motionkit.io/';
    public const ITEM_ID = 17;
    private const PUBLIC_PACKAGE_URL = 'https://github.com/Wealcoder/motionkit-with-gsap/releases/latest/download/motionkit-with-gsap.zip';
    private const CACHE_OPTION = 'motionkit_connector_update_info';
    private const FAILED_OPTION = 'motionkit_connector_update_failed';
    private const CACHE_TTL = 3 * HOUR_IN_SECONDS;
    private const RETRY_AFTER = HOUR_IN_SECONDS;
    private const CHECK_ACTION = 'motionkit_connector_check_update';
    private const CHECK_RESULTS = ['available', 'current', 'error'];

    private string $basename;
    private string $slug;
    private string $license = '';
    private bool $force_checked = false;

    public function __construct(string $plugin_file)
    {
        $this->basename = plugin_basename($plugin_file);
        $this->slug = dirname($this->basename);
    }

    public function init(): void
    {
        // Updates are only ever checked from wp-admin, the wp_version_check cron and WP-CLI, so a front-end request has nothing to hook.
        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }

        add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 2);
        add_action('admin_post_' . self::CHECK_ACTION, [$this, 'handle_manual_check']);
        add_action('admin_notices', [$this, 'render_check_notice']);

        $this->license = LicenseStatus::get_license_key();
        $this->register_update_hooks();
    }

    private function register_update_hooks(): void
    {
        if (has_filter('pre_set_site_transient_update_plugins', [$this, 'check_update'])) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
    }

    /**
     * "Check for updates" in the Connector's own Plugins-list row.
     *
     * @param array  $meta
     * @param string $plugin_file
     * @return array
     */
    public function row_meta(array $meta, string $plugin_file): array
    {
        if ($plugin_file !== $this->basename || !current_user_can('update_plugins')) {
            return $meta;
        }

        $meta[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::CHECK_ACTION), self::CHECK_ACTION)),
            esc_html__('Check for updates', 'motionkit-with-gsap')
        );

        return $meta;
    }

    public function handle_manual_check(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'motionkit-with-gsap'));
        }

        check_admin_referer(self::CHECK_ACTION);

        // Refresh first so a site that connected before the server started sending the key picks it up now rather than at the next daily refresh.
        if (OAuthHandler::is_connected()) {
            LicenseStatus::refresh(true);
        }

        $this->license = LicenseStatus::get_license_key();
        delete_option(self::CACHE_OPTION);
        delete_option(self::FAILED_OPTION);
        $this->register_update_hooks();

        // Deleting the transient lifts wp_update_plugins()'s own throttle, and its rewrite of the transient is what runs check_update().
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $transient = get_site_transient('update_plugins');

        if (isset($transient->response[$this->basename])) {
            $result = 'available';
        } elseif (isset($transient->no_update[$this->basename])) {
            $result = 'current';
        } else {
            $result = 'error';
        }

        wp_safe_redirect(add_query_arg('motionkit_update_check', $result, self_admin_url('plugins.php')));
        exit;
    }

    public function render_check_notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the value is matched against a fixed list.
        $result = isset($_GET['motionkit_update_check']) ? sanitize_key(wp_unslash($_GET['motionkit_update_check'])) : '';

        if (!in_array($result, self::CHECK_RESULTS, true) || !current_user_can('update_plugins')) {
            return;
        }

        $messages = [
            'available'  => ['success', __('A new version of MotionKit Connector is available. Use "update now" in its row below.', 'motionkit-with-gsap')],
            'current'    => ['success', __('MotionKit Connector is up to date.', 'motionkit-with-gsap')],
            'error'      => ['error', __('Could not reach the MotionKit update server. Please try again in a few minutes.', 'motionkit-with-gsap')],
        ];

        [$type, $message] = $messages[$result];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    /**
     * Inject this plugin's entry into the update_plugins transient.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function check_update($transient)
    {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $info = $this->get_info();

        if (!$info) {
            return $transient;
        }

        $item = $this->to_update_item($info);

        if (version_compare(MOTIONKIT_CONNECTOR_VERSION, $info->new_version, '<')) {
            $transient->response[$this->basename] = $item;
            unset($transient->no_update[$this->basename]);
        } else {
            // WordPress needs the no_update entry to offer the auto-update toggle for a plugin that is not on wordpress.org.
            $transient->no_update[$this->basename] = $item;
            unset($transient->response[$this->basename]);
        }

        $transient->checked[$this->basename] = MOTIONKIT_CONNECTOR_VERSION;

        return $transient;
    }

    /**
     * Answer the "View version details" modal for this plugin.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugins_api($result, $action = '', $args = null)
    {
        if ('plugin_information' !== $action || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $info = $this->get_info();

        if (!$info) {
            return $result;
        }

        return (object) [
            'name'          => $info->name,
            'slug'          => $this->slug,
            'version'       => $info->new_version,
            'new_version'   => $info->new_version,
            'author'        => '<a href="https://motionkit.io">MotionKit</a>',
            'homepage'      => $info->homepage,
            'download_link' => $info->package,
            'last_updated'  => $info->last_updated,
            'tested'        => $info->tested,
            'requires'      => $info->requires,
            'requires_php'  => $info->requires_php,
            'sections'      => $this->sections_for($info),
            'banners'       => $info->banners,
            'icons'         => $this->icons_for($info),
        ];
    }

    // A section the store leaves empty is read from the bundled readme.txt, so the modal is never blank because the store's product page was not filled in.
    private function sections_for(object $info): array
    {
        $sections = array_filter((array) $info->sections, static function ($html) {
            return '' !== trim(wp_strip_all_tags((string) $html));
        });

        return $sections + self::readme_sections();
    }

    // The store's icons come from the product's featured image, which the store theme also prints at checkout, so the bundled icon stands in when it has none.
    private function icons_for(object $info): array
    {
        $icons = array_filter((array) $info->icons);

        if ($icons) {
            return $icons;
        }

        return [
            '1x' => MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/images/icon-128x128.gif',
            '2x' => MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/images/icon-256x256.gif',
        ];
    }

    /**
     * The Description and Changelog of the bundled readme.txt as HTML, keyed the way the modal expects.
     *
     * @return array<string, string>
     */
    private static function readme_sections(): array
    {
        $file = MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'readme.txt';

        if (!is_readable($file)) {
            return [];
        }

        $parts = preg_split('/^==\s*(.+?)\s*==\s*$/m', (string) file_get_contents($file), -1, PREG_SPLIT_DELIM_CAPTURE); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, not a URL.
        $sections = [];

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $key = sanitize_key($parts[$i]);

            if (in_array($key, ['description', 'changelog'], true)) {
                $sections[$key] = self::readme_to_html($parts[$i + 1]);
            }
        }

        return array_filter($sections);
    }

    // Just the readme syntax this plugin's readme uses: "= 1.0.0 =" headings, "* " list items and plain paragraphs.
    private static function readme_to_html(string $text): string
    {
        $html = '';
        $list = false;

        foreach (preg_split('/\R/', trim($text)) as $line) {
            $line = trim($line);
            $is_item = 0 === strpos($line, '* ');

            if ($list && !$is_item) {
                $html .= '</ul>';
                $list = false;
            }

            if ('' === $line) {
                continue;
            }

            if ($is_item) {
                $html .= ($list ? '' : '<ul>') . '<li>' . esc_html(substr($line, 2)) . '</li>';
                $list = true;
            } elseif (preg_match('/^=\s*(.+?)\s*=$/', $line, $m)) {
                $html .= '<h4>' . esc_html($m[1]) . '</h4>';
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }

        return $html . ($list ? '</ul>' : '');
    }

    private function to_update_item(object $info): object
    {
        return (object) [
            'id'           => $this->basename,
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => $info->new_version,
            'url'          => $info->homepage,
            'package'      => $info->package,
            'tested'       => $info->tested,
            'requires'     => $info->requires,
            'requires_php' => $info->requires_php,
            'icons'        => $this->icons_for($info),
            'banners'      => $info->banners,
        ];
    }

    /**
     * The store's answer, cached for CACHE_TTL and keyed to the license so a changed key is never served another key's package.
     *
     * @return object|null
     */
    private function get_info(): ?object
    {
        $license_hash = md5($this->license);
        $cache = get_option(self::CACHE_OPTION);

        if (!$this->is_force_check()
            && is_array($cache)
            && ($cache['license'] ?? '') === $license_hash
            && (int) ($cache['timeout'] ?? 0) > time()
        ) {
            $cached = json_decode((string) ($cache['value'] ?? ''), true);

            return is_array($cached) ? (object) $cached : null;
        }

        // A store outage would otherwise add a 15s request to every wp-admin page load until it recovers.
        if ((int) get_option(self::FAILED_OPTION, 0) > time()) {
            return null;
        }

        $info = $this->fetch_remote();

        if (!$info) {
            update_option(self::FAILED_OPTION, time() + self::RETRY_AFTER, false);
            return null;
        }

        delete_option(self::FAILED_OPTION);
        update_option(
            self::CACHE_OPTION,
            [
                'license' => $license_hash,
                'timeout' => time() + self::CACHE_TTL,
                'value'   => wp_json_encode($info),
            ],
            false
        );

        return (object) $info;
    }

    // "Check again" on Dashboard → Updates should reach the store once, not reuse a three-hour-old answer; the flag stops the several transient writes in that request from each refetching.
    private function is_force_check(): bool
    {
        if ($this->force_checked) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; it only decides whether to bypass a cache.
        if (empty($_GET['force-check']) || !current_user_can('update_plugins')) {
            return false;
        }

        $this->force_checked = true;

        return true;
    }

    /**
     * @return array|null
     */
    private function fetch_remote(): ?array
    {
        $store_url = defined('MOTIONKIT_STORE_URL') ? MOTIONKIT_STORE_URL : apply_filters('motionkit_store_url', self::STORE_URL);
        $response = wp_remote_post(
            $store_url,
            [
                'timeout' => 15,
                'body'    => [
                    'edd_action'  => 'get_version',
                    'license'     => $this->license,
                    'item_id'     => self::ITEM_ID,
                    'version'     => MOTIONKIT_CONNECTOR_VERSION,
                    'slug'        => $this->slug,
                    'url'         => home_url(),
                    'beta'        => false,
                    'php_version' => phpversion(),
                    'wp_version'  => get_bloginfo('version'),
                ],
            ]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || empty($body['new_version'])) {
            return null;
        }

        $sections = array_map('wp_kses_post', array_map('strval', self::unpack($body['sections'] ?? [])));
        $package = esc_url_raw((string) ($body['package'] ?? ''));

        return [
            'name'         => sanitize_text_field((string) ($body['name'] ?? 'MotionKit Connector')),
            'new_version'  => sanitize_text_field((string) $body['new_version']),
            'homepage'     => esc_url_raw((string) ($body['homepage'] ?? 'https://motionkit.io')),
            // The store leaves it empty for a site with no license valid here, which still gets the update, from the public release.
            'package'      => '' !== $package ? $package : self::PUBLIC_PACKAGE_URL,
            'last_updated' => sanitize_text_field((string) ($body['last_updated'] ?? '')),
            'tested'       => sanitize_text_field((string) ($body['tested'] ?? '')),
            'requires'     => sanitize_text_field((string) ($body['requires'] ?? '')),
            'requires_php' => sanitize_text_field((string) ($body['requires_php'] ?? '')),
            'sections'     => $sections,
            'banners'      => array_map('esc_url_raw', array_map('strval', self::unpack($body['banners'] ?? []))),
            'icons'        => array_map('esc_url_raw', array_map('strval', self::unpack($body['icons'] ?? []))),
        ];
    }

    // EDD sends sections and banners as PHP-serialized strings; objects are refused so a tampered response cannot instantiate a class here.
    private static function unpack($value): array
    {
        if (is_string($value) && is_serialized($value)) {
            $value = @unserialize($value, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        }

        return is_array($value) ? array_filter($value, 'is_scalar') : [];
    }
}
