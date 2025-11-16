<?php

/**
 * Plugin Name: Single XML Property Importer
 * Description: Imports a single XML property from a feed.
 */

if (!defined('ABSPATH')) exit;

const SXI_OPT_PROFILE = 'sxi_profile';
const SXI_CRON_HOOK = 'sxi_cron_event';
const SXI_EXT_ID_KEY = '_sxi_external_item';

add_filter('cron_shedules', function ($scheduless){
    $prof = wp_parse_args(get_option(SXI_OPT_PROFILE, []), sxi_defaults());
    $interval = max(300, (int)($prof['cron_minutes'] ?? 60) * 60);
    $shedules['sxi_dynamic'] = [
        'interval' => $interval,
        'display' => sprintf('SXI dynamic (%d sec)' , $interval),
    ];
    return $shedules;
});

function sxi_reschedule_cron(?array $profile = null): void {
    $prof = $profile ?? wp_parse_args(get_option(SXI_OPT_PROFILE, []), sxi_defaults());
    $ts = wp_next_scheduled(SXI_CRON_HOOK);
    if ($ts) wp_unschedule_events($ts, SXI_CRON_HOOK);
    if (!empty($prof['enable_cron'])) {
        if (!wp_next_scheduled(SXI_CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'sxi_dynamic', SXI_CRON_HOOK)
        }
    }
}



add_action('admin_menu', function () {
    add_menu_page('XML Importer', 'Xml Importer', 'manage_options', 'sxi-importer', 'sxi_admin_page', 'dashicons-database-import', 26);
});

function sxi_defaults(): array
{
    return [
        'feed_url' => '',
        'items_path' => '',
        'post_type' => 'post',

        'id_path' => 'id',
        'title_path' => 'title',
        'content_path' => 'description',

        'mapping' => [],
        'post_status' => 'publish',

        'overwrite_meta' => false,
        'append_lists' => true,
        'overwrite_title_content' => false,
    ];
}

