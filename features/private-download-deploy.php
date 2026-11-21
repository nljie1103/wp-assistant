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
        $images_raw = trim( (string) ( $settings['openlist_public_images'] ?? '' ) );
        $images = JLWA_Private_Download::prefix( $images_raw );
        if ( ! $images || ! preg_match( '#^/d/(?:[\p{L}\p{N}_-]+/){2,}$#u', $images ) ||
            0 === strpos( $images, $source ) || 0 === strpos( $source, $images ) ) {
            return '';
        }
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
            '--openlist-hosts', implode( ',', $hosts ),
            '--port', $port,
            '--source', $source,
            '--php-user', $php_user,
        );
        if ( '' !== $container ) {
            $args[] = '--container';
            $args[] = $container;
        }
        $args[] = '--public-images';
        $args[] = $images;
        $command = 'sudo python3 ' . escapeshellarg( $script ) . ' install-auto';
        foreach ( $args as $index => $arg ) {
            $command .= ' ' . ( 0 === $index % 2 ? $arg : escapeshellarg( $arg ) );
        }
        return $command;
    }

    /** Read-only Nginx snippet generator for sites without supported Baota layout. */
    public static function portable_command( $settings, $wordpress_host = null, $plugin_dir = null, $php_user = null ) {
        $base = self::installer_command( $settings, $wordpress_host, $plugin_dir, $php_user );
        if ( '' === $base ) { return ''; }
        return str_replace( ' install-auto', ' render-config', preg_replace( '/^sudo python3 /', 'python3 ', $base ) );
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
            // A saved settings change never rewrites the root-approved
            // manifest. Refuse to run step ④ against a stale public policy.
            $approved_images = (string) ( $approved['approved_public_images'] ?? '' );
            $configured_images = trim( (string) ( $options['openlist_public_images'] ?? '' ) );
            if ( $configured_images !== $approved_images ) {
                wp_die( '当前公开图片目录与首次 root 安装时授权的策略不一致；请检查已安装助手的只读状态，不要重复执行第④步。修改 WordPress 设置不会自动修改 root 授权。', '公网策略不一致', array( 'response' => 400 ) );
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
            <p>新安装统一使用<strong>严格公网仅图片</strong>策略，不提供弱保护模式。允许公开图片目录的 GET/HEAD，其余 OpenList 入口只允许本机访问。必须先填写域名、本机端口、私有源目录、公开图片目录。仅支持能够通过安全预检的宝塔 Nginx 单 server 配置。首次 root 安装只授予有限命令权限，之后逐步验收，不会自动开放敏感路径。</p>
            <p><strong>当前状态：</strong><?php echo esc_html( $phases[ $phase ] ?? '需要人工检查配置' ); ?></p>
            <?php if ( $has_helper ) : ?>
                <p><strong>公网访问状态：</strong>
                    <?php if ( 'strict_images_only' === ( $status['public_access_policy'] ?? '' ) ) : ?>
                        严格模式：公网 GET/HEAD 只允许 <code><?php echo esc_html( $status['approved_public_images'] ?? '' ); ?></code>，其余路径（首页、登录、API、WebDAV、下载与预览）只允许回环访问。
                    <?php else : ?>
                        <strong>旧版非严格配置，无法证明满足当前安全目标。</strong>请保持现有业务不变，并由服务器管理员单独检查；新版初次安装不再生成此类规则。
                    <?php endif; ?>
                </p>
            <?php endif; ?>
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
                <details id="jlwa-deploy-result" class="jlwa-deploy-result <?php echo ! empty( $last['ok'] ) ? 'is-success' : 'is-error'; ?>" <?php echo $show_last ? 'open' : ''; ?>>
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
                <p>首次安装时，插件会从已保存设置一次确定公网访问策略、WordPress 域名、OpenList 域名及本地端口，无需单独安装旧规则再升级。</p>
                <?php $initial_options = JLWA_Download_Page_Feature::options(); ?>
                <?php if ( ! empty( $initial_options['openlist_public_images'] ) ) : ?>
                    <p><strong>本次首次安装直接采用「严格公网仅图片」策略：</strong><code><?php echo esc_html( $initial_options['openlist_public_images'] ); ?></code>。第④步一次写入完整白名单。</p>
                <?php else : ?>
                    <p class="notice notice-warning" style="padding:10px"><strong>必须先填写公开图片目录：</strong>缺少此项时不会生成安装命令，更不会退回只封锁付费目录的弱策略。请先去「<a href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-download-page&tab=advanced' ) ); ?>">交付高级设置</a>」填写图片目录并保存，再复制安装命令。</p>
                <?php endif; ?>
                <?php
                $command = self::installer_command( JLWA_Download_Page_Feature::options() );
                if ( $command ) :
                ?>
                    <label for="jlwa-private-install-command"><strong>在服务器 root / sudo 终端执行以下命令：</strong></label>
                    <textarea id="jlwa-private-install-command" class="large-text code" rows="3" readonly style="margin:8px 0;white-space:pre-wrap"><?php echo esc_textarea( $command ); ?></textarea>
                    <button type="button" class="button button-secondary" onclick="var f=document.getElementById('jlwa-private-install-command');f.focus();f.select();document.execCommand('copy');this.textContent='命令已复制';">复制一键安装命令</button>
                    <p class="description">首次执行时脚本以管理员权限自动定位宝塔的 WordPress/OpenList Nginx 配置文件，并验证本机 Docker 端口。此时只完成受限授权，不会直接封锁付费文件。</p>
                <?php else : ?>
                    <p class="notice notice-warning" style="padding:12px">请填写并保存 OpenList 源站域名、私有源目录、宿主机回环端口及唯一公开图片目录；所有字段通过验证后才会生成受限安装命令。</p>
                <?php endif; ?>
                <?php if ( $command ) : ?>
                    <details style="margin:12px 0">
                        <summary>非宝塔 / 自定义 Nginx：只读生成手动配置片段</summary>
                        <p>自动部署只支持通过预检的宝塔单 server 虚拟主机。其它 Nginx 环境可执行以下<strong>不需要 root、不会写入任何配置</strong>的命令，分别复制两段规则到 WordPress 和每一个 OpenList 域名的 Nginx server 块。必须由服务器管理员自行备份、检查并部署，不能视为已完成安全保护。</p>
                        <textarea id="jlwa-portable-command" class="large-text code" rows="3" readonly><?php echo esc_textarea( self::portable_command( $initial_options ) ); ?></textarea>
                        <button type="button" class="button button-secondary" onclick="var e=document.getElementById('jlwa-portable-command');e.focus();e.select();document.execCommand('copy')">复制只读生成命令</button>
                    </details>
                <?php endif; ?>
                <p><a href="https://github.com/nljie1103/wp-assistant/blob/main/docs/paid-download-security.md" target="_blank" rel="noopener noreferrer">查看简明部署教程</a>。根目录配置文件只有 root 可读取；插件不会为方便自动化而放开 Nginx 文件权限。</p>
                </details>
            <?php else : ?>
                <?php self::op_form( 'preview', '① 预览配置差异' ); ?>
                <?php self::op_form( 'prepare', '② 自动备份并配置内部代理' ); ?>
                <?php self::op_form( 'confirm', '③ 确认已购用户下载测试' ); ?>
                <?php self::op_form( 'protect', '④ 应用公网文件访问保护规则' ); ?>
                <?php if ( 'strict_images_only' !== ( $status['public_access_policy'] ?? '' ) ) { self::op_form( 'rollback', '恢复本次配置备份', '', true ); } ?>
            <?php endif; ?>

            <?php if ( $has_helper && 'protected' === $phase ) : ?>
                <hr>
                <h4>🔐 已部署公网访问边界</h4>
                <?php if ( 'strict_images_only' === ( $status['public_access_policy'] ?? '' ) ) : ?>
                    <p>受限助手报告 <code>strict_images_only</code>，公开目录 <code><?php echo esc_html( $status['approved_public_images'] ?? '' ); ?></code>。请另外检查两个域名的公网首页/API/WebDAV/私有 ZIP 均为 403、真实图片为 200，本机 OpenList 和已购 WordPress 下载正常。已保护并不等于完成全部业务验收。</p>
                <?php else : ?>
                    <p class="notice notice-error" role="alert">当前部署仍是历史非严格策略。新版首次安装已移除所有弱模式与分阶段迁移入口。请不要重复执行首次安装或手动删除受限助手；需要更换旧部署时，应由服务器管理员先完整备份、独立安排迁移。</p>
                <?php endif; ?>
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
                <p id="jlwa-probe-result" class="jlwa-probe-result <?php echo ! empty( $probe['ok'] ) ? 'is-success' : 'is-error'; ?>" role="status"><strong><?php echo ! empty( $probe['ok'] ) ? '本次探测通过：' : '本次探测未通过：'; ?></strong><?php echo esc_html( $probe['message'] ?? '' ); ?></p>
            <?php endif; ?>
            <p><strong>提示：</strong>向导状态仅证明配置阶段；即使本次匿名探测得到 403，也不能代替已购账号、CDN 缓存及 OpenList API 的完整外部安全验收。</p>
        </section>
        <?php
    }
}
JLWA_Private_Deploy::boot();
