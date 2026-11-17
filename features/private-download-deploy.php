<?php
/** Administrator-only UI for root-approved Nginx deployment. No theme modifications. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Private_Deploy {
    const BRIDGE = '/usr/local/sbin/jlwa-nginx-helper';
    const ACTIONS = array( 'status', 'preview', 'prepare', 'confirm', 'protect', 'rollback' );

    public static function boot() {
        add_action( 'admin_post_jlwa_private_deploy', array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_jlwa_private_probe', array( __CLASS__, 'probe' ) );
    }

    /** Fixed command and argument set, never accepts paths or shell text from HTTP. */
    public static function call( $operation ) {
        if ( ! in_array( $operation, self::ACTIONS, true ) ) {
            return array( 'ok' => false, 'message' => '不支持的部署操作。' );
        }
        if ( ! is_file( self::BRIDGE ) || ! function_exists( 'proc_open' ) ) {
            return array( 'ok' => false, 'message' => '未安装受限 Nginx 部署助手。可先执行一次性授权步骤，也可使用手动部署教程。' );
        }
        $pipes = array();
        $process = @proc_open(
            array( 'sudo', '-n', self::BRIDGE, $operation ),
            array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
            $pipes
        );
        if ( ! is_resource( $process ) ) {
            return array( 'ok' => false, 'message' => '无法启动部署助手，请检查 PHP proc_open 和受限 sudo 授权。' );
        }
        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1], 32768 );
        $stderr = stream_get_contents( $pipes[2], 8192 );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $process );
        return array( 'ok' => 0 === $code, 'message' => trim( 0 === $code ? $stdout : $stderr ) );
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '没有管理权限。', '无权限', array( 'response' => 403 ) ); }
        $operation = isset( $_POST['jlwa_operation'] ) && is_string( $_POST['jlwa_operation'] ) ? sanitize_key( wp_unslash( $_POST['jlwa_operation'] ) ) : '';
        if ( ! in_array( $operation, self::ACTIONS, true ) || 'status' === $operation ) { wp_die( '不允许的部署操作。', 400 ); }
        check_admin_referer( 'jlwa_nginx_' . $operation );
        if ( 'confirm' === $operation ) {
            $confirmed = ! empty( $_POST['jlwa_buyer_test_passed'] ) && '1' === (string) $_POST['jlwa_buyer_test_passed'];
            if ( ! $confirmed ) { wp_die( '必须先勾选已完成真实购买用户下载验证。', 400 ); }
        }
        if ( 'protect' === $operation ) {
            $options = JLWA_Download_Page_Feature::options();
            if ( empty( $options['private_enabled'] ) ) { wp_die( '请先启用私有交付并验证正常下载，再保护源站。', '配置不完整', array( 'response' => 400 ) ); }
            $bridge_status = self::call( 'status' );
            $approved = ! empty( $bridge_status['ok'] ) ? json_decode( $bridge_status['message'], true ) : null;
            $hosts = JLWA_Private_Download::hosts( $options['private_hosts'] ?? '' );
            if ( ! is_array( $approved ) || ( $approved['approved_source'] ?? '' ) !== ( $options['private_source_prefix'] ?? '' )
                || ! in_array( $approved['approved_host'] ?? '', $hosts, true )
                || ( $options['private_internal_prefix'] ?? '' ) !== JLWA_Private_Download::DEFAULT_INTERNAL ) {
                wp_die( '插件的私有源目录或域名与系统授权配置不一致，禁止启用公网封锁，请检查设置。', '配置不匹配', array( 'response' => 400 ) );
            }
        }
        $result = self::call( $operation );
        // Admin-only diagnostic; never put actual paths or remote output into URL params.
        update_option( 'jlwa_nginx_last_result', array(
            'time' => time(),
            'action' => $operation,
            'ok' => (bool) $result['ok'],
            'message' => substr( (string) $result['message'], 0, 4500 ),
        ), false );
        wp_safe_redirect( admin_url( 'admin.php?page=jlwa-download-page' ) );
        exit;
    }

    /** Anonymous small Range probe of an actual Zibll stored resource (no passwords sent). */
    public static function probe() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '没有管理权限。', '无权限', array( 'response' => 403 ) ); }
        check_admin_referer( 'jlwa_private_probe' );
        $post_id = isset( $_POST['jlwa_probe_post'] ) && is_scalar( $_POST['jlwa_probe_post'] ) ? absint( wp_unslash( $_POST['jlwa_probe_post'] ) ) : 0;
        $settings = JLWA_Download_Page_Feature::options();
        $result = array( 'time' => time(), 'post_id' => $post_id, 'ok' => false, 'message' => '' );
        $pay_meta = $post_id ? get_post_meta( $post_id, 'posts_zibpay', true ) : null;
        if ( ! $post_id || ! is_array( $pay_meta ) || '2' !== (string) ( $pay_meta['pay_type'] ?? '' ) || ! function_exists( 'zibpay_get_post_down_array' ) ) {
            $result['message'] = '无效的付费下载文章，或子比资源接口不可用。';
        } else {
            $lines = zibpay_get_post_down_array( $post_id );
            $file = '';
            foreach ( (array) $lines as $line ) {
                if ( is_array( $line ) && ! empty( $line['link'] ) && is_string( $line['link'] ) && is_array( JLWA_Private_Download::resolve( $line['link'], $settings ) ) ) {
                    $file = $line['link'];
                    break;
                }
            }
            if ( ! $file ) {
                $result['message'] = '此文章没有匹配当前私有源站域名/路径的下载地址。';
            } else {
                $parts = wp_parse_url( $file );
                $hosts = JLWA_Private_Download::hosts( $settings['private_hosts'] ?? '' );
                $statuses = array();
                $path = isset( $parts['path'] ) ? $parts['path'] : '';
                foreach ( $hosts as $host ) {
                    // Uses WordPress safe HTTP transport to reject loopback/private-IP SSRF.
                    $url = 'https://' . $host . $path;
                    $response = wp_safe_remote_get( $url, array(
                        'timeout' => 8,
                        'redirection' => 0,
                        'limit_response_size' => 2048,
                        'headers' => array( 'Range' => 'bytes=0-0', 'Cache-Control' => 'no-cache' ),
                    ) );
                    $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                    $statuses[] = $code;
                }
                $result['ok'] = ! empty( $statuses ) && count( array_filter( $statuses, function ( $code ) { return 403 === $code; } ) ) === count( $statuses );
                $result['message'] = '公网源站受保护路径检查返回状态：' . implode( ', ', $statuses ) . '。' . ( $result['ok'] ? '本次样例均被拒绝；仍需排查 API、源 IP 与其它绕过。' : '未通过；切勿将源站视为安全，检查 CDN 缓存和 Nginx 配置。' );
            }
        }
        update_option( 'jlwa_private_probe_result', $result, false );
        wp_safe_redirect( admin_url( 'admin.php?page=jlwa-download-page' ) );
        exit;
    }

    protected static function op_form( $name, $text, $description = '', $danger = false ) {
        ?>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin:4px 8px 4px 0">
            <input type="hidden" name="action" value="jlwa_private_deploy">
            <input type="hidden" name="jlwa_operation" value="<?php echo esc_attr( $name ); ?>">
            <?php wp_nonce_field( 'jlwa_nginx_' . $name ); ?>
            <?php if ( 'confirm' === $name ) : ?>
                <label><input type="checkbox" name="jlwa_buyer_test_passed" value="1" required> 已经用真实购买资格核对文件完整、下载不中断且浏览器未看到原始存储 URL</label>
            <?php endif; ?>
            <button type="submit" class="button <?php echo $danger ? 'button-secondary' : 'button-primary'; ?>" <?php echo $danger ? 'onclick="return confirm(\'确定执行该部署阶段？请确保有独立备份。\')"' : ''; ?>><?php echo esc_html( $text ); ?></button>
        </form>
        <?php if ( $description ) : ?><span class="description"><?php echo esc_html( $description ); ?></span><?php endif;
    }

    public static function render_panel() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $has_helper = is_file( self::BRIDGE );
        $result = $has_helper ? self::call( 'status' ) : array( 'ok' => false, 'message' => '尚未安装受限部署助手' );
        $status = $result['ok'] ? json_decode( $result['message'], true ) : null;
        $status = is_array( $status ) ? $status : array();
        $phase = $status['phase'] ?? 'not_installed';
        $phases = array(
            'not_installed' => '尚未授权安装（可手动配置）',
            'new' => '授权完成，尚未配置',
            'wp_prepared' => '内部通道已配置，等待真实购买测试',
            'protected' => '规则已写入，仍需公网/CDN/API 外部验收',
            'rolled_back' => '已恢复备份',
        );
        ?>
        <section class="jlwa-download-section" style="margin-top:20px">
            <h3>🛡️ Nginx 安全部署向导</h3>
            <p>一套 OpenList 即使绑定多个域名，也只维护一份源站站点规则。默认不修改任何系统文件。首次使用需要服务器管理员授权一次；之后所有操作都在九流 WP 助手后台执行。</p>
            <p><strong>当前状态：</strong><?php echo esc_html( $phases[ $phase ] ?? '需要人工检查配置' ); ?></p>
            <?php if ( ! $result['ok'] ) : ?><p class="notice notice-warning" style="padding:10px"><?php echo esc_html( $result['message'] ); ?></p><?php endif; ?>
            <?php if ( ! $has_helper ) : ?>
                <p>请参照 <a href="https://github.com/nljie1103/wp-assistant/blob/main/docs/paid-download-security.md" target="_blank" rel="noopener noreferrer">部署教程</a>，通过服务器终端一次性安装插件包内 <code>ops/jlwa-nginx-helper.py</code>。安装脚本会复制为 root 所有，限制可管理的两个站点配置文件，安装后才能点击以下操作。<strong>切勿将 PHP 运行用户加入全权 sudo。</strong></p>
            <?php else : ?>
                <?php self::op_form( 'preview', '① 预览配置差异' ); ?>
                <?php self::op_form( 'prepare', '② 自动备份并配置内部代理' ); ?>
                <?php self::op_form( 'confirm', '③ 确认已购用户下载测试' ); ?>
                <?php self::op_form( 'protect', '④ 封锁 OpenList 公网付费目录' ); ?>
                <?php self::op_form( 'rollback', '恢复本次配置备份', '', true ); ?>
            <?php endif; ?>
            <?php
            $last = get_option( 'jlwa_nginx_last_result', array() );
            if ( is_array( $last ) && ! empty( $last['time'] ) ) :
            ?>
                <details><summary>最近一次部署操作结果</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( ( ! empty( $last['ok'] ) ? '成功：' : '失败：' ) . ( $last['action'] ?? '' ) . "\n" . ( $last['message'] ?? '' ) ); ?></pre></details>
            <?php endif; ?>
            <hr>
            <h4>🔍 已发布付费资源的公网匿名探测</h4>
            <p>选择一个已配置受保护文件直链的付费文章 ID，插件尝试以无 Cookie 的小型 Range 请求从每个设置的 OpenList 访问域名读取该文件，预期均返回 403。不会把真实文件 URL 显示在页面或保存到日志。</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jlwa_private_probe">
                <?php wp_nonce_field( 'jlwa_private_probe' ); ?>
                <input type="number" min="1" name="jlwa_probe_post" placeholder="付费文章 ID" required>
                <button type="submit" class="button button-secondary">检查公开直链是否已封锁</button>
            </form>
            <?php $probe = get_option( 'jlwa_private_probe_result', array() ); if ( is_array( $probe ) && ! empty( $probe['time'] ) ) : ?>
                <p><strong><?php echo ! empty( $probe['ok'] ) ? '本次探测通过：' : '本次探测未通过：'; ?></strong><?php echo esc_html( $probe['message'] ?? '' ); ?></p>
            <?php endif; ?>
            <p><strong>提示：</strong>向导状态仅证明配置阶段；即使本次匿名探测得到 403，也不能代替已购账号、CDN 缓存及 OpenList API 的完整外部安全验收。</p>
        </section>
        <?php
    }
}
JLWA_Private_Deploy::boot();
