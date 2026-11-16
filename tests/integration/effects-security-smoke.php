<?php
/** Run only on a local throwaway WordPress site. Writes NO database options. */
if (!defined('ABSPATH')) exit(1);
function jlwa_2510_assert($ok,$message) { if (!$ok) throw new RuntimeException($message); }

$defs = JLWA_Page_Effects_Feature::effect_definitions();
ob_start();
wp_set_current_user( (int) get_user_by('login','jlwaadmin')->ID );
JLWA_Page_Effects_Feature::instance()->render_admin_page();
$html = ob_get_clean();
jlwa_2510_assert(substr_count($html,'data-xjpe-card') === count($defs), 'Missing compact effect cards');
jlwa_2510_assert(substr_count($html,'class="xjpe-card-body"') === count($defs), 'Expected exactly one closed parameter panel per card');
jlwa_2510_assert(substr_count($html,'aria-expanded="false"') === count($defs), 'Panels must start collapsed');
jlwa_2510_assert(strpos($html,'data-xjpe-filter="enabled"')!==false,'Missing enabled-only filter');
jlwa_2510_assert(strpos($html,'xjpe_options[effects][waves][height]')!==false,'Waves values missing from hidden settings');
jlwa_2510_assert(strpos($html,'xjpe_options[effects][lantern][text]')!==false,'Lantern text missing from hidden settings');
echo '16 项紧凑特效卡、按需展开、配置仍随表单提交：通过' . PHP_EOL;

// Isolated option filter allows testing restricted mode without editing saved settings.
$source = JLWA_Page_Effects_Feature::default_options();
$source['effects']['nosource']['enabled'] = 1;
$source['effects']['nosource']['server_mode'] = 'restricted';
$source['effects']['nosource']['server_posts'] = 1;
// Deliberately DISABLE legacy optional REST/Feed flags: restricted must fail closed.
$source['effects']['nosource']['server_hide_rest'] = 0;
$source['effects']['nosource']['server_hide_feed'] = 0;
$oldUser = get_current_user_id();
$oldPost = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
$fake = static function()use($source) {return $source;};
add_filter('pre_option_xjpe_options',$fake);
wp_set_current_user(0);
$post = get_post(6);
if (!$post || $post->post_type !== 'post') $post = new WP_Post((object)array('ID'=>0,'post_type'=>'post','post_title'=>'test','post_content'=>'SENSITIVE-CONTENT','post_excerpt'=>'SENSITIVE-EXCERPT'));
try {
    $GLOBALS['post'] = $post;
    $response = new WP_REST_Response(array('content'=>array('rendered'=>'SENSITIVE-CONTENT','raw'=>'RAW-SECRET'),'excerpt'=>array('rendered'=>'SENSITIVE-EXCERPT','raw'=>'RAW-EXCERPT')));
    $response = JLWA_Page_Effects_Feature::instance()->filter_protected_rest_response($response,$post,null);
    $data = $response->get_data();
    jlwa_2510_assert(strpos($data['content']['rendered'],'SENSITIVE-CONTENT')===false && $data['content']['raw']==='', 'REST content still leaked with restricted mode');
    jlwa_2510_assert($data['excerpt']['raw']==='' && strpos($data['excerpt']['rendered'],'SENSITIVE-EXCERPT')===false,'REST excerpt still leaked');
    jlwa_2510_assert(strpos(JLWA_Page_Effects_Feature::instance()->filter_protected_feed('SECRET-FEED'),'SECRET-FEED')===false,'RSS leaked content');
    jlwa_2510_assert(strpos(JLWA_Page_Effects_Feature::instance()->filter_protected_excerpt('SECRET-EXCERPT',$post),'SECRET-EXCERPT')===false,'Archive excerpt leaked content');
} finally {
    remove_filter('pre_option_xjpe_options',$fake);
    $GLOBALS['post'] = $oldPost;
    wp_set_current_user($oldUser);
}
echo '服务端限制全文：REST、RSS、文章摘要均不会因关闭可选隐藏开关泄漏：通过' . PHP_EOL;

$anti = JLWA_Anti_Debug_Feature::instance();
$method = new ReflectionMethod($anti,'safe_event_path');$method->setAccessible(true);
foreach(array('https://evil.example/other?token=secret','//evil.example/path','https://user:password@evil.example/a','https://sub.example.org/?jlwa_safe=secret') as $url) {
    jlwa_2510_assert($method->invoke($anti,$url)==='/','Public event logged external URL');
}
$siteHost=wp_parse_url(home_url(),PHP_URL_HOST);
$path=$method->invoke($anti,'https://'.$siteHost.'/private/post?jlwa_safe=secret-reset-token#fragment');
jlwa_2510_assert($path==='/private/post','Log must strip query and fragment');
echo '反调试公开日志不存外部域名、查询密钥与 URL 片段：通过' . PHP_EOL;

// The settings UI must show the real boundary, not claim F12 blocking = paid content security.
ob_start();
$anti->render_admin_page();
$securityHtml = ob_get_clean();
jlwa_2510_assert(strpos($securityHtml, '安全能力自检') !== false, 'Anti-debug capability audit absent');
jlwa_2510_assert(strpos($securityHtml, '服务器限制全文') !== false && strpos($securityHtml, 'WordPress') !== false, 'Backend fails to distinguish role-based server protection');
jlwa_2510_assert(strpos($securityHtml, '客户端反调试') !== false && strpos($securityHtml, '仍可绕过') !== false || strpos($securityHtml, '未启用') !== false, 'Backend overstates client protection');
echo '反调试设置界面准确展示客户端限制与服务器权限状态：通过' . PHP_EOL;
