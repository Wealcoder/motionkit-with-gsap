<?php

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

namespace MotionKitConnector\Frontend;

/**
 * Frontend Class for MotionKit Extension
 *
 * @package MotionKitConnector
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

use MotionKitConnector\Common\Assets\AssetLoader;
use MotionKitConnector\Common\AnimationResolver;
use MotionKitConnector\Common\MotionkitBuilderPageType;
use MotionKitConnector\Common\ShareContext;
use MotionKitConnector\Common\ShareLinks;
use MotionKitConnector\Auth\JwtTokenManager;
use MotionKitConnector\Auth\OAuthHandler;
use MotionKitConnector\Factory\ComponentFactory;
use MotionKitConnector\Support\EditorSessionTrait;

/**
 * Frontend Class
 *
 * Handles frontend animation execution, script loading, GSAP enqueues,
 * ScrollSmoother, Page Transitions, Preloader Engine, and AJAX config endpoints.
 */
final class Frontend
{
  use EditorSessionTrait;

  /**
   * Maps get_active_plugins()'s animation-schema keys to their registered WP script handle.
   *
   * @var array<string,string>
   */
  private const GSAP_SCHEMA_TO_HANDLE = [
    'scrollTo'   => 'ScrollToPlugin',
    'drawSVG'    => 'DrawSVGPlugin',
    'morphSVG'   => 'MorphSVGPlugin',
    'motionPath' => 'MotionPathPlugin',
    'splitText'  => 'SplitText',
  ];

  /**
   * Every spelling one GSAP library's on/off toggle can be stored under.
   *
   * The toggles are named after WP script handles, but the vendor map in
   * register_required_script() also carries the older camelCase alias for the
   * same file, and a site saved by an older editor may hold either. A handle is
   * looked up under both spellings so one switch governs one library.
   *
   * @var array<string,string>
   */
  private const GSAP_TOGGLE_ALIASES = [
    'ScrollToPlugin'     => 'scrollTo',
    'DrawSVGPlugin'      => 'drawSVG',
    'MorphSVGPlugin'     => 'morphSVG',
    'MotionPathPlugin'   => 'motionPath',
    'SplitText'          => 'splitText',
    'TextPlugin'         => 'text',
    'ScrambleTextPlugin' => 'scrambleText',
    'Flip'               => 'flip',
    'Draggable'          => 'draggable',
    'InertiaPlugin'      => 'inertia',
    'Physics2DPlugin'    => 'physics2D',
    'PhysicsPropsPlugin' => 'physicsProps',
    'CustomEase'         => 'customEase',
    'CustomWiggle'       => 'customWiggle',
    'CustomBounce'       => 'customBounce',
  ];

  /**
   * Asset loader instance
   *
   * @var AssetLoader
   */
  private AssetLoader $asset_loader;

  /**
   * Page type resolver
   *
   * @var MotionkitBuilderPageType
   */
  private MotionkitBuilderPageType $page_type;

  /**
   * Animation payload resolver — the only reader of the animation buckets.
   *
   * @var AnimationResolver
   */
  private AnimationResolver $resolver;

  /**
   * Share link store — validates the `mk_share` token against this page's rows.
   *
   * @var ShareLinks
   */
  private ShareLinks $share_links;

  /**
   * Per-request memo for the resolved share link.
   *
   * Sentinel `false` = not yet resolved; `null` = no link, or a token that
   * matched nothing. A dead token deliberately lands on `null` so the request
   * falls through the ordinary published path — a revoked link shows the live
   * page rather than an error.
   *
   * @var ShareContext|null|false
   */
  private $share_context_cache = false;

  /**
   * ScrollSmoother manager
   *
   * @var ScrollSmoother
   */
  private ScrollSmoother $smoother;

  /**
   * Per-request memo for resolved preloader config.
   *
   * @var array|null|false
   */
  private $preloader_cache = false;

  /**
   * Per-request memo for global settings.
   *
   * @var array|object|false
   */
  private $global_settings_cache = false;

  /**
   * GSAP handles that actually registered this request.
   *
   * @var array<string,bool>
   */
  private array $registered_gsap_handles = [];

  /**
   * Optional GSAP plugin handles requested by config but NOT registered.
   *
   * @var array<string,bool>
   */
  private array $unavailable_gsap_handles = [];

  /**
   * Initialize frontend functionality
   *
   * @return void
   */
  public function init(): void
  {
    $this->asset_loader = ComponentFactory::create_asset_loader();
    $this->page_type = MotionkitBuilderPageType::instance();
    $this->resolver = new AnimationResolver($this->page_type);
    $this->share_links = new ShareLinks($this->page_type);
    $this->smoother = new ScrollSmoother();

    $this->init_hooks();
  }

  /**
   * Initialize hooks
   *
   * @return void
   */
  private function init_hooks(): void
  {
    // Cache bypass is decided from the query string alone, before anything is resolved — a full-page cache writes its entry long before wp_enqueue_scripts runs, so deciding later would let a share response be stored and then served to ordinary visitors.
    $this->block_page_cache_for_share();

    // Allow the editor iframe to embed this site
    add_action('send_headers', [$this, 'allow_editor_iframe']);

    // Never let a page cache serve a stale motionkitData snapshot into the
    // editor iframe (LiteSpeed / WP Rocket / Cloudflare APO) — animations
    // would appear unchanged after a save + reload.
    add_action('send_headers', [$this, 'no_store_for_editor_preview']);

    // Optimizer plugins defer, delay or rewrite scripts; inside the editor iframe the bridge has to run on load, unprompted. Decided here, from the query string, for the same reason the share bypass is: WP Rocket and LiteSpeed read these constants before anything later could set them.
    $this->disable_optimizers_for_preview();
    add_filter('script_loader_tag', [$this, 'exempt_script_from_optimizers'], 20, 2);
    add_filter('wp_inline_script_attributes', [$this, 'exempt_inline_script_from_optimizers']);

    // Register GSAP CDN scripts via core deps filter
    add_filter('motionkit_core_lib_deps', [$this, 'register_required_script']);
    add_filter('motionkit_extension_core_lib_deps', [$this, 'register_required_script']);

    // Stand-down signal for third-party smoothers
    add_filter('bricksfly_smooth_scroller_is_active', [$this, 'veto_third_party_smoother']);

    // Frontend script enqueue & head output — only on actual page loads, and only while the site is connected to a MotionKit account. Disconnecting pauses every animation, transition, preloader and smoother without touching the saved records; reconnecting brings them back. A stored-token check, never a remote one, so no page load waits on the editor.
    if (!is_admin() && OAuthHandler::is_connected()) {
      add_action('wp_head', ['\\MotionKitConnector\\Frontend\\PageTransition', 'print_head_view_transition'], 0);
      add_action('wp_head', ['\\MotionKitConnector\\Frontend\\PageTransition', 'print_head_early_cover'], 0);
      add_action('wp_enqueue_scripts', [$this, 'enqueue_page_transition'], 2);
      add_action('wp_enqueue_scripts', [$this, 'enqueue_preloader'], 5);
      add_action('wp_head', [$this, 'print_preloader_cover'], 0);
      add_action('wp_head', [$this, 'print_gsap_preload'], 1);
      add_action('wp_enqueue_scripts', [$this, 'maybe_init_scroll_smoother'], 50);
      add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts'], 60);
    }

    // AJAX handlers
    add_action('wp_ajax_motionkit_builder_pagetype_configs', [$this, 'ajax_configs_store']);
    add_action('wp_ajax_motionkit_configs_delete', [$this, 'ajax_configs_delete']);
    add_action('wp_ajax_motionkit_builder_gl_configs_store', [$this, 'ajax_global_configs_store']);
    add_action('wp_ajax_motionkit_gl_configs_delete', [$this, 'ajax_global_configs_delete']);

    do_action('motionkit_extension/frontend/init', $this);
  }

