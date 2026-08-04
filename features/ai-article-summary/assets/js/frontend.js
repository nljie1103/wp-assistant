/**
 * 九流 - AI 摘要插件 · 前端 JS
 *
 * 工作流程：
 *   1. 优先检测页面中已存在的 .wpaias-summary（由 the_content 自动注入）→ 直接初始化动画 / Ajax；
 *   2. 若不存在，则尝试从 #wpaias-summary-template 模板克隆，
 *      按用户配置的 CSS 选择器列表寻找文章容器并注入（适配 Zibll/Astra/Divi/Elementor/FSE 等绕开 the_content 的主题）；
 *   3. 注入完成后再统一进入动画 + Ajax 拉取流程。
 */
( function () {
	'use strict';

	var WPAIAS = window.WPAIAS_FRONT || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		// 0) 若页面已经有摘要节点（来自 the_content），直接初始化。
		var existing = document.querySelectorAll( '.wpaias-summary' );

		if ( existing.length === 0 ) {
			// 1) 尝试通过 template + 选择器智能注入。
			tryDomInject();
		}

		// 2) 重新查询并初始化所有节点。
		var nodes = document.querySelectorAll( '.wpaias-summary' );
		if ( ! nodes.length ) {
			return;
		}
		nodes.forEach( function ( box ) {
			initBox( box );
		} );

		// 3) 监听 DOM 变化（懒加载、SPA 主题、Elementor 视图切换等）。
		observeLateContent();
	} );

	/**
	 * 当前页是否启用 DOM 注入（基于 template 是否存在 + method）。
	 */
	function tryDomInject() {
		var tpl = document.getElementById( 'wpaias-summary-template' );
		if ( ! tpl ) return;

		var method = ( tpl.getAttribute( 'data-method' ) || 'auto' ).toLowerCase();
		if ( method === 'manual' || method === 'shortcode_only' ) return;

		var selectors = parseSelectors( tpl.getAttribute( 'data-selectors' ) || '' );
		var position  = ( tpl.getAttribute( 'data-position' ) || 'prepend' ).toLowerCase();

		var target = findTarget( selectors );
		if ( ! target ) return;

		injectTemplateInto( tpl, target, position );
	}

	function parseSelectors( raw ) {
		return raw.split( /,(?![^()]*\))/ )
			.map( function ( s ) { return s.trim(); } )
			.filter( function ( s ) { return s.length > 0; } );
	}

	function findTarget( selectors ) {
		// 1) 用户自定义优先。
		for ( var i = 0; i < selectors.length; i++ ) {
			var el;
			try {
				el = document.querySelector( selectors[ i ] );
			} catch ( e ) { /* 选择器异常容错 */ }
			if ( el ) return el;
		}

		// 2) 内置通用兜底列表（更广泛覆盖商用主题）。
		var fallback = [
			'article.post .entry-content',
			'article .entry-content',
			'.entry-content',
			'.post-content',
			'.article-content',
			'.single-content',
			'.article__content',
			'.post__content',
			'.single .post-content',
			'.single-post .post-content',
			'.typo',
			'.post-body',
			'.post-detail',
			'.post-text',
			'.post-single-content',
			'.s-content',
			'.elementor-widget-theme-post-content .elementor-widget-container',
			'.et_pb_post_content',
			'.fl-post-content',
			'.has-text-align-left',
			'main article',
			'#post-' + ( WPAIAS.post_id || 0 ),
			'article'
		];
		for ( var j = 0; j < fallback.length; j++ ) {
			var el2;
			try {
				el2 = document.querySelector( fallback[ j ] );
			} catch ( e ) {}
			if ( el2 ) return el2;
		}
		return null;
	}

	function injectTemplateInto( tpl, target, position ) {
		var content;
		// <template>.content 在所有现代浏览器中均支持。
		if ( 'content' in tpl ) {
			content = tpl.content.cloneNode( true );
		} else {
			// 兜底：把 innerHTML 转节点。
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = tpl.innerHTML;
			content = document.createDocumentFragment();
			while ( wrap.firstChild ) {
				content.appendChild( wrap.firstChild );
			}
		}

		switch ( position ) {
			case 'append':
				target.appendChild( content );
				break;
			case 'before':
				target.parentNode && target.parentNode.insertBefore( content, target );
				break;
			case 'after':
				target.parentNode && target.parentNode.insertBefore( content, target.nextSibling );
				break;
			case 'prepend':
			default:
				target.insertBefore( content, target.firstChild );
				break;
		}
	}

	/**
	 * 部分主题（Elementor / Divi 编辑器、懒加载、SPA）会在 DOMContentLoaded 之后再渲染正文。
	 * 这里通过 MutationObserver 在 5 秒窗口内监听并补一次。
	 */
	function observeLateContent() {
		if ( document.querySelector( '.wpaias-summary' ) ) return;
		if ( ! ( 'MutationObserver' in window ) ) return;

		var tpl = document.getElementById( 'wpaias-summary-template' );
		if ( ! tpl ) return;

		var stopped = false;
		var observer = new MutationObserver( function () {
			if ( stopped ) return;
			if ( document.querySelector( '.wpaias-summary' ) ) {
				stopAndInit();
				return;
			}
			tryDomInject();
			if ( document.querySelector( '.wpaias-summary' ) ) {
				stopAndInit();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		var timer = window.setTimeout( function () {
			stopAndInit();
		}, 5000 );

		function stopAndInit() {
			if ( stopped ) return;
			stopped = true;
			observer.disconnect();
			window.clearTimeout( timer );
			document.querySelectorAll( '.wpaias-summary' ).forEach( function ( box ) {
				if ( ! box.dataset.wpaiasInit ) {
					initBox( box );
				}
			} );
		}
	}

	function initBox( box ) {
		if ( box.dataset.wpaiasInit ) return;
		box.dataset.wpaiasInit = '1';

		var state = box.getAttribute( 'data-state' );
		var delay = parseInt( box.getAttribute( 'data-delay' ), 10 ) || 0;

		if ( 'ready' === state ) {
			// 已有缓存，直接动画。
			setTimeout( function () {
				playAnimation( box );
			}, delay );
			return;
		}

		// 否则 Ajax 拉取。
		fetchSummary( box );
	}

	function fetchSummary( box ) {
		if ( ! WPAIAS.ajax_url || ! WPAIAS.nonce ) {
			showError( box, 'Missing config.' );
			return;
		}
		var pid = parseInt( box.getAttribute( 'data-post-id' ), 10 ) || 0;
		if ( ! pid ) return;

		var form = new FormData();
		form.append( 'action', 'wpaias_front_generate' );
		form.append( 'nonce', WPAIAS.nonce );
		form.append( 'post_id', pid );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', WPAIAS.ajax_url, true );
		xhr.onreadystatechange = function () {
			if ( 4 !== xhr.readyState ) return;
			try {
				var resp = JSON.parse( xhr.responseText );
				if ( resp && resp.success && resp.data && resp.data.summary ) {
					injectSummary( box, resp.data.summary );
				} else {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'AI 摘要生成失败';
					showError( box, msg );
				}
			} catch ( e ) {
				showError( box, '响应解析失败' );
			}
		};
		xhr.send( form );
	}

	function injectSummary( box, summary ) {
		var placeholder = box.querySelector( '.wpaias-summary__placeholder' );
		var text        = box.querySelector( '.wpaias-summary__text' );
		if ( placeholder ) placeholder.style.display = 'none';
		if ( text ) {
			text.removeAttribute( 'data-pending' );
			text.textContent = summary;
		}
		box.setAttribute( 'data-state', 'ready' );

		var delay = parseInt( box.getAttribute( 'data-delay' ), 10 ) || 0;
		setTimeout( function () {
			playAnimation( box );
		}, delay );
	}

	function showError( box, msg ) {
		var placeholder = box.querySelector( '.wpaias-summary__placeholder' );
		if ( placeholder ) {
			placeholder.innerHTML = '<span class="wpaias-summary__loading-text" style="color:#a55;">' + escapeHtml( msg ) + '</span>';
		}
		box.setAttribute( 'data-state', 'error' );
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
		} );
	}

	/**
	 * 入场动画分发。
	 */
	function playAnimation( box ) {
		var anim = box.getAttribute( 'data-anim' ) || 'none';
		var text = box.querySelector( '.wpaias-summary__text' );

		// 已通过 CSS 类自动产生入场效果，typewriter 单独处理。
		box.classList.add( 'wpaias-anim-active' );

		if ( anim === 'typewriter' && text ) {
			runTypewriter( box, text );
		} else if ( anim === 'line-fade' && text ) {
			runLineFade( box, text );
		}
	}

	/**
	 * 打字机效果。
	 */
	function runTypewriter( box, text ) {
		var speed   = parseInt( box.getAttribute( 'data-speed' ), 10 ) || 35;
		var cursor  = parseInt( box.getAttribute( 'data-cursor' ), 10 ) || 0;
		var content = text.textContent || '';
		text.textContent = '';
		text.classList.add( 'wpaias-typing' );

		if ( cursor ) {
			text.classList.add( 'wpaias-with-cursor' );
		}

		var i = 0;
		function step() {
			if ( i >= content.length ) {
				// 完成后光标消失。
				text.classList.remove( 'wpaias-with-cursor' );
				text.classList.remove( 'wpaias-typing' );
				text.classList.add( 'wpaias-typed' );
				return;
			}
			text.textContent = content.substring( 0, i + 1 );
			i++;
			window.setTimeout( step, speed );
		}
		step();
	}

	/**
	 * 逐行渐入（兼容老 iOS Safari，不使用正则 lookbehind）。
	 */
	function runLineFade( box, text ) {
		var raw = text.textContent || '';
		// 第一轮：按中英文句末标点 + 换行切分。
		var lines = raw.split( /[\n。！？!?]+/ ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s.length > 0; } );

		// 第二轮兜底：如果只有 1 段，则按"在 ，,；; 之后"插入分隔符再切。
		if ( lines.length <= 1 ) {
			var s2 = raw.replace( /([，,；;])/g, '$1\u0001' );
			lines = s2.split( '\u0001' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s.length > 0; } );
		}
		if ( lines.length === 0 ) lines = [ raw ];

		// 直接重建为多个 <span class="wpaias-line">。
		while ( text.firstChild ) text.removeChild( text.firstChild );
		lines.forEach( function ( line, idx ) {
			var span = document.createElement( 'span' );
			span.className = 'wpaias-line';
			span.style.animationDelay = ( idx * 0.12 ) + 's';
			// 在视觉上保留句末标点 / 空格。
			span.textContent = line + ' ';
			text.appendChild( span );
		} );
	}

} )();
