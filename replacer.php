<?php
/*
Plugin Name: 聚合图床 Pro
Plugin URI: https://www.52nfw.cn/
Description: 聚合图床自动检测指定链接上传到图床并替换工具
Version: 2.2.0
Author: 小小随风
*/

if (!defined('ABSPATH')) exit;

// 核心配置
define('XIR_PER_PAGE', 20);
define('XIR_API_TIMEOUT', 10);
define('XIR_REGEX_CACHE_KEY', 'xir_compiled_regex');
define('XIR_LOG_DIR', plugin_dir_path(__FILE__).'logs/');

// 注册管理菜单
add_action('admin_menu', function() {
    add_options_page(
        '图片替换设置',
        '图片替换设置',
        'manage_options',
        'xir-pro-settings',
        'xir_pro_settings_page'
    );
});

// 安装/卸载处理
register_activation_hook(__FILE__, function() {
    // 安全文件
    file_put_contents(plugin_dir_path(__FILE__).'.htaccess', "Deny from all");
    
    // 创建日志目录
    if (!file_exists(XIR_LOG_DIR)) {
        mkdir(XIR_LOG_DIR, 0755, true);
    }
    
    // 初始化配置
    add_option('xir_target_domains', 'www.52nfw.cn', '', 'no');
    add_option('xir_api_token', '', '', 'no');
    add_option('xir_watermark', 0, '', 'no');
    add_option('xir_compress', 0, '', 'no');
    add_option('xir_webp', 0, '', 'no');
    add_option('xir_categories', '', '', 'no');
    add_option('xir_keep_data', 0, '', 'no'); // 新增：卸载时保留数据选项
    
    // 预编译正则
    xir_update_regex_pattern();
});

register_deactivation_hook(__FILE__, 'xir_pro_deactivate');
register_uninstall_hook(__FILE__, 'xir_pro_uninstall');

function xir_pro_deactivate() {
    delete_post_meta_by_key('_xir_processed');
    delete_transient(XIR_REGEX_CACHE_KEY);
}

function xir_pro_uninstall() {
    global $wpdb;
    
    // 检查是否保留数据
    if (get_option('xir_keep_data')) return;
    
    // 清理所有插件数据
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE 'xir_%'"
    );
    delete_post_meta_by_key('_xir_processed');
    
    // 删除日志目录
    xir_clean_log_files();
    @rmdir(XIR_LOG_DIR);
}

