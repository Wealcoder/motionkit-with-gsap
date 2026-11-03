<?php

namespace MotionKitConnector\Admin;

/**
 * MotionKit Connector — Proprietary. Copyright (c) 2024-2026 Wealcoder / MotionKit.
 * All rights reserved. Licensed, not sold. Unauthorized copying, modification,
 * distribution, reverse engineering, or AI-assisted reproduction is prohibited.
 * See LICENSE.txt. Abuse: legal@motionkit.io
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

use MotionKitConnector\Auth\JwtTokenManager;
use MotionKitConnector\Common\EditorEndpoint;

/**
 * Animation Data Tools (data layer for the dashboard's Tools tab)
 *
 * Owns every read/delete operation on the animation data this plugin stores
 * (motionkit_pg_animation_* / motionkit_pg_settings_* rows plus the global
 * options). The Tools tab UI lives in the MotionKit dashboard plugin; it
 * talks to these handlers over admin-ajax (motionkit_tools_list,
 * motionkit_tools_bulk_delete) and the delete_one admin_init POST, and the
 * dashboard hides the tab entirely when this class is absent.
 *
 * @package MotionKitConnector
 * @since 1.0.0
 */
final class AnimationDataTools
{
  private const TOOLS_OPTION_PREFIX = 'motionkit_pg_animation_';
  private const TOOLS_SETTINGS_PREFIX = 'motionkit_pg_settings_';
  private const TOOLS_GLOBAL_SETTINGS_OPTION = 'motionkit_global_settings';
  private const TOOLS_GLOBAL_ANIMATIONS_OPTION = 'motionkit_global_animations';

  public function init(): void
  {
    add_action('admin_init', [$this, 'handle_tools_actions']);
    add_action('wp_ajax_motionkit_tools_list', [$this, 'ajax_tools_list']);
    add_action('wp_ajax_motionkit_tools_bulk_delete', [$this, 'ajax_tools_bulk_delete']);
  }

