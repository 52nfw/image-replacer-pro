<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$options_to_delete = [
    'xir_target_domains',
    'xir_api_token',
    'xir_watermark',
    'xir_compress',
    'xir_webp',
    'xir_categories'
];

foreach ($options_to_delete as $option) {
       delete_option($option);
       delete_site_option($option);
}

delete_transient(XIR_REGEX_CACHE_KEY);

$wpdb->query(
    "DELETE FROM {$wpdb->options} 
    WHERE option_name LIKE 'xir_log_%'"
);

$htaccess = plugin_dir_path(__FILE__) . '.htaccess';
if (file_exists($htaccess) && is_writable($htaccess)) {
    unlink($htaccess);
}

$batch_size = 100;
do {
    $rows_affected = $wpdb->query(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE '%xir_temp%' 
        LIMIT {$batch_size}"
    );
} while ($rows_affected > 0);

if (WP_DEBUG_LOG) {
    error_log('[XIR Plugin] 插件配置已彻底清理 - ' . date('Y-m-d H:i:s'));
}