// 设置页面
function xir_pro_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_xir_nonce'])) {
        xir_pro_handle_form_submit();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_xir_cleanup_nonce'])) {
        xir_pro_handle_cleanup();
    }
    
    $stats = xir_get_processing_stats();
    ?>
    <div class="wrap">
        <h1>图片替换设置</h1>
        
        <?php settings_errors('xir_messages'); ?>
        
        <div class="notice notice-warning">
            <p><strong>温馨提示</strong></p>
            <ol>
                <li>首次使用需要到<a href="https://www.superbed.cn/signup?link=tfw6auj6" target="_blank">聚合图床</a> 获取token密钥</li>
                <li>​检测域名填写需替换的图片源站域名​</li>
                <li>操作前建议备份数据库因为替换是不可逆的</li>
                <li>高流量时段避免执行批量替换和清理</li>
            </ol>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('xir_pro_settings', '_xir_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label>API Token</label></th>
                    <td>
                        <input type="password" name="api_token" 
                               value="<?php echo esc_attr(get_option('xir_api_token')); ?>"
                               class="regular-text" required>
                        <p class="description">从聚合图床获取token密钥</p>
                    </td>
                </tr>
                <tr>
                    <th><label>检测域名</label></th>
                    <td>
                        <input type="text" name="target_domains" 
                               value="<?php echo esc_attr(get_option('xir_target_domains')); ?>"
                               class="regular-text">
                        <p class="description">多个域名用英文逗号分隔</p>
                    </td>
                </tr>
                <tr>
                    <th><label>相册分类</label></th>
                    <td>
                        <input type="text" name="categories" 
                               value="<?php echo esc_attr(get_option('xir_categories')); ?>"
                               class="regular-text">
                        <p class="description">多个分类用英文逗号分隔</p>
                    </td>
                </tr>
                <tr>
                    <th><label>水印设置</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="watermark" 
                                <?php checked(get_option('xir_watermark'), 1); ?>>
                            启用图片水印
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label>压缩设置</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="compress" 
                                <?php checked(get_option('xir_compress'), 1); ?>>
                            启用压缩（覆盖用户中心默认设置）
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label>WebP转换</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="webp" 
                                <?php checked(get_option('xir_webp'), 1); ?>>
                            强制转为WebP格式（覆盖用户中心默认设置）
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label>数据保留</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="keep_data" 
                                <?php checked(get_option('xir_keep_data'), 1); ?>>
                            卸载插件时保留数据
                        </label>
                        <p class="description">勾选后卸载插件不会删除处理记录和设置</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
        </form>

        <div class="card" style="margin-top:20px">
            <h2>批量替换</h2>
            <div class="xir-stats">
                <p>总文章数：<?php echo $stats['total']; ?> 
                   | 已处理：<?php echo $stats['processed']; ?>
                   | 剩余：<?php echo $stats['remaining']; ?></p>
            </div>
            <button id="xir-start" class="button button-primary">开始扫描替换</button>
            <div id="xir-progress" style="margin-top:15px;display:none">
                <div class="xir-progress-bar">
                    <div class="xir-progress"></div>
                </div>
                <p>进度: <span id="xir-processed">0</span>/<span id="xir-total">0</span></p>
                <p>成功: <span id="xir-success">0</span> 失败: <span id="xir-failed">0</span></p>
            </div>
        </div>

        <div class="card" style="margin-top:20px">
            <h2>数据清理</h2>
            <div class="xir-stats">
                <p>已处理数据：<?php echo $stats['processed']; ?> 条
                   | 占用空间：<?php echo size_format(xir_get_processed_data_size()); ?>
                   | 日志文件：<?php echo xir_count_log_files(); ?> 个</p>
            </div>
            <form method="post" onsubmit="return confirm('警告：此操作不可逆！请确认已备份数据库。')">
                <?php wp_nonce_field('xir_cleanup', '_xir_cleanup_nonce'); ?>
                <label>
                    <input type="checkbox" name="clean_meta" checked> 清理处理标记（_xir_processed）
                </label>
                <label style="margin-left:15px">
                    <input type="checkbox" name="clean_logs"> 清理日志记录
                </label>
                <label style="margin-left:15px">
                    <input type="checkbox" name="optimize_tables"> 优化数据库表
                </label>
                <?php submit_button('执行清理', 'delete'); ?>
            </form>
        </div>
    </div>

    <style>
    .xir-progress-bar {
        width: 100%; height: 20px;
        background: #f1f1f1;
        border-radius: 3px;
        overflow: hidden;
    }
    .xir-progress {
        height: 100%;
        background: #0073aa;
        transition: width 0.3s ease;
    }
    .xir-stats {
        margin: 15px 0;
        padding: 10px;
        background: #f8f9fa;
        border-left: 4px solid #0073aa;
    }
    </style>

    <script>
    jQuery(function($){
        const $btn = $('#xir-start');
        const $progress = $('#xir-progress');
        
        $btn.click(function(){
            $btn.prop('disabled', true);
            $progress.show();
            
            let success = 0, failed = 0;
            
            const processBatch = (page = 1) => {
                $.post(ajaxurl, {
                    action: 'xir_process',
                    nonce: '<?php echo wp_create_nonce('xir_processing'); ?>',
                    page: page,
                    _: Date.now()
                }).done(res => {
                    if (res.success) {
                        const percent = (res.data.processed / res.data.total * 100).toFixed(1);
                        $('.xir-progress').css('width', percent + '%');
                        
                        $('#xir-processed').text(res.data.processed);
                        $('#xir-total').text(res.data.total);
                        success += res.data.success;
                        failed += res.data.failed;
                        $('#xir-success').text(success);
                        $('#xir-failed').text(failed);

                        if (res.data.remaining > 0) {
                            setTimeout(() => processBatch(page + 1), 300);
                        } else {
                            alert('处理完成！');
                            location.reload();
                        }
                    } else {
                        alert('错误: ' + res.data);
                        $btn.prop('disabled', false);
                    }
                });
            }
            
            processBatch(1);
        });
    });
    </script>
    <?php
}

