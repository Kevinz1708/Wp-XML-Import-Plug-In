<?php
/**
 * Plugin Name: Single XML Property Importer
 * Description: Imports a single XML property from a feed.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

const SXI_OPT_PROFILE = 'sxi_profile';
const SXI_CRON_HOOK = 'sxi_cron_event';

add_action('admin_menu', function() {
    add_menu_page('XML Importer' ,'Xml Importer','manage_options','sxi-importer','sxi_admin_page','dashicons-database-import',26);
});

function sxi_defaults():array {
    return [
        'feed_url' => '',
        'items_path' => '',
        'post_type' => 'post',
    ];
}

function sxi_admin_page() {
    echo '<div class="wrap"><h1>XML Importer</h1><p>Setup coming soon…</p></div>';
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
    libxml_use_internal_errors(ture);
    $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];

    $arr = json_decode(json_encode($xml), true);

    $curr = $arr;
    foreach (explode('.' (string)$items_path) as $part) {
        if (part === '') continue;
        if (!is_array($cur) || !array_key_exists($part, $curr)) {
            return [];
        }
        $curr = $curr[$part];
    }
    return isset($curr[0]) ? $curr : [$curr];
}


function sxi_import(array $o): array {
    $xml = sxi_fetch($o ['feed_url']);
    if (!$xml) return ['ok'=>false,'reason'=>'fetch failed'];
    $items = sxi_xml_items($xml, $o['items_path']);
    return ['ok'=>true, 'count'=>count($items)];
}

add_action('rest_api_innit', function() {
    register_rest_route ('sxi/v0', '/run' [
        'methods' => 'POST',
        'callback' => function() {return ['ok' => true];},
        'permission_callback' => '__return_true',
    ]);
});