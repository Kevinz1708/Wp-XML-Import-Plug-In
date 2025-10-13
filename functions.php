<?php

/**
 * Plugin Name: Xml Importer
 * Description: Admin page + test import button.
 * Version: 0.0.1
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('XML Importer', 'XML Importer', 'manage_options', 'sxi-importer', function () {
        if (!current_user_can('manage_options')) return;

        $feed_url = get_option('sxi_feed_url', '');

        if (!empty($_POST['sxi_action'])) {
            check_admin_referer('sxi_nonce');
            $feed_url = esc_url_raw($_POST['feed_url'] ?? '');
            update_option('sxi_feed_url', $feed_url, false);

            if ($_POST['sxi_action'] === 'run_now') {
                $result = sxi_import($feed_url);
                echo '<div class="notice notice-success"><p><strong>Import executed.</strong></p><pre>'
                    . esc_html(print_r($result, true)) . '</pre></div>';
            } else {
                echo '<div class="notice notice-info"><p>Settings saved.</p></div>';
            }
        }
?>
        <div class="wrap">
            <h1>Simple XML Importer</h1>
            <form method="post">
                <?php wp_nonce_field('sxi_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="feed_url">Feed URL</label></th>
                        <td><input type="url" name="feed_url" id="feed_url" style="width:100%;" value="<?php echo esc_attr($feed_url); ?>" required></td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" name="sxi_action" value="run_now">Run Import</button>
                    <button class="button" name="sxi_action" value="save">Save</button>
                </p>
            </form>
        </div>
<?php
    }, 'dashicons-database-import', 26);
});

function sxi_import(string $url): array
{
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'Invalid URL'];
    }

    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'error' => $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    $length = strlen($body);

    return ['ok' => true, 'bytes_received' => $length];
}
