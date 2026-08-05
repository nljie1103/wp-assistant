<?php
/**
 * Template name: Zibll-资源下载（科技蓝紫版）
 * Description: 九流网络定制独立下载页（真实动态数据、深色模式自适应、横竖封面智能适配） - 科技蓝紫版 v4.2.1
 *
 * 使用说明：
 * 1. 可由九流WP助手加载，也可单独替换子比主题 pages/download.php；
 * 2. 仅改造显示层，保留 zibpay 原有权限验证与下载逻辑；
 * 4. 自动跟随子比 body.dark-theme，无额外 JavaScript 监听与轮询；
 * 3. 推荐放入子主题同路径 pages/download.php，避免主题更新覆盖。
 */

if (empty($_GET['post'])) {
    get_header();
    get_template_part('template/content-404');
    get_footer();
    exit;
}

$post_id = absint(wp_unslash($_GET['post']));
$source_post = get_post($post_id);
$pay_meta = get_post_meta($post_id, 'posts_zibpay', true);

if (!$source_post || 'publish' !== $source_post->post_status || empty($pay_meta['pay_type']) || '2' !== (string) $pay_meta['pay_type']) {
    get_header();
    get_template_part('template/content-404');
    get_footer();
    exit;
}



/**
 * ===== 九流网络资源下载页 v4.2：真实动态数据与状态校验辅助函数 =====
 * 这些函数只读取 WordPress、子比主题与当前商品已有数据，不访问第三方接口。
 */
if (!function_exists('jldv4_trim_text')) {
    function jldv4_trim_text($text, $length = 150)
    {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes((string) $text))));
        if (!$text) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8') . '…' : $text;
        }
        return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
    }
}

if (!function_exists('jldv4_get_resource_excerpt')) {
    function jldv4_get_resource_excerpt($post_id, $length = 150)
    {
        $excerpt = get_post_field('post_excerpt', $post_id);
        if (!$excerpt) {
            $excerpt = get_post_field('post_content', $post_id);
        }
        return jldv4_trim_text($excerpt, $length);
    }
}

if (!function_exists('jldv4_get_download_intel')) {
    function jldv4_get_download_intel($download_items)
    {
        $formats = array();
        $secrets = array();
        $hosts = array();
        $format_aliases = array(
            'jpeg' => 'JPG', 'jpg' => 'JPG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP',
            'tif' => 'TIFF', 'tiff' => 'TIFF', 'psd' => 'PSD', 'psb' => 'PSB', 'ai' => 'AI',
            'eps' => 'EPS', 'svg' => 'SVG', 'pdf' => 'PDF', 'zip' => 'ZIP', 'rar' => 'RAR',
            '7z' => '7Z', 'tar' => 'TAR', 'gz' => 'GZ', 'mp4' => 'MP4', 'mov' => 'MOV',
            'mkv' => 'MKV', 'mp3' => 'MP3', 'wav' => 'WAV', 'doc' => 'DOC', 'docx' => 'DOCX',
            'ppt' => 'PPT', 'pptx' => 'PPTX', 'xls' => 'XLS', 'xlsx' => 'XLSX', 'txt' => 'TXT'
        );

        foreach ((array) $download_items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $link = !empty($item['link']) ? trim((string) $item['link']) : '';
            if ($link) {
                $parts = wp_parse_url($link);
                if (!empty($parts['host'])) {
                    $hosts[] = strtolower($parts['host']);
                }
                $path = !empty($parts['path']) ? rawurldecode($parts['path']) : '';
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($extension && isset($format_aliases[$extension])) {
                    $formats[] = $format_aliases[$extension];
                } elseif ($extension && strlen($extension) <= 8 && preg_match('/^[a-z0-9]+$/i', $extension)) {
                    $formats[] = strtoupper($extension);
                }
            }

            if (!empty($item['copy_val'])) {
                $label = !empty($item['copy_key']) ? jldv4_trim_text($item['copy_key'], 22) : '';
                if (!$label && !empty($item['more'])) {
                    $label = jldv4_trim_text($item['more'], 22);
                }
                if (!$label) {
                    $label = '补充信息';
                }
                $secrets[] = array(
                    'label' => $label,
                    'value' => (string) $item['copy_val'],
                    'index' => (int) $index + 1,
                );
            }
        }

        $formats = array_values(array_unique(array_filter($formats)));
        $hosts = array_values(array_unique(array_filter($hosts)));

        $storage_label = '站内安全交付';
        if ($hosts) {
            $is_openlist = false;
            foreach ($hosts as $host) {
                if (false !== strpos($host, 'openlist')) {
                    $is_openlist = true;
                    break;
                }
            }
            $storage_label = $is_openlist ? 'OpenList 云端交付' : (count($hosts) > 1 ? '多线路云端交付' : '云端资源交付');
        }

        return array(
            'formats' => $formats,
            'secrets' => $secrets,
            'hosts' => $hosts,
            'storage_label' => $storage_label,
        );
    }
}

if (!function_exists('jldv4_get_resource_attributes')) {
    function jldv4_get_resource_attributes($pay_meta, $formats, $download_count, $modified_date, $storage_label)
    {
        $attributes = array();
        $used_keys = array();
        if (!empty($pay_meta['attributes']) && is_array($pay_meta['attributes'])) {
            foreach ($pay_meta['attributes'] as $attribute) {
                $key = !empty($attribute['key']) ? trim(wp_strip_all_tags((string) $attribute['key'])) : '';
                $value = !empty($attribute['value']) ? trim((string) $attribute['value']) : '';
                if ($key && $value) {
                    $attributes[] = array('key' => $key, 'value' => $value, 'html' => true);
                    $used_keys[] = strtolower($key);
                }
            }
        }

        $fallbacks = array(
            array('key' => '文件格式', 'value' => $formats ? implode(' / ', $formats) : '数字资源'),
            array('key' => '下载线路', 'value' => $download_count ? $download_count . ' 条' : '权限解锁后显示'),
            array('key' => '交付方式', 'value' => $storage_label),
            array('key' => '最近更新', 'value' => $modified_date),
        );
        foreach ($fallbacks as $fallback) {
            if (!in_array(strtolower($fallback['key']), $used_keys, true)) {
                $attributes[] = array('key' => $fallback['key'], 'value' => $fallback['value'], 'html' => false);
            }
        }
        return array_slice($attributes, 0, 8);
    }
}

if (!function_exists('jldv4_get_hot_resource_ids')) {
    function jldv4_get_hot_resource_ids($exclude_id = 0, $limit = 4)
    {
        $cache_key = 'jldv4_hot_resource_ids';
        $ids = get_transient($cache_key);
        if (!is_array($ids)) {
            $common = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => max(10, $limit + 4),
                'fields' => 'ids',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                'meta_query' => array(
                    array(
                        'key' => 'posts_zibpay',
                        'value' => 's:8:"pay_type";s:1:"2"',
                        'compare' => 'LIKE',
                    ),
                ),
            );
            $ids = get_posts(array_merge($common, array(
                'meta_key' => 'views',
                'orderby' => 'meta_value_num',
                'order' => 'DESC',
            )));
            if (!$ids) {
                $ids = get_posts(array_merge($common, array('orderby' => 'date', 'order' => 'DESC')));
            }
            $ids = array_values(array_unique(array_map('absint', (array) $ids)));
            set_transient($cache_key, $ids, 15 * MINUTE_IN_SECONDS);
        }

        $ids = array_values(array_diff(array_map('absint', $ids), array(absint($exclude_id))));
        if (count($ids) < $limit) {
            $more_ids = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $limit * 2,
                'fields' => 'ids',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'post__not_in' => array_merge($ids, array(absint($exclude_id))),
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => 'posts_zibpay',
                        'value' => 's:8:"pay_type";s:1:"2"',
                        'compare' => 'LIKE',
                    ),
                ),
            ));
            $ids = array_values(array_unique(array_merge($ids, array_map('absint', (array) $more_ids))));
        }
        return array_slice($ids, 0, max(1, absint($limit)));
    }
}

$paid_obj = zibpay_is_paid($post_id);
$is_paid = !empty($paid_obj);
$needs_login = false;

$posts_title = get_the_title($post_id) . zib_get_post_meta($post_id, 'subtitle', true);
$pay_title = !empty($pay_meta['pay_title']) ? $pay_meta['pay_title'] : $posts_title;
$pay_doc_raw = !empty($pay_meta['pay_doc']) ? $pay_meta['pay_doc'] : '';
$pay_details_raw = !empty($pay_meta['pay_details']) ? $pay_meta['pay_details'] : '';
$pay_extra_hide = !empty($pay_meta['pay_extra_hide']) ? $pay_meta['pay_extra_hide'] : '';
$source_url = get_permalink($post_id);
$purchase_url = $source_url . '#posts-pay';
$membership_url = home_url('/join-vip/');
/**
 * ===== 左上角资源封面：无特色图片时的回退模式 =====
 *
 * 已设置“特色图片”的文章：始终优先显示特色图片，不受下面模式影响。
 * 没有特色图片的文章：修改 $jld_cover_fallback_mode 的数字即可切换。
 *
 * 1 = 正文首张图
 *     按文章正文中的实际出现顺序，读取第一张可用图片。
 *
 * 2 = 正文/附件中的任意一张图
 *     收集正文图片、图库图片和该文章上传的附件图片，再按文章 ID
 *     稳定选择其中一张；同一篇文章不会因为刷新页面而不断更换图片。
 *
 * 3 = 默认图
 *     不读取正文图片，直接显示下方配置的默认图片。
 *
 * 当模式 1 或模式 2 找不到任何可用图片时，也会自动使用默认图，
 * 因此左上角不会再因为没有特色图片而留空。
 */
$jld_cover_fallback_mode = 1; // 只改这里：可填写 1、2 或 3。

/**
 * 自定义默认图地址：
 * - 留空：自动读取与 download.php 同目录下的 jld-download-default.svg；
 * - 也可以填写媒体库图片的完整 URL，例如：
 *   https://你的域名/wp-content/uploads/2026/08/download-default.webp
 */
$jld_default_cover_custom_url = '';
$jld_default_cover_filename = 'jld-download-default.svg';

if (!function_exists('jldv4_normalize_cover_url')) {
    function jldv4_normalize_cover_url($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        $url = trim($url, " \t\n\r\0\x0B\"'");
        if (!$url || 0 === stripos($url, 'data:') || 0 === stripos($url, 'blob:')) {
            return '';
        }

        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (0 === strpos($url, '/')) {
            $url = home_url($url);
        }

        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        // 排除常见透明占位图，防止懒加载文章误把占位图当作封面。
        if (preg_match('#(?:transparent|spacer|blank|loading)(?:[-_.][^/?#]*)?\.(?:gif|png|webp)(?:[?#]|$)#i', $url)) {
            return '';
        }

        return esc_url_raw($url);
    }
}