// 核心处理函数
add_action('wp_ajax_xir_process', 'xir_pro_process_images');
function xir_pro_process_images() {
    check_ajax_referer('xir_processing', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足', 403);
    }

    $page = max(1, intval($_POST['page'] ?? 1));
    $stats = xir_get_processing_stats();
    
    $query = new WP_Query([
        'post_type'      => 'post',
        'posts_per_page' => XIR_PER_PAGE,
        'paged'          => $page,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => '_xir_processed',
            'compare' => 'NOT EXISTS'
        ]]
    ]);

    $success = $failed = 0;
    
    foreach ($query->posts as $post_id) {
        $result = xir_process_post($post_id);
        $result['success'] ? $success++ : $failed++;
    }

    wp_send_json_success([
        'processed' => min($page * XIR_PER_PAGE, $stats['total']),
        'total'     => $stats['total'],
        'success'   => $success,
        'failed'    => $failed,
        'remaining' => $stats['remaining'] - XIR_PER_PAGE
    ]);
}

function xir_process_post($post_id) {
    $content = get_post_field('post_content', $post_id);
    $pattern = get_transient(XIR_REGEX_CACHE_KEY);
    
    if (!$pattern) {
        $pattern = xir_update_regex_pattern();
    }
    
    preg_match_all($pattern, $content, $matches);
    
    if (empty($matches[0])) {
        update_post_meta($post_id, '_xir_processed', time());
        return ['success' => true];
    }

    $replacements = [];
    foreach ($matches[0] as $url) {
        if ($new_url = xir_upload_image($url)) {
            $replacements[$url] = $new_url;
        }
    }

    if (!empty($replacements)) {
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => str_replace(
                array_keys($replacements),
                array_values($replacements),
                $content
            )
        ]);
    }

    update_post_meta($post_id, '_xir_processed', time());
    return ['success' => true];
}

function xir_upload_image($url) {
    $args = [
        'timeout' => XIR_API_TIMEOUT,
        'body'    => [
            'token'     => get_option('xir_api_token'),
            'src'       => $url,
            'watermark' => get_option('xir_watermark') ? 'true' : 'false',
            'compress'  => get_option('xir_compress') ? 'true' : 'false',
            'webp'      => get_option('xir_webp') ? 'true' : 'false',
            'categories'=> get_option('xir_categories')
        ]
    ];

    $response = wp_remote_post('https://api.superbed.cn/upload', $args);
    
    if (is_wp_error($response) || 
        wp_remote_retrieve_response_code($response) !== 200) {
        xir_log_error('上传失败: '.$url.' - '.$response->get_error_message());
        return false;
    }

    $data = json_decode($response['body'], true);
    return $data['url'] ?? false;
}

// 数据清理功能
function xir_pro_handle_cleanup() {
    check_admin_referer('xir_cleanup', '_xir_cleanup_nonce');
    
    $cleaned = 0;
    
    // 清理处理标记
    if (!empty($_POST['clean_meta'])) {
        $cleaned += delete_post_meta_by_key('_xir_processed');
    }
    
    // 清理日志
    if (!empty($_POST['clean_logs'])) {
        $cleaned += xir_clean_log_files();
    }
    
    // 优化数据库表
    if (!empty($_POST['optimize_tables'])) {
        xir_optimize_database_tables();
    }
    
    // 数据库优化后执行
    do_action('xir_after_cleanup');
    
    add_settings_error(
        'xir_messages', 
        'xir_cleanup_success',
        sprintf('已清理 %d 条冗余数据', $cleaned),
        'success'
    );
}