  /**
   * Read motionkit_global_settings, memoized per request.
   *
   * @return array|object
   */
  private function get_global_settings()
  {
    if ($this->global_settings_cache !== false) {
      return $this->global_settings_cache;
    }
    return $this->global_settings_cache = get_option('motionkit_global_settings', []);
  }

  /**
   * Resolve the active preloader config, memoized per request.
   *
   * @return array|null Config array when enabled, null otherwise.
   */
  public function get_preloader()
  {
    if ($this->preloader_cache !== false) {
      return $this->preloader_cache;
    }

    $settings = $this->get_global_settings();
    if (is_object($settings)) {
      $settings = (array) $settings;
    }
    if (!is_array($settings) || !isset($settings['preloader'])) {
      return $this->preloader_cache = null;
    }

    $preloader = $settings['preloader'];
    if (is_object($preloader)) {
      $preloader = (array) $preloader;
    }
    if (!is_array($preloader) || empty($preloader['enable'])) {
      return $this->preloader_cache = null;
    }

    return $this->preloader_cache = $preloader;
  }

  /**
   * Plugin-relative path of the engine bundle for the selected preloader, or null.
   *
   * @param array $preloader
   * @return string|null
   */
  private function preloader_bundle(array $preloader): ?string
  {
    $dir = 'assets/build/presets/preloader/';

    $is_custom = isset($preloader['tabs']) && $preloader['tabs'] === 'custom';
    if ($is_custom) {
      $key = 'custom';
    } else {
      $base = $this->preloader_base($preloader);
      $key = isset($base['preloaderType']) && is_string($base['preloaderType'])
        ? $base['preloaderType']
        : 'spinner';
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key)) {
      $key = 'spinner';
    }

    if (file_exists(MOTIONKIT_EXTENSION_PLUGIN_DIR . $dir . $key . '.js')) {
      return $dir . $key . '.js';
    }
    if (file_exists(MOTIONKIT_EXTENSION_PLUGIN_DIR . $dir . 'spinner.js')) {
      return $dir . 'spinner.js';
    }