if (!function_exists('jldv4_get_attachment_cover_data')) {
    function jldv4_get_attachment_cover_data($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        $result = array('id' => 0, 'url' => '', 'width' => 0, 'height' => 0);
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return $result;
        }

        foreach (array('large', 'full') as $size) {
            $data = wp_get_attachment_image_src($attachment_id, $size);
            if (!empty($data[0])) {
                $result['id'] = $attachment_id;
                $result['url'] = jldv4_normalize_cover_url($data[0]);
                $result['width'] = !empty($data[1]) ? (int) $data[1] : 0;
                $result['height'] = !empty($data[2]) ? (int) $data[2] : 0;
                if ($result['url']) {
                    return $result;
                }
            }
        }

        $original_url = wp_get_attachment_url($attachment_id);
        $result['url'] = jldv4_normalize_cover_url($original_url);
        if ($result['url']) {
            $result['id'] = $attachment_id;
        }
        return $result;
    }
}

if (!function_exists('jldv4_extract_srcset_cover_url')) {
    function jldv4_extract_srcset_cover_url($srcset)
    {
        $best_url = '';
        $best_width = -1;
        foreach (explode(',', (string) $srcset) as $candidate) {
            $candidate = trim($candidate);
            if (!$candidate) {
                continue;
            }
            $parts = preg_split('/\s+/', $candidate);
            $url = !empty($parts[0]) ? jldv4_normalize_cover_url($parts[0]) : '';
            if (!$url) {
                continue;
            }
            $width = 0;
            if (!empty($parts[1]) && preg_match('/^(\d+)w$/i', $parts[1], $match)) {
                $width = (int) $match[1];
            }
            if ($width >= $best_width) {
                $best_width = $width;
                $best_url = $url;
            }
        }
        return $best_url;
    }
}

if (!function_exists('jldv4_get_content_cover_candidates')) {
    function jldv4_get_content_cover_candidates($post_id, $include_attached = false)
    {
        $post_id = absint($post_id);
        $content = (string) get_post_field('post_content', $post_id);
        $candidates = array();

        $push_candidate = static function ($url, $attachment_id = 0) use (&$candidates) {
            $url = jldv4_normalize_cover_url($url);
            if (!$url) {
                return;
            }
            $key = strtolower($url);
            if (!isset($candidates[$key])) {
                $candidates[$key] = array(
                    'id' => absint($attachment_id),
                    'url' => $url,
                    'width' => 0,
                    'height' => 0,
                );
            }
        };

        if ($content && preg_match_all('/<img\b[^>]*>/i', $content, $image_tags)) {
            foreach ($image_tags[0] as $image_tag) {
                $attachment_id = 0;
                if (preg_match('/\bwp-image-(\d+)\b/i', $image_tag, $id_match)) {
                    $attachment_id = absint($id_match[1]);
                } elseif (preg_match('/\bdata-(?:attachment-)?id=["\'](\d+)["\']/i', $image_tag, $id_match)) {
                    $attachment_id = absint($id_match[1]);
                }

                // 优先读取真实懒加载地址，最后才读取 src，避免抓到透明占位图。
                $image_url = '';
                foreach (array('data-src', 'data-lazy-src', 'data-original', 'data-url', 'src') as $attribute) {
                    if (preg_match('/\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $image_tag, $url_match)) {
                        $image_url = jldv4_normalize_cover_url($url_match[1]);
                        if ($image_url) {
                            break;
                        }
                    }
                }

                if (!$image_url && preg_match('/\bsrcset=["\']([^"\']+)["\']/i', $image_tag, $srcset_match)) {
                    $image_url = jldv4_extract_srcset_cover_url($srcset_match[1]);
                }

                // 能识别到媒体库附件 ID 时，优先使用 large/full，避免拿到正文小缩略图。
                if ($attachment_id) {
                    $attachment_data = jldv4_get_attachment_cover_data($attachment_id);
                    if ($attachment_data['url']) {
                        $push_candidate($attachment_data['url'], $attachment_id);
                        continue;
                    }
                }

                if ($image_url) {
                    $push_candidate($image_url, 0);
                }
            }
        }

        // 支持文章中的 WordPress 图库短代码。
        if ($content && preg_match_all('/\[gallery\b[^\]]*\bids=["\']([^"\']+)["\'][^\]]*\]/i', $content, $gallery_matches)) {
            foreach ($gallery_matches[1] as $gallery_ids) {
                foreach (array_filter(array_map('absint', explode(',', $gallery_ids))) as $gallery_id) {
                    $attachment_data = jldv4_get_attachment_cover_data($gallery_id);
                    if ($attachment_data['url']) {
                        $push_candidate($attachment_data['url'], $gallery_id);
                    }
                }
            }
        }

        // 模式 2 额外加入“上传到当前文章”的图片附件。
        if ($include_attached) {
            $attached_images = get_attached_media('image', $post_id);
            foreach ((array) $attached_images as $attached_image) {
                $attachment_data = jldv4_get_attachment_cover_data($attached_image->ID);
                if ($attachment_data['url']) {
                    $push_candidate($attachment_data['url'], $attached_image->ID);
                }
            }
        }

        return array_values($candidates);
    }
}

if (!function_exists('jldv4_get_default_cover_url')) {
    function jldv4_get_default_cover_url($custom_url, $filename)
    {
        $custom_url = jldv4_normalize_cover_url($custom_url);
        if ($custom_url) {
            return $custom_url;
        }

        $relative_path = '/pages/' . ltrim((string) $filename, '/');
        if (file_exists(get_stylesheet_directory() . $relative_path)) {
            return trailingslashit(get_stylesheet_directory_uri()) . 'pages/' . ltrim((string) $filename, '/');
        }
        return trailingslashit(get_template_directory_uri()) . 'pages/' . ltrim((string) $filename, '/');
    }
}

$jlwa_download_options = isset($jlwa_download_page_options) && is_array($jlwa_download_page_options)
    ? $jlwa_download_page_options
    : array();
$jld_cover_strategy = isset($jlwa_download_options['cover_mode'])
    ? sanitize_key((string) $jlwa_download_options['cover_mode'])
    : 'auto';
if (!in_array($jld_cover_strategy, array('auto', 'featured', 'first', 'random', 'default'), true)) {
    $jld_cover_strategy = 'auto';
}
$jld_default_cover_custom_url = !empty($jlwa_download_options['default_image_url'])
    ? esc_url_raw((string) $jlwa_download_options['default_image_url'])
    : '';
$jld_builtin_default_cover_url = defined('JLWA_PLUGIN_URL')
    ? trailingslashit(JLWA_PLUGIN_URL) . 'assets/default-download-cover.svg'
    : '';

if (!function_exists('jldv4_normalize_cover_url')) {
    function jldv4_normalize_cover_url($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        $url = trim($url, " \t\n\r\0\x0B\"'");
        if (!$url || 0 === stripos($url, 'data:') || 0 === stripos($url, 'blob:')) {
            return '';
        }
        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (0 === strpos($url, '/')) {
            $url = home_url($url);
        }
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        if (preg_match('#(?:transparent|spacer|blank|loading|placeholder|spinner|pixel)(?:[-_.][^/?#]*)?\.(?:gif|png|webp|svg)(?:[?#]|$)#i', $url)) {
            return '';
        }
        return esc_url_raw($url);
    }
}

if (!function_exists('jldv4_get_attachment_cover_data')) {
    function jldv4_get_attachment_cover_data($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        $result = array('id' => 0, 'url' => '', 'width' => 0, 'height' => 0);
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return $result;
        }
        foreach (array('large', 'full') as $size) {
            $data = wp_get_attachment_image_src($attachment_id, $size);
            if (!empty($data[0])) {
                $url = jldv4_normalize_cover_url($data[0]);
                if ($url) {
                    return array(
                        'id' => $attachment_id,
                        'url' => $url,
                        'width' => !empty($data[1]) ? (int) $data[1] : 0,
                        'height' => !empty($data[2]) ? (int) $data[2] : 0,
                    );
                }
            }
        }
        $url = jldv4_normalize_cover_url(wp_get_attachment_url($attachment_id));
        if ($url) {
            $result['id'] = $attachment_id;
            $result['url'] = $url;
        }
        return $result;
    }
}