function xir_get_processed_data_size() {
    global $wpdb;
    return $wpdb->get_var(
        "SELECT SUM(LENGTH(meta_value)) 
         FROM {$wpdb->postmeta} 
         WHERE meta_key = '_xir_processed'"
    );
}

function xir_count_log_files() {
    if (!file_exists(XIR_LOG_DIR)) return 0;
    return count(glob(XIR_LOG_DIR.'*.log'));
}

function xir_clean_log_files() {
    if (!file_exists(XIR_LOG_DIR)) return 0;
    
    $count = 0;
    foreach (glob(XIR_LOG_DIR.'*.log') as $file) {
        if (unlink($file)) $count++;
    }
    return $count;
}

function xir_optimize_database_tables() {
    global $wpdb;
    
    $tables = $wpdb->get_col("SHOW TABLES");
    foreach ($tables as $table) {
        $wpdb->query("OPTIMIZE TABLE `$table`");
    }
    
    return count($tables);
}

function xir_log_error($message) {
    if (!file_exists(XIR_LOG_DIR)) {
        mkdir(XIR_LOG_DIR, 0755, true);
    }
    
    $log_file = XIR_LOG_DIR.'error-'.date('Y-m-d').'.log';
    $log_entry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// 性能优化函数
function xir_update_regex_pattern() {
    $domains = get_option('xir_target_domains', '');
    $domains_array = array_filter(array_map('trim', explode(',', $domains)));
    
    $regex = '';
    if (!empty($domains_array)) {
        $cleaned = array_map(function($domain) {
            return preg_quote(preg_replace('#^https?://#', '', $domain), '#');
        }, $domains_array);
        
        $regex = '#https?://(?:[a-zA-Z0-9-]+\.)*('.implode('|', $cleaned).')[^\s"\'<>]+\.(jpe?g|png|gif)#i';
        set_transient(XIR_REGEX_CACHE_KEY, $regex, WEEK_IN_SECONDS);
    }
    
    return $regex;
}

function xir_get_processing_stats() {
    global $wpdb;
    
    $total = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} 
        WHERE post_type = 'post' AND post_status = 'publish'"
    );
    
    $processed = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_xir_processed'"
    );
    
    return [
        'total'     => $total,
        'processed' => $processed,
        'remaining' => $total - $processed
    ];
}

// 设置保存处理
function xir_pro_handle_form_submit() {
    check_admin_referer('xir_pro_settings', '_xir_nonce');
    
    // 清理域名
    $domains = array_filter(array_map(function($domain) {
        $domain = preg_replace('#^https?://#', '', trim($domain));
        return sanitize_text_field($domain);
    }, explode(',', $_POST['target_domains'])));
    
    // 更新设置
    update_option('xir_target_domains', implode(',', $domains), 'no');
    update_option('xir_api_token', sanitize_text_field($_POST['api_token']), 'no');
    update_option('xir_watermark', isset($_POST['watermark']) ? 1 : 0, 'no');
    update_option('xir_compress', isset($_POST['compress']) ? 1 : 0, 'no');
    update_option('xir_webp', isset($_POST['webp']) ? 1 : 0, 'no');
    update_option('xir_categories', sanitize_text_field($_POST['categories']), 'no');
    update_option('xir_keep_data', isset($_POST['keep_data']) ? 1 : 0, 'no');
    
    // 更新正则表达式
    xir_update_regex_pattern();
    
    add_settings_error(
        'xir_messages',
        'xir_settings_updated',
        '设置已保存！正则表达式已更新。',
        'success'
    );
}

// 前端零影响保障
add_action('wp_enqueue_scripts', function() {
    if (!is_admin()) {
        remove_action('wp_head', 'xir_pro_settings_page');
        wp_dequeue_style('dashicons');
    }
}, 999);
