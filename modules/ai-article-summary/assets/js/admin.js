/**
 * 九流 - AI 摘要插件 后台 JS
 * 三级联动 + Ajax 测试 + 编辑页生成 + 缓存管理
 */
( function ( $ ) {
	'use strict';

	var WPAIAS = window.WPAIAS_ADMIN || {};
	var providers = WPAIAS.providers || {};
	var i18n = WPAIAS.i18n || {};
	var apiKeys = $.extend( {}, WPAIAS.api_keys || {} );
	var activeKeySlot = '';

	function init() {
		bindProviderChange();
		bindToggleKey();
		bindApiKeyActions();
		bindTestConnection();
		bindCacheActions();
		bindMetaBoxActions();
		bindUpdateActions();
		bindStyleTab();
		// 初始化模型列表。
		var $prov = $( '#wpaias-provider' );
		if ( $prov.length ) {
			loadModelOptions( $prov.val(), true );
			toggleCustomRows( $prov.val() );
			loadApiKeyForCurrentSelection();
		}
		// 在线更新页：自动检查一次。
		if ( $( '#wpaias-check-update' ).length ) {
			doCheckUpdate( false );
		}
	}

	/**
	 * 外观样式 Tab：
	 *  1. 预设卡片点击 → 自动填充 5 个颜色输入 + 切换 .active；
	 *  2. 颜色输入框变更（文本 / 取色器） → 实时同步并刷新实时预览；
	 *  3. 实时预览（#wpaias-live-preview）通过 CSS 变量实时更新。
	 */
	function bindStyleTab() {
		var $grid = $( '.wpaias-style-grid' );
		if ( ! $grid.length ) return;
		var $preview = $( '#wpaias-live-preview' );

		// 1. 预设卡片点击
		$grid.on( 'click', '.wpaias-style-card', function ( e ) {
			e.preventDefault();
			var $card = $( this );
			$grid.find( '.wpaias-style-card' ).removeClass( 'active' );
			$card.addClass( 'active' );
			$card.find( 'input[type="radio"]' ).prop( 'checked', true );

			// 把 data-* 写入颜色输入框 + 同步取色器
			var colors = {
				bg:     $card.data( 'bg' ),
				border: $card.data( 'border' ),
				title:  $card.data( 'title' ),
				text:   $card.data( 'text' ),
				accent: $card.data( 'accent' )
			};
			$.each( colors, function ( key, val ) {
				if ( ! val ) return;
				$( '#wpaias-color-' + key + '-text' ).val( val ).trigger( 'change.preview' );
				var hex = colorToHex( val );
				$( '#wpaias-color-' + key + '-pick' ).val( hex );
			} );

			// 切换预览装饰 class
			if ( $preview.length ) {
				// 移除所有 wpaias-style-* class
				var classes = ( $preview.attr( 'class' ) || '' ).split( /\s+/ ).filter( function ( c ) {
					return c && c.indexOf( 'wpaias-style-' ) !== 0;
				} );
				classes.push( 'wpaias-style-' + ( $card.data( 'key' ) || '' ) );
				// 同时根据 card 上的 wpaias-style-xxx class 取出装饰 class（如 wpaias-style-glass）
				var deco = ( $card.attr( 'class' ) || '' ).match( /wpaias-style-(glass|gradient-blue|gradient-pink|gradient-cyan|outline|paper|neon-cyber|notebook|card-shadow)/ );
				if ( deco ) classes.push( deco[ 0 ] );
				$preview.attr( 'class', classes.join( ' ' ) );
				applyPreviewVars();
			}
		} );

		// 2. 颜色文本输入变更
		$( '.wpaias-color-input' ).on( 'input change change.preview', function () {
			var val = $( this ).val();
			var id  = $( this ).attr( 'id' ); // wpaias-color-xxx-text
			var key = id.replace( 'wpaias-color-', '' ).replace( '-text', '' );
			var hex = colorToHex( val );
			if ( hex ) {
				$( '#wpaias-color-' + key + '-pick' ).val( hex );
			}
			applyPreviewVars();
		} );

		// 3. 取色器（input[type="color"]）变更
		$( '.wpaias-color-pick' ).on( 'input change', function () {
			var $pick = $( this );
			var targetId = $pick.data( 'target' );
			$( '#' + targetId ).val( $pick.val() ).trigger( 'change.preview' );
		} );

		// 首次进入也刷一次预览
		applyPreviewVars();

		function applyPreviewVars() {
			if ( ! $preview.length ) return;
			var bg     = $( '#wpaias-color-bg-text' ).val() || '#1a1a1a';
			var border = $( '#wpaias-color-border-text' ).val() || '#333';
			var title  = $( '#wpaias-color-title-text' ).val() || '#fff';
			var text   = $( '#wpaias-color-text-text' ).val() || '#ccc';
			var accent = $( '#wpaias-color-accent-text' ).val() || '#ffd95a';
			$preview.attr( 'style',
				'--wpaias-bg:' + bg + ';' +
				'--wpaias-border:' + border + ';' +
				'--wpaias-title:' + title + ';' +
				'--wpaias-text:' + text + ';' +
				'--wpaias-accent:' + accent + ';'
			);
		}
	}

	/**
	 * 将任意颜色字符串转换为 #rrggbb（用于设置 <input type="color">）。
	 */
	function colorToHex( c ) {
		if ( ! c ) return '#ffffff';
		c = ( '' + c ).trim();
		if ( c.toLowerCase() === 'transparent' ) return '#ffffff';
		var m;
		// #rgb
		m = c.match( /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i );
		if ( m ) return '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3];
		// #rrggbb / #rrggbbaa
		m = c.match( /^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i );
		if ( m ) return '#' + m[1].toLowerCase();
		// rgb / rgba
		m = c.match( /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i );
		if ( m ) {
			function pad( n ) { n = parseInt( n, 10 ); if ( n < 0 ) n = 0; if ( n > 255 ) n = 255; var s = n.toString( 16 ); return s.length === 1 ? '0' + s : s; }
			return '#' + pad( m[1] ) + pad( m[2] ) + pad( m[3] );
		}
		return '#ffffff';
	}

	/**
	 * 二级联动：服务商 → 模型。
	 */
	function loadModelOptions( providerKey, useCurrent ) {
		var $sel = $( '#wpaias-model' );
		if ( ! $sel.length ) return;

		var current = useCurrent ? ( $sel.data( 'current' ) || '' ) : '';
		$sel.empty();

		var p = providers[ providerKey ];
		if ( ! p ) return;

		if ( providerKey === 'custom' ) {
			// 自定义模式：隐藏模型下拉。
			return;
		}

		var models = p.models || [];
		if ( models.length === 0 ) {
			$sel.append( $( '<option/>' ).val( '' ).text( '（无可用模型）' ) );
			return;
		}

		$.each( models, function ( i, m ) {
			$sel.append( $( '<option/>' ).val( m ).text( m ) );
		} );

		// 复原已选。
		if ( current && models.indexOf( current ) !== -1 ) {
			$sel.val( current );
		}
	}

	function toggleCustomRows( providerKey ) {
		var $modelRow  = $( '#wpaias-model-row' );
		var $cep       = $( '#wpaias-custom-endpoint-row' );
		var $cmodel    = $( '#wpaias-custom-model-row' );

		if ( providerKey === 'custom' ) {
			$modelRow.hide();
			$cep.show();
			$cmodel.show();
		} else {
			$modelRow.show();
			$cep.hide();
			$cmodel.hide();
		}
	}

	function rfc3986Encode( value ) {
		return encodeURIComponent( value ).replace( /[!'()*]/g, function ( c ) {
			return '%' + c.charCodeAt( 0 ).toString( 16 ).toUpperCase();
		} );
	}

	function currentProvider() {
		return $( '#wpaias-provider' ).val() || '';
	}

	function currentModel() {
		if ( currentProvider() === 'custom' ) {
			return ( $( '#wpaias-custom-model' ).val() || '' ).trim();
		}
		return $( '#wpaias-model' ).val() || '';
	}

	function apiKeySlot( providerKey, modelName ) {
		modelName = ( modelName || '' ).trim();
		if ( ! providerKey || ! modelName ) {
			return '';
		}
		return providerKey + '::' + rfc3986Encode( modelName );
	}

	function syncApiKeysJson() {
		var $json = $( '#wpaias-api-keys-json' );
		if ( $json.length ) {
			$json.val( JSON.stringify( apiKeys ) );
		}
	}

	function storeCurrentApiKey( useCurrentSelection ) {
		var $key = $( '#wpaias-api-key' );
		if ( ! $key.length ) return;

		var slot = useCurrentSelection ? apiKeySlot( currentProvider(), currentModel() ) : activeKeySlot;
		if ( ! slot ) return;

		var value = $key.val() || '';
		if ( value ) {
			apiKeys[ slot ] = value;
		} else {
			delete apiKeys[ slot ];
		}
		syncApiKeysJson();
	}

	function loadApiKeyForCurrentSelection() {
		var $key = $( '#wpaias-api-key' );
		if ( ! $key.length ) return;

		var providerKey = currentProvider();
		var modelName = currentModel();
		var slot = apiKeySlot( providerKey, modelName );
		activeKeySlot = slot;

		if ( slot && Object.prototype.hasOwnProperty.call( apiKeys, slot ) ) {
			$key.val( apiKeys[ slot ] );
		} else {
			$key.val( '' );
		}

		var label = modelName ? providerKey + ' / ' + modelName : providerKey;
		$( '#wpaias-key-binding-label' ).text( label ? '当前绑定：' + label : '' );
		syncApiKeysJson();
	}

	function bindApiKeyActions() {
		$( document ).on( 'change', '#wpaias-model', function () {
			storeCurrentApiKey( false );
			loadApiKeyForCurrentSelection();
		} );

		$( document ).on( 'change blur', '#wpaias-custom-model', function () {
			storeCurrentApiKey( false );
			loadApiKeyForCurrentSelection();
		} );

		$( document ).on( 'input change', '#wpaias-api-key', function () {
			storeCurrentApiKey( false );
		} );

		$( document ).on( 'submit', '#wpaias-form', function () {
			storeCurrentApiKey( true );
		} );
	}

	function bindProviderChange() {
		$( document ).on( 'change', '#wpaias-provider', function () {
			storeCurrentApiKey( false );
			var key = $( this ).val();
			loadModelOptions( key, false );
			toggleCustomRows( key );
			loadApiKeyForCurrentSelection();
		} );
	}

	function bindToggleKey() {
		$( document ).on( 'click', '#wpaias-toggle-key', function ( e ) {
			e.preventDefault();
			var $i = $( '#wpaias-api-key' );
			$i.attr( 'type', $i.attr( 'type' ) === 'password' ? 'text' : 'password' );
		} );
	}

	function bindTestConnection() {
		$( document ).on( 'click', '#wpaias-test-conn', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $res = $( '#wpaias-test-result' );

			$res.removeClass( 'ok fail' ).text( i18n.testing || '...' );
			$btn.prop( 'disabled', true );
			storeCurrentApiKey( true );

			var data = {
				action: 'wpaias_test_connection',
				nonce: WPAIAS.nonce,
				provider: currentProvider(),
				model: currentModel(),
				current_api_key: $( '#wpaias-api-key' ).val(),
				endpoint: $( '#wpaias-custom-endpoint' ).val(),
				custom_model: $( '#wpaias-custom-model' ).val()
			};

			$.post( WPAIAS.ajax_url, data )
				.done( function ( resp ) {
					if ( resp && resp.success ) {
						$res.addClass( 'ok' ).text( ( i18n.test_ok || 'OK' ) + ' ' + ( resp.data.sample || '' ) );
					} else {
						$res.addClass( 'fail' ).text( ( i18n.test_fail || 'fail: ' ) + ( resp && resp.data && resp.data.message ? resp.data.message : 'unknown' ) );
					}
				} )
				.fail( function ( xhr ) {
					$res.addClass( 'fail' ).text( ( i18n.test_fail || 'fail: ' ) + ( xhr.status + ' ' + xhr.statusText ) );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	}

	function bindCacheActions() {
		$( document ).on( 'click', '#wpaias-clear-all', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( i18n.confirm || 'Confirm?' ) ) return;
			var $btn = $( this );
			var $res = $( '#wpaias-clear-all-result' );
			$btn.prop( 'disabled', true );
			$res.removeClass( 'ok fail' ).text( '...' );
			$.post( WPAIAS.ajax_url, {
				action: 'wpaias_clear_all_cache',
				nonce: WPAIAS.nonce
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$res.addClass( 'ok' ).text( resp.data.message || ( i18n.cleared || 'cleared' ) );
					$( '#wpaias-cache-count' ).text( '0' );
				} else {
					$res.addClass( 'fail' ).text( resp && resp.data && resp.data.message ? resp.data.message : 'fail' );
				}
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( document ).on( 'click', '#wpaias-clear-by-id', function ( e ) {
			e.preventDefault();
			var id = parseInt( $( '#wpaias-clear-id' ).val(), 10 ) || 0;
			if ( ! id ) return;
			var $btn = $( this );
			var $res = $( '#wpaias-clear-id-result' );
			$btn.prop( 'disabled', true );
			$res.removeClass( 'ok fail' ).text( '...' );
			$.post( WPAIAS.ajax_url, {
				action: 'wpaias_clear_cache_by_id',
				nonce: WPAIAS.nonce,
				post_id: id
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$res.addClass( 'ok' ).text( resp.data.message || ( i18n.cleared || 'cleared' ) );
					if ( typeof resp.data.count !== 'undefined' ) {
						$( '#wpaias-cache-count' ).text( resp.data.count );
					}
				} else {
					$res.addClass( 'fail' ).text( resp && resp.data && resp.data.message ? resp.data.message : 'fail' );
				}
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	}

	function bindMetaBoxActions() {
		function doGen( $box, force ) {
			var pid = parseInt( $box.data( 'post-id' ), 10 ) || 0;
			if ( ! pid ) return;
			var $res = $box.find( '.wpaias-mb-result' );
			$res.show().removeClass( 'ok fail' ).text( i18n.generating || '...' );

			$.post( WPAIAS.ajax_url, {
				action: 'wpaias_generate_summary',
				nonce: WPAIAS.nonce,
				post_id: pid,
				force: force ? 1 : 0
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$res.addClass( 'ok' ).text( resp.data.message || ( i18n.gen_ok || 'ok' ) );
					$box.find( '.wpaias-mb-status' ).removeClass( 'no' ).addClass( 'ok' ).text( '● ' + ( i18n.gen_ok || '已生成' ) );
					$box.find( '.wpaias-mb-preview' ).html(
						'<small style="display:block;margin-top:8px;color:#888;">预览：</small>' +
						'<div class="wpaias-mb-text"></div>'
					);
					$box.find( '.wpaias-mb-text' ).text( ( resp.data.summary || '' ).substring( 0, 200 ) );
				} else {
					$res.addClass( 'fail' ).text( ( i18n.gen_fail || 'fail: ' ) + ( resp && resp.data && resp.data.message ? resp.data.message : 'unknown' ) );
				}
			} ).fail( function () {
				$res.addClass( 'fail' ).text( i18n.gen_fail || 'fail' );
			} );
		}

		$( document ).on( 'click', '.wpaias-mb-gen', function ( e ) {
			e.preventDefault();
			doGen( $( this ).closest( '.wpaias-mb' ), false );
		} );

		$( document ).on( 'click', '.wpaias-mb-regen', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( i18n.confirm || 'Confirm?' ) ) return;
			doGen( $( this ).closest( '.wpaias-mb' ), true );
		} );

		$( document ).on( 'click', '.wpaias-mb-clear', function ( e ) {
			e.preventDefault();
			var $box = $( this ).closest( '.wpaias-mb' );
			var pid  = parseInt( $box.data( 'post-id' ), 10 ) || 0;
			if ( ! pid ) return;
			$.post( WPAIAS.ajax_url, {
				action: 'wpaias_clear_post_cache',
				nonce: WPAIAS.nonce,
				post_id: pid
			} ).done( function ( resp ) {
				var $res = $box.find( '.wpaias-mb-result' );
				$res.show();
				if ( resp && resp.success ) {
					$res.removeClass( 'fail' ).addClass( 'ok' ).text( resp.data.message );
					$box.find( '.wpaias-mb-status' ).removeClass( 'ok' ).addClass( 'no' ).text( '○ 暂无缓存' );
					$box.find( '.wpaias-mb-preview' ).empty();
				} else {
					$res.removeClass( 'ok' ).addClass( 'fail' ).text( resp && resp.data && resp.data.message ? resp.data.message : 'fail' );
				}
			} );
		} );
	}

	/**
	 * 在线更新：检查 & 执行更新。
	 */
	function bindUpdateActions() {
		$( document ).on( 'click', '#wpaias-check-update', function ( e ) {
			e.preventDefault();
			doCheckUpdate( true );
		} );

		$( document ).on( 'click', '#wpaias-do-update', function ( e ) {
			e.preventDefault();
			if ( $( this ).prop( 'disabled' ) ) return;
			if ( ! window.confirm( '即将下载并覆盖本地插件文件，确认继续？' ) ) return;

			var $btn   = $( this );
			var $check = $( '#wpaias-check-update' );
			var $msg   = $( '#wpaias-update-status' );

			$btn.prop( 'disabled', true ).addClass( 'updating-message' );
			$check.prop( 'disabled', true );
			$msg.removeClass( 'ok fail warn' ).addClass( 'pending' ).html( '⏳ 正在下载并解压最新版本…（请勿关闭页面）' );

			$.ajax( {
				url: WPAIAS.ajax_url,
				type: 'POST',
				timeout: 120000,
				data: {
					action: 'wpaias_do_update',
					nonce: WPAIAS.nonce
				}
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$msg.removeClass( 'pending fail warn' ).addClass( 'ok' ).html(
						'✅ ' + ( resp.data.message || '更新完成。' ) +
						'<br><small>页面将在 2 秒后自动刷新加载新版本…</small>'
					);
					window.setTimeout( function () { window.location.reload(); }, 2000 );
				} else {
					var msg = resp && resp.data && resp.data.message ? resp.data.message : '更新失败';
					$msg.removeClass( 'pending ok warn' ).addClass( 'fail' ).html( '❌ ' + msg );
					$btn.prop( 'disabled', false ).removeClass( 'updating-message' );
					$check.prop( 'disabled', false );
				}
			} ).fail( function ( xhr ) {
				$msg.removeClass( 'pending ok warn' ).addClass( 'fail' ).html( '❌ 网络错误：' + xhr.status + ' ' + xhr.statusText );
				$btn.prop( 'disabled', false ).removeClass( 'updating-message' );
				$check.prop( 'disabled', false );
			} );
		} );
	}

	function doCheckUpdate( force ) {
		var $btn  = $( '#wpaias-check-update' );
		var $upd  = $( '#wpaias-do-update' );
		var $msg  = $( '#wpaias-update-status' );
		var $log  = $( '#wpaias-changelog' );

		$btn.prop( 'disabled', true );
		$upd.prop( 'disabled', true );
		$msg.removeClass( 'ok fail warn' ).addClass( 'pending' ).html( '⏳ 正在从 GitHub 获取最新版本信息…' );

		$.ajax( {
			url: WPAIAS.ajax_url,
			type: 'POST',
			timeout: 30000,
			data: {
				action: 'wpaias_check_update',
				nonce: WPAIAS.nonce,
				force: force ? 1 : 0
			}
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				var d = resp.data || {};
				var current = d.current_version || '?';
				var latest  = d.latest_version || '?';
				var has     = !!d.has_update;
				var html;

				if ( has ) {
					html = '⚠️ 检测到新版本：本地 v' + current + ' → 远程 v' + latest +
						'<br><small>点击右侧 "一键在线更新" 立即升级。</small>';
					$msg.removeClass( 'pending ok fail' ).addClass( 'warn' ).html( html );
					$upd.prop( 'disabled', false ).removeClass( 'updating-message' );
				} else {
					html = '✅ 当前已是最新版本（v' + current + '）。';
					$msg.removeClass( 'pending warn fail' ).addClass( 'ok' ).html( html );
					$upd.prop( 'disabled', true );
				}

				if ( d.changelog ) {
					$log.text( d.changelog );
				}
			} else {
				var msg = resp && resp.data && resp.data.message ? resp.data.message : '检查失败';
				$msg.removeClass( 'pending ok warn' ).addClass( 'fail' ).html( '❌ ' + msg );
			}
		} ).fail( function ( xhr ) {
			$msg.removeClass( 'pending ok warn' ).addClass( 'fail' ).html( '❌ 网络错误：' + xhr.status + ' ' + xhr.statusText );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	}

	$( init );
} )( jQuery );
