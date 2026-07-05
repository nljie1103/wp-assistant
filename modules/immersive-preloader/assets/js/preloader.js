/**
 * 九流沉浸式预加载 - 前台脚本
 * 版本：1.0.6
 * 作者：九流（https://www.jiuliu.org）
 * 许可证：GPLv2+
 *
 * 设计思路：
 *  1. 关键内联样式已让 #jip-preloader 在 DOM 出现前后立即全屏覆盖。
 *  2. 本脚本统一管理生命周期：等待 window.load + minDuration → 触发淡出。
 *  3. 超时保护：maxDuration 到达时强制结束。
 *  4. 允许点击跳过：满足 minDuration 后任何点击直接结束。
 *  5. 各效果的额外动画（粒子、字幕入场等）在此扩展。
 *
 * 与传统"固定时长开屏"区别：
 *  - 我们不是固定 N 秒消失，而是"等到资源 ready + 不低于最小展示时长"才消失。
 */
(function () {
	'use strict';

	// 配置（由 wp_localize_script 注入），未注入时使用默认值，避免脚本错误。
	var CFG = (typeof window !== 'undefined' && window.JIP_CFG) ? window.JIP_CFG : {};
	var MIN = Math.max(0, parseFloat(CFG.minDuration || 1)) * 1000;
	var MAX = Math.max(1, parseFloat(CFG.maxDuration || 8)) * 1000;
	var EFFECT = CFG.effect || 'logo3d';
	var ALLOW_SKIP = !!parseInt(CFG.allowSkip, 10);
	var LOGO_URL = CFG.logoUrl || '';

	var startTime = Date.now();
	var ended = false;

	/**
	 * 获取预加载元素（在 DOM 出现后可用）。
	 */
	function getEl() {
		return document.getElementById('jip-preloader');
	}

	/**
	 * 结束预加载：执行淡出动画，淡出完成后从 DOM 移除并恢复主内容。
	 */
	function endPreloader() {
		if (ended) return;
		ended = true;

		var el = getEl();
		var html = document.documentElement;

		// 触发主内容淡入。
		html.classList.add('jip-fade-in');

		if (!el) {
			// 没有 DOM 节点（极少数情况下），直接收尾。
			html.classList.remove('jip-loading');
			return;
		}

		el.classList.add('jip-hide');

		// 监听淡出过渡结束。
		var removed = false;
		function cleanup() {
			if (removed) return;
			removed = true;
			html.classList.remove('jip-loading');
			if (el && el.parentNode) {
				el.parentNode.removeChild(el);
			}
			// 触发自定义事件，方便主题/其他脚本响应。
			try {
				window.dispatchEvent(new CustomEvent('jip:ended'));
			} catch (e) { /* IE11 兜底，可忽略 */ }
		}
		el.addEventListener('transitionend', cleanup, { once: true });
		// 兜底定时器，避免某些浏览器不触发 transitionend。
		setTimeout(cleanup, 1300);
	}

	/**
	 * 满足 minDuration 后尝试结束。
	 * @param {number} delayBase 额外延迟（毫秒）。
	 */
	function endWithMinDuration(delayBase) {
		var elapsed = Date.now() - startTime;
		var remain = Math.max(0, MIN - elapsed);
		setTimeout(endPreloader, remain + (delayBase || 0));
	}

	/**
	 * 绑定点击跳过。
	 */
	function bindSkip() {
		if (!ALLOW_SKIP) return;
		var handler = function () {
			var elapsed = Date.now() - startTime;
			// 即使在 minDuration 之内也允许跳过（用户体验优先）。
			// 但留一个极小的最小展示（200ms），避免一闪而过。
			if (elapsed < 200) {
				setTimeout(endPreloader, 200 - elapsed);
			} else {
				endPreloader();
			}
		};
		document.addEventListener('click', handler, { once: true, capture: true });
		document.addEventListener('touchstart', handler, { once: true, capture: true });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') endPreloader();
		}, { once: true });
	}

	/**
	 * 粒子效果（Canvas 2D）。在 window.load 后启动一次汇聚动画。
	 */
	function initParticlesEffect() {
		var el = getEl();
		if (!el) return;
		var canvas = el.querySelector('.jip-particles-canvas');
		var sourceImg = el.querySelector('.jip-particles-target');
		if (!canvas || !sourceImg) return;

		var ctx = canvas.getContext('2d');
		if (!ctx) return;

		function resize() {
			canvas.width = el.clientWidth * (window.devicePixelRatio || 1);
			canvas.height = el.clientHeight * (window.devicePixelRatio || 1);
		}
		resize();
		window.addEventListener('resize', resize);

		var particles = [];
		var targetReady = false;

		function buildFromImage() {
			try {
				var size = Math.min(canvas.width, canvas.height) * 0.4;
				var tmp = document.createElement('canvas');
				tmp.width = 120; tmp.height = 120;
				var tctx = tmp.getContext('2d');
				tctx.drawImage(sourceImg, 0, 0, 120, 120);
				var data = tctx.getImageData(0, 0, 120, 120).data;

				var cx = canvas.width / 2;
				var cy = canvas.height / 2;
				particles = [];
				for (var y = 0; y < 120; y += 3) {
					for (var x = 0; x < 120; x += 3) {
						var i = (y * 120 + x) * 4;
						var a = data[i + 3];
						if (a > 128) {
							var tx = cx + (x - 60) * size / 60;
							var ty = cy + (y - 60) * size / 60;
							particles.push({
								x: cx + (Math.random() - 0.5) * canvas.width * 1.2,
								y: cy + (Math.random() - 0.5) * canvas.height * 1.2,
								tx: tx,
								ty: ty,
								vx: 0, vy: 0,
								c: 'rgba(' + data[i] + ',' + data[i + 1] + ',' + data[i + 2] + ',1)'
							});
						}
					}
				}
				targetReady = true;
				el.classList.add('jip-particles-ready');
			} catch (err) {
				// 跨域或其他错误，回退到圆形粒子。
				var n = 600;
				particles = [];
				var R = Math.min(canvas.width, canvas.height) * 0.2;
				for (var k = 0; k < n; k++) {
					var ang = (k / n) * Math.PI * 2;
					particles.push({
						x: canvas.width / 2 + (Math.random() - 0.5) * canvas.width,
						y: canvas.height / 2 + (Math.random() - 0.5) * canvas.height,
						tx: canvas.width / 2 + Math.cos(ang) * R,
						ty: canvas.height / 2 + Math.sin(ang) * R,
						vx: 0, vy: 0,
						c: 'rgba(120,180,255,0.9)'
					});
				}
				targetReady = true;
			}
		}

		function loop() {
			if (ended && particles.length === 0) return;
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			for (var i = 0; i < particles.length; i++) {
				var p = particles[i];
				var dx = p.tx - p.x;
				var dy = p.ty - p.y;
				p.vx = (p.vx + dx * 0.002) * 0.92;
				p.vy = (p.vy + dy * 0.002) * 0.92;
				p.x += p.vx;
				p.y += p.vy;
				ctx.fillStyle = p.c;
				ctx.fillRect(p.x, p.y, 2, 2);
			}
			requestAnimationFrame(loop);
		}

		if (sourceImg.complete) {
			buildFromImage();
		} else {
			sourceImg.addEventListener('load', buildFromImage);
			sourceImg.addEventListener('error', buildFromImage);
		}
		requestAnimationFrame(loop);
	}

	/**
	 * 站点名称入场（统一处理）。
	 */
	function animateTitle() {
		var el = getEl();
		if (!el) return;
		var title = el.querySelector('.jip-site-title');
		if (title) {
			// CSS 已有延迟过渡，这里仅添加 class 以便外部覆盖。
			setTimeout(function () { title.classList.add('jip-in'); }, 50);
		}
		setTimeout(function () { el.classList.add('jip-ready'); }, 200);
	}

	/**
	 * 主入口：DOM 就绪即绑定，window.load 触发结束。
	 */
	function init() {
		// 会话级跳过：critical-pre 决定不显示时（__JIP_SKIP__ 被设置 或 html 没有 jip-loading 类）
		// 立即清理，跳过所有预加载逻辑。
		if (window.__JIP_SKIP__ || !document.documentElement.classList.contains('jip-loading')) {
			var skipEl = getEl();
			if (skipEl && skipEl.parentNode) skipEl.parentNode.removeChild(skipEl);
			document.documentElement.classList.remove('jip-loading');
			return;
		}

		// 兜底：若由于主题未触发 wp_body_open 导致预加载元素缺失，则不强加遮罩。
		if (!getEl()) {
			document.documentElement.classList.remove('jip-loading');
			return;
		}

		bindSkip();
		animateTitle();

		if (EFFECT === 'particles') {
			initParticlesEffect();
		}

		// 超时保护：无论是否加载完成。
		setTimeout(function () {
			if (!ended) endPreloader();
		}, MAX);

		// window.load 表示页面所有关键资源加载完成。
		if (document.readyState === 'complete') {
			endWithMinDuration(150);
		} else {
			window.addEventListener('load', function () {
				endWithMinDuration(150);
			});
		}
	}

	// 在 DOMContentLoaded 后初始化（DOM 必须存在）。
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
