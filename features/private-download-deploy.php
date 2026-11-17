<?php
/** Administrator-only UI for root-approved Nginx deployment. No theme modifications. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Private_Deploy {
    const BRIDGE = '/usr/local/sbin/jlwa-nginx-helper';
    const ACTIONS = array( 'status', 'preview', 'prepare', 'confirm', 'protect', 'rollback' );

    /**
     * Generate a single, strictly escaped command from saved WordPress settings.
     * No Nginx paths or container name are required for the normal install.
     * The root-owned installer discovers vhosts and checks Docker at run time.
     */
    public static function installer_command( $settings, $wordpress_host = null, $plugin_dir = null, $php_user = null ) {
        $settings = is_array( $settings ) ? $settings : array();
        $wordpress_host = null === $wordpress_host ? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : $wordpress_host;
        $plugin_dir = null === $plugin_dir ? JLWA_PLUGIN_DIR : $plugin_dir;
        if ( null === $php_user ) {
            $php_user = 'www';
            if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
                $user = posix_getpwuid( posix_geteuid() );
                if ( is_array( $user ) && ! empty( $user['name'] ) ) {
                    $php_user = $user['name'];
                }
            }
        }
        $hosts = JLWA_Private_Download::hosts( $settings['private_hosts'] ?? '' );
        $source = JLWA_Private_Download::prefix( $settings['private_source_prefix'] ?? '' );
        $port = trim( (string) ( $settings['openlist_host_port'] ?? '' ) );
        $container = trim( (string) ( $settings['openlist_container'] ?? '' ) );
        $wordpress_host = strtolower( (string) $wordpress_host );
        $plugin_dir = (string) $plugin_dir;
        if ( ! $hosts || ! $source || ! preg_match( '/^[0-9]{1,5}$/D', $port ) || (int) $port < 1 || (int) $port > 65535
            || JLWA_Private_Download::hosts( $wordpress_host ) !== array( $wordpress_host )
            || ! preg_match( '/^[a-z_][a-z0-9_-]{0,30}$/D', (string) $php_user )
            || ( '' !== $container && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $container ) )
            || '' === $plugin_dir || '/' !== $plugin_dir[0] ) {
            return '';
        }
        $script = rtrim( $plugin_dir, '/' ) . '/ops/jlwa-nginx-helper.py';
        $args = array(
            '--wordpress-host', $wordpress_host,
            '--openlist-host', $hosts[0],
            '--port', $port,
            '--source', $source,
            '--php-user', $php_user,
        );
        if ( '' !== $container ) {
            $args[] = '--container';
            $args[] = $container;
        }
        $command = 'sudo python3 ' . escapeshellarg( $script ) . ' install-auto';
        foreach ( $args as $index => $arg ) {
            $command .= ' ' . ( 0 === $index % 2 ? $arg : escapeshellarg( $arg ) );
        }
        return $command;
    }

    public static function boot() {
        add_action( 'admin_post_jlwa_private_deploy', array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_jlwa_private_probe', array( __CLASS__, 'probe' ) );
    }

    /** Fixed command and argument set, never accepts paths or shell text from HTTP. */
    public static function call( $operation ) {
        if ( ! in_array( $operation, self::ACTIONS, true ) ) {
            return array( 'ok' => false, 'message' => '不支持的部署操作。' );
        }
        // A Baota/PHP-FPM site may set open_basedir to its website directory.
        // is_file('/usr/local/sbin/...') can then return false even though the
        // root-owned helper is correctly installed. PHP must NEVER need to
        // read the privileged executable: only sudo may execute it.
        if ( ! function_exists( 'proc_open' ) ) {
            return array( 'ok' => false, 'message' => 'PHP 运行环境禁用了 proc_open，无法查询已安装的受限助手。请检查当前站点 PHP-FPM 的 disable_functions；不要重复安装或放宽 Nginx 文件权限。' );
        }

        $pipes = array();
        try {
            // Array form avoids invoking a shell and accepts only whitelisted ops.
            // Absolute sudo path also works when PHP-FPM has a restricted PATH.
            $process = @proc_open(
                array( '/usr/bin/sudo', '-n', self::BRIDGE, $operation ),
                array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
                $pipes
            );
        } catch ( \Throwable $error ) {
            return array( 'ok' => false, 'message' => 'PHP 无法启动受限助手，请检查 proc_open 是否允许执行 sudo，以及 PHP-FPM 的运行权限。' );
        }
        if ( ! is_resource( $process ) ) {
            return array( 'ok' => false, 'message' => 'PHP 无法启动受限助手，请检查 PHP-FPM 的 proc_open 与 sudo 授权。' );
        }
        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1], 32768 );
        $stderr = stream_get_contents( $pipes[2], 8192 );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $process );
        if ( 0 !== $code ) {
            $detail = trim( (string) $stderr );
            if ( '' === $detail ) {
                $detail = trim( (string) $stdout );
            }
            return array(
                'ok' => false,
                'message' => '无法通过 sudo 查询部署助手（退出码 ' . (int) $code . '）。' . ( '' !== $detail ? '系统信息：' . $detail : '请检查 PHP-FPM 用户与 sudoers 授权。' ),
            );
        }
        return array( 'ok' => true, 'message' => trim( (string) $stdout ) );
    }

    /** Keep all deployment and anonymous-probe results on their own admin tab. */
    public static function result_redirect_url( $type = 'deployment' ) {
        $type = 'probe' === $type ? 'probe' : 'deployment';
        $flag = 'probe' === $type ? 'jlwa_probe_result' : 'jlwa_deploy_result';
        $anchor = 'probe' === $type ? 'jlwa-probe-result' : 'jlwa-deploy-result';
        return admin_url( 'admin.php?page=jlwa-download-page&tab=deployment&' . $flag . '=1' ) . '#' . $anchor;
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
        wp_safe_redirect( self::result_redirect_url( 'deployment' ), 303 );
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
        wp_safe_redirect( self::result_redirect_url( 'probe' ), 303 );
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
        // Query the privileged helper, not the PHP file-access sandbox.
        $result = self::call( 'status' );
        $status = ! empty( $result['ok'] ) ? json_decode( $result['message'], true ) : null;
        $status = is_array( $status ) ? $status : array();
        $has_helper = ! empty( $result['ok'] ) && isset( $status['phase'] ) && is_string( $status['phase'] );
        if ( ! $has_helper && ! empty( $result['ok'] ) ) {
            $result = array( 'ok' => false, 'message' => '受限助手返回无效状态；请在终端检查 sudo -n 授权与状态输出。' );
        }
        $phase = $has_helper ? $status['phase'] : 'unknown';
        $phases = array(
            'unknown' => '安装状态尚未确认（不代表未安装）',
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
            <?php if ( ! empty( $status['docker_container_detected'] ) ) : ?><p>已识别与所填本地端口对应的 Docker 容器映射，并确认端口未对公网发布。</p><?php endif; ?>
            <?php
            // The redirect includes this flag only after handling a form POST.
            // Display the latest result immediately instead of hiding it in
            // a collapsed details block or sending users to the template tab.
            $last = get_option( 'jlwa_nginx_last_result', array() );
            $has_last = is_array( $last ) && ! empty( $last['time'] );
            $show_last = isset( $_GET['jlwa_deploy_result'] ) && '1' === (string) $_GET['jlwa_deploy_result'];
            if ( $has_last ) :
            ?>
                <details id="jlwa-deploy-result" class="jlwa-deploy-result" <?php echo $show_last ? 'open' : ''; ?>>
                    <summary><strong><?php echo esc_html( ( ! empty( $last['ok'] ) ? '成功' : '失败' ) . '：' . ( $last['action'] ?? 'Nginx 操作' ) ); ?></strong> <span>最近一次部署操作结果（点击查看详细输出）</span></summary>
                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( ( ! empty( $last['ok'] ) ? '成功：' : '失败：' ) . ( $last['action'] ?? '' ) . "\n" . ( $last['message'] ?? '' ) ); ?></pre>
                </details>
            <?php elseif ( $show_last ) : ?>
                <p id="jlwa-deploy-result" class="notice notice-warning" role="alert">本次操作没有找到可展示的结果，请检查管理员权限和日志。</p>
            <?php endif; ?>

            <?php if ( ! $has_helper ) : ?>
                <p><strong>已在终端看到 installed 的站长请不要重新安装。</strong> 当前仅表示 WordPress PHP 无法通过受限 sudo 读取助手状态。可在服务器终端进行<strong>只读诊断</strong>：</p>
                <textarea class="large-text code" rows="2" readonly><?php echo esc_textarea( 'sudo -u www /usr/bin/sudo -n ' . self::BRIDGE . ' status' ); ?></textarea>
                <p class="description">若输出 JSON，说明助手已安装，重点检查 WordPress 站点 PHP-FPM 的 <code>proc_open</code>、运行用户和 sudoers；若提示需要密码或不允许操作，请核对 PHP 实际运行用户。<strong>不要 chmod 777、不要授权全权 sudo、不要重复执行安装。</strong></p>
                <details><summary>尚未首次安装？展开查看安装命令</summary>
                <p>首次安装时，插件自动读取 WordPress 域名和已保存的 OpenList 域名、目录与本机端口，无需手工寻找 Nginx 配置文件。</p>
                <?php
                $command = self::installer_command( JLWA_Download_Page_Feature::options() );
                if ( $command ) :
                ?>
                    <label for="jlwa-private-install-command"><strong>在服务器 root / sudo 终端执行以下命令：</strong></label>
                    <textarea id="jlwa-private-install-command" class="large-text code" rows="3" readonly style="margin:8px 0;white-space:pre-wrap"><?php echo esc_textarea( $command ); ?></textarea>
                    <button type="button" class="button button-secondary" onclick="var f=document.getElementById('jlwa-private-install-command');f.focus();f.select();document.execCommand('copy');this.textContent='命令已复制';">复制一键安装命令</button>
                    <p class="description">首次执行时脚本以管理员权限自动定位宝塔的 WordPress/OpenList Nginx 配置文件，并验证本机 Docker 端口。此时只完成受限授权，不会直接封锁付费文件。</p>
                <?php else : ?>
                    <p class="notice notice-warning" style="padding:12px">请先在上方填写受保护源站域名、公开源路径和 OpenList 本机端口，点击“保存下载页设置”，刷新后将自动生成一条可复制的完整命令。</p>
                <?php endif; ?>
                <p><a href="https://github.com/nljie1103/wp-assistant/blob/main/docs/paid-download-security.md" target="_blank" rel="noopener noreferrer">查看简明部署教程</a>。根目录配置文件只有 root 可读取；插件不会为方便自动化而放开 Nginx 文件权限。</p>
                </details>
            <?php else : ?>
                <?php self::op_form( 'preview', '① 预览配置差异' ); ?>
                <?php self::op_form( 'prepare', '② 自动备份并配置内部代理' ); ?>
                <?php self::op_form( 'confirm', '③ 确认已购用户下载测试' ); ?>
                <?php self::op_form( 'protect', '④ 封锁 OpenList 公网付费目录' ); ?>
                <?php self::op_form( 'rollback', '恢复本次配置备份', '', true ); ?>
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
                <p id="jlwa-probe-result" role="status"><strong><?php echo ! empty( $probe['ok'] ) ? '本次探测通过：' : '本次探测未通过：'; ?></strong><?php echo esc_html( $probe['message'] ?? '' ); ?></p>
            <?php endif; ?>
            <p><strong>提示：</strong>向导状态仅证明配置阶段；即使本次匿名探测得到 403，也不能代替已购账号、CDN 缓存及 OpenList API 的完整外部安全验收。</p>
        </section>
        <?php
    }
}
JLWA_Private_Deploy::boot();