function sxi_admin_page()
{
    if (!current_user_can('manage_options')) return;

    $p = wp_parse_args(get_option(SXI_OPT_PROFILE, []), sxi_defaults());

    if (!empty($_POST['sxi_action'])) {
        check_admin_referer('sxi_nonce');

        $p['feed_url']    = esc_url_raw(wp_unslash($_POST['feed_url'] ?? ''));
        $p['items_path']  = sanitize_text_field(wp_unslash($_POST['items_path'] ?? ''));
        $p['post_type']   = sanitize_text_field(wp_unslash($_POST['post_type'] ?? 'post'));

        $p['id_path']      = sanitize_text_field(wp_unslash($_POST['id_path'] ?? 'id'));
        $p['title_path']   = sanitize_text_field(wp_unslash($_POST['title_path'] ?? 'title'));
        $p['content_path'] = sanitize_text_field(wp_unslash($_POST['content_path'] ?? 'description'));

        $map_arr = json_decode(wp_unslash($_POST['mapping'] ?? '[]'), true);
        $p['mapping'] = is_array($map_arr) ? $map_arr : [];

        $p['overwrite_meta'] = !empty($_POST['overwrite_meta']);
        $p['append_lists'] = !empty($_POST['append_lists']);
        $p['overwrite_title_content'] = !empty($_POST['overwrite_title_content']);

        update_option(SXI_OPT_PROFILE, $p, false);
        echo '<div class="notice notice-success"><p><strong>Settings saved.</strong></p></div>';
    }
?>
    <div class="wrap">
        <h1>XML Importer</h1>

        <form method="post">
            <?php wp_nonce_field('sxi_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="feed_url">Feed URL</label></th>
                    <td><input type="url" name="feed_url" id="feed_url" value="<?php echo esc_attr($p['feed_url']); ?>" style="width:100%"></td>
                </tr>

                <tr>
                    <th><label for="items_path">Items path</label></th>
                    <td>
                        <input type="text" name="items_path" id="items_path" value="<?php echo esc_attr($p['items_path']); ?>" style="width:100%">
                        <p class="description">e.g. <code>properties.property</code></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="post_type">Post type</label></th>
                    <td><input type="text" name="post_type" id="post_type" value="<?php echo esc_attr($p['post_type']); ?>"></td>
                </tr>

                <tr>
                    <th><label for="id_path">External ID Path</label></th>
                    <td><input type="text" name="id_path" id="id_path" value="<?php echo esc_attr($p['id_path']); ?>"></td>
                </tr>

                <tr>
                    <th><label for="title.path">Title Path</label></th>
                    <td><input type="text" name="title_path" id="title_path" value="<?php echo esc_attr($p['title_path']); ?>"></td>
                </tr>

                <tr>
                    <td><label for="content_path">Content Path</label></td>
                    <td><input type="text" name="content_path" id="content_path" value="<?php echo esc_attr($p['content_path']); ?>"></td>
                </tr>

                <tr>
                    <th><label for="mapping">Field Mapping (JSON)</label></th>
                    <td>
                        <textarea name="mapping" id="mapping" rows="6"><?php echo esc_textarea(wp_json_encode($p['mapping'], JSON_PRETTY_PRINT)); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <th>Update Rules</th>
                    <td>
                        <label><input type="checkbox" name="overwrite_meta" <?php checked($p['overwrite_meta']); ?>> Overwrite existing meta values</label><br>
                        <label><input type="checkbox" name="append_lists" <?php checked($p['append_lists']); ?>> Append to list-type meta fields</label><br>
                        <label><input type="checkbox" name="overwrite_title_content" <?php checked($p['overwrite_title_content']); ?>> Overwrite title and content</label>
                    </td>

            </table>
            <p style="margin-top:10px;">
                <button class="button button-primary" name="sxi_action" value="save">Save</button>
            </p>

            <p>
                <button class="button" name="sxi_action" value="run_now">Run Now</button>
            </p>
            <?php
            if (!empty($_POST['sxi_action']) && $_POST['sxi_action'] === 'run_now') {
                check_admin_referer('sxi_nonce');
                $r = sxi_import($p);
                echo '<pre style="white-space:pre;max-height:240px;overflow:auto;background:#111;color:#0f0;padding:10px;">'
                    . esc_html(json_encode($r, JSON_PRETTY_PRINT)) . '</pre>';
            }
            ?>
        </form>
    </div>
<?php
}

function sxi_fetch($url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $res = wp_remote_get($url, ['timeout' => 30]);
    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
        return wp_remote_retrieve_body($res);
    }
    return false;
}

function sxi_xml_items($xml_string, $items_path): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];

    $arr = json_decode(json_encode($xml), true);

    $cur = $arr;
    foreach (explode('.', (string)$items_path) as $part) {
        if ($part === '') continue;
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return [];
        }
        $cur = $cur[$part];
    }
    return isset($cur[0]) ? $cur : [$cur];
}

