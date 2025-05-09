<?php
/*
Plugin Name: 聚合图床 Pro
Plugin URI: https://www.52nfw.cn/
Description: 聚合图床手动检测指定链接上传到图床并替换工具（高性能版）
Version: 2.0
Author: 小小随风
*/

if (!defined('ABSPATH')) exit;

// 核心配置
define('XIR_PER_PAGE', 20);
define('XIR_API_TIMEOUT', 10); // API超时时间
define('XIR_REGEX_CACHE_KEY', 'xir_compiled_regex');

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
    
    // 初始化配置（禁用自动加载）
    add_option('xir_target_domains', 'www.52nfw.cn', '', 'no');
    add_option('xir_api_token', '', '', 'no');
    add_option('xir_watermark', 0, '', 'no');
    add_option('xir_categories', '', '', 'no');
    
    // 预编译正则
    xir_update_regex_pattern();
});

register_deactivation_hook(__FILE__, function() {
    delete_post_meta_by_key('_xir_processed');
    delete_transient(XIR_REGEX_CACHE_KEY);
});

// 设置页面
function xir_pro_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        xir_pro_handle_form_submit();
    }
    
    $stats = xir_get_processing_stats();
    ?>
    <div class="wrap">
        <h1>图片替换设置</h1>
        
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
                    _: Date.now() // 防缓存
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
            'categories'=> get_option('xir_categories')
        ]
    ];

    $response = wp_remote_post('https://api.superbed.cn/upload', $args);
    
    if (is_wp_error($response) || 
        wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $data = json_decode($response['body'], true);
    return $data['url'] ?? false;
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
    
    // 更新设置（保持no-autoload）
    update_option('xir_target_domains', implode(',', $domains), 'no');
    update_option('xir_api_token', sanitize_text_field($_POST['api_token']), 'no');
    update_option('xir_watermark', isset($_POST['watermark']) ? 1 : 0, 'no');
    update_option('xir_categories', sanitize_text_field($_POST['categories']), 'no');
    
    // 更新正则表达式
    xir_update_regex_pattern();
    
    echo '<div class="notice notice-success"><p>设置已保存！正则表达式已更新。</p></div>';
}

// 前端零影响保障
add_action('wp_enqueue_scripts', function() {
    if (!is_admin()) {
        remove_action('wp_head', 'xir_pro_settings_page');
        wp_dequeue_style('dashicons');
    }
}, 999);

