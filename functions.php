<?php
/**
 * Plugin Name: Single XML Property Importer
 * Description: Imports a single XML property from a feed.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

const SXI_OPT_PROFILE = 'sxi_profile';
const SXI_CRON_HOOK = 'sxi_cron_event';
const SXI_EXT_ID_KEY = '_sxi_external_item';

add_action('admin_menu', function() {
    add_menu_page('XML Importer' ,'Xml Importer','manage_options','sxi-importer','sxi_admin_page','dashicons-database-import',26);
});

function sxi_defaults():array {
    return [
        'feed_url' => '',
        'items_path' => '',
        'post_type' => 'post',

        'id_path' => 'id',
        'title_path' => 'title',
        'content_path' => 'description',

        'mapping'=> [],
        'post_status' => 'publish',

        'overwrite_meta' => false,
        'append_lists' => true,
        'overwrite_title_content' => false,
    ];
}

function sxi_admin_page() {
    if (!current_user_can('manage_options')) return;

    $p = wp_parse_args(get_option(SXI_OPT_PROFILE, []), sxi_defaults());

    if (!empty($_POST['sxi_action'])) {
        check_admin_referer('sxi_nonce');

        $p['feed_url'] = esc_url_raw(wp_unslash)($_POST['feed_url'] ?? '');
        $p['items_path'] = sanitize_text_field(wp_unslash($_POST['items_path'] ?? ''));
        $p['post_type'] = sanitize_text_field(wp_unslash($_POST['post_type'] ?? 'post'));

        $p['id_path'] = sanitize_text_field(wp_unslash($_POST['id_path'] ?? 'id_path'));
        $p['title_path'] = sanitize_text_field(wp_unslash($_POST['title_path'] ?? 'title_path'));
        $p['content_path'] = sanitize_text_field(wp_unslash($_POST['content_path'] ?? 'content_path'));

        $map_arr = json_decode(wp_unslash($_POST['mapping'] ?? '[]'), true);
        $p['mapping'] = is_array($map_arr) ? $map_arr : [];

        $p['overwrite_meta'] = !empty($_POST['overwrite_meta']);
        $p['append_lists'] = !empty($_POST['append_lists']);
        $p['overwrite_title_content'] = !empty($_POST['overwrite_title_content']);

        update_option(SXI_OPT_PROFILE, $p , false);
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

function sxi_fetch($url){
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $res = wp_remote_get($url, ['timeout'=>30]);
    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res)===200){
        return wp_remote_retrieve_body($res);
    }
    return false;
}

function sxi_xml_items($xml_string, $items_path) : array {
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
    return isset($crr[0]) ? $cur : [$cur];
}


function sxi_import(array $o): array {
    $xml = sxi_fetch($o ['feed_url']);
    if (!$xml) return ['ok'=>false,'reason'=>'fetch failed'];
    
    $items = sxi_xml_items($xml, $o['items_path']);
    if (empty($items)) return ['ok'=>false, 'reason'=>'no items'];

    $post_type = post_type_exists(&o['post_type']) : 'post';
    $created = $updated = $skipped = 0;

    foreach ($items as $it) {
        $ext = sxi_first($it, $o['id_path']);
        if (!$ext) {$skipped++; continue; }

        $title = (string)(sxi_first($it, $o['title_path']) ?? 'Untitles');
        $content = (string)(sxi_first($it, $o['content_path']) ?? '');

        $post_id = sxi_find_post($ext, $post_type);

        if (!$post_id) {
            $post_id = wp_insert_post([
                'post_type' => $post_type,
                'post_status' => in_array(($o['post_status'] ?? 'draft'), ['draft','publish'], true) ? $o['post_status'] : 'draft',
                'post_title' => wp_strip_all($title),
                'post_content' => wp_kses_post($content),
            ], true);

            if (is_wp_error($post_id)) {$skipped++; continue;}
            update_post_meta($post_id), SXI_EXT_ID_KEY, ((string)$ext);
            $created++;
        } else {
            if (!empty($o['overwrite_title_content'])) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => wp_strip_all_tags($title),
                    'post_content' => wp_kses_post($content),
                ]);
            }
            $updated++
        }
    }
    return ['ok'=>true, 'created'=>$created, 'updated'=>$updated, 'skipped'=>$skipped, 'total'=>count($items)];
}



add_action('rest_api_init', function() {
    register_rest_route ('sxi/v0', '/run', [
        'methods' => 'POST',
        'callback' => function() {return ['ok' => true]; },
        'permission_callback' => '__return_true',
    ]);
});

function sxi_find_post($external_id, $post_type) : int {
    $q = new WP_Query([
        'post_type' => $post_type,
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [['key' => SXI_EXT_ID_KEY, 'value' => (string)$external_id]],
    ]);
    return $q->have_posts() ? (int)$q->posts[0] : 0;
}

function sxi_to_list($v) : array {
    if (is_array($v)) return array_values(array_map('strval', $v));
    if (is_string($v)) {
        $dec = json_decode($v, true);
        if (is_array($dec)) return array_values(array_map('strval', $dec));
        if (strpos($v, ',') !== false) return array_values(array_map ('trim', explode(',', $v)));
        return [$v];

    }
    return [$v];
}

function sxi_save_meta(int $post_id, string $key, $new, bool $overwrite, bool $append_lists) {
    $cur = get_post_meta($post_id, $key, true);
    if ($overwrite) {update_post_meta($post_id, $key, $new); return;}
    if ($cur !== '' && $cur !== null) {
        if ($append_lists) {
            $merged = array_values (array_unique(array_merge(sxi_to_list($cur), sxi_to_list($new))));
            update_post_meta($post_id, $key, wp_json_encode($merged));
        }
        return;
    }
    update_post_meta($post_id, $key, $new);
}

function sxi_first($arr, $path) {
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

function sxi_collect_values($arr, $path): array {
    $cur = $arr;
    foreach (explode('.', (string)$path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return [];
        $cur = $cur[$p];
    }
    $out = [];

    $walk = function($node) use ($out, &$walk) {
        if ($node === null) return;
        if (is_scalar($node)) {$s = (string)$node; if ($s !== '') $out[] = $s; return;}
        if (is_array($node)) foreach ($node as $c) $walk($c);
    };
    $walk($cur);
    return array_values(array_unique(array_map('strval', $out)));
}
?>