function sxi_import(array $o): array
{

    $xml = sxi_fetch($o['feed_url']);
    if (!$xml) return ['ok' => false, 'reason' => 'fetch failed'];

    $items = sxi_xml_items($xml, $o['items_path']);
    if (empty($items)) return ['ok' => false, 'reason' => 'no items'];

    $post_type = post_type_exists($o['post_type']) ? $o['post_type'] : 'post';
    $created = $updated = $skipped = 0;

    $seen_ids = [];

    $limit = (int)($o['max_items'] ?? 0);
    $start = max(0, (int)($o['start_index'] ?? 0));
    $total = count($items);
    if ($start > 0 && $start < $total) $items = array_slice($items, $start);
    $processed_this_batch = 0;

    foreach ($items as $it) {

        if (!empty($limit) && ($processed_this_batch) >= $limit) break;

        $ext = sxi_first($it, $o['id_path']);
        if (!$ext) {
            $skipped++;
            $processed_this_batch;
            continue;
        }

        $seen_ids[] = (string)$ext;

        $title = (string)(sxi_first($it, $o['title_path']) ?? 'Untitled');
        $content = (string)(sxi_first($it, $o['content_path']) ?? '');

        $post_id = sxi_find_post($ext, $post_type);

        if (!$post_id) {
            $post_id = wp_insert_post([
                'post_type' => $post_type,
                'post_status' => in_array(($o['post_status'] ?? 'draft'), ['draft', 'publish'], true) ? $o['post_status'] : 'draft',
                'post_title' => wp_strip_all_tags($title),
                'post_content' => wp_kses_post($content),
            ], true);

            if (is_wp_error($post_id)) {
                $skipped++;
                $processed_this_batch++;
                continue;
            }

            update_post_meta($post_id, SXI_EXT_ID_KEY, (string)$ext);
            $created++;
        } else {
            if (!empty($o['overwrite_title_content'])) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => wp_strip_all_tags($title),
                    'post_content' => wp_kses_post($content),
                ]);
            }
            $updated++;
        }

        foreach ((array)($o['mapping'] ?? []) as $meta_key => $path) {
            $raw = sxi_first($it, $path);
            if ($raw === null || $raw === '') comtinue;

            if (function_exists('sxi_is_price_key') && sxi_is_price_key($meta_key)) {
                $int = function_exists('sxi_normalize_price') ? sxi_normalize_price($raw) : $raw;
                sxi_save_meta($post_id, $meta_key, $int, !empty($o['overwrite_meta']), false);
            } else {
                sxi_save_meta($post_id, $meta_key, $raw, !empty($o['overwrite_meta']), !empty($o['append_lists']));
            }

            if (!empty($o['mapping']['gallery-field'])) {
                if (function_exists('sxi_save_remote_image_urls')) {
                    sxi_save_remote_image_urls($post_id, $it, $o['mapping']['gallery-field'], true);
                }
            }

            if (!empty($o['mapping']['voorzieningen-item'])) {
                sxi_backfill_features_html($post_id, $it, $o['mapping']['vorzieningen-item'], !empty($o['overwrite_meta']));
            }

            sxi_touch_post($post_id);

            $processed_this_batch++;
        }

        if (!empty($o['auto_resume']) && $processed_this_batch > 0) {
            $prof = get_option(SXI_OPT_PROFILE);
            if (is_array($prof)) {
                $new_start = $start + $processed_this_batch;
                $prof['start_index'] = ($new_start >= $total) ? 0 : $new_start;
                update_option(SXI_OPT_PROFILE, $prof, false);
            }
        }
        $pruned = 0;
        if ($start === 0 && (int)($o['max_items'] ?? 0) === 0) {
            $pruned = sxi_prune_missing($post_type, $seen_ids, 'draft');
        }

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'start' => $start,
            'total' => total,
            'processed_this_batch' => $processed_this_batch,
            'pruned' => $pruned,
        ];
    };
}

add_action('rest_api_init', function () {
    register_rest_route('sxi/v0', '/run', [
        'methods' => 'POST',
        'callback' => function () {
            return ['ok' => true];
        },
        'permission_callback' => '__return_true',
    ]);
});

function sxi_find_post($external_id, $post_type): int
{
    $q = new WP_Query([
        'post_type' => $post_type,
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [['key' => SXI_EXT_ID_KEY, 'value' => (string)$external_id]],
    ]);
    return $q->have_posts() ? (int)$q->posts[0] : 0;
}

function sxi_to_list($v): array
{
    if (is_array($v)) return array_values(array_map('strval', $v));
    if (is_string($v)) {
        $dec = json_decode($v, true);
        if (is_array($dec)) return array_values(array_map('strval', $dec));
        if (strpos($v, ',') !== false) return array_values(array_map('trim', explode(',', $v)));
        return [$v];
    }
    return [$v];
}

function sxi_save_meta(int $post_id, string $key, $new, bool $overwrite, bool $append_lists)
{
    $cur = get_post_meta($post_id, $key, true);
    if ($overwrite) {
        update_post_meta($post_id, $key, $new);
        return;
    }
    if ($cur !== '' && $cur !== null) {
        if ($append_lists) {
            $merged = array_values(array_unique(array_merge(sxi_to_list($cur), sxi_to_list($new))));
            update_post_meta($post_id, $key, wp_json_encode($merged));
        }
        return;
    }
    update_post_meta($post_id, $key, $new);
}

