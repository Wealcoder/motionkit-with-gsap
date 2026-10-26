<?php

namespace MotionKitConnector\Admin;

use MotionKitConnector\Common\AnimationResolver;

/**
 * MotionKit with GSAP — the MotionKit mark beside animated posts.
 *
 * In the Pages, Posts and custom post type lists, a post that carries
 * MotionKit animations shows the logo mark after the title, where WordPress
 * puts post states ("— Front Page, Elementor"), with the animation count in
 * its tooltip. With the MotionKit dashboard plugin active the mark links to
 * its Animations tab narrowed to that post; without it the mark is a label.
 * Each user can switch the mark off from the list screen's Screen Options.
 *
 * @package MotionKitConnector
 * @since 1.7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

final class PostListBadge
{
  private const PUBLISHED_PREFIX = 'motionkit_pg_animation_';
  private const DRAFT_PREFIX = 'motionkit_pg_draft_';
  // Per-user, from the list screen's Screen Options panel; '1' hides the mark on every post list.
  private const HIDDEN_OPTION = 'motionkit_post_badge_hidden';
  private const AJAX_ACTION = 'motionkit_post_badge_visibility';

  /**
   * Animation counts for the posts on the current list screen, read once for the whole screen.
   *
   * @var array<int, array{total: int, live: int}>|null
   */
  private $counts = null;

  public function init(): void
  {
    add_filter('display_post_states', [$this, 'add_post_state'], 20, 2);
    add_filter('screen_settings', [$this, 'screen_settings'], 10, 2);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_set_visibility']);
  }

  public function enqueue_assets(string $hook): void
  {
    if ($hook !== 'edit.php') {
      return;
    }

    // The file's modification time rides on the version, so a rebuilt asset is a new URL and no browser keeps the old one between releases.
    $version = static function (string $relative): string {
      $path = MOTIONKIT_CONNECTOR_PLUGIN_DIR . $relative;
      return MOTIONKIT_CONNECTOR_VERSION . (file_exists($path) ? '.' . (int) filemtime($path) : '');
    };

    wp_enqueue_style('motionkit-post-badge', MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/build/admin/post-badge.css', [], $version('assets/build/admin/post-badge.css'));
    wp_enqueue_script('motionkit-post-badge', MOTIONKIT_CONNECTOR_PLUGIN_URL . 'assets/build/admin/post-badge.js', [], $version('assets/build/admin/post-badge.js'), true);
    wp_localize_script('motionkit-post-badge', 'motionkitPostBadge', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::AJAX_ACTION),
    ]);
  }

  /**
   * Whether this user has switched the mark off.
   */
  private function is_hidden(): bool
  {
    return (string) get_user_option(self::HIDDEN_OPTION) === '1';
  }

  /**
   * The Screen Options entry: one checkbox, saved per user the moment it
   * changes (like the Columns boxes), then the list reloads without the mark.
   *
   * @param string     $settings The panel's extra settings so far.
   * @param \WP_Screen $screen   The screen the panel is for.
   */
  public function screen_settings($settings, $screen): string
  {
    if (!($screen instanceof \WP_Screen) || $screen->base !== 'edit') {
      return (string) $settings;
    }

    return (string) $settings
      . '<fieldset class="motionkit-post-badge-option">'
      . '<legend>' . esc_html__('MotionKit', 'motionkit-with-gsap') . '</legend>'
      . '<label><input type="checkbox" id="motionkit-post-badge-toggle"' . checked(!$this->is_hidden(), true, false) . '> '
      . esc_html__('Show the MotionKit mark on pages that have animations', 'motionkit-with-gsap') . '</label>'
      . '</fieldset>';
  }

  /**
   * AJAX: remember the checkbox for this user.
   */
  public function ajax_set_visibility(): void
  {
    check_ajax_referer(self::AJAX_ACTION, 'nonce');
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    $hidden = isset($_POST['hidden']) && sanitize_key(wp_unslash($_POST['hidden'])) === '1';
    update_user_option(get_current_user_id(), self::HIDDEN_OPTION, $hidden ? '1' : '', true);

    wp_send_json_success(['hidden' => $hidden]);
  }

  /**
   * Add the mark to a post's states when it carries at least one animation.
   *
   * Only on list screens: the post states are printed unescaped there, which
   * the markup relies on, while other callers (the menu editor, for one) may
   * show states as plain text.
   *
   * @param string[] $states The post's states so far.
   * @param \WP_Post $post   The post the row is for.
   * @return string[]
   */
  public function add_post_state(array $states, $post): array
  {
    if (!($post instanceof \WP_Post)) {
      return $states;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'edit' || $this->is_hidden()) {
      return $states;
    }

    $counts = $this->counts_for($post->ID);
    if ($counts['total'] === 0) {
      return $states;
    }

    $states['motionkit'] = $this->badge_html($post, $counts);

    return $states;
  }

  /**
   * How many animations a post carries, and how many of them are live.
   *
   * The whole screen's posts are read in one query the first time any row
   * asks, since the list table calls the filter once per row.
   *
   * @return array{total: int, live: int}
   */
  private function counts_for(int $post_id): array
  {
    if ($this->counts === null || !isset($this->counts[$post_id])) {
      $this->load_counts($post_id);
    }

    return $this->counts[$post_id] ?? ['total' => 0, 'live' => 0];
  }

  private function load_counts(int $post_id): void
  {
    global $wpdb, $wp_query;

    $ids = $wp_query instanceof \WP_Query ? array_map('intval', wp_list_pluck($wp_query->posts, 'ID')) : [];
    $ids[] = $post_id;
    $ids = array_values(array_unique(array_filter($ids)));

    if ($this->counts === null) {
      $this->counts = [];
    }
    foreach ($ids as $id) {
      $this->counts[$id] = ['total' => 0, 'live' => 0];
    }

    // One %d per id, so every id still goes through prepare().
    $in = implode(',', array_fill(0, count($ids), '%d'));
    $published_like = $wpdb->esc_like(self::PUBLISHED_PREFIX) . '%';
    $draft_like = $wpdb->esc_like(self::DRAFT_PREFIX) . '%';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- A LIKE on meta keys across the screen's posts has no API; the interpolated part is the placeholder list built for the id count, and the replacement count matches it.
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT post_id, meta_key, meta_value
         FROM {$wpdb->postmeta}
         WHERE post_id IN ({$in}) AND (meta_key LIKE %s OR meta_key LIKE %s)",
        array_merge($ids, [$published_like, $draft_like])
      )
    );
    // phpcs:enable

    // Every id the post's buckets hold, once: a published animation's working copy sits in the draft bucket under the same id.
    $seen = [];
    foreach ($rows as $row) {
      $id = (int) $row->post_id;
      $is_draft_bucket = strpos((string) $row->meta_key, self::DRAFT_PREFIX) === 0;
      foreach ($this->entries($row->meta_value) as $index => $entry) {
        $entry_id = AnimationResolver::entry_id($entry) ?? ($row->meta_key . '-' . $index);
        if (isset($seen[$id][$entry_id])) {
          continue;
        }
        $seen[$id][$entry_id] = true;
        ++$this->counts[$id]['total'];
        if (!$is_draft_bucket && AnimationResolver::is_published($entry)) {
          ++$this->counts[$id]['live'];
        }
      }
    }
  }

  /**
   * The animation entries in a bucket as the database holds it: serialised by the meta API, or JSON from an older build, in a `published` envelope or flat.
   *
   * @param mixed $raw The meta value.
   * @return array<int, array>
   */
  private function entries($raw): array
  {
    if (is_string($raw)) {
      $raw = maybe_unserialize($raw);
    }
    if (is_string($raw)) {
      $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
      return [];
    }
    if (isset($raw['published']) && is_array($raw['published'])) {
      $raw = $raw['published'];
    }

    return array_values(array_filter($raw, 'is_array'));
  }

  /**
   * @param array{total: int, live: int} $counts
   */
  private function badge_html(\WP_Post $post, array $counts): string
  {
    $label = sprintf(
      /* translators: 1: number of animations on the page, 2: how many of them are live */
      _n('MotionKit: %1$d animation, %2$d live', 'MotionKit: %1$d animations, %2$d live', $counts['total'], 'motionkit-with-gsap'),
      $counts['total'],
      $counts['live']
    );

    $mark = '<span class="motionkit-post-badge-mark" aria-hidden="true">'
      . '<svg viewBox="0 0 245 245" fill="none" xmlns="http://www.w3.org/2000/svg">'
      . '<path d="M2.7 87.7C2.7 40.7 40.7 2.7 87.7 2.7h69c47 0 85 38 85 85v69c0 47-38 85-85 85h-69c-47 0-85-38-85-85v-69z" fill="url(#motionkit_pb_g)"/>'
      . '<path d="M66.4 71.9c0-10.5 13.2-15.8 20.9-8.4l51.9 50.3c4.8 4.6 4.8 12.1 0 16.7l-51.9 50.3c-7.7 7.5-20.9 2.2-20.9-8.3V71.9z" fill="#BFD8FE"/>'
      . '<path d="M157.5 63.5c7.5-7.5 20.2-2.2 20.2 8.4v100.6c0 10.6-12.8 15.8-20.2 8.4l-50.3-50.3c-4.6-4.6-4.6-12.1 0-16.8l50.3-50.3z" fill="#fff"/>'
      . '<defs><radialGradient id="motionkit_pb_g" cx="0" cy="0" r="1" gradientTransform="matrix(-62.2 127 -127 -62.2 122.2 122.2)" gradientUnits="userSpaceOnUse"><stop stop-color="#1C7E92"/><stop offset="1" stop-color="#599BFD"/></radialGradient></defs>'
      . '</svg></span>';

    // The dashboard's Animations tab belongs to the MotionKit plugin and is for administrators; otherwise the mark is a label. The count lives in the tooltip only, so the row stays a mark.
    if (!class_exists('\MotionKit\Admin\ConnectPage') || !current_user_can('manage_options')) {
      return '<span class="motionkit-post-badge" title="' . esc_attr($label) . '">' . $mark . '</span>';
    }

    $url = add_query_arg(
      [
        'tab'           => 'animations',
        '_wpnonce'      => wp_create_nonce('motionkit_tab_nav'),
        'mk_page'       => 'post-' . $post->ID,
        'mk_page_title' => get_the_title($post),
      ],
      admin_url('admin.php?page=motionkit-connect')
    );

    return '<a class="motionkit-post-badge" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">' . $mark . '</a>';
  }
}