if (!function_exists('jldv4_extract_srcset_cover_url')) {
    function jldv4_extract_srcset_cover_url($srcset)
    {
        $best_url = '';
        $best_width = -1;
        foreach (explode(',', (string) $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            $url = !empty($parts[0]) ? jldv4_normalize_cover_url($parts[0]) : '';
            if (!$url) {
                continue;
            }
            $width = 0;
            if (!empty($parts[1]) && preg_match('/^(\d+)w$/i', $parts[1], $match)) {
                $width = (int) $match[1];
            }
            if ($width >= $best_width) {
                $best_width = $width;
                $best_url = $url;
            }
        }
        return $best_url;
    }
}

if (!function_exists('jldv4_get_content_cover_candidates')) {
    function jldv4_get_content_cover_candidates($post_id, $include_attached = false)
    {
        $content = (string) get_post_field('post_content', absint($post_id));
        $candidates = array();
        $push = static function ($url, $attachment_id = 0, $width = 0, $height = 0) use (&$candidates) {
            $url = jldv4_normalize_cover_url($url);
            if (!$url) {
                return;
            }
            $key = strtolower($url);
            if (!isset($candidates[$key])) {
                $candidates[$key] = array(
                    'id' => absint($attachment_id),
                    'url' => $url,
                    'width' => absint($width),
                    'height' => absint($height),
                );
            }
        };

        if ($content && preg_match_all('/<img\b[^>]*>/i', $content, $image_tags)) {
            foreach ($image_tags[0] as $image_tag) {
                if (preg_match('/\bclass=["\'][^"\']*(?:avatar|emoji|logo|icon|placeholder|loading|spinner|pixel)[^"\']*["\']/i', $image_tag)) {
                    continue;
                }
                $attachment_id = 0;
                if (preg_match('/\bwp-image-(\d+)\b/i', $image_tag, $id_match)) {
                    $attachment_id = absint($id_match[1]);
                } elseif (preg_match('/\bdata-(?:attachment-)?id=["\'](\d+)["\']/i', $image_tag, $id_match)) {
                    $attachment_id = absint($id_match[1]);
                }
                if ($attachment_id) {
                    $data = jldv4_get_attachment_cover_data($attachment_id);
                    if ($data['url']) {
                        $push($data['url'], $attachment_id, $data['width'], $data['height']);
                        continue;
                    }
                }
                $image_url = '';
                foreach (array('data-src', 'data-lazy-src', 'data-original', 'data-url', 'src') as $attribute) {
                    if (preg_match('/\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $image_tag, $url_match)) {
                        $image_url = jldv4_normalize_cover_url($url_match[1]);
                        if ($image_url) {
                            break;
                        }
                    }
                }
                if (!$image_url && preg_match('/\bsrcset=["\']([^"\']+)["\']/i', $image_tag, $srcset_match)) {
                    $image_url = jldv4_extract_srcset_cover_url($srcset_match[1]);
                }
                $width = preg_match('/\bwidth=["\']?(\d+)/i', $image_tag, $m) ? (int) $m[1] : 0;
                $height = preg_match('/\bheight=["\']?(\d+)/i', $image_tag, $m) ? (int) $m[1] : 0;
                if ($image_url) {
                    $push($image_url, 0, $width, $height);
                }
            }
        }

        if ($content && preg_match_all('/\[gallery\b[^\]]*\bids=["\']([^"\']+)["\'][^\]]*\]/i', $content, $gallery_matches)) {
            foreach ($gallery_matches[1] as $gallery_ids) {
                foreach (array_filter(array_map('absint', explode(',', $gallery_ids))) as $gallery_id) {
                    $data = jldv4_get_attachment_cover_data($gallery_id);
                    if ($data['url']) {
                        $push($data['url'], $gallery_id, $data['width'], $data['height']);
                    }
                }
            }
        }

        if ($include_attached) {
            foreach ((array) get_attached_media('image', absint($post_id)) as $attached_image) {
                $data = jldv4_get_attachment_cover_data($attached_image->ID);
                if ($data['url']) {
                    $push($data['url'], $attached_image->ID, $data['width'], $data['height']);
                }
            }
        }
        return array_values($candidates);
    }
}

if (!function_exists('jldv4_get_default_cover_url')) {
    function jldv4_get_default_cover_url($custom_url, $builtin_url = '')
    {
        $custom_url = jldv4_normalize_cover_url($custom_url);
        if ($custom_url) {
            return $custom_url;
        }
        $builtin_url = jldv4_normalize_cover_url($builtin_url);
        if ($builtin_url) {
            return $builtin_url;
        }
        $relative_path = '/pages/jld-download-default.svg';
        if (file_exists(get_stylesheet_directory() . $relative_path)) {
            return trailingslashit(get_stylesheet_directory_uri()) . 'pages/jld-download-default.svg';
        }
        return trailingslashit(get_template_directory_uri()) . 'pages/jld-download-default.svg';
    }
}

$cover_id = 0;
$cover_url = '';
$cover_width = 0;
$cover_height = 0;
$selected_cover = array('id' => 0, 'url' => '', 'width' => 0, 'height' => 0);

if (in_array($jld_cover_strategy, array('auto', 'featured'), true)) {
    $featured_id = get_post_thumbnail_id($post_id);
    if ($featured_id) {
        $selected_cover = jldv4_get_attachment_cover_data($featured_id);
    }
}

if (!$selected_cover['url'] && in_array($jld_cover_strategy, array('auto', 'first'), true)) {
    $content_candidates = jldv4_get_content_cover_candidates($post_id, false);
    if ($content_candidates) {
        $selected_cover = $content_candidates[0];
    }
}

if (!$selected_cover['url'] && in_array($jld_cover_strategy, array('auto', 'random'), true)) {
    $all_candidates = jldv4_get_content_cover_candidates($post_id, true);
    if ($all_candidates) {
        $stable_index = abs((int) crc32(get_current_blog_id() . ':' . absint($post_id))) % count($all_candidates);
        $selected_cover = $all_candidates[$stable_index];
    }
}

if (!$selected_cover['url']) {
    $selected_cover = array(
        'id' => 0,
        'url' => jldv4_get_default_cover_url($jld_default_cover_custom_url, $jld_builtin_default_cover_url),
        'width' => 1200,
        'height' => 900,
    );
}

$cover_id = !empty($selected_cover['id']) ? absint($selected_cover['id']) : 0;
$cover_url = !empty($selected_cover['url']) ? (string) $selected_cover['url'] : '';
$cover_width = !empty($selected_cover['width']) ? (int) $selected_cover['width'] : 0;
$cover_height = !empty($selected_cover['height']) ? (int) $selected_cover['height'] : 0;

if ($cover_url && (!$cover_width || !$cover_height)) {
    if (!$cover_id && function_exists('attachment_url_to_postid')) {
        $cover_id = absint(attachment_url_to_postid($cover_url));
    }
    if ($cover_id) {
        $attachment_data = jldv4_get_attachment_cover_data($cover_id);
        $cover_width = $attachment_data['width'];
        $cover_height = $attachment_data['height'];
    }
}

$cover_orientation = 'is-landscape';
if ($cover_width > 0 && $cover_height > 0) {
    $cover_ratio = $cover_width / $cover_height;
    if ($cover_ratio < 0.82) {
        $cover_orientation = 'is-portrait';
    } elseif ($cover_ratio <= 1.22) {
        $cover_orientation = 'is-square';
    }
}

$cover_alt = $cover_id ? trim((string) get_post_meta($cover_id, '_wp_attachment_image_alt', true)) : '';
if (!$cover_alt) {
    $cover_alt = wp_strip_all_tags($pay_title);
}
$cover_image_html = $cover_url
    ? '<img class="jld-cover-image" src="' . esc_url($cover_url) . '" alt="' . esc_attr($cover_alt) . '" loading="eager" fetchpriority="high" decoding="async" referrerpolicy="no-referrer-when-downgrade">'
    : '';


$download_items = function_exists('zibpay_get_post_down_array') ? zibpay_get_post_down_array($pay_meta) : array();
$download_count = is_array($download_items) ? count($download_items) : 0;
$modified_date = get_the_modified_date('Y-m-d', $post_id);
$category_names = wp_get_post_terms($post_id, 'category', array('fields' => 'names'));
$category_name = !empty($category_names) && !is_wp_error($category_names) ? $category_names[0] : '数字资源';

$download_intel = jldv4_get_download_intel($download_items);
$file_formats = $download_intel['formats'];
$secret_items = $download_intel['secrets'];
$storage_label = $download_intel['storage_label'];
$resource_excerpt = jldv4_get_resource_excerpt($post_id, 165);
$pay_doc = $pay_doc_raw ? $pay_doc_raw : '<p>' . esc_html($resource_excerpt ? $resource_excerpt : '该资源已接入九流网络站内交付系统，购买状态与下载权限均由系统自动核验。') . '</p>';
$format_text = $file_formats ? implode(' / ', $file_formats) : '数字资源';
$pay_details = $pay_details_raw ? $pay_details_raw : '<p>当前资源共 ' . esc_html((string) max(1, $download_count)) . ' 条下载线路，识别到的文件格式为 ' . esc_html($format_text) . '。下载按钮仅显示商品后台真实配置的线路。</p>';
$resource_attributes = jldv4_get_resource_attributes($pay_meta, $file_formats, $download_count, $modified_date, $storage_label);

$current_user_id = get_current_user_id();
$current_user = $current_user_id ? wp_get_current_user() : false;
$user_logged_in = $current_user_id && $current_user && $current_user->exists();
$user_name = $user_logged_in ? $current_user->display_name : '访客用户';
$user_avatar_html = $user_logged_in ? get_avatar($current_user_id, 96, '', $user_name, array('class' => 'jld-user-avatar-img', 'loading' => 'lazy')) : '<span class="jld-user-avatar-guest"><i class="fa fa-user-o" aria-hidden="true"></i></span>';
$user_vip_level = $user_logged_in && function_exists('zib_get_user_vip_level') ? (int) zib_get_user_vip_level($current_user_id) : 0;
$user_is_member = $user_logged_in && $user_vip_level > 0;
$user_vip_name = $user_vip_level ? (string) _pz('pay_user_vip_' . $user_vip_level . '_name', 'VIP会员') : ($user_logged_in ? '普通用户' : '尚未登录');
$user_vip_expiry = $user_vip_level && function_exists('zib_get_user_vip_exp_date_text') ? zib_get_user_vip_exp_date_text($current_user_id) : '';
$user_download_limit = $user_logged_in && function_exists('zibpay_get_user_free_down_limit') ? (int) zibpay_get_user_free_down_limit($current_user_id) : 0;
$user_downloaded = $user_logged_in && function_exists('zibpay_get_user_free_downloaded_number') ? (int) zibpay_get_user_free_downloaded_number($current_user_id) : 0;
$user_remaining = $user_download_limit > 0 ? max(0, $user_download_limit - $user_downloaded) : 0;
$user_quota_percent = $user_download_limit > 0 ? min(100, round(($user_downloaded / max(1, $user_download_limit)) * 100, 2)) : 0;
$user_center_url = $user_logged_in && function_exists('zib_get_user_center_url') ? zib_get_user_center_url() : '';
$hot_resource_ids = jldv4_get_hot_resource_ids($post_id, 4);


$paid_type = $is_paid && !empty($paid_obj['paid_type']) ? (string) $paid_obj['paid_type'] : '';
$is_single_purchase = ('paid' === $paid_type);
$is_member_free_access = in_array($paid_type, array('vip1_free', 'vip2_free'), true);
$is_public_free_access = ('free' === $paid_type);
$is_any_free_access = $is_public_free_access || $is_member_free_access;
$is_trial_access = ('trial' === $paid_type);
$is_guest_purchase = $is_single_purchase && !$user_logged_in;
$has_daily_limit = $user_logged_in && $user_download_limit > 0;
$is_quota_exhausted = $is_any_free_access && $has_daily_limit && $user_remaining <= 0;
$paid_name = $is_paid && function_exists('zibpay_get_paid_type_name') ? zibpay_get_paid_type_name($paid_type) : '';
if (!$paid_name && $is_paid) {
    $paid_name = '已获得权限';
}

$download_html = '';
$login_html = '';
if ($is_paid) {
    // 继续调用子比原生下载按钮函数；额度超限后的“明日再下载/立即购买”也由它处理。
    $download_html = zibpay_get_post_down_buts($pay_meta, $paid_type, $post_id);
    if ($is_public_free_access && _pz('pay_free_logged_show') && !$user_logged_in) {
        $needs_login = true;
        $download_html = '<div class="jld-login-notice"><i class="fa fa-user-circle-o" aria-hidden="true"></i><div><b>免费资源，请先登录</b><span>登录后系统会自动恢复下载权限。</span></div></div>';
        $login_html = zib_get_user_singin_page_box('jld-login-box', 'Hi！请先登录');
    }
}

// 按真实访问状态生成统一文案，避免“已购买却显示不限量”等误导。
$status_title = '尚未获得下载权限';
$status_desc = '请返回原文章完成购买，支付成功后刷新本页即可下载。';
$status_icon = 'fa-lock';
$status_seal_icon = 'fa-exclamation-circle';
$status_seal_text = '等待购买';
$access_class = 'is-locked';

if ($is_paid) {
    $access_class = 'is-paid';
    $status_title = '下载权限已解锁';
    $status_desc = '系统已确认您的访问权限，请在下方选择合适的下载线路。';
    $status_icon = 'fa-check';
    $status_seal_icon = 'fa-shield';
    $status_seal_text = '系统已验证';

    if ($needs_login) {
        $access_class .= ' is-login-required';
        $status_title = '登录后即可下载';
        $status_desc = '这是免费资源，登录网站账户后即可获取下载链接。';
        $status_icon = 'fa-user-circle-o';
        $status_seal_icon = 'fa-sign-in';
        $status_seal_text = '需要登录';
    } elseif ($is_quota_exhausted) {
        $access_class .= ' is-quota-exhausted';
        $status_title = '今日免费额度已用完';
        $status_desc = !empty($pay_meta['download_limit_over_price'])
            ? '今日免费资源额度已经用完，您可以等待明日恢复，或按页面提示单独购买本资源。'
            : '今日免费资源额度已经用完，请明日额度恢复后再下载。';
        $status_icon = 'fa-clock-o';
        $status_seal_icon = 'fa-hourglass-end';
        $status_seal_text = '明日恢复';
    } elseif ($is_guest_purchase) {
        $access_class .= ' is-guest-purchase';
        $status_title = '游客订单已验证';
        $status_desc = '系统已通过当前浏览器的订单凭证确认购买，请及时下载并妥善保存文件。';
        $status_icon = 'fa-shopping-bag';
        $status_seal_icon = 'fa-check-circle';
        $status_seal_text = '订单有效';
    } elseif ($is_single_purchase) {
        $access_class .= ' is-single-purchase';
        $status_title = '购买订单已验证';
        $status_desc = '当前资源来自单独购买订单，不会扣减会员每日免费资源额度。';
        $status_icon = 'fa-shopping-bag';
        $status_seal_text = '订单有效';
    } elseif ($is_member_free_access) {
        $access_class .= ' is-member-free';
        $status_title = '会员下载权限已解锁';
        $status_desc = '当前资源由会员权益解锁，本次下载按网站的每日免费资源额度规则计算。';
        $status_icon = 'fa-diamond';
    } elseif ($is_public_free_access) {
        $access_class .= ' is-public-free';
        $status_title = '免费资源可下载';
        $status_desc = '当前资源无需单独购买；如后台启用了普通用户每日额度，将按该额度规则计算。';
        $status_icon = 'fa-gift';
    } elseif ($is_trial_access) {
        $access_class .= ' is-trial';
        $status_title = '试用权限已生效';
        $status_desc = '当前资源处于有效试用权限内，请在权限有效期内完成下载。';
        $status_icon = 'fa-hourglass-half';
    }
}

$page_id = get_queried_object_id();
// 默认使用页面内部的账户/热门/公告侧栏，避免与主题全局侧栏重复挤压。
// 如确实需要保留主题侧栏，可给“资源下载”页面添加自定义字段：jld_keep_theme_sidebar = 1。
$keep_theme_sidebar = '1' === (string) get_post_meta($page_id, 'jld_keep_theme_sidebar', true);

$site_notice_title = trim((string) get_post_meta($page_id, 'jld_download_notice_title', true));
$site_notice = trim((string) get_post_meta($page_id, 'jld_download_notice', true));
if (!$site_notice_title) {
    $site_notice_title = '网站公告';
}
if (!$site_notice) {
    $site_notice = '资源链接如有失效，或网站充值、支付后未及时到账，请先刷新订单状态；仍未解决时再联系 Telegram 客服核查。';
}

$content_style = zib_get_page_content_style();
$container_class = 'container';
$container_class .= $content_style ? ' page-content-' . $content_style : '';
$widgets_register_container = array();
if (get_post_meta($page_id, 'widgets_register', true)) {
    $widgets_register_container = (array) get_post_meta($page_id, 'widgets_register_container', true);
}

get_header();
if ($widgets_register_container && in_array('top_fluid', $widgets_register_container, true)) {
    echo '<div class="fluid-widget-wrap">';
    dynamic_sidebar('page_top_fluid_' . $page_id);
    echo '</div>';
}
?>
<style>

.jld-shell.jld-no-theme-sidebar .content-layout{margin-left:0!important;margin-right:0!important}.jld-shell.jld-no-theme-sidebar .content-wrap{float:none;width:100%}.jld-shell .jld-article{padding:0!important;overflow:hidden;border-radius:18px}
.jld-page{--jld-radius:18px;--jld-gap:22px;position:relative;overflow:hidden;color:var(--main-color);font-size:15px;line-height:1.72;container-type:inline-size;container-name:jld-download}
.jld-page *{box-sizing:border-box}
.jld-page a{text-decoration:none}
.jld-hero{position:relative;padding:28px 32px 34px;overflow:hidden}
.jld-decoration{position:absolute;pointer-events:none;border-radius:999px;filter:blur(2px)}
.jld-breadcrumb{position:relative;z-index:2;display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:24px;font-size:13px}
.jld-breadcrumb a,.jld-breadcrumb span{display:inline-flex;align-items:center;gap:6px}
.jld-hero-grid{position:relative;z-index:2;display:grid;grid-template-columns:minmax(220px,310px) minmax(0,1fr);gap:34px;align-items:center}
.jld-cover{position:relative;width:100%;min-height:0;aspect-ratio:4/3;border-radius:22px;overflow:hidden;isolation:isolate;box-shadow:0 22px 55px rgba(18,31,61,.22)}
.jld-cover.is-landscape{aspect-ratio:4/3}
.jld-cover.is-square{aspect-ratio:1/1;max-width:282px;justify-self:center}
.jld-cover.is-portrait{aspect-ratio:4/5;max-width:258px;justify-self:center}
.jld-cover-backdrop{position:absolute;z-index:0;inset:-24px;background-size:cover;background-position:center;filter:blur(22px) saturate(1.12);transform:scale(1.1);opacity:.46}
.jld-cover-media{position:absolute;z-index:1;inset:0;display:flex;align-items:center;justify-content:center;padding:10px}
.jld-cover-image{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain!important;object-position:center;border-radius:14px;filter:drop-shadow(0 12px 20px rgba(8,16,38,.18))}
.jld-cover:after{content:"";position:absolute;z-index:2;inset:0;pointer-events:none;background:linear-gradient(145deg,rgba(10,19,42,.02),rgba(10,19,42,.24))}
.jld-cover.no-cover{display:flex;align-items:center;justify-content:center;max-width:none;aspect-ratio:4/3}
.dark-theme .jld-page .jld-cover-backdrop{opacity:.38}
.jld-cover-label{position:absolute;z-index:4;top:16px;left:16px;display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.04em;backdrop-filter:blur(12px)}
.jld-cover-mark{position:absolute;z-index:4;right:20px;bottom:18px;width:74px;height:74px;border-radius:22px;display:flex;align-items:center;justify-content:center;font-size:32px;backdrop-filter:blur(12px)}
.jld-eyebrow{display:flex;align-items:center;gap:10px;font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
.jld-eyebrow span{width:34px;height:3px;border-radius:99px}
.jld-hero-copy h1{margin:12px 0 10px;font-size:clamp(28px,4vw,46px);line-height:1.2;letter-spacing:-.035em}
.jld-hero-copy>p{max-width:760px;margin:0 0 18px;font-size:15px}
.jld-meta-list{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 22px}
.jld-meta-list span{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:650}
.jld-hero-actions,.jld-locked-actions{display:flex;gap:11px;flex-wrap:wrap}
.jld-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 18px;border-radius:12px;font-weight:750;transition:.2s ease;border:1px solid transparent}
.jld-button:hover{transform:translateY(-2px)}
.jld-status-card{position:relative;z-index:3;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:18px;align-items:center;margin:-2px 28px 26px;padding:18px 20px;border-radius:18px}
.jld-status-icon{width:58px;height:58px;border-radius:17px;display:flex;align-items:center;justify-content:center;font-size:23px}
.jld-status-kicker,.jld-panel-kicker{display:block;font-size:10px;font-weight:900;letter-spacing:.16em;text-transform:uppercase}
.jld-status-copy h2{margin:1px 0 2px;font-size:20px;line-height:1.3}
.jld-status-copy p{margin:0;font-size:13px}
.jld-status-seal{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap}
.jld-main-grid{display:grid;grid-template-columns:minmax(0,1.58fr) minmax(280px,.72fr);gap:var(--jld-gap);padding:0 28px 28px}
.jld-side-stack{display:flex;flex-direction:column;gap:var(--jld-gap)}
.jld-panel{border-radius:var(--jld-radius);padding:22px}
.jld-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;padding-bottom:16px}
.jld-panel-heading.compact{margin-bottom:14px;padding-bottom:12px}
.jld-panel-heading h2,.jld-panel-heading h3{display:flex;align-items:center;gap:9px;margin:2px 0 0;line-height:1.3}
.jld-panel-heading h2{font-size:22px}.jld-panel-heading h3{font-size:17px}
.jld-secure-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
.jld-resource-doc{margin-bottom:18px}
.jld-resource-doc>:first-child,.jld-resource-details>:first-child{margin-top:0}.jld-resource-doc>:last-child,.jld-resource-details>:last-child{margin-bottom:0}
.jld-resource-details{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:flex-start;margin-bottom:20px;padding:14px 16px;border-radius:14px;font-size:13px}
.jld-info-icon{width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.jld-download-area{padding:4px 0}
.jld-page .jld-download-area>div>.flex.hh{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:stretch!important}
.jld-page .but-download{display:flex!important;align-items:stretch!important;width:100%;margin:0!important}
.jld-page .but-download>.but,.jld-page .but-download>span{min-width:0!important;margin:0!important;border-radius:12px}
.jld-page .but-download>a:first-child{display:flex!important;align-items:center;justify-content:center;gap:9px;flex:1;min-height:52px;padding:10px 14px!important;font-weight:800;box-shadow:0 8px 20px rgba(16,29,57,.12)}
.jld-page .but-download>a:not(:first-child){display:flex!important;align-items:center;justify-content:center;min-width:72px;margin-left:8px!important;padding:10px!important}
.jld-page .muted-box{border-radius:13px;padding:13px 15px!important}
.jld-hidden-info{margin-top:20px;border-radius:15px;overflow:hidden}
.jld-hidden-heading{display:flex;align-items:center;gap:9px;padding:13px 16px;font-size:13px}
.jld-hidden-content{padding:16px;word-break:break-word}
.jld-hidden-content>:first-child{margin-top:0}.jld-hidden-content>:last-child{margin-bottom:0}
.jld-login-notice{display:flex;align-items:center;gap:13px;padding:15px;border-radius:14px}
.jld-login-notice>i{font-size:28px}.jld-login-notice b,.jld-login-notice span{display:block}.jld-login-notice span{font-size:12px;margin-top:3px}
.jld-login-wrap{margin-top:16px}
.jld-locked-box{text-align:center;padding:28px 16px 18px}
.jld-locked-illustration{width:88px;height:88px;border-radius:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:34px}
.jld-locked-box h3{margin:0 0 7px;font-size:22px}.jld-locked-box p{max-width:610px;margin:0 auto 20px}
.jld-locked-actions{justify-content:center}
.jld-steps{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:16px}
.jld-steps li{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:flex-start}
.jld-steps li>span{width:31px;height:31px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900}
.jld-steps b,.jld-steps small{display:block}.jld-steps b{font-size:14px}.jld-steps small{font-size:12px;line-height:1.55;margin-top:2px}
.jld-help-icon{width:48px;height:48px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:23px;margin-bottom:14px}
.jld-help-panel h3{margin:3px 0 6px;font-size:18px}.jld-help-panel p{margin:0;font-size:13px}
.jld-help-actions{display:grid;grid-template-columns:1fr;gap:9px;margin-top:16px}
.jld-help-actions a{display:flex;align-items:center;justify-content:center;gap:8px;min-height:40px;border-radius:11px;font-size:13px;font-weight:800}
.jld-notice-panel h3{display:flex;align-items:center;gap:8px;margin:0 0 11px;font-size:16px}
.jld-notice-panel ul{margin:0;padding-left:19px}.jld-notice-panel li{font-size:12px;margin:6px 0}
.jld-page-content{margin:0 28px 28px}
@media (max-width:1100px){.jld-main-grid{grid-template-columns:1fr}.jld-side-stack{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.jld-notice-panel{grid-column:1/-1}}
@media (max-width:820px){.jld-hero{padding:22px}.jld-hero-grid{grid-template-columns:1fr;gap:22px}.jld-cover{min-height:0}.jld-cover.is-landscape{aspect-ratio:16/10}.jld-cover.is-square{max-width:340px}.jld-cover.is-portrait{max-width:280px}.jld-status-card{grid-template-columns:auto 1fr;margin:0 18px 20px}.jld-status-seal{grid-column:1/-1;justify-self:start}.jld-main-grid{padding:0 18px 18px}.jld-page-content{margin:0 18px 18px}.jld-side-stack{grid-template-columns:1fr}.jld-page .jld-download-area>div>.flex.hh{grid-template-columns:1fr}}
@media (max-width:560px){.jld-shell .jld-article{border-radius:14px}.jld-hero{padding:18px 16px 22px}.jld-breadcrumb{margin-bottom:17px}.jld-cover{min-height:0;border-radius:17px}.jld-cover.is-landscape{aspect-ratio:16/10}.jld-cover.is-square{max-width:310px}.jld-cover.is-portrait{max-width:250px}.jld-cover-media{padding:8px}.jld-cover-image{border-radius:11px}.jld-hero-copy h1{font-size:27px}.jld-meta-list{gap:7px}.jld-meta-list span{padding:6px 9px}.jld-button{width:100%}.jld-status-card{margin:0 12px 16px;padding:15px;gap:12px}.jld-status-icon{width:49px;height:49px;border-radius:14px}.jld-main-grid{padding:0 12px 12px;gap:14px}.jld-side-stack{gap:14px}.jld-panel{padding:17px;border-radius:15px}.jld-panel-heading{align-items:flex-start}.jld-secure-badge{display:none}.jld-page-content{margin:0 12px 12px}.jld-page .but-download{flex-wrap:wrap}.jld-page .but-download>a:not(:first-child){width:100%;margin:7px 0 0!important}.jld-hidden-heading{align-items:flex-start}}

.jld-tech{--tech-blue:#4f6dff;--tech-purple:#7857ff;--tech-cyan:#18c6d9;background:linear-gradient(180deg,rgba(246,248,255,.96),rgba(238,245,255,.94))}
.jld-tech .jld-hero{color:#fff;background:radial-gradient(circle at 10% 20%,rgba(53,223,231,.32),transparent 30%),radial-gradient(circle at 88% 12%,rgba(174,103,255,.34),transparent 28%),linear-gradient(135deg,#172454 0%,#263d88 45%,#5e48c5 100%)}
.jld-tech .jld-decoration-one{width:280px;height:280px;right:-85px;bottom:-125px;background:rgba(30,221,222,.18);box-shadow:0 0 70px rgba(30,221,222,.32)}
.jld-tech .jld-decoration-two{width:190px;height:190px;left:-80px;top:-70px;background:rgba(133,105,255,.25);box-shadow:0 0 55px rgba(133,105,255,.38)}
.jld-tech .jld-breadcrumb,.jld-tech .jld-breadcrumb a{color:rgba(255,255,255,.76)}
.jld-tech .jld-breadcrumb a:hover{color:#fff}
.jld-tech .jld-cover{border:1px solid rgba(255,255,255,.28);background:linear-gradient(145deg,#2b4cba,#8558e8)}
.jld-tech .jld-cover-label,.jld-tech .jld-cover-mark{color:#fff;background:rgba(15,24,66,.46);border:1px solid rgba(255,255,255,.2)}
.jld-tech .jld-cover-mark{box-shadow:0 12px 30px rgba(11,19,52,.3)}
.jld-tech .jld-eyebrow{color:#9cf7ff}.jld-tech .jld-eyebrow span{background:linear-gradient(90deg,#5ae9f4,#9f8cff)}
.jld-tech .jld-hero-copy>p{color:rgba(255,255,255,.76)}
.jld-tech .jld-meta-list span{color:#eaf5ff;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.13)}
.jld-tech .jld-button-primary{color:#fff;background:linear-gradient(135deg,#18bfd5,#6659f7);box-shadow:0 13px 28px rgba(35,72,214,.35)}
.jld-tech .jld-button-ghost{color:#fff;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.22)}
.jld-tech .jld-status-card{background:rgba(255,255,255,.9);border:1px solid rgba(87,103,191,.13);box-shadow:0 20px 45px rgba(36,51,109,.15);backdrop-filter:blur(18px)}
.jld-tech .jld-status-icon{color:#fff;background:linear-gradient(145deg,#1fcbd4,#685df6);box-shadow:0 11px 24px rgba(77,91,235,.3)}
.jld-tech.is-locked .jld-status-icon{background:linear-gradient(145deg,#ff9f4a,#ff5d7e)}
.jld-tech .jld-status-kicker,.jld-tech .jld-panel-kicker{color:#6670cb}.jld-tech .jld-status-copy p{color:#7c829d}
.jld-tech .jld-status-seal{color:#256b79;background:#e8fbfd}.jld-tech.is-locked .jld-status-seal{color:#9f4d3c;background:#fff0e9}
.jld-tech .jld-panel{background:rgba(255,255,255,.9);border:1px solid rgba(102,112,203,.12);box-shadow:0 14px 36px rgba(43,57,114,.09)}
.jld-tech .jld-panel-heading{border-bottom:1px solid #edf0fb}.jld-tech .jld-panel-heading h2 i,.jld-tech .jld-panel-heading h3 i{color:#655df1}
.jld-tech .jld-secure-badge{color:#3c6a7d;background:#ebfafa}
.jld-tech .jld-resource-details{color:#59617b;background:#f3f5ff;border:1px solid #e7eaff}.jld-tech .jld-info-icon{color:#fff;background:linear-gradient(145deg,#61d9e2,#6f62ef)}
.jld-tech .jld-hidden-info{background:#f7f8ff;border:1px solid #e6e9ff}.jld-tech .jld-hidden-heading{color:#5749c7;background:#eef0ff}.jld-tech .jld-hidden-content{color:#5e647b}
.jld-tech .jld-login-notice{color:#4d55a7;background:#eff1ff;border:1px solid #dfe4ff}
.jld-tech .jld-locked-illustration{color:#fff;background:linear-gradient(145deg,#ff9d55,#ff5d83);box-shadow:0 15px 28px rgba(255,101,124,.25)}
.jld-tech .jld-locked-box p,.jld-tech .jld-help-panel p,.jld-tech .jld-steps small,.jld-tech .jld-notice-panel li{color:#7a8097}
.jld-tech .jld-steps li>span{color:#fff;background:linear-gradient(145deg,#28c5d5,#665ef2)}
.jld-tech .jld-help-icon{color:#fff;background:linear-gradient(145deg,#28bde5,#5473f5);box-shadow:0 12px 24px rgba(64,115,232,.25)}
.jld-tech .jld-help-actions a{color:#fff;background:linear-gradient(135deg,#3bbbdc,#675df1)}
.jld-tech .jld-help-actions a+ a{color:#5c61a8;background:#eff1ff}
.jld-tech .jld-notice-panel{background:linear-gradient(145deg,#202f66,#3e397f);color:#fff}.jld-tech .jld-notice-panel li{color:rgba(255,255,255,.72)}



/* ===== 九流网络：跟随子比主题深色模式（v2） =====
 * 子比切换时会在 body 上增删 .dark-theme，并使用 View Transition API。
 * 本页只使用 CSS 跟随，不注册额外监听器，不轮询、不重复执行 JavaScript。
 */
body:not(.dark-theme) .jld-page{color-scheme:light}
body.dark-theme .jld-page{color-scheme:dark}
.jld-page,
.jld-page .jld-hero,
.jld-page .jld-status-card,
.jld-page .jld-panel,
.jld-page .jld-resource-details,
.jld-page .jld-hidden-info,
.jld-page .jld-hidden-heading,
.jld-page .jld-hidden-content,
.jld-page .jld-login-notice,
.jld-page .jld-meta-list span,
.jld-page .jld-status-seal,
.jld-page .jld-secure-badge,
.jld-page .jld-help-actions a,
.jld-page .muted-box{
    transition:color .22s ease,background-color .22s ease,border-color .22s ease,box-shadow .22s ease,opacity .22s ease;
}
/* 子比性能加速模式下主动关闭大面积毛玻璃，降低低端设备 GPU 压力 */
body.fps-accelerat .jld-page .jld-status-card,
body.fps-accelerat .jld-page .jld-cover-label,
body.fps-accelerat .jld-page .jld-cover-mark{
    -webkit-backdrop-filter:none!important;
    backdrop-filter:none!important;
}
@media (prefers-reduced-motion:reduce){
    .jld-page,.jld-page *{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
}

/* 科技蓝紫版：深色主题配色 */
.dark-theme .jld-tech{background:linear-gradient(180deg,#252731,#222631);color:#e9edff}
.dark-theme .jld-tech .jld-hero{background:radial-gradient(circle at 10% 20%,rgba(35,205,220,.21),transparent 31%),radial-gradient(circle at 88% 12%,rgba(151,91,241,.23),transparent 29%),linear-gradient(135deg,#10162d 0%,#17284f 48%,#362d70 100%)}
.dark-theme .jld-tech .jld-status-card{background:rgba(34,36,53,.93);border-color:rgba(128,139,245,.2);box-shadow:0 20px 45px rgba(0,0,0,.25)}
.dark-theme .jld-tech .jld-panel{background:rgba(40,42,58,.93);border-color:rgba(133,142,230,.16);box-shadow:0 14px 36px rgba(0,0,0,.2)}
.dark-theme .jld-tech .jld-status-copy h2,
.dark-theme .jld-tech .jld-panel-heading h2,
.dark-theme .jld-tech .jld-panel-heading h3,
.dark-theme .jld-tech .jld-steps b,
.dark-theme .jld-tech .jld-help-panel h3,
.dark-theme .jld-tech .jld-locked-box h3{color:#f4f6ff}
.dark-theme .jld-tech .jld-status-kicker,
.dark-theme .jld-tech .jld-panel-kicker{color:#9ca5ff}
.dark-theme .jld-tech .jld-status-copy p,
.dark-theme .jld-tech .jld-locked-box p,
.dark-theme .jld-tech .jld-help-panel p,
.dark-theme .jld-tech .jld-steps small{color:#aeb4c9}
.dark-theme .jld-tech .jld-status-seal{color:#7fe8ef;background:rgba(41,205,216,.1);border:1px solid rgba(65,220,229,.16)}
.dark-theme .jld-tech.is-locked .jld-status-seal{color:#ffb39e;background:rgba(255,112,83,.1);border-color:rgba(255,139,112,.16)}
.dark-theme .jld-tech .jld-panel-heading{border-bottom-color:rgba(144,151,226,.15)}
.dark-theme .jld-tech .jld-secure-badge{color:#8fe4e8;background:rgba(40,205,213,.09);border:1px solid rgba(83,220,228,.14)}
.dark-theme .jld-tech .jld-resource-doc,
.dark-theme .jld-tech .jld-page-content{color:#d9dded}
.dark-theme .jld-tech .jld-resource-details{color:#bac0d4;background:#303348;border-color:#414660}
.dark-theme .jld-tech .jld-hidden-info{background:#292c3e;border-color:#3e435d}
.dark-theme .jld-tech .jld-hidden-heading{color:#b8b2ff;background:#343750;border-bottom:1px solid #41465f}
.dark-theme .jld-tech .jld-hidden-content{color:#c9cede}
.dark-theme .jld-tech .jld-login-notice{color:#c7cbff;background:rgba(103,94,242,.11);border-color:rgba(132,125,255,.18)}
.dark-theme .jld-tech .jld-help-actions a+a{color:#cbd0ff;background:#31344a;border:1px solid #42475f}
.dark-theme .jld-tech .jld-notice-panel{background:linear-gradient(145deg,#171d39,#282552);border-color:rgba(135,142,244,.2)}
.dark-theme .jld-tech .muted-box{background:#303347!important;color:#c5cad9!important}
.dark-theme .jld-tech .muted-2-color{color:#aeb4c6!important}



/* ===== v4 真实动态模块：用户、额度、热门资源、公告、属性、密码 ===== */
.jld-page{--jld-v4-accent:#665df1;--jld-v4-accent-2:#20bfd4;--jld-v4-soft:rgba(102,93,241,.08);--jld-v4-border:rgba(102,93,241,.15);--jld-v4-code:rgba(23,32,70,.06)}
.jld-business{--jld-v4-accent:#2563eb;--jld-v4-accent-2:#11a981;--jld-v4-soft:rgba(37,99,235,.07);--jld-v4-border:rgba(37,99,235,.14);--jld-v4-code:rgba(18,38,72,.055)}
.jld-vip{--jld-v4-accent:#bd8a2d;--jld-v4-accent-2:#dfb75f;--jld-v4-soft:rgba(189,138,45,.09);--jld-v4-border:rgba(189,138,45,.19);--jld-v4-code:rgba(83,61,20,.07)}
.dark-theme .jld-page{--jld-v4-soft:rgba(133,124,255,.1);--jld-v4-border:rgba(145,137,255,.18);--jld-v4-code:rgba(255,255,255,.06)}
.dark-theme .jld-business{--jld-v4-accent:#69a3ff;--jld-v4-accent-2:#40d3ae;--jld-v4-soft:rgba(81,137,232,.11);--jld-v4-border:rgba(101,155,240,.18)}
.dark-theme .jld-vip{--jld-v4-accent:#e2bd68;--jld-v4-accent-2:#f0cf88;--jld-v4-soft:rgba(226,189,104,.1);--jld-v4-border:rgba(226,189,104,.18)}
.jld-resource-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0 0 18px}
.jld-fact-item{display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:48px;padding:10px 13px;border:1px solid var(--jld-v4-border);border-radius:12px;background:var(--jld-v4-soft)}
.jld-fact-item span{font-size:12px;color:var(--muted-2-color);white-space:nowrap}.jld-fact-item b{font-size:13px;text-align:right;word-break:break-word}
.jld-inline-quota{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:12px;margin:0 0 18px;padding:13px 15px;border:1px solid var(--jld-v4-border);border-radius:14px;background:linear-gradient(135deg,var(--jld-v4-soft),transparent)}
.jld-inline-quota-icon{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,var(--jld-v4-accent),var(--jld-v4-accent-2))}
.jld-inline-quota b,.jld-inline-quota span{display:block}.jld-inline-quota b{font-size:13px}.jld-inline-quota span{font-size:11px;color:var(--muted-2-color);margin-top:2px}.jld-inline-quota strong{font-size:20px;color:var(--jld-v4-accent)}
.jld-page .jld-download-area .but-download>a[data-clipboard-text]{display:none!important}
.jld-hidden-info-v4 .jld-hidden-heading{justify-content:flex-start;flex-wrap:wrap}.jld-hidden-info-v4 .jld-hidden-heading>span{margin-left:auto;font-size:10px;opacity:.7;font-weight:500}
.jld-secret-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:15px}
.jld-secret-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 10px;align-items:center;padding:12px;border:1px solid var(--jld-v4-border);border-radius:12px;background:var(--jld-v4-code)}
.jld-secret-item>span{grid-column:1/-1;font-size:11px;color:var(--muted-2-color)}.jld-secret-item code{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0;background:none;color:inherit;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
.jld-secret-copy{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:8px;color:var(--jld-v4-accent);background:var(--jld-v4-soft);font-size:11px;font-weight:800}
.jld-download-tips{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:18px}
.jld-download-tips>div{padding:13px;border:1px solid var(--jld-v4-border);border-radius:13px;background:var(--jld-v4-soft)}.jld-download-tips i{color:var(--jld-v4-accent);margin-right:6px}.jld-download-tips b{font-size:12px}.jld-download-tips span{display:block;margin-top:6px;font-size:11px;line-height:1.55;color:var(--muted-2-color)}
.jld-user-panel{overflow:hidden}.jld-user-head{display:flex;align-items:center;gap:13px}.jld-user-avatar{flex:0 0 62px;width:62px;height:62px;border-radius:20px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--jld-v4-accent),var(--jld-v4-accent-2));box-shadow:0 10px 24px rgba(31,48,94,.16)}
.jld-user-avatar img{display:block;width:100%;height:100%;object-fit:cover}.jld-user-avatar-guest{color:#fff;font-size:26px}.jld-user-copy{min-width:0}.jld-user-copy h3{margin:1px 0 5px;font-size:18px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jld-user-badges{display:flex;flex-wrap:wrap;gap:5px}
.jld-user-level,.jld-user-expiry{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;font-size:10px}.jld-user-level{color:var(--jld-v4-accent);background:var(--jld-v4-soft);font-weight:800}.jld-user-expiry{color:var(--muted-2-color);background:var(--muted-border-color)}
.jld-quota-card{margin-top:16px;padding:14px;border:1px solid var(--jld-v4-border);border-radius:14px;background:var(--jld-v4-soft)}.jld-quota-top{display:flex;align-items:center;justify-content:space-between;gap:12px}.jld-quota-top span,.jld-quota-top b{display:block}.jld-quota-top span{font-size:11px;color:var(--muted-2-color)}.jld-quota-top b{margin-top:2px;font-size:18px}.jld-quota-top i{font-size:24px;color:var(--jld-v4-accent)}
.jld-quota-progress{height:7px;margin:11px 0 8px;border-radius:99px;overflow:hidden;background:var(--muted-border-color)}.jld-quota-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--jld-v4-accent),var(--jld-v4-accent-2));transition:width .35s ease}.jld-quota-card small{font-size:10px;color:var(--muted-2-color)}
.jld-user-center-link{display:flex;align-items:center;gap:8px;margin-top:13px;padding:10px 12px;border-radius:11px;color:var(--jld-v4-accent);background:var(--jld-v4-soft);font-size:12px;font-weight:800}.jld-user-center-link .fa-angle-right{margin-left:auto}.jld-guest-note{margin-top:15px;font-size:12px;color:var(--muted-2-color)}
.jld-hot-list{display:flex;flex-direction:column;gap:8px}.jld-hot-item{display:grid;grid-template-columns:48px minmax(0,1fr) auto;align-items:center;gap:10px;padding:8px;border-radius:12px;transition:transform .18s ease,background-color .18s ease}.jld-hot-item:hover{transform:translateX(3px);background:var(--jld-v4-soft)}
.jld-hot-thumb{width:48px;height:48px;border-radius:11px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--jld-v4-soft);color:var(--jld-v4-accent)}.jld-hot-thumb img{width:100%;height:100%;object-fit:cover}.jld-hot-copy{min-width:0}.jld-hot-copy b{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;font-size:12px;line-height:1.45;color:var(--main-color)}.jld-hot-copy small{display:block;margin-top:4px;font-size:10px;color:var(--muted-2-color)}.jld-hot-copy small i{margin-right:4px}.jld-hot-arrow{font-size:12px;color:var(--muted-2-color)}.jld-empty-state{padding:15px;border-radius:12px;background:var(--jld-v4-soft);font-size:12px;color:var(--muted-2-color);text-align:center}
.jld-site-notice-panel{display:grid;grid-template-columns:auto minmax(0,1fr);gap:13px}.jld-notice-icon{width:45px;height:45px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,var(--jld-v4-accent),var(--jld-v4-accent-2));font-size:18px}.jld-site-notice-panel h3{margin:2px 0 6px;font-size:17px}.jld-site-notice-text,.jld-site-notice-text p{font-size:12px;line-height:1.65;color:var(--muted-2-color);margin:0}.jld-help-panel-v4{margin:0}
.jld-page .jld-user-panel,.jld-page .jld-hot-panel,.jld-page .jld-site-notice-panel,.jld-page .jld-fact-item,.jld-page .jld-inline-quota,.jld-page .jld-secret-item,.jld-page .jld-download-tips>div{transition:color .22s ease,background-color .22s ease,border-color .22s ease,box-shadow .22s ease}
@media (max-width:1100px){.jld-user-panel{grid-column:auto}.jld-hot-panel{grid-column:auto}.jld-site-notice-panel{grid-column:1/-1}.jld-help-panel-v4{grid-column:1/-1}}
@media (max-width:720px){.jld-resource-facts,.jld-secret-grid{grid-template-columns:1fr}.jld-download-tips{grid-template-columns:1fr}.jld-inline-quota{grid-template-columns:auto minmax(0,1fr)}.jld-inline-quota strong{grid-column:2;justify-self:start}.jld-hidden-info-v4 .jld-hidden-heading>span{width:100%;margin-left:0}.jld-site-notice-panel{grid-column:auto}}
.jld-page.is-login-required .jld-status-icon{background:linear-gradient(145deg,#269bd8,#5574f5)!important}
.jld-page.is-quota-exhausted .jld-status-icon{background:linear-gradient(145deg,#ffad42,#ee6b43)!important}
.jld-page.is-quota-exhausted .jld-status-seal{color:#9a5a16!important;background:rgba(255,173,66,.14)!important}
.jld-page.is-guest-purchase .jld-status-icon,.jld-page.is-single-purchase .jld-status-icon{background:linear-gradient(145deg,#20b884,#3587e8)!important}
@container jld-download (max-width:860px){.jld-hero-grid{grid-template-columns:1fr;gap:22px}.jld-cover.is-landscape{aspect-ratio:16/10}.jld-cover.is-square{max-width:340px}.jld-cover.is-portrait{max-width:280px}.jld-main-grid{grid-template-columns:1fr}.jld-side-stack{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.jld-user-panel,.jld-hot-panel{grid-column:auto}.jld-site-notice-panel,.jld-help-panel-v4{grid-column:1/-1}}
@container jld-download (max-width:620px){.jld-side-stack{grid-template-columns:1fr}.jld-site-notice-panel,.jld-help-panel-v4{grid-column:auto}.jld-resource-facts,.jld-secret-grid,.jld-download-tips{grid-template-columns:1fr}.jld-inline-quota{grid-template-columns:auto minmax(0,1fr)}.jld-inline-quota strong{grid-column:2;justify-self:start}}
@media (prefers-reduced-motion:reduce){.jld-hot-item,.jld-quota-progress span{transition:none!important}.jld-hot-item:hover{transform:none}}

</style>
<main class="<?php echo esc_attr($container_class); ?> jld-shell <?php echo $keep_theme_sidebar ? 'jld-keep-theme-sidebar' : 'jld-no-theme-sidebar'; ?>">
    <div class="content-wrap">
        <div class="content-layout">
            <?php if ($widgets_register_container && in_array('top_content', $widgets_register_container, true)) {
                dynamic_sidebar('page_top_content_' . $page_id);
            } ?>

            <?php if ('not' !== $content_style) : ?>
                <article class="article page-article main-bg theme-box main-shadow jld-article">
                    <div id="jl-download-page" class="jld-page jld-tech <?php echo esc_attr($access_class); ?>">
                        <section class="jld-hero">
                            <div class="jld-decoration jld-decoration-one"></div>
                            <div class="jld-decoration jld-decoration-two"></div>
                            <div class="jld-breadcrumb">
                                <a href="<?php echo esc_url(home_url('/')); ?>"><i class="fa fa-home" aria-hidden="true"></i> 首页</a>
                                <i class="fa fa-angle-right" aria-hidden="true"></i>
                                <a href="<?php echo esc_url($source_url); ?>">原文章</a>
                                <i class="fa fa-angle-right" aria-hidden="true"></i>
                                <span>资源下载</span>
                            </div>

                            <div class="jld-hero-grid">
                                <div class="jld-cover <?php echo esc_attr($cover_url ? 'has-cover ' . $cover_orientation : 'no-cover'); ?>">
                                    <?php if ($cover_url) : ?>
                                        <span class="jld-cover-backdrop" aria-hidden="true" style="background-image:url('<?php echo esc_url($cover_url); ?>');"></span>
                                        <div class="jld-cover-media"><?php echo $cover_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                    <?php endif; ?>
                                    <span class="jld-cover-label"><i class="fa fa-cloud-download" aria-hidden="true"></i> 资源交付</span>
                                    <div class="jld-cover-mark">
                                        <i class="fa fa-file-archive-o" aria-hidden="true"></i>
                                    </div>
                                </div>

                                <div class="jld-hero-copy">
                                    <div class="jld-eyebrow"><span></span> 九流网络 · 资源下载中心</div>
                                    <h1><?php echo esc_html($pay_title); ?></h1>
                                    <p>购买状态与下载权限由网站系统自动核验，支付成功后无需人工开通。</p>
                                    <div class="jld-meta-list">
                                        <span><i class="fa fa-folder-open-o" aria-hidden="true"></i><?php echo esc_html($category_name); ?></span>
                                        <span><i class="fa fa-link" aria-hidden="true"></i><?php echo esc_html($download_count ? $download_count . ' 条下载线路' : '下载线路待显示'); ?></span>
                                        <span><i class="fa fa-calendar-check-o" aria-hidden="true"></i>更新于 <?php echo esc_html($modified_date); ?></span>
                                    </div>
                                    <div class="jld-hero-actions">
                                        <a class="jld-button jld-button-primary" href="<?php echo esc_url($source_url); ?>"><i class="fa fa-file-text-o" aria-hidden="true"></i>查看原文章</a>
                                        <a class="jld-button jld-button-ghost" href="<?php echo esc_url($membership_url); ?>"><i class="fa fa-diamond" aria-hidden="true"></i>会员权益</a>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="jld-status-card">
                            <div class="jld-status-icon"><i class="fa <?php echo esc_attr($status_icon); ?>" aria-hidden="true"></i></div>
                            <div class="jld-status-copy">
                                <span class="jld-status-kicker"><?php echo $is_paid ? esc_html($paid_name) : '权限状态'; ?></span>
                                <h2><?php echo esc_html($status_title); ?></h2>
                                <p><?php echo esc_html($status_desc); ?></p>
                            </div>
                            <div class="jld-status-seal">
                                <i class="fa <?php echo esc_attr($status_seal_icon); ?>" aria-hidden="true"></i>
                                <span><?php echo esc_html($status_seal_text); ?></span>
                            </div>
                        </section>

                        <div class="jld-main-grid">
                            <section class="jld-panel jld-download-panel">
                                <div class="jld-panel-heading">
                                    <div>
                                        <span class="jld-panel-kicker">DOWNLOAD</span>
                                        <h2><i class="fa fa-download" aria-hidden="true"></i>资源下载</h2>
                                    </div>
                                    <span class="jld-secure-badge"><i class="fa fa-lock" aria-hidden="true"></i>安全验证</span>
                                </div>

                                <?php if ($pay_doc) : ?>
                                    <div class="jld-resource-doc"><?php echo $pay_doc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                <?php endif; ?>

                                <?php if ($pay_details) : ?>
                                    <div class="jld-resource-details">
                                        <div class="jld-info-icon"><i class="fa fa-info" aria-hidden="true"></i></div>
                                        <div><?php echo $pay_details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                    </div>
                                <?php endif; ?>

                                <div class="jld-resource-facts">
                                    <?php foreach ($resource_attributes as $attribute) : ?>
                                        <div class="jld-fact-item">
                                            <span><?php echo esc_html($attribute['key']); ?></span>
                                            <b><?php echo !empty($attribute['html']) ? wp_kses_post($attribute['value']) : esc_html($attribute['value']); ?></b>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($is_single_purchase) : ?>
                                    <div class="jld-inline-quota">
                                        <div class="jld-inline-quota-icon"><i class="fa fa-shopping-bag" aria-hidden="true"></i></div>
                                        <div><b><?php echo $is_guest_purchase ? '游客订单已验证' : '本资源已单独购买'; ?></b><span>当前权限来自本资源订单，不会扣减会员每日免费资源额度。</span></div>
                                        <strong>已解锁</strong>
                                    </div>
                                <?php elseif ($user_logged_in && $is_any_free_access && $user_download_limit > 0) : ?>
                                    <div class="jld-inline-quota">
                                        <div class="jld-inline-quota-icon"><i class="fa <?php echo $is_quota_exhausted ? 'fa-clock-o' : 'fa-bolt'; ?>" aria-hidden="true"></i></div>
                                        <div><b><?php echo $user_is_member ? '今日会员免费额度' : '今日免费资源额度'; ?></b><span>系统按“已下载的不同资源数量”统计，而不是按重复点击次数统计。</span></div>
                                        <strong><?php echo $is_quota_exhausted ? '已用完' : esc_html((string) $user_remaining); ?></strong>
                                    </div>
                                <?php elseif ($user_logged_in && $is_member_free_access) : ?>
                                    <div class="jld-inline-quota">
                                        <div class="jld-inline-quota-icon"><i class="fa fa-diamond" aria-hidden="true"></i></div>
                                        <div><b>会员免费资源</b><span>该会员等级后台未设置每日免费资源数量上限。</span></div>
                                        <strong>不限量</strong>
                                    </div>
                                <?php elseif ($is_public_free_access) : ?>
                                    <div class="jld-inline-quota">
                                        <div class="jld-inline-quota-icon"><i class="fa fa-gift" aria-hidden="true"></i></div>
                                        <div><b>免费资源</b><span><?php echo $needs_login ? '登录网站账户后即可下载。' : '当前资源无需会员或单独购买。'; ?></span></div>
                                        <strong><?php echo $needs_login ? '需登录' : '免费'; ?></strong>
                                    </div>
                                <?php elseif ($is_trial_access) : ?>
                                    <div class="jld-inline-quota">
                                        <div class="jld-inline-quota-icon"><i class="fa fa-hourglass-half" aria-hidden="true"></i></div>
                                        <div><b>试用权限</b><span>请在试用权限有效期内完成下载并保存资源。</span></div>
                                        <strong>有效</strong>
                                    </div>
                                <?php endif; ?>

                                <?php if ($is_paid) : ?>
                                    <div class="jld-download-area">
                                        <?php echo $download_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                    <?php if ($login_html) : ?>
                                        <div class="jld-login-wrap"><?php echo $login_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                    <?php endif; ?>
                                    <?php if (!$needs_login && ($secret_items || $pay_extra_hide)) : ?>
                                        <div class="jld-hidden-info jld-hidden-info-v4">
                                            <div class="jld-hidden-heading"><i class="fa fa-key" aria-hidden="true"></i><b>提取码、解压密码与补充信息</b><span>仅对已获得权限的用户显示</span></div>
                                            <?php if ($secret_items) : ?>
                                                <div class="jld-secret-grid">
                                                    <?php foreach ($secret_items as $secret) : ?>
                                                        <div class="jld-secret-item">
                                                            <span><?php echo esc_html($secret['label']); ?></span>
                                                            <code><?php echo esc_html($secret['value']); ?></code>
                                                            <a href="javascript:;" data-clipboard-tag="<?php echo esc_attr($secret['label']); ?>" data-clipboard-text="<?php echo esc_attr($secret['value']); ?>" class="jld-secret-copy"><i class="fa fa-copy" aria-hidden="true"></i>复制</a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($pay_extra_hide) : ?><div class="jld-hidden-content"><?php echo $pay_extra_hide; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="jld-download-tips">
                                        <div><i class="fa fa-check-circle" aria-hidden="true"></i><b>自动到账</b><span>站内支付完成后，订单与下载权限由系统自动更新。</span></div>
                                        <div><i class="fa fa-shield" aria-hidden="true"></i><b>权限校验</b><span>下载入口继续调用子比原生验证与真实商品线路。</span></div>
                                        <div><i class="fa fa-folder-open-o" aria-hidden="true"></i><b>及时保存</b><span>下载后请及时转存，并保留解压密码等补充信息。</span></div>
                                    </div>
                                <?php else : ?>
                                    <div class="jld-locked-box">
                                        <div class="jld-locked-illustration"><i class="fa fa-lock" aria-hidden="true"></i></div>
                                        <h3>该资源尚未解锁</h3>
                                        <p>请返回原文章选择购买方式。网站内完成支付后，订单会自动到账并立即开放下载权限。</p>
                                        <div class="jld-locked-actions">
                                            <a class="jld-button jld-button-primary" href="<?php echo esc_url($purchase_url); ?>"><i class="fa fa-shopping-cart" aria-hidden="true"></i>返回文章购买</a>
                                            <a class="jld-button jld-button-ghost" href="<?php echo esc_url($membership_url); ?>"><i class="fa fa-diamond" aria-hidden="true"></i>查看会员方案</a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </section>

                            <aside class="jld-side-stack">
                                <section class="jld-panel jld-user-panel">
                                    <div class="jld-user-head">
                                        <div class="jld-user-avatar"><?php echo $user_avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                        <div class="jld-user-copy">
                                            <span class="jld-panel-kicker">ACCOUNT</span>
                                            <h3><?php echo esc_html($user_name); ?></h3>
                                            <div class="jld-user-badges">
                                                <span class="jld-user-level level-<?php echo esc_attr((string) $user_vip_level); ?>"><i class="fa fa-diamond" aria-hidden="true"></i><?php echo esc_html($user_vip_name); ?></span>
                                                <?php if ($user_vip_expiry) : ?><span class="jld-user-expiry"><?php echo esc_html($user_vip_expiry); ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($user_logged_in) : ?>
                                        <?php if ($user_is_member) : ?>
                                            <div class="jld-quota-card">
                                                <div class="jld-quota-top">
                                                    <div><span>今日会员免费额度</span><b><?php echo $user_download_limit > 0 ? esc_html($user_remaining . ' / ' . $user_download_limit) : '不限量'; ?></b></div>
                                                    <i class="fa fa-cloud-download" aria-hidden="true"></i>
                                                </div>
                                                <?php if ($user_download_limit > 0) : ?>
                                                    <div class="jld-quota-progress" aria-label="今日已使用 <?php echo esc_attr((string) $user_downloaded); ?> 个资源额度"><span style="width:<?php echo esc_attr((string) $user_quota_percent); ?>%"></span></div>
                                                    <small>今日已使用 <?php echo esc_html((string) $user_downloaded); ?> 个资源额度，剩余 <?php echo esc_html((string) $user_remaining); ?> 个。</small>
                                                <?php else : ?>
                                                    <small>该会员等级未设置每日免费资源下载上限。</small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else : ?>
                                            <div class="jld-quota-card">
                                                <div class="jld-quota-top">
                                                    <div><span>会员状态</span><b>未开通会员</b></div>
                                                    <i class="fa fa-user-o" aria-hidden="true"></i>
                                                </div>
                                                <?php if ($is_single_purchase) : ?>
                                                    <small>当前资源已单独购买，可直接下载；该权限不计入会员每日免费额度。</small>
                                                <?php elseif ($is_public_free_access) : ?>
                                                    <small>当前为免费资源，无需开通会员即可下载。</small>
                                                <?php else : ?>
                                                    <small>普通用户可以单独购买资源，或开通会员获得每日免费额度。</small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($user_center_url) : ?><a class="jld-user-center-link" href="<?php echo esc_url($user_center_url); ?>"><i class="fa fa-user-circle-o" aria-hidden="true"></i>进入用户中心<i class="fa fa-angle-right" aria-hidden="true"></i></a><?php endif; ?>
                                    <?php else : ?>
                                        <div class="jld-guest-note"><?php echo $is_guest_purchase ? '当前游客订单已经验证。建议登录或注册账户，便于长期保存订单记录。' : ($needs_login ? '当前免费资源要求登录后下载。' : '登录后可查看真实会员等级、今日已下载资源数量与剩余额度。'); ?></div>
                                        <a href="javascript:;" class="jld-user-center-link signin-loader"><i class="fa fa-sign-in" aria-hidden="true"></i>登录网站账户<i class="fa fa-angle-right" aria-hidden="true"></i></a>
                                    <?php endif; ?>
                                </section>

                                <section class="jld-panel jld-hot-panel">
                                    <div class="jld-panel-heading compact">
                                        <div>
                                            <span class="jld-panel-kicker">POPULAR</span>
                                            <h3><i class="fa fa-fire" aria-hidden="true"></i>热门资源</h3>
                                        </div>
                                    </div>
                                    <div class="jld-hot-list">
                                        <?php if ($hot_resource_ids) : ?>
                                            <?php foreach ($hot_resource_ids as $hot_id) :
                                                $hot_title = get_the_title($hot_id);
                                                $hot_url = get_permalink($hot_id);
                                                $hot_views = (int) get_post_meta($hot_id, 'views', true);
                                                $hot_thumb = get_the_post_thumbnail($hot_id, 'thumbnail', array('loading' => 'lazy', 'decoding' => 'async', 'alt' => $hot_title));
                                                ?>
                                                <a class="jld-hot-item" href="<?php echo esc_url($hot_url); ?>">
                                                    <span class="jld-hot-thumb <?php echo $hot_thumb ? '' : 'no-thumb'; ?>"><?php echo $hot_thumb ? $hot_thumb : '<i class="fa fa-file-archive-o" aria-hidden="true"></i>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                    <span class="jld-hot-copy"><b><?php echo esc_html($hot_title); ?></b><small><i class="fa fa-eye" aria-hidden="true"></i><?php echo esc_html((string) $hot_views); ?> 次浏览</small></span>
                                                    <i class="fa fa-angle-right jld-hot-arrow" aria-hidden="true"></i>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <div class="jld-empty-state">暂时没有可推荐的其他付费资源。</div>
                                        <?php endif; ?>
                                    </div>
                                </section>

                                <section class="jld-panel jld-site-notice-panel">
                                    <div class="jld-notice-icon"><i class="fa fa-bullhorn" aria-hidden="true"></i></div>
                                    <div>
                                        <span class="jld-panel-kicker">NOTICE</span>
                                        <h3><?php echo esc_html($site_notice_title); ?></h3>
                                        <div class="jld-site-notice-text"><?php echo wp_kses_post(wpautop($site_notice)); ?></div>
                                    </div>
                                </section>

                                <section class="jld-panel jld-help-panel jld-help-panel-v4">
                                    <div class="jld-help-icon"><i class="fa fa-telegram" aria-hidden="true"></i></div>
                                    <div>
                                        <span class="jld-panel-kicker">SUPPORT</span>
                                        <h3>售前咨询与支付售后</h3>
                                        <p>会员权益咨询、充值未到账、支付异常或资源链接失效，可联系 Telegram 客服。</p>
                                    </div>
                                    <div class="jld-help-actions">
                                        <a target="_blank" rel="noopener noreferrer" href="https://t.me/jiuliu_org"><i class="fa fa-paper-plane" aria-hidden="true"></i>联系客户服务</a>
                                        <a target="_blank" rel="noopener noreferrer" href="https://t.me/jiuliu_group"><i class="fa fa-users" aria-hidden="true"></i>加入官方群聊</a>
                                    </div>
                                </section>
                            </aside>
                        </div>

                        <?php
                        $page_show_content = get_post_meta($page_id, 'page_show_content', true);
                        if ($page_show_content) : ?>
                            <section class="jld-panel jld-page-content wp-posts-content">
                                <?php
                                the_content();
                                wp_link_pages(array(
                                    'before' => '<p class="text-center post-nav-links radius8 padding-6">',
                                    'after' => '</p>',
                                ));
                                ?>
                            </section>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>

            <?php if ($widgets_register_container && in_array('bottom_content', $widgets_register_container, true)) {
                dynamic_sidebar('page_bottom_content_' . $page_id);
            } ?>
        </div>
    </div>
    <?php if ($keep_theme_sidebar) { get_sidebar(); } ?>
</main>
<?php
if ($widgets_register_container && in_array('bottom_fluid', $widgets_register_container, true)) {
    echo '<div class="fluid-widget-wrap">';
    dynamic_sidebar('page_bottom_fluid_' . $page_id);
    echo '</div>';
}
get_footer();
exit;