    return null;
  }

  private function preloader_base(array $preloader): array
  {
    $mode = (isset($preloader['tabs']) && $preloader['tabs'] === 'custom')
      ? 'custom'
      : 'preset';
    $base = isset($preloader[$mode]) ? $preloader[$mode] : [];
    if (is_object($base)) {
      $base = (array) $base;
    }
    return is_array($base) ? $base : [];
  }

  /**
   * Paint the preloader cover before anything else renders.
   *
   * @return void
   */
  public function print_preloader_cover(): void
  {
    if ($this->is_editor_preview() || $this->is_full_preview()) {
      return;
    }

    $preloader = $this->get_preloader();
    if ($preloader === null) {
      return;
    }

    if ($this->preloader_bundle($preloader) === null) {
      return;
    }

    $base = $this->preloader_base($preloader);

    $background = isset($base['background']) && is_string($base['background'])
      ? $base['background']
      : '#0a0a0a';
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$|^rgba?\([0-9,.\s%]+\)$/', $background)) {
      $background = '#0a0a0a';
    }

    $max = isset($base['maxDuration']) ? (float) $base['maxDuration'] : 8.0;
    if ($max < 1) {
      $max = 1.0;
    }
    if ($max > 60) {
      $max = 60.0;
    }

    $opacity = isset($base['overlayOpacity']) ? (float) $base['overlayOpacity'] : 1.0;
    $mask_body = $opacity >= 1;

    $css = sprintf(
      'html.motionkit-preloading{background:%s!important;animation:mk-pl-failsafe-html .01s linear %ss forwards}',
      $background,
      $max
    );
    if ($mask_body) {
      $css .= sprintf(
        'html.motionkit-preloading body{visibility:hidden;animation:mk-pl-failsafe-body .01s linear %ss forwards}',
        $max
      );
    }
    $css .= '@keyframes mk-pl-failsafe-html{to{background:transparent!important}}';
    $css .= '@keyframes mk-pl-failsafe-body{to{visibility:visible}}';

    printf(
      "\n<style id='motionkit-preloader-cover-style'>%s</style>\n",
      $css
    );

    wp_print_inline_script_tag(
      "document.documentElement.className+=' motionkit-preloading';",
      ['id' => 'motionkit-preloader-cover']
    );
  }

  /**
   * Enqueue the preloader engine into <head>, with its config inlined before it.
   *
   * @return void
   */
  public function enqueue_preloader(): void
  {
    if ($this->is_editor_preview() || $this->is_full_preview()) {
      return;
    }

    $preloader = $this->get_preloader();
    if ($preloader === null) {
      return;
    }

    $bundle = $this->preloader_bundle($preloader);
    if ($bundle === null) {
      return;
    }

    // The engine core is its own bundle now instead of being inlined into all seventeen preset
    // bundles. Two handles: the core, then the one preset, which depends on it so WordPress
    // prints them in that order.
    wp_enqueue_script(
      'motionkit-preloader-engine',
      MOTIONKIT_EXTENSION_PLUGIN_URL . 'assets/build/engine/preloaderEngine.js',
      [],
      MOTIONKIT_EXTENSION_VERSION,
      false
    );

    wp_enqueue_script(
      'motionkit-preloader',
      MOTIONKIT_EXTENSION_PLUGIN_URL . $bundle,
      ['motionkit-preloader-engine'],
      MOTIONKIT_EXTENSION_VERSION,
      false
    );

    // Attached to the core so the config is in place before either script runs — the preset is
    // what actually calls into the engine, and it cannot run before its own dependency.
    wp_add_inline_script(
      'motionkit-preloader-engine',
      'window.__MOTIONKIT_PRELOADER__=' . wp_json_encode($preloader) . ';',
      'before'
    );
  }

  /**
   * Switch off third-party smoothers when MotionKit Extension owns it.
   *
   * @param mixed $active
   * @return bool
   */
  public function veto_third_party_smoother($active): bool
  {
    return ScrollSmoother::should_run() ? false : (bool) $active;
  }

  /**
   * Hook ScrollSmoother runtime in wp_footer if active
   *
   * @return void
   */
  public function maybe_init_scroll_smoother(): void
  {
    if (!ScrollSmoother::should_run()) {
      return;
    }

    add_action('wp_footer', [$this->smoother, 'run_scroll_smoother']);
  }

  /**
   * Preload GSAP for smooth page transition execution
   *
   * @return void
   */
  /**
   * Preload GSAP for smooth page transition execution
   *
   * @return void
   */
  public function print_gsap_preload(): void
  {
    if ($this->is_editor_preview() || $this->is_full_preview()) {
      return;
    }

    if ($this->page_transition_config() === null) {
      return;
    }

    $gsap_src = wp_scripts()->registered['gsap']->src ?? '';
    if ($gsap_src === '') {
      return;
    }

    $host = wp_parse_url($gsap_src, PHP_URL_HOST);
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

    // If external CDN is used, output DNS prefetch and preconnect
    if (!empty($host) && $host !== $site_host) {
      $origin = 'https://' . $host;
      printf("<link rel='dns-prefetch' href='%s'>\n", esc_url($origin));
      printf("<link rel='preconnect' href='%s' crossorigin>\n", esc_url($origin));
    }
  }

  /**
   * Enqueue GSAP and Page Transition runtime in <head>
   *
   * @return void
   */
  public function enqueue_page_transition(): void
  {
    if ($this->is_editor_preview() || $this->is_full_preview()) {
      return;
    }

    $config = $this->page_transition_config();
    if ($config === null) {
      return;
    }

    $bundle = $this->page_transition_bundle($config);
    if ($bundle === null) {
      return;
    }

    $this->register_required_script([]);
    wp_enqueue_script('gsap');

    // Same shape as the preloader: the engine core as one bundle, plus the single selected transition, and the settings handed over as data on the global the engine reads.
    // This replaces a generated runtime that was stored in the `motionkit-page-transition-code` option and printed inline. That code was frozen at whatever editor version saved it, so engine fixes never reached a live transition, and when no code was stored a PHP fallback drew a curtain regardless of which preset the user had chosen.
    wp_enqueue_script(
      'motionkit-page-transition-engine',
      MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/build/engine/pageTransitionEngine.js',
      ['gsap'],
      MOTIONKIT_CONNECTOR_VERSION,
      false
    );

    wp_enqueue_script(
      'motionkit-page-transition',
      MOTIONKIT_CONNECTOR_PLUGIN_URL . $bundle,
      ['motionkit-page-transition-engine'],
      MOTIONKIT_CONNECTOR_VERSION,
      false
    );

    // Attached to the core so the config is in place before either script runs — the preset is what actually calls into the engine, and it cannot run before its own dependency.
    wp_add_inline_script(
      'motionkit-page-transition-engine',
      'window.__MOTIONKIT_PAGE_TRANSITION__=' . wp_json_encode($config) . ';',
      'before'
    );
  }

  /**
   * The page-transition settings, or null when it is off or unconfigured.
   *
   * `enable` is compared strictly because the engine does the same — a stored
   * `1` or `"1"` means something wrote the setting without agreeing on the
   * type, and covering a page on a guess is not a recoverable mistake.
   *
   * @return array|null
   */
  private function page_transition_config(): ?array
  {
    $settings = $this->get_global_settings();
    $transition = is_array($settings) ? ($settings['pageTransition'] ?? null) : null;
    if (!is_array($transition)) {
      return null;
    }
    if (($transition['enable'] ?? null) !== true || !PageTransition::allowed_by_plan()) {
      return null;
    }

    // The user-authored transition is selected by `tabs`, not by a preset key — the same shape the preloader already uses. Its data lives at `custom`, so requiring a transitionType here would have made every custom transition silently stop working.
    if (($transition['tabs'] ?? null) === 'custom') {
      return is_array($transition['custom'] ?? null) ? $transition : null;
    }

    $preset = $transition['preset'] ?? null;
    if (!is_array($preset) || empty($preset['transitionType']) || !is_string($preset['transitionType'])) {
      return null;
    }

    return $transition;
  }

  /**
   * Bundle path for the selected transition, or null when none is on disk.
   *
   * The key IS the filename here, the way it already is for preloaders — which
   * is why adding a transition needs no PHP edit. The preset bundles cannot do
   * that because their key and filename are unrelated strings.
   *
   * @param array $config
   * @return string|null
   */
  private function page_transition_bundle(array $config): ?string
  {
    $dir = 'assets/build/presets/pageTransition/';
    $key = ($config['tabs'] ?? null) === 'custom'
      ? 'custom'
      : $config['preset']['transitionType'];

    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key)) {
      return null;
    }

    return file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $dir . $key . '.js')
      ? $dir . $key . '.js'
      : null;
  }

  /**
   * Register GSAP CDN libraries and premium plugins configured in settings.
   *
   * @param array $deps Existing deps from filter
   * @return array Merged dependency handles
   */
  public function register_required_script(array $deps = []): array
  {
    $this->registered_gsap_handles = [];
    $this->unavailable_gsap_handles = [];

    $global = $this->get_global_settings();
    $gsap_plugin = null;
    if (is_object($global) && isset($global->gsapPlugin)) {
      $gsap_plugin = $global->gsapPlugin;
    } elseif (is_array($global) && isset($global['gsapPlugin'])) {
      $gsap_plugin = $global['gsapPlugin'];
    }
    $gsap_plugin = is_array($gsap_plugin) ? $gsap_plugin : (is_object($gsap_plugin) ? (array) $gsap_plugin : []);
    $cdns = isset($gsap_plugin['cdns']) ? (array) $gsap_plugin['cdns'] : [];

    $local_vendor_files = [
      // Core & Scrolling
      'gsap'                => 'assets/vendor/gsap/gsap.min.js',
      'ScrollTrigger'       => 'assets/vendor/gsap/ScrollTrigger.min.js',
      'ScrollSmoother'      => 'assets/vendor/gsap/ScrollSmoother.min.js',
      'scrollSmoother'      => 'assets/vendor/gsap/ScrollSmoother.min.js',
      'ScrollToPlugin'      => 'assets/vendor/gsap/ScrollToPlugin.min.js',
      'Observer'            => 'assets/vendor/gsap/Observer.min.js',

      // Text & Typography
      'SplitText'           => 'assets/vendor/gsap/SplitText.min.js',
      'TextPlugin'          => 'assets/vendor/gsap/TextPlugin.min.js',
      'text'                => 'assets/vendor/gsap/TextPlugin.min.js',
      'ScrambleTextPlugin'  => 'assets/vendor/gsap/ScrambleTextPlugin.min.js',
      'scrambleText'        => 'assets/vendor/gsap/ScrambleTextPlugin.min.js',

      // SVG & Morphing
      'DrawSVGPlugin'       => 'assets/vendor/gsap/DrawSVGPlugin.min.js',
      'drawSVG'             => 'assets/vendor/gsap/DrawSVGPlugin.min.js',
      'MorphSVGPlugin'      => 'assets/vendor/gsap/MorphSVGPlugin.min.js',
      'morphSVG'            => 'assets/vendor/gsap/MorphSVGPlugin.min.js',
      'MotionPathPlugin'    => 'assets/vendor/gsap/MotionPathPlugin.min.js',
      'motionPath'          => 'assets/vendor/gsap/MotionPathPlugin.min.js',

      // UI & Layout
      'Flip'                => 'assets/vendor/gsap/Flip.min.js',
      'flip'                => 'assets/vendor/gsap/Flip.min.js',
      'Draggable'           => 'assets/vendor/gsap/Draggable.min.js',
      'draggable'           => 'assets/vendor/gsap/Draggable.min.js',
      'InertiaPlugin'       => 'assets/vendor/gsap/InertiaPlugin.min.js',
      'inertia'             => 'assets/vendor/gsap/InertiaPlugin.min.js',

      // Physics & Simulation
      'Physics2DPlugin'     => 'assets/vendor/gsap/Physics2DPlugin.min.js',
      'physics2D'           => 'assets/vendor/gsap/Physics2DPlugin.min.js',
      'PhysicsPropsPlugin'  => 'assets/vendor/gsap/PhysicsPropsPlugin.min.js',
      'physicsProps'        => 'assets/vendor/gsap/PhysicsPropsPlugin.min.js',

      // Custom Eases
      'CustomEase'          => 'assets/vendor/gsap/CustomEase.min.js',
      'customEase'          => 'assets/vendor/gsap/CustomEase.min.js',
      'CustomWiggle'        => 'assets/vendor/gsap/CustomWiggle.min.js',
      'customWiggle'        => 'assets/vendor/gsap/CustomWiggle.min.js',
      'CustomBounce'        => 'assets/vendor/gsap/CustomBounce.min.js',
      'customBounce'        => 'assets/vendor/gsap/CustomBounce.min.js',
      'EasePack'            => 'assets/vendor/gsap/EasePack.min.js',

      // Canvas / WebGL / CSS Helpers
      'PixiPlugin'          => 'assets/vendor/gsap/PixiPlugin.min.js',
      'EaselPlugin'         => 'assets/vendor/gsap/EaselPlugin.min.js',
      'CSSRulePlugin'       => 'assets/vendor/gsap/CSSRulePlugin.min.js',
    ];

    // Bundle GSAP locally only. wordpress.org forbids loading executable JS
    // from a remote CDN, and a CDN fallback is also a supply-chain risk (CDN
    // compromise → arbitrary JS on every page). A handle whose local file is
    // missing is simply not registered rather than sourced remotely.
    $default_cdns = [];
    foreach ($local_vendor_files as $name => $rel_path) {
      if (!file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $rel_path)) {
        continue;
      }
      $default_cdns[$name] = [
        'url'     => MOTIONKIT_CONNECTOR_PLUGIN_URL . $rel_path,
        'deps'    => $name === 'gsap' ? [] : ['gsap'],
        'version' => '3.15',
      ];
    }
    if (isset($default_cdns['ScrollSmoother'])) {
      $default_cdns['ScrollSmoother']['deps'] = ['gsap', 'ScrollTrigger'];
    }

    if (empty($cdns)) {
      $cdns = $default_cdns;
    } else {
      $cdns = array_merge($default_cdns, $cdns);
    }

    $always = [
      'gsap'           => true,
      'ScrollTrigger'  => true,
      'ScrollSmoother' => true,
      'scrollSmoother' => true,
    ];

    foreach ($cdns as $handle => $entry) {
      $handle = (string) $handle;
      $is_optional = !isset($always[$handle]);

      if (is_string($entry)) {
        $entry = ['url' => $entry];
      } else {
        $entry = (array) $entry;
      }

      // If user's stored CDN is an external jsdelivr default or empty, override with local file
      if (isset($local_vendor_files[$handle])) {
        $local_rel = $local_vendor_files[$handle];
        if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $local_rel)) {
          $current_url = $entry['url'] ?? '';
          if (empty($current_url) || strpos($current_url, 'cdn.jsdelivr.net/npm/gsap') !== false) {
            $entry['url'] = MOTIONKIT_CONNECTOR_PLUGIN_URL . $local_rel;
          }
        }
      }

      // A library the user switched OFF is not registered at all, and that is what makes the switch mean something: every enqueue path downstream asks only `wp_script_is($handle, 'registered')`, so this is the single point where the answer has to be no.
      // The old test also required the handle to be absent from $default_cdns, which is built from every vendor file present on disk — so the condition was never true for a bundled library and the switch changed nothing: a disabled plugin still registered, still enqueued, and still ran for visitors.
      // The editor preview is exempt: the switch decides what VISITORS download, not what the author can build with. Refusing it there took the preset bundles down with it — animation-builder-assets.php names SplitText as a hard dependency of the text presets, and WordPress silently drops a script whose dependency is unregistered — so switching a library off left those presets unplayable in the editor even with GSAP's own SplitText loaded from the CDN beside them.
      if (
        $is_optional
        && !$this->is_editor_preview()
        && self::gsap_toggle_state($gsap_plugin, $handle) === false
      ) {
        $this->unavailable_gsap_handles[$handle] = true;
        continue;
      }

      $url = isset($entry['url']) && is_string($entry['url']) ? trim($entry['url']) : '';

      if ($url === '' || wp_http_validate_url($url) === false) {
        if ($is_optional) {
          $this->unavailable_gsap_handles[$handle] = true;
        }
        continue;
      }

      // Only allow scripts served from this plugin's own URL. A stored/filtered
      // CDN entry pointing off-site would reintroduce remote executable code
      // (wordpress.org blocker + supply-chain risk).
      if (strpos($url, MOTIONKIT_CONNECTOR_PLUGIN_URL) !== 0) {
        if ($is_optional) {
          $this->unavailable_gsap_handles[$handle] = true;
        }
        continue;
      }

      if ($handle === 'gsap') {
        $handle_deps = [];
      } else {
        $handle_deps = isset($entry['deps']) && is_array($entry['deps'])
          ? array_values(array_filter(array_map(
            static function ($dep) {
              $dep = is_string($dep) ? preg_replace('/[^A-Za-z0-9_-]/', '', $dep) : '';
              return $dep !== '' ? $dep : null;
            },
            $entry['deps']
          )))
          : ['gsap'];
      }

      $version = isset($entry['version']) && is_string($entry['version']) && $entry['version'] !== ''
        ? sanitize_text_field($entry['version'])
        : null;

      if ($handle === 'gsap') {
        $is_pt_active = $this->page_transition_config() !== null;
        $in_footer    = !$is_pt_active; // If page transition is active -> load in <head> (false); otherwise -> load in footer (true)

        wp_register_script($handle, $url, $handle_deps, $version, $in_footer);
        $this->registered_gsap_handles[$handle] = true;

        if ($is_pt_active) {
          wp_enqueue_script($handle);
        }
        continue;
      }

      wp_register_script($handle, $url, $handle_deps, $version, true);
      $this->registered_gsap_handles[$handle] = true;
    }

    $core_deps = [];
    if (isset($this->registered_gsap_handles['gsap'])) {
      $core_deps[] = 'gsap';
    }
    foreach (['ScrollSmoother', 'scrollSmoother'] as $smoother_handle) {
      if (isset($this->registered_gsap_handles[$smoother_handle])) {
        $core_deps[] = $smoother_handle;
        break;
      }
    }

    return array_values(array_unique(array_merge($deps, $core_deps)));
  }

  /**
   * Conditionally enqueue frontend animation scripts
   *
   * @return void
   */
  public function enqueue_frontend_scripts(): void
  {
    if ($this->is_editor_preview()) {
      $this->enqueue_editor_preview_scripts();
      return;
    }

    // Page identity is resolved once and threaded through — the animation read, the settings read and the share lookup all key off it.
    $page_type_config = $this->page_type->getCurrentPageType();

    // A valid share token swaps the shared animation's published version for its working copy; everything else on the page stays exactly what a visitor sees. An absent or dead token falls through to published, which is why a revoked link quietly shows the live page.
    $share = $this->share_context($page_type_config);
    $page_configs = $share === null
      ? $this->resolver->published($page_type_config)
      : $this->resolver->for_share($page_type_config, $share);

    $is_custom = false;
    $global_settings = $this->get_global_settings();

    // Free animations belong to the wp.org plugin's WAAPI runtime, which this plugin never carries. Detection runs on the pro half only: a free record's `timeline` would otherwise enqueue the custom engine, and its `splitText` key GSAP's SplitText, for an animation neither can play.
    $pro_configs = array_values(array_filter($page_configs, function ($config) {
      return !self::is_free_animation($config);
    }));

    // Preset detection reads the same list that will be dispatched, so a shared draft's bundle is enqueued while an ordinary visit never loads it.
    $active_presets = $this->get_active_presets($pro_configs, $is_custom);

    do_action('motionkit/frontend/before_enqueue_scripts', $page_configs, $global_settings);

    $deps = apply_filters('motionkit_core_lib_deps', []);

    $active_plugins = $this->get_active_plugins($pro_configs, $is_custom);
    if (!empty($active_plugins)) {
      $deps = array_merge($deps, $active_plugins);
    }

    $enqueued_handles = [];

    if ($is_custom || $this->is_full_preview()) {
      $custom_anim_file = 'assets/build/engine/customAnimation.js';
      if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $custom_anim_file)) {
        $custom_handle = 'motionkit-custom-animation';
        wp_enqueue_script(
          $custom_handle,
          MOTIONKIT_CONNECTOR_PLUGIN_URL . $custom_anim_file,
          $deps,
          MOTIONKIT_CONNECTOR_VERSION,
          true
        );
        $enqueued_handles[] = $custom_handle;
      }
    }

    $this->enqueue_preset_bundles($active_presets, $is_custom, $deps, $enqueued_handles);

    $frontend_deps = array_values(array_unique(array_merge($deps, $enqueued_handles)));

    // With the wp.org plugin active the free half is its to play, and dispatching it here too would have the router warn about an engine that is in fact on the page. Without it the free records still go through, so the router's once-per-page warning names why they are silent.
    $dispatch = defined('MOTIONKIT_LOADED') ? $pro_configs : $page_configs;
    $this->enqueue_core_frontend($dispatch, $is_custom, $frontend_deps);
  }

  /**
   * What the stored toggles say about one optional GSAP library.
   *
   * Null means the toggles do not mention it, which is NOT the same as off: a
   * site saved before that toggle existed carries no key for it, and several
   * bundled libraries have no switch in the panel at all. Both must keep
   * loading, so only a library the user can actually see switched off is ever
   * refused.
   *
   * @param array  $gsap_plugin Stored gsapPlugin subtree.
   * @param string $handle      Handle being registered.
   * @return bool|null True on, false off, null not mentioned.
   */
  private static function gsap_toggle_state(array $gsap_plugin, string $handle): ?bool
  {
    $spellings = [$handle];

    if (isset(self::GSAP_TOGGLE_ALIASES[$handle])) {
      $spellings[] = self::GSAP_TOGGLE_ALIASES[$handle];
    }

    // The same map read backwards, so the camelCase alias resolves to its PascalCase twin as well.
    $canonical = array_search($handle, self::GSAP_TOGGLE_ALIASES, true);
    if ($canonical !== false) {
      $spellings[] = $canonical;
    }

    $mentioned = false;
    foreach ($spellings as $key) {
      if (!array_key_exists($key, $gsap_plugin)) {
        continue;
      }
      $mentioned = true;
      // Any spelling saying yes is a yes, so a site holding both keys with one stale value keeps the library its animations expect.
      if (filter_var($gsap_plugin[$key], FILTER_VALIDATE_BOOLEAN)) {
        return true;
      }
    }

    return $mentioned ? false : null;
  }

  /**
   * Whether a record runs on the free WAAPI runtime rather than this engine.
   *
   * Mirrors MotionKit\Common\AnimationEngine::is_waapi() in the wp.org plugin
   * and isWaapiAnimation() in the engine source; the three must agree on what
   * a free record looks like.
   *
   * @param mixed $config Stored animation record.
   * @return bool
   */
  private static function is_free_animation($config): bool
  {
    if (!is_array($config)) {
      return false;
    }

    if (isset($config['engine']) && $config['engine'] === 'waapi') {
      return true;
    }

    if (isset($config['runtime']['engine']) && $config['runtime']['engine'] === 'waapi') {
      return true;
    }

    if (isset($config['group']) && $config['group'] === 'free_animation') {
      return true;
    }

    return isset($config['presetKey']) && is_string($config['presetKey'])
      && strpos($config['presetKey'], 'motionkit-free-') === 0;
  }

  /**
   * Enqueue editor preview scripts
   *
   * @return void
   */
  private function enqueue_editor_preview_scripts(): void
  {
    $deps = apply_filters('motionkit_core_lib_deps', []);
    $all_plugins = [
      'ScrollToPlugin',
      'DrawSVGPlugin',
      'MorphSVGPlugin',
      'MotionPathPlugin',
      'SplitText',
      'Flip',
    ];

    foreach ($all_plugins as $plugin) {
      if (wp_script_is($plugin, 'registered')) {
        $deps[] = $plugin;
      }
    }

    // customAnimation.js, the preset bundles, frontend.js and editor-reset.js all come from the editor, which injects its own copies into the proxied snapshot — enqueueing the plugin's copies too would load the engine twice, and two `aae-animation-event` listeners build every step twice. `to`/`fromTo` survive that, but `from` reads its end value off the live DOM, so the second copy samples the value the first already wrote and the element parks partway through.
    // motionkit-frontend is still registered below, without a file: it carries the motionkitData localization that both the injected runtime and editor-bridge read, and editor-bridge depends on the handle for ordering.
    $this->enqueue_core_frontend([], false, $deps, true);

    // Enqueue editor-bridge
    if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'assets/build/modules/editor-bridge.js')) {
      wp_enqueue_script(
        'motionkit-editor-bridge',
        MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/build/modules/editor-bridge.js',
        ['motionkit-frontend'],
        MOTIONKIT_CONNECTOR_VERSION,
        true
      );
    }
  }

  /**
   * Enqueue per-preset bundle files
   *
   * @param array $active_presets
   * @param bool  $is_custom
   * @param array $deps
   * @param array $enqueued_handles
   * @return void
   */
  private function enqueue_preset_bundles(array $active_presets, bool $is_custom, array $deps, array &$enqueued_handles = []): void
  {
    $dir = 'assets/build/presets/mostPopular/';
    $assets_file = MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/Common/configs/animation-builder-assets.php';
    $asset_map = file_exists($assets_file) ? require $assets_file : [];
    $premium_presets = $asset_map['premiumPresets'] ?? [];

    if ($this->is_editor_preview() || $this->is_full_preview()) {
      $files = glob(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $dir . '*.js');
      if ($files) {
        foreach ($files as $file) {
          $filename = basename($file, '.js');
          $handle = 'motionkit-preset-' . sanitize_key($filename);
          wp_enqueue_script(
            $handle,
            MOTIONKIT_CONNECTOR_PLUGIN_URL . $dir . $filename . '.js',
            $deps,
            MOTIONKIT_CONNECTOR_VERSION,
            true
          );
          $enqueued_handles[] = $handle;
        }
      }
      return;
    }

    foreach ($active_presets as $key => $meta) {
      if (is_array($meta) && !empty($meta['src'])) {
        $src = $meta['src'];
        $preset_deps = array_values(array_unique(array_merge($deps, $meta['deps'] ?? [])));
        foreach ($preset_deps as $p_dep) {
          if (wp_script_is($p_dep, 'registered') && !wp_script_is($p_dep, 'enqueued')) {
            wp_enqueue_script($p_dep);
          }
        }
        $handle = 'motionkit-preset-' . sanitize_key((string) $key);
        wp_enqueue_script($handle, $src, $preset_deps, $meta['version'] ?? MOTIONKIT_CONNECTOR_VERSION, true);
        $enqueued_handles[] = $handle;
      } elseif (isset($premium_presets[$key])) {
        $p_info = $premium_presets[$key];
        $src = $p_info['src'];
        $preset_deps = array_values(array_unique(array_merge($deps, $p_info['deps'] ?? [])));
        foreach ($preset_deps as $p_dep) {
          if (wp_script_is($p_dep, 'registered') && !wp_script_is($p_dep, 'enqueued')) {
            wp_enqueue_script($p_dep);
          }
        }
        $handle = 'motionkit-preset-' . sanitize_key((string) $key);
        wp_enqueue_script($handle, $src, $preset_deps, $p_info['version'] ?? MOTIONKIT_CONNECTOR_VERSION, true);
        $enqueued_handles[] = $handle;
      } else {
        $clean_name = sanitize_key((string) $key);
        $bundle_file = $dir . $clean_name . '.js';
        if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $bundle_file)) {
          $handle = 'motionkit-preset-' . $clean_name;
          wp_enqueue_script($handle, MOTIONKIT_CONNECTOR_PLUGIN_URL . $bundle_file, $deps, MOTIONKIT_CONNECTOR_VERSION, true);
          $enqueued_handles[] = $handle;
        }
      }
    }
  }

  /**
   * Enqueue core frontend runtime and localize data
   *
   * @param array $page_configs
   * @param bool  $is_custom
   * @param array $deps
   * @param bool  $data_only Register the handle without a file, as a carrier for the motionkitData localization only.
   * @return void
   */
  private function enqueue_core_frontend(array $page_configs, bool $is_custom, array $deps, bool $data_only = false): void
  {
    $handle = 'motionkit-frontend';

    if ($data_only) {
      // wp_enqueue_script() skips wp_scripts()->add() when $src is falsy, leaving the handle unregistered so nothing — not even the localized data — prints. Registering explicitly is what makes this a data-only handle. $deps is kept so the registered GSAP plugin scripts still load.
      wp_register_script($handle, false, $deps, MOTIONKIT_CONNECTOR_VERSION, true);
      wp_enqueue_script($handle);
    } else {
      // Two scripts, deliberately separate. `engine/animationEngine.js` is the vendored engine — the router for both animation kinds plus its public API and is built in the editor repo; `lib/engine-wrapper.js` is this plugin's own source and is the only half that knows what motionkitData means. The wrapper depends on the handle carrying the localization, and the runtime is a dependency of that, so the load order is expressed once here rather than assumed.
      $runtime_handle = 'motionkit-animation-engine';
      $runtime_file = 'assets/build/engine/animationEngine.js';
      if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $runtime_file)) {
        wp_enqueue_script(
          $runtime_handle,
          MOTIONKIT_CONNECTOR_PLUGIN_URL . $runtime_file,
          $deps,
          MOTIONKIT_CONNECTOR_VERSION,
          true
        );
      }

      wp_register_script($handle, false, array_merge($deps, [$runtime_handle]), MOTIONKIT_CONNECTOR_VERSION, true);
      wp_enqueue_script($handle);

      $wrapper_file = 'assets/build/lib/engine-wrapper.js';
      if (file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . $wrapper_file)) {
        wp_enqueue_script(
          'motionkit-engine-wrapper',
          MOTIONKIT_CONNECTOR_PLUGIN_URL . $wrapper_file,
          [$handle],
          MOTIONKIT_CONNECTOR_VERSION,
          true
        );
      }
    }

    $page_type_config = $this->page_type->getCurrentPageType();

    $page_settings = $this->page_type->getConfig(
      $this->settings_config($page_type_config)
    );

    // The editor works with one list per bucket, working copies stitched back onto their published records — the same shape it sent on save. A visitor only ever gets the published half.
    if ($this->is_editor_preview()) {
      $editor_buckets = $this->resolver->for_editor($page_type_config);
      $global_animation = $editor_buckets['global'];
      $page_animation = $editor_buckets['page'];
    } else {
      $global_animation = $this->resolver->split(
        $this->resolver->global_bucket(AnimationResolver::GLOBAL_PUBLISHED_OPTION)
      )['published'];
      $page_animation = $this->resolver->split(
        $this->resolver->page_bucket($page_type_config)
      )['published'];
    }

    $device_config = file_exists(MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/Common/configs/animation-builder-device.php')
      ? require MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/Common/configs/animation-builder-device.php'
      : [];

    $folders = get_option('motionkit_animation_folders', []);
    if (!is_array($folders)) {
      $folders = [];
    }

    $fav = get_option('motionkit_favourite_cloud_animation', []);
    if (!is_array($fav)) {
      $fav = [];
    }

    $token = isset($_GET['motionkit_token']) ? sanitize_text_field(wp_unslash($_GET['motionkit_token'])) : '';

    $runtime_data = [
      'platform'                  => 'wordpress',
      'plugins'                   => $this->plugins_status(),
      'ajax_url'                  => admin_url('admin-ajax.php'),
      'nonce'                     => wp_create_nonce('motionkit_frontend_nonce'),
      'rest_url'                  => rest_url('motionkit/v1/'),
      'site_url'                  => home_url('/'),
      'base_domain'               => home_url('/'),
      'motionkit_token'           => $token,
      'pageTypeConfigs'           => $page_type_config,
      'currentPageSettings'       => is_array($page_settings) ? $page_settings : [],
      'globalSettings'            => $this->get_global_settings(),
      'global_settings'           => $this->get_global_settings(),
      'globalAnimation'           => $global_animation,
      'global_animation'          => $global_animation,
      'pageConfigs'               => $page_configs,
      'page_animation'            => $page_animation,
      'all_animations'            => $page_configs,
      'all_settings'              => [
        'deviceConfig'        => $device_config,
        'globalSettings'      => $this->get_global_settings(),
        'currentPageSettings' => is_array($page_settings) ? $page_settings : [],
      ],
      'favourite_cloud_animation' => $fav,
      'favouriteCloudAnimation'   => $fav,
      'animation_folders'         => $folders,
      'animationFolders'          => $folders,
      'device_config'             => $device_config,
      'deviceConfig'              => $device_config,
      'isEditor'                  => $this->is_editor_preview(),
      'isFullPreview'             => $this->is_full_preview(),
    ];

    wp_localize_script($handle, 'motionkitData', $runtime_data);
  }

  /**
   * Both MotionKit plugins' version and status, reported to the editor.
   *
   * @return array<string, array<string, string>>
   */
  private function plugins_status(): array
  {
    if (class_exists('\MotionKit\Common\PluginStatus')) {
      return \MotionKit\Common\PluginStatus::snapshot();
    }

    return [
      'motionkit'          => ['version' => '', 'status' => 'inactive'],
      'motionkitConnector' => [
        'version' => MOTIONKIT_CONNECTOR_VERSION,
        'status'  => 'active',
      ],
    ];
  }

  /**
   * Determine active presets in page configurations
   *
   * @param array $configs
   * @param bool  $is_custom
   * @return array
   */
  private function get_active_presets(array $configs, bool &$is_custom): array
  {
    $presets = [];
    $assets_file = MOTIONKIT_CONNECTOR_PLUGIN_DIR . 'includes/Common/configs/animation-builder-assets.php';
    $asset_map = file_exists($assets_file) ? require $assets_file : [];
    $premium_presets = $asset_map['premiumPresets'] ?? [];

    foreach ($configs as $config) {
      if (!is_array($config)) {
        continue;
      }

      $type = $config['animationType'] ?? ($config['type'] ?? '');
      $group = $config['group'] ?? '';
      $preset_key = $config['presetKey'] ?? '';

      if ($type === 'custom' || $group === 'custom_animation' || $group === 'cloud_trigger' || isset($config['timeline'])) {
        $is_custom = true;
      }

      if (!empty($preset_key) && isset($premium_presets[$preset_key])) {
        $presets[$preset_key] = $premium_presets[$preset_key];
      } elseif (!empty($preset_key)) {
        $presets[$preset_key] = true;
      } elseif (!empty($type) && is_string($type)) {
        $presets[$type] = true;
      }
    }

    return $presets;
  }

  /**
   * Determine required GSAP plugin handles for the active animation configs
   *
   * @param array $configs
   * @param bool  $is_custom
   * @return array
   */
  private function get_active_plugins(array $configs, bool $is_custom): array
  {
    $plugins = [];

    if ($is_custom) {
      if (wp_script_is('ScrollTrigger', 'registered')) {
        $plugins[] = 'ScrollTrigger';
      }
    }

    $json_string = wp_json_encode($configs);

    $plugin_keywords = [
      'splitText'    => 'SplitText',
      'SplitText'    => 'SplitText',
      'drawSVG'      => 'DrawSVGPlugin',
      'DrawSVG'      => 'DrawSVGPlugin',
      'morphSVG'     => 'MorphSVGPlugin',
      'MorphSVG'     => 'MorphSVGPlugin',
      'motionPath'   => 'MotionPathPlugin',
      'MotionPath'   => 'MotionPathPlugin',
      'scrollTo'     => 'ScrollToPlugin',
      'ScrollTo'     => 'ScrollToPlugin',
      'scrambleText' => 'ScrambleTextPlugin',
      'physics2D'    => 'Physics2DPlugin',
      'flip'         => 'Flip',
      'Flip'         => 'Flip',
    ];

    foreach ($plugin_keywords as $keyword => $handle) {
      if (stripos($json_string, '"' . $keyword . '"') !== false || stripos($json_string, $keyword) !== false) {
        if (wp_script_is($handle, 'registered')) {
          $plugins[] = $handle;
        }
      }
    }

    foreach ($configs as $config) {
      if (!is_array($config)) {
        continue;
      }

      $needed = $config['requiredPlugins'] ?? [];
      if (is_array($needed)) {
        foreach ($needed as $plugin) {
          $handle = self::GSAP_SCHEMA_TO_HANDLE[$plugin] ?? $plugin;
          if (wp_script_is($handle, 'registered')) {
            $plugins[] = $handle;
          }
        }
      }
    }

    return array_values(array_unique($plugins));
  }

  /**
   * Check whether current request is inside the editor preview iframe
   *
   * @return bool
   */
  private function is_editor_preview(): bool
  {
    // `motionkit=editor` is the editor's flag, the one its other connectors always used. `action=motionkit-editor` is what it sent WordPress before the rename and what an older editor build still sends; it stays accepted, though `action` is a name other front-end plugins read and act on, which is why it was retired.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['motionkit']) && sanitize_text_field(wp_unslash($_GET['motionkit'])) === 'editor') {
      return true;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return isset($_GET['action']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'motionkit-editor';
  }

  /**
   * Check whether current request is a full preview
   *
   * The editor sends `motionkit_full_preview=1` — see FullPreviewButton.jsx, and
   * ScrollSmoother::run_scroll_smoother() has always read that spelling. This
   * method read `motionkit_preview=full`, which nothing sends, so it answered
   * false on every real Full Preview: the request took the ordinary visitor
   * path and enqueued the engine and preset bundles while the editor injected
   * its own copies into the same page. Two engines, and `from()` tweens sample
   * the DOM the first copy already wrote, parking the element partway through.
   * The old spelling is still accepted so any saved or external link keeps
   * working.
   *
   * @return bool
   */
  private function is_full_preview(): bool
  {
    if (isset($_GET['motionkit_full_preview']) && sanitize_text_field(wp_unslash($_GET['motionkit_full_preview'])) === '1') {
      return true;
    }

    return isset($_GET['motionkit_preview']) && sanitize_text_field(wp_unslash($_GET['motionkit_preview'])) === 'full';
  }

  // ─── Share Link Preview ──────────────────────────────────────────

  /**
   * Whether this request carries a share token, valid or not.
   *
   * Deliberately shape-blind — cache decisions are made from this, and a bad
   * token skipping the cache costs one uncached response while a good token
   * landing in the cache would leak a draft to everyone.
   *
   * @return bool
   */
  private function is_share_preview(): bool
  {
    return isset($_GET[ShareLinks::QUERY_PARAM])
      && is_string($_GET[ShareLinks::QUERY_PARAM])
      && $_GET[ShareLinks::QUERY_PARAM] !== '';
  }

  /**
   * Keep share responses out of every cache layer we can reach.
   *
   * @return void
   */
  private function block_page_cache_for_share(): void
  {
    if (!$this->is_share_preview()) {
      return;
    }

    // The constant is what LiteSpeed, WP Rocket, W3TC and friends look for; it has to be set before they decide to buffer the response.
    if (!defined('DONOTCACHEPAGE')) {
      define('DONOTCACHEPAGE', true);
    }

    add_action('send_headers', [$this, 'send_share_nocache_headers']);
  }

  /**
   * No-store headers for a share response.
   *
   * @return void
   */
  public function send_share_nocache_headers(): void
  {
    if (!$this->is_share_preview() || headers_sent()) {
      return;
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }

  /**
   * Resolve the share token against this page's rows, memoized per request.
   *
   * @param array $page_type_config Descriptor from getCurrentPageType().
   * @return ShareContext|null
   */
  private function share_context(array $page_type_config): ?ShareContext
  {
    if ($this->share_context_cache !== false) {
      return $this->share_context_cache;
    }

    if (!$this->is_share_preview()) {
      return $this->share_context_cache = null;
    }

    $token = sanitize_text_field(wp_unslash($_GET[ShareLinks::QUERY_PARAM]));

    return $this->share_context_cache = $this->share_links->resolve($page_type_config, $token);
  }

  /**
   * Set headers to allow editor iframe embedding
   *
   * @return void
   */
  public function allow_editor_iframe(): void
  {
    // Only relax framing on the editor-preview request itself. Doing it on
    // every front-end request stripped X-Frame-Options site-wide, and the
    // old wildcard origin made frame-ancestors meaningless (clickjacking on
    // every page). editor_allowed_origins() no longer emits '*'.
    if (!$this->is_editor_preview()) {
      return;
    }

    $allowed_origins = $this->editor_allowed_origins();
    if (empty($allowed_origins)) {
      return;
    }

    header_remove('X-Frame-Options');
    header('Content-Security-Policy: frame-ancestors ' . implode(' ', $allowed_origins));
  }

  /**
   * Send no-store cache headers on editor-preview requests so page caches
   * can't feed a stale localized snapshot back into the editor iframe.
   *
   * @return void
   */
  public function no_store_for_editor_preview(): void
  {
    if (!$this->is_editor_preview() && !$this->is_full_preview()) {
      return;
    }

    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    if (!defined('DONOTCACHEPAGE')) {
      define('DONOTCACHEPAGE', true);
    }
  }

  // What each optimizer looks for to leave a script alone: WP Rocket (nowprocket), LiteSpeed and FlyingPress (data-no-optimize/-defer/-minify), Autoptimize (data-noptimize), Cloudflare Rocket Loader (data-cfasync), Jetpack Boost. One list, so the external tag and the inline data tag cannot drift apart.
  private const OPTIMIZER_EXEMPT_ATTRIBUTES = [
    'nowprocket'         => true,
    'data-no-optimize'   => '1',
    'data-no-defer'      => '1',
    'data-no-minify'     => '1',
    'data-noptimize'     => '1',
    'data-cfasync'       => 'false',
    'data-jetpack-boost' => 'ignore',
  ];

  /**
   * Request-level switches the optimizer plugins honour on an editor or full preview.
   *
   * Each is idempotent and a no-op when that plugin is absent. A delay-until-interaction optimizer inside the preview iframe waits for a click that never comes, so the bridge never announces itself and the editor reads the silence as "plugin not detected"; a visitor's page keeps whatever the site owner configured.
   *
   * @return void
   */
  public function disable_optimizers_for_preview(): void
  {
    if (!$this->is_editor_preview() && !$this->is_full_preview()) {
      return;
    }

    // W3 Total Cache, WP Rocket (all file optimisation, delay JS included), LiteSpeed Cache.
    foreach (['DONOTMINIFY', 'DONOTROCKETOPTIMIZE', 'LITESPEED_DISABLE_ALL'] as $constant) {
      if (!defined($constant)) {
        define($constant, true);
      }
    }

    // LiteSpeed's own API for the same switch, fired once its listener exists; Autoptimize and Perfmatters only offer filters.
    add_action('wp', static function (): void {
      do_action('litespeed_disable_all', 'MotionKit editor preview');
    });
    add_filter('autoptimize_filter_noptimize', '__return_true');
    add_filter('perfmatters_delay_js', '__return_false');
    add_filter('perfmatters_defer_js', '__return_false');
  }

  /**
   * Mark this plugin's own script tags so no optimizer defers, delays, minifies or rewrites them on a preview.
   *
   * @param string $tag    The <script> tag WordPress is about to print.
   * @param string $handle The script handle.
   * @return string
   */
  public function exempt_script_from_optimizers(string $tag, string $handle): string
  {
    if (!$this->is_own_script($handle) || (!$this->is_editor_preview() && !$this->is_full_preview())) {
      return $tag;
    }

    // Already marked — the wp.org plugin does the same on a site running both.
    if (strpos($tag, 'data-cfasync=') !== false) {
      return $tag;
    }

    return (string) preg_replace('/<script\b/', '<script ' . self::optimizer_exempt_attribute_string(), $tag, 1);
  }

  /**
   * The same exemption for the inline tags WordPress prints beside those handles — the localized motionkitData above all, which the bridge cannot run without.
   *
   * @param array $attributes Attributes of the inline <script> about to be printed.
   * @return array
   */
  public function exempt_inline_script_from_optimizers(array $attributes): array
  {
    $id = isset($attributes['id']) && is_string($attributes['id']) ? $attributes['id'] : '';
    // The id is the handle plus WordPress's own suffix: -js-extra, -js-before, -js-after.
    $handle = (string) preg_replace('/-js(-extra|-before|-after|-translations)?$/', '', $id);
    if ($handle === '' || !$this->is_own_script($handle) || (!$this->is_editor_preview() && !$this->is_full_preview())) {
      return $attributes;
    }

    return array_merge($attributes, self::OPTIMIZER_EXEMPT_ATTRIBUTES);
  }

  private function is_own_script(string $handle): bool
  {
    return $handle === 'gsap'
      || isset($this->registered_gsap_handles[$handle])
      || strpos($handle, 'motionkit') === 0;
  }

  private static function optimizer_exempt_attribute_string(): string
  {
    $parts = [];
    foreach (self::OPTIMIZER_EXEMPT_ATTRIBUTES as $name => $value) {
      $parts[] = $value === true ? $name : $name . '="' . esc_attr((string) $value) . '"';
    }
    return implode(' ', $parts);
  }

  // ─── AJAX Handlers ───────────────────────────────────────────────

  public function ajax_configs_store(): void
  {
    check_ajax_referer('motionkit_frontend_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['msg' => 'unauthorized'], 403);
    }

    $raw = isset($_POST['configs']) ? sanitize_text_field(wp_unslash($_POST['configs'])) : '';
    $configs = json_decode($raw, true);

    if (!is_array($configs)) {
      wp_send_json_error(['msg' => 'invalid_data'], 400);
    }

    $this->page_type->saveConfig(
      $this->page_type->getCurrentPageType(),
      $configs
    );

    wp_send_json_success(['msg' => 'configs_saved']);
  }

  public function ajax_configs_delete(): void
  {
    check_ajax_referer('motionkit_frontend_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['msg' => 'unauthorized'], 403);
    }

    $this->page_type->deleteConfig();

    wp_send_json_success(['msg' => 'configs_deleted']);
  }

  public function ajax_global_configs_store(): void
  {
    check_ajax_referer('motionkit_frontend_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['msg' => 'unauthorized'], 403);
    }

    $raw = isset($_POST['configs']) ? sanitize_text_field(wp_unslash($_POST['configs'])) : '';
    $configs = json_decode($raw, true);

    if (!is_array($configs)) {
      wp_send_json_error(['msg' => 'invalid_data'], 400);
    }

    update_option('motionkit_global_animations', $configs);

    wp_send_json_success(['msg' => 'global_configs_saved']);
  }

  public function ajax_global_configs_delete(): void
  {
    check_ajax_referer('motionkit_frontend_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['msg' => 'unauthorized'], 403);
    }

    delete_option('motionkit_global_animations');

    wp_send_json_success(['msg' => 'global_configs_deleted']);
  }
}
