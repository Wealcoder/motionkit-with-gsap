<?php

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

namespace MotionKitConnector\Frontend;

use MotionKitConnector\Common\MotionkitBuilderPageType;

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

/**
 * ScrollSmoother Manager
 *
 * Prints an inline script in wp_footer that:
 *  1. Injects #smooth-wrapper / #smooth-content around the existing body
 *     children (only if they don't already exist — safe against other plugins).
 *  2. Exposes window.motionkitRebootSmoother() — a settings-driven boot/kill
 *     function that reads motionkitData and creates, updates, or kills the ScrollSmoother instance.
 *
 * @package MotionKitConnector
 * @since 1.0.0
 */
final class ScrollSmoother
{
  /**
   * Should MotionKit drive the page smoother on THIS request?
   *
   * True when the ScrollSmoother is switched on for the current page or globally.
   */
  public static function should_run(): bool
  {
    // A disconnected site plays nothing (Frontend::init_hooks), so it must not claim the smoother either: a third-party smoother would stand down for one that never starts.
    $should_run = \MotionKitConnector\Auth\OAuthHandler::is_connected() && self::is_enabled_for_current_page();
    return (bool) apply_filters('motionkit_extension/scroll_smoother/should_run', $should_run);
  }

  /**
   * Is the ScrollSmoother switched on for the current page?
   */
  public static function is_enabled_for_current_page(): bool
  {
    $page_ss = self::current_page_scroll_smoother();

    // Page override wins whole; otherwise the global baseline applies.
    if (!empty($page_ss) && is_array($page_ss)) {
      return self::config_enables_smoother($page_ss);
    }

    return self::config_enables_smoother(self::global_scroll_smoother());
  }

  /**
   * Is the ScrollSmoother switched on at the GLOBAL (all-page) level, ignoring any
   * per-page override?
   */
  public static function is_enabled_globally(): bool
  {
    return self::config_enables_smoother(self::global_scroll_smoother());
  }

  /** The global scrollSmother config, or null. */
  private static function global_scroll_smoother()
  {
    $global = get_option('motionkit_global_settings');

    return (is_array($global) && isset($global['scrollSmother']) && is_array($global['scrollSmother']))
      ? $global['scrollSmother']
      : null;
  }