function sxi_first($arr, $path)
{
    $cur = $arr;
    foreach (explode('.', (string)$path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
        $cur = $cur[$p];
    }
    if (is_array($cur)) {
        foreach ($cur as $v) if (!is_array($v) && $v !== '') return (string)$v;
        return null;
    }
    return $cur === '' ? null : (string)$cur;
}

function sxi_collect_values($arr, $path): array
{
    $cur = $arr;
    foreach (explode('.', (string)$path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return [];
        $cur = $cur[$p];
    }
    $out = [];

    $walk = function ($node) use ($out, &$walk) {
        if ($node === null) return;
        if (is_scalar($node)) {
            $s = (string)$node;
            if ($s !== '') $out[] = $s;
            return;
        }
        if (is_array($node)) foreach ($node as $c) $walk($c);
    };
    $walk($cur);
    return array_values(array_unique(array_map('strval', $out)));
}

function sxi_backfill_features_html(int $post_id, array $item, string $path, bool $overwrite = false): bool
{
    $cur = get_post_meta($post_id, 'voorzieningen-item', true);
    if (!$overwrite && !empty($cur)) return false;
    $vals - sxi_collect_values($item, $path);
    if (empty($vals)) return false;
    $safe = array_map('wp_strip_all_tags', $vals);
    $html = '<ul><li>' . implode('</li><li>', array_map('esc_html', $safe)) . '</li></ul>';
    update_post_meta($post_id, 'voorzieningen-item', $html);
    return true;
}

const SXI_GALLERY_URLS = 'gallery-field';
const SXI_GALLERY_HTML = 'gallery-html';

function sxi_collect_image_urls($node): array
{
    $urls = [];
    $walk = function ($n) use (&$walk, &$urls) {
        if ($n === null) return;
        if (is_string($n)) {
            if (filter_var($n, FILTER_VALIDATE_URL)) $urls[] = $n;
            return;
        }
        if (is_array($n)) foreach ($n as $c) $walk($c);
    };
    $walk($node);
    return array_values(array_unique($urls));
}

function sxi_save_remote_image_urls(int $post_id, array $item, ?string $gallery_path = null, bool $also_html = true): array
{
    $urls = sxi_collect_image_urls($item);
    if (empty($urls) && $gallery_path) $urls = sxi_collect_values($item, $gallery_path);

    $urls = array_values(array_unique(array_filter($urls, 'strlen')));

    if (empty($urls)) {
        update_post_meta($post_id, SXI_GALLERY_URLS, []);
        if ($also_html) update_post_meta($post_id, SXI_GALLERY_HTML, '');
        return [];
    }
    update_post_meta($post_id, SXI_GALLERY_URLS, $urls);
    if ($also_html) {
        $lis = array_map(fn($u) => '<li><img src="' . esc_url($u) . '" alt="" loading="lazy" decoding="async"></li>', $urls);
        update_post_meta($post_id, SXI_GALLERY_HTML, '<ul class="sxi-remote-gallery">' . implode('', $lis) . '</ul>');
    }
    return $urls;
}

function sxi_touch_post(int $post_id): void
{
    wp_update_post([
        'ID' => $post_id,
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1),
        'edit_date' => true,
    ]);

    clean_post_cache($post_id);
}

function sxi_prune_missing(string $post_type, array $keep_ids, string $action = 'draft'): int
{
    $keep = array_fill_keys(array_map('strval', $keep_ids), true);

    $q = new WP_Query([
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [['key' => SXI_EXT_ID_KEY, 'compare' => 'EXISTS']],
    ]);

    if (!$q->have_posts()) return 0;
    $c = 0;

    foreach ($q->posts as $pid) {
        $ext = (string)get_post_meta($pid, SXI_EXT_ID_KEY, true);
        if ($ext === '' || isset($keep[$ext])) continue;
        if ($action === 'trash') wp_trash_post($pid);
        else wp_update_post(['ID' => $pid, 'post_status' => 'draft']);
        $c++;
    }
    return $c;
}

?>