  public function handle_tools_actions(): void
  {
    if (!isset($_POST['motionkit_tools_action'])) {
      return;
    }
    if (!current_user_can('manage_options')) {
      return;
    }
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'motionkit_tools_nonce')) {
      wp_safe_redirect(wp_nonce_url(
        admin_url('admin.php?page=motionkit-connect&tab=tools&error=nonce_failed'),
        'motionkit_notice'
      ));
      exit;
    }

    $action = sanitize_text_field(wp_unslash($_POST['motionkit_tools_action']));

    if ($action === 'delete_one') {
      $store = sanitize_text_field(wp_unslash($_POST['store_type'] ?? ''));
      $option = sanitize_text_field(wp_unslash($_POST['option_key'] ?? ''));
      $id = isset($_POST['object_id']) ? (int) $_POST['object_id'] : 0;
      $this->delete_animation_record($store, $option, $id);
      wp_safe_redirect(wp_nonce_url(
        admin_url('admin.php?page=motionkit-connect&tab=tools&tools_deleted=1'),
        'motionkit_notice'
      ));
      exit;
    }
  }

  public function ajax_tools_list(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'unauthorized'], 403);
    }
    check_ajax_referer('motionkit_tools_ajax', 'nonce');

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $page = max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
    $per_page = 20;

    $all = $this->collect_animation_records($search);
    $total = count($all);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $offset = ($page - 1) * $per_page;
    $rows = array_slice($all, $offset, $per_page);

    // One session token for the whole response — tokens are site-scoped (the editor reuses one token across page switches), and generating per row would mean up to 20 serial wp_remote_post calls when connected.
    $token = JwtTokenManager::generate(home_url('/'));

    wp_send_json_success([
      'rows'        => array_map(function ($rec) use ($token) {
        return $this->decorate_record($rec, $token);
      }, $rows),
      'page'        => $page,
      'per_page'    => $per_page,
      'total'       => $total,
      'total_pages' => $total_pages,
    ]);
  }

  public function ajax_tools_bulk_delete(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'unauthorized'], 403);
    }
    check_ajax_referer('motionkit_tools_ajax', 'nonce');

    $first = !empty($_POST['first']);
    $batch_size = 25;

    // First batch: clear global keys + sweep any orphan settings rows that
    // have no matching animation record (so nothing is left behind).
    if ($first) {
      delete_option(self::TOOLS_GLOBAL_SETTINGS_OPTION);
      delete_option(self::TOOLS_GLOBAL_ANIMATIONS_OPTION);
      $this->delete_all_settings_records();
    }

    // Always consume from the FRONT of a fresh scan. Deletions shrink the collected list between batches, so an advancing client offset would skip one record for every record deleted — leaving roughly half the data behind on a "Delete All".
    $all = $this->collect_animation_records('');
    $total = count($all);
    $slice = array_slice($all, 0, $batch_size);

    $deleted = 0;
    foreach ($slice as $record) {
      if ($this->delete_animation_record($record['store_type'], $record['option'], $record['id'])) {
        $deleted++;
      }
    }

    $remaining = count($this->collect_animation_records(''));
    // Done when nothing remains — or when a full pass deleted nothing, which prevents an infinite client loop if some rows turn out to be undeletable.
    $done = $remaining === 0 || $deleted === 0;

    wp_send_json_success([
      'total'     => $total,
      'deleted'   => $deleted,
      'remaining' => $remaining,
      'done'      => $done,
    ]);
  }

  // Defense-in-depth: both callers (handle_tools_actions, ajax_tools_bulk_delete)
  // already gate on manage_options, but this re-checks so the destructive
  // delete itself never fires without it, even if a future caller forgets.
  private function delete_animation_record(string $store_type, string $option, int $id = 0): bool
  {
    if (!current_user_can('manage_options')) {
      return false;
    }

    $deleted = $this->delete_keyed_record($store_type, $option, $id);

    // Cascade: when we remove motionkit_pg_animation_<type>, also remove the
    // matching motionkit_pg_settings_<type> on the same store/id. Settings are
    // orphaned once the animation row is gone — no UI points at them.
    if (strpos($option, self::TOOLS_OPTION_PREFIX) === 0) {
      $settings_key = preg_replace(
        '/^' . preg_quote(self::TOOLS_OPTION_PREFIX, '/') . '/',
        self::TOOLS_SETTINGS_PREFIX,
        $option
      );
      if ($settings_key !== null && $settings_key !== $option) {
        $this->delete_keyed_record($store_type, $settings_key, $id);
      }
    }

    return $deleted;
  }

  private function delete_keyed_record(string $store_type, string $option, int $id = 0): bool
  {
    // Whitelist the key and store: delete_one's inputs come from POST, and without this gate the handler could delete ANY option (e.g. active_plugins) or meta key — nonce-protected, but a destructive primitive that broad is still a wp.org reviewer flag.
    $allowed_key = strpos($option, self::TOOLS_OPTION_PREFIX) === 0
      || strpos($option, self::TOOLS_SETTINGS_PREFIX) === 0
      || in_array($option, [self::TOOLS_GLOBAL_SETTINGS_OPTION, self::TOOLS_GLOBAL_ANIMATIONS_OPTION], true);

    if (!$allowed_key || !in_array($store_type, ['post_meta', 'term_meta', 'option'], true)) {
      return false;
    }

    switch ($store_type) {
      case 'post_meta':
        return (bool) delete_post_meta($id, $option);
      case 'term_meta':
        return (bool) delete_term_meta($id, $option);
      default:
        return (bool) delete_option($option);
    }
  }

  // Wipe every motionkit_pg_settings_* row across post_meta/term_meta/options.
  // Used by bulk delete to catch orphans whose animation row was already gone.
  // Defense-in-depth: re-checks the capability here too, so this destructive,
  // site-wide sweep stays safe even if a future caller forgets to gate it.
  private function delete_all_settings_records(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    global $wpdb;
    $like = $wpdb->esc_like(self::TOOLS_SETTINGS_PREFIX) . '%';

    $post_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT post_id AS id, meta_key AS `key` FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $like
      )
    );
    foreach ($post_rows as $r) {
      delete_post_meta((int) $r->id, $r->key);
    }

    $term_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT term_id AS id, meta_key AS `key` FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
        $like
      )
    );
    foreach ($term_rows as $r) {
      delete_term_meta((int) $r->id, $r->key);
    }

    $opt_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
      )
    );
    foreach ($opt_rows as $r) {
      delete_option($r->option_name);
    }
  }

  /**
   * Scan post_meta, term_meta, options for animation configs.
   *
   * @return array<int,array{store_type:string,option:string,id:int,title:string,type_label:string,modified:string,edit_url:string|null,permalink:string|null}>
   */
  private function collect_animation_records(string $search = ''): array
  {
    global $wpdb;
    $prefix = self::TOOLS_OPTION_PREFIX;
    $like = $wpdb->esc_like($prefix) . '%';
    $records = [];

    // post_meta rows
    $meta_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT pm.post_id AS id, pm.meta_key AS `key`, p.post_title AS title, p.post_type AS type, p.post_modified_gmt AS modified
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key LIKE %s",
        $like
      )
    );
    foreach ($meta_rows as $r) {
      $records[] = [
        'store_type' => 'post_meta',
        'option'     => $r->key,
        'id'         => (int) $r->id,
        'title'      => $r->title !== '' ? $r->title : '(no title)',
        'type_label' => $r->type,
        'modified'   => $r->modified,
      ];
    }

    // term_meta rows
    $term_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT tm.term_id AS id, tm.meta_key AS `key`, t.name AS title, tt.taxonomy AS type
         FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
         WHERE tm.meta_key LIKE %s",
        $like
      )
    );
    foreach ($term_rows as $r) {
      $records[] = [
        'store_type' => 'term_meta',
        'option'     => $r->key,
        'id'         => (int) $r->id,
        'title'      => $r->title,
        'type_label' => 'term:' . $r->type,
        'modified'   => '',
      ];
    }

    // options rows
    $opt_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
      )
    );
    foreach ($opt_rows as $r) {
      $records[] = [
        'store_type' => 'option',
        'option'     => $r->option_name,
        'id'         => 0,
        'title'      => $this->humanize_option_key($r->option_name),
        'type_label' => 'option',
        'modified'   => '',
      ];
    }

    if ($search !== '') {
      $needle = mb_strtolower($search);
      $records = array_values(array_filter($records, function ($rec) use ($needle) {
        return strpos(mb_strtolower($rec['title']), $needle) !== false
          || strpos(mb_strtolower($rec['option']), $needle) !== false;
      }));
    }

    usort($records, function ($a, $b) {
      return strcmp($b['modified'] ?? '', $a['modified'] ?? '');
    });

    return $records;
  }

  private function humanize_option_key(string $key): string
  {
    $suffix = substr($key, strlen(self::TOOLS_OPTION_PREFIX));
    return $suffix !== '' ? $suffix : $key;
  }

  /**
   * A URL that resolves to the kind of request an option-stored slot holds.
   *
   * Preview and the editor both open it, and the editor saves under whatever
   * PageType::current() sees there, so the home URL sent the 404 slot's
   * Preview to the front page and its edits to the front page's bucket. The
   * 404 and search URLs are the ones search_pages() lists for the editor.
   */
  private function option_slot_url(string $slot): string
  {
    switch ($slot) {
      case '404':
        return home_url('/404-preview/');
      case 'search':
        return home_url('/?s=blog');
      case 'archive':
        // Every archive shares the slot; the first author's archive exists on every site and never 404s.
        $authors = get_users(['has_published_posts' => true, 'orderby' => 'ID', 'number' => 1, 'fields' => 'ID']);
        if ($authors === []) {
          $authors = get_users(['orderby' => 'ID', 'number' => 1, 'fields' => 'ID']);
        }
        return get_author_posts_url($authors === [] ? 1 : (int) $authors[0]);
    }

    return home_url('/');
  }

  /**
   * Add edit_url (this build's editor, with token) + permalink to each record.
   */
  private function decorate_record(array $rec, string $token = ''): array
  {
    $permalink = null;

    if ($rec['store_type'] === 'post_meta' && $rec['id']) {
      $permalink = get_permalink($rec['id']) ?: null;
    } elseif ($rec['store_type'] === 'term_meta' && $rec['id']) {
      $link = get_term_link($rec['id']);
      $permalink = is_wp_error($link) ? null : $link;
    } elseif ($rec['store_type'] === 'option') {
      $permalink = $this->option_slot_url($this->humanize_option_key($rec['option']));
    }

    $rec['permalink'] = $permalink;
    $rec['edit_url']  = $permalink ? $this->build_editor_url($permalink, $token) : null;

    return $rec;
  }

  private function build_editor_url(string $page_url, string $token = ''): string
  {
    // Always attach a session JWT so the editor preview can require a valid token unconditionally.
    $query_args = [
      'site'            => $page_url,
      'platform'        => 'wordpress',
      'motionkit_token' => $token !== '' ? $token : JwtTokenManager::generate($page_url),
    ];
    $base = apply_filters('motionkit/editor/url', EditorEndpoint::base());
    return add_query_arg($query_args, $base);
  }
}