  /**
   * Does a scrollSmother config switch the smoother on for any device?
   *
   * @param mixed $cfg
   */
  private static function config_enables_smoother($cfg): bool
  {
    if (!is_array($cfg)) {
      return false;
    }

    if (isset($cfg['allPage']) && is_array($cfg['allPage'])) {
      $cfg = $cfg['allPage'];
    }

    if (isset($cfg['enable']) && false === filter_var($cfg['enable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
      return false;
    }

    foreach (['desktop', 'laptop', 'tablet', 'mobile', 'tab', 'tab_land'] as $device) {
      $bucket = (isset($cfg[$device]) && is_array($cfg[$device])) ? $cfg[$device] : null;
      if ($bucket && false !== filter_var($bucket['enable'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) && (float) ($bucket['value'] ?? 0) > 0) {
        return true;
      }
    }

    return false;
  }

  /** The current page's scrollSmother override, or null. */
  private static function current_page_scroll_smoother()
  {
    // Try MotionKitConnector page type resolver
    try {
      $pt = MotionkitBuilderPageType::instance();
      $cfg = $pt->getCurrentPageType();

      if (is_array($cfg) && !empty($cfg['option'])) {
        $cfg['option'] = str_replace('motionkit_pg_animation_', 'motionkit_pg_settings_', $cfg['option']);
        $settings = $pt->getConfig($cfg);

        if (is_array($settings) && isset($settings['scrollSmother']) && is_array($settings['scrollSmother'])) {
          return $settings['scrollSmother'];
        }
      }
    } catch (\Throwable $e) {
      // Fallback
    }

    // Direct post meta lookup fallback for standard pages/posts
    if (is_singular()) {
      $post_id = get_the_ID();
      if ($post_id) {
        $settings = get_post_meta($post_id, 'motionkit_pg_settings_page', true);
        if (is_array($settings) && isset($settings['scrollSmother']) && is_array($settings['scrollSmother'])) {
          return $settings['scrollSmother'];
        }
        $front_settings = get_post_meta($post_id, 'motionkit_pg_settings_front_page', true);
        if (is_array($front_settings) && isset($front_settings['scrollSmother']) && is_array($front_settings['scrollSmother'])) {
          return $front_settings['scrollSmother'];
        }
      }
    }

    return null;
  }

  // phpcs:disable WordPress.Security.NonceVerification.Recommended
  public function run_scroll_smoother(): void
  {
    // Skip full preview if requested
    if (isset($_GET['motionkit_full_preview']) && $_GET['motionkit_full_preview'] === '1') {
      return;
    }
    ?>
    <script>
      (function () {
        function ensureWrapper() {
          var wrapper = document.getElementById('smooth-wrapper');
          if (wrapper) return wrapper;

          wrapper = document.createElement('div');
          wrapper.id = 'smooth-wrapper';

          var content = document.createElement('div');
          content.id = 'smooth-content';

          // Elements that must stay OUTSIDE the smooth wrapper
          // (fixed position UI, popups, modals, overlays, system bars)
          var excludeIds = [
            'wpadminbar',
            'query-monitor-main',
            'motionkit-pt-overlay',
            'elementor-finder',               // Elementor Finder popup
          ];
          var excludeSelectors = [
            '.motionkit-pt-overlay',
            '.wcf-image-generator-popup',      // WCF image generator popup
            '.dialog-widget',                  // Elementor dialog/modal widgets
            '.elementor-popup-modal',          // Elementor popup modals
            '.elementor-location-popup',       // Elementor popup locations
            '[data-elementor-type="popup"]',   // Elementor popup containers
            '.e-search',                       // Elementor search popup
            '.e-search__overlay',              // Elementor search overlay
            '#elementor-finder',               // Elementor finder
            '.dialog-lightbox-widget',         // Elementor lightbox
            '[role="dialog"]',                 // Any aria dialog/modal
            '.eael-popup',                     // Essential Addons popup
            '.jet-popup',                      // JetEngine popup
          ];

          var children = Array.prototype.slice.call(document.body.childNodes);
          for (var i = 0; i < children.length; i++) {
            var node = children[i];

            // Check if this node should be excluded
            var shouldExclude = false;
            if (node.nodeType === 1) { // Element node
              var nodeId = node.id || '';
              for (var j = 0; j < excludeIds.length; j++) {
                if (nodeId === excludeIds[j]) {
                  shouldExclude = true;
                  break;
                }
              }
              if (!shouldExclude) {
                for (var k = 0; k < excludeSelectors.length; k++) {
                  try {
                    if (node.matches && node.matches(excludeSelectors[k])) {
                      shouldExclude = true;
                      break;
                    }
                  } catch (e) {}
                }
              }
            }

            if (!shouldExclude) {
              content.appendChild(node);
            }
          }

          wrapper.appendChild(content);
          document.body.appendChild(wrapper);

          // Sentinel for pin-end detection
          var sentinel = document.createElement('div');
          sentinel.className = 'motionkit-pin-end-selector-26';
          sentinel.hidden = true;
          wrapper.appendChild(sentinel);

          return wrapper;
        }

        function resolveDeviceKey(deviceConfig) {
          var list;
          if (Array.isArray(deviceConfig)) {
            list = deviceConfig;
          } else if (deviceConfig && typeof deviceConfig === 'object') {
            var order = ['desktop', 'laptop', 'tab_land', 'tab', 'mobile'];
            list = [];
            for (var o = 0; o < order.length; o++) {
              var entry = deviceConfig[order[o]];
              if (entry) list.push(entry);
            }
          } else {
            return 'desktop';
          }

          for (var i = 0; i < list.length; i++) {
            var d = list[i];
            if (d && d.mediaQuery) {
              try {
                if (window.matchMedia(d.mediaQuery).matches) return d.key;
              } catch (e) {}
            }
          }
          return (list[0] && list[0].key) || 'desktop';
        }

        function resolveSmootherValue() {
          var data = window.motionkitData || {};
          var gs = data.global_settings || data.all_settings || {};
          var page = data.currentPageSettings || {};

          var pageCfg = page.scrollSmother || (page.currentPageSettings && page.currentPageSettings.scrollSmother) || null;
          var globalCfg = gs.scrollSmother || (gs.globalSettings && gs.globalSettings.scrollSmother) || null;

          var cfg = null;
          if (pageCfg && typeof pageCfg === 'object' && typeof pageCfg.enable !== 'undefined') {
            cfg = pageCfg;
          } else if (globalCfg && typeof globalCfg === 'object') {
            cfg = globalCfg.allPage || globalCfg;
          }

          if (!cfg || cfg.enable === false) return null;

          var devices = data.device_config || data.deviceConfig || gs.deviceConfig || [];
          var currentKey = resolveDeviceKey(devices);

          var device = cfg[currentKey];
          if (!device && (currentKey === 'tab_land' || currentKey === 'tab')) {
            device = cfg.tablet || cfg.tab;
          }
          if (!device) {
            device = cfg.desktop;
          }

          if (!device || device.enable === false) return null;

          var raw = Number(device.value);
          if (!isFinite(raw)) raw = 1;
          var smooth = Math.max(0, Math.min(2, raw));
          return smooth > 0 ? smooth : null;
        }

        window.motionkitRebootSmoother = function () {
          if (typeof window.gsap === 'undefined' || typeof window.ScrollSmoother === 'undefined') {
            return;
          }

          gsap.registerPlugin(ScrollSmoother);
          var existing = ScrollSmoother.get();
          var smooth = resolveSmootherValue();

          if (smooth === null) {
            if (existing) existing.kill();
            return;
          }

          ensureWrapper();

          if (existing) {
            existing.smooth(smooth);
            return;
          }

          ScrollSmoother.create({
            wrapper: '#smooth-wrapper',
            content: '#smooth-content',
            smooth: smooth,
            effects: true,
            normalizeScroll: false,
            ignoreMobileResize: true
          });
        };

        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', window.motionkitRebootSmoother);
          window.addEventListener('load', window.motionkitRebootSmoother);
        } else {
          window.motionkitRebootSmoother();
        }

        var _mkSmootherResizeTimer;
        function _mkRebootSmootherDebounced() {
          clearTimeout(_mkSmootherResizeTimer);
          _mkSmootherResizeTimer = setTimeout(window.motionkitRebootSmoother, 200);
        }
        window.addEventListener('resize', _mkRebootSmootherDebounced);
        window.addEventListener('orientationchange', _mkRebootSmootherDebounced);
      })();
    </script>
    <?php
  }
  // phpcs:enable WordPress.Security.NonceVerification.Recommended
}
