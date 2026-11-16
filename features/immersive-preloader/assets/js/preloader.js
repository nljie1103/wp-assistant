/** 九流沉浸式预加载：真实就绪事件、无伪百分比、低开销退出。 */
(function () {
	'use strict';

	var CFG = window.JIP_CFG || {};
	function duration(value, fallback) { var parsed = parseFloat(value); return isFinite(parsed) && parsed >= 0 ? parsed : fallback; }
	var MIN = Math.max(0, duration(CFG.minDuration, 0.12)) * 1000;
	var MAX = Math.max(500, duration(CFG.maxDuration, 2) * 1000);
	var EFFECT = CFG.effect || 'logo3d';
	var COMPLETION = CFG.completion || 'dom';
	var ALLOW_SKIP = !!parseInt(CFG.allowSkip, 10);
	var startTime = window.__JIP_START__ || Date.now();
	var ended = false;
	var cleanupCallbacks = [];
	var progressDelay = 0;
	var endDelay = 0;
	var maxTimer = 0;
	var criticalTimer = 0;

	function getEl() { return document.getElementById('jip-preloader'); }
	function onCleanup(fn) { cleanupCallbacks.push(fn); }
	function cleanupEffects() {
		cleanupCallbacks.splice(0).forEach(function (fn) { try { fn(); } catch (e) {} });
		if (progressDelay) { clearTimeout(progressDelay); progressDelay = 0; }
		if (endDelay) { clearTimeout(endDelay); endDelay = 0; }
		if (maxTimer) { clearTimeout(maxTimer); maxTimer = 0; }
		if (criticalTimer) { clearTimeout(criticalTimer); criticalTimer = 0; }
		if (window.__JIP_HARD_TIMER__) { clearTimeout(window.__JIP_HARD_TIMER__); window.__JIP_HARD_TIMER__ = 0; }
		window.__JIP_END__ = null;
	}

	// An arbitrary percentage cannot describe a WordPress page: the full total
	// (ads, third-party requests, lazy images) is unknown. Use an honest
	// indeterminate indicator only if the page is still preparing after 160 ms.
	function startProgress() {
		progressDelay = setTimeout(function () {
			if (!ended && getEl()) getEl().classList.add('jip-progress-visible');
		}, 160);
	}

	function endPreloader() {
		if (ended) return;
		ended = true;
		cleanupEffects();
		var complete = getEl();
		if (complete) {
			complete.classList.add('jip-complete');
			var status = complete.querySelector('.jip-progress em');
			if (status) status.textContent = '页面已就绪';
		}
		var el = getEl();
		var html = document.documentElement;
		html.classList.add('jip-fade-in');
		if (!el) { html.classList.remove('jip-loading'); return; }
		el.classList.add('jip-hide');
		var removed = false;
		function cleanup() {
			if (removed) return; removed = true;
			html.classList.remove('jip-loading');
			if (el.parentNode) el.parentNode.removeChild(el);
			try { window.dispatchEvent(new CustomEvent('jip:ended')); } catch (e) {}
		}
		el.addEventListener('transitionend', function (event) { if (event.target === el) cleanup(); });
		setTimeout(cleanup, EFFECT === 'aperture' ? 540 : 440);
	}

	function endWithMinDuration(extra) {
		if (ended) return;
		// DOM readiness often fires before the effect has actually painted.
		// Wait for the first visible frame, not a fixed delay from navigation.
		if (!window.__JIP_VISUAL_START__) {
			window.addEventListener('jip:visible', function () { endWithMinDuration(extra); }, { once: true });
			return;
		}
		var intro = MIN === 0 ? 0 : ((EFFECT === 'editorial' || EFFECT === 'aperture') ? 700 : 340);
		var visualMinimum = Math.max(MIN, intro);
		var remain = Math.max(0, visualMinimum - (Date.now() - window.__JIP_VISUAL_START__));
		// Never exceed the global fail-safe, even on a frozen/slow page.
		remain = Math.min(remain, Math.max(0, MAX + 650 - (Date.now() - startTime)));
		if (endDelay) clearTimeout(endDelay);
		endDelay = setTimeout(endPreloader, remain + (extra || 0));
	}

	function bindSkip() {
		if (!ALLOW_SKIP) return;
		var triggered = false;
		var guardTimer = 0;

		function consume(ev) {
			if (!ev) return;
			if (ev.cancelable) ev.preventDefault();
			ev.stopPropagation();
			if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
		}

		function removePrimary() {
			document.removeEventListener('pointerdown', handler, true);
			document.removeEventListener('touchstart', handler, true);
			document.removeEventListener('click', handler, true);
			document.removeEventListener('keydown', handler, true);
		}

		function installFollowupGuard() {
			function guard(ev) { consume(ev); return false; }
			document.addEventListener('click', guard, true);
			document.addEventListener('auxclick', guard, true);
			guardTimer = window.setTimeout(function () {
				document.removeEventListener('click', guard, true);
				document.removeEventListener('auxclick', guard, true);
			}, 900);
		}

		function handler(ev) {
			if (ev && ev.type === 'keydown' && ev.key !== 'Escape' && ev.key !== 'Enter' && ev.key !== ' ') return;
			consume(ev);
			if (triggered) return false;
			triggered = true;
			removePrimary();
			installFollowupGuard();
			// “跳过”必须立即结束，不再受最小展示时长限制。
			endPreloader();
			return false;
		}

		document.addEventListener('pointerdown', handler, { capture: true, passive: false });
		document.addEventListener('touchstart', handler, { capture: true, passive: false });
		document.addEventListener('click', handler, true);
		document.addEventListener('keydown', handler, true);
		onCleanup(function () {
			removePrimary();
			// 手动跳过后的短期 click guard 不能在遮罩淡出时提前移除，
			// 否则移动端合成 click 会落到遮罩下方的链接。
			if (!triggered && guardTimer) window.clearTimeout(guardTimer);
		});
	}

	function initParticlesEffect() {
		var el = getEl(); if (!el) return;
		var canvas = el.querySelector('.jip-particles-canvas');
		var sourceImg = el.querySelector('.jip-particles-target');
		if (!canvas || !sourceImg) return;
		var ctx = canvas.getContext('2d'); if (!ctx) return;
		var particles = [];
		var rafId = 0;
		var cssWidth = 1, cssHeight = 1, dpr = 1;

		function resize() {
			cssWidth = Math.min(360, Math.max(1, el.clientWidth)); cssHeight = Math.min(360, Math.max(1, el.clientHeight));
			dpr = Math.max(1, Math.min(1.25, window.devicePixelRatio || 1));
			canvas.width = Math.round(cssWidth * dpr); canvas.height = Math.round(cssHeight * dpr);
			canvas.style.width = cssWidth + 'px'; canvas.style.height = cssHeight + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		}
		resize(); window.addEventListener('resize', resize, { passive: true });
		onCleanup(function () { window.removeEventListener('resize', resize); if (rafId) cancelAnimationFrame(rafId); rafId = 0; particles = []; });

		function fallback() {
			particles = [];
			var n = Math.min(120, Math.round(cssWidth / 3));
			var r = Math.min(cssWidth, cssHeight) * 0.13;
			for (var k = 0; k < n; k++) {
				var ang = (k / n) * Math.PI * 2;
				particles.push({ x: cssWidth / 2 + (Math.random() - .5) * cssWidth, y: cssHeight / 2 + (Math.random() - .5) * cssHeight, tx: cssWidth / 2 + Math.cos(ang) * r, ty: cssHeight / 2 + Math.sin(ang) * r, vx: 0, vy: 0, c: 'rgba(120,180,255,.9)' });
			}
		}

		function buildFromImage() {
			try {
				var tmp = document.createElement('canvas'); tmp.width = 90; tmp.height = 90;
				var tctx = tmp.getContext('2d'); tctx.drawImage(sourceImg, 0, 0, 90, 90);
				var data = tctx.getImageData(0, 0, 90, 90).data;
				var scale = Math.min(cssWidth, cssHeight) * .0032;
				particles = [];
				for (var y = 0; y < 90; y += 7) for (var x = 0; x < 90; x += 7) {
					var i = (y * 90 + x) * 4; if (data[i + 3] < 130) continue;
					particles.push({ x: Math.random() * cssWidth, y: Math.random() * cssHeight, tx: cssWidth / 2 + (x - 45) * scale, ty: cssHeight / 2 + (y - 45) * scale, vx: 0, vy: 0, c: 'rgba(' + data[i] + ',' + data[i+1] + ',' + data[i+2] + ',.95)' });
				}
				if (!particles.length) fallback();
			} catch (e) { fallback(); }
		}

		var firstFrame = 0, lastFrame = 0;
		function loop(stamp) {
			if (ended) return;
			if (!firstFrame) firstFrame = stamp;
			if (stamp - firstFrame > 820) { el.classList.add('jip-particles-ready'); return; }
			if (stamp - lastFrame < 30) { rafId = requestAnimationFrame(loop); return; }
			lastFrame = stamp;
			ctx.clearRect(0, 0, cssWidth, cssHeight);
			for (var i = 0; i < particles.length; i++) {
				var p = particles[i]; var dx = p.tx - p.x, dy = p.ty - p.y;
				p.vx = (p.vx + dx * .004) * .9; p.vy = (p.vy + dy * .004) * .9; p.x += p.vx; p.y += p.vy;
				ctx.fillStyle = p.c; ctx.fillRect(p.x, p.y, 2, 2);
			}
			rafId = requestAnimationFrame(loop);
		}
		if (sourceImg.complete && sourceImg.naturalWidth > 0) buildFromImage(); else if (sourceImg.complete) fallback(); else { sourceImg.addEventListener('load', buildFromImage, { once: true }); sourceImg.addEventListener('error', fallback, { once: true }); }
		rafId = requestAnimationFrame(loop);
	}

	function animateTitle() {
		var el = getEl(); if (!el) return;
		var title = el.querySelector('.jip-site-title');
		if (title) setTimeout(function () { if (!ended) title.classList.add('jip-in'); }, 40);
		setTimeout(function () { if (!ended) el.classList.add('jip-ready'); }, 100);
	}

	function waitForCriticalResources(done) {
		var finished = false;
		var pending = 0;
		function finish() { if (finished) return; finished = true; done(); }
		function oneDone() { pending -= 1; if (pending <= 0) finish(); }
		var images = Array.prototype.slice.call(document.images || []).filter(function (img) {
			if (!img || img.closest('#jip-preloader')) return false;
			var rect = img.getBoundingClientRect();
			return rect.bottom > 0 && rect.top < (window.innerHeight || 800) * 1.25 && rect.width > 24 && rect.height > 24;
		}).slice(0, 3);
		images.forEach(function (img) {
			// Broken cached images are already settled, not a reason to hold the page.
			if (img.complete) return;
			pending += 1;
			var settled = false;
			function settle() { if (settled) return; settled = true; oneDone(); }
			img.addEventListener('load', settle, { once: true });
			img.addEventListener('error', settle, { once: true });
			if (img.decode) img.decode().then(settle, settle);
		});
		if (document.fonts && document.fonts.ready) {
			pending += 1;
			document.fonts.ready.then(oneDone).catch(oneDone);
		}
		if (!pending) { finish(); return; }
		criticalTimer = setTimeout(finish, Math.min(400, Math.max(120, MAX - (Date.now() - startTime) - 80)));
	}

	function finishByStrategy() {
		if (COMPLETION === 'load') {
			if (document.readyState === 'complete') endWithMinDuration(20);
			else window.addEventListener('load', function () { endWithMinDuration(20); }, { once: true });
			return;
		}
		function afterDom() {
			if (COMPLETION === 'critical') {
				requestAnimationFrame(function () { waitForCriticalResources(function () { endWithMinDuration(0); }); });
			} else if (COMPLETION === 'paint' && window.requestAnimationFrame) {
				requestAnimationFrame(function () { requestAnimationFrame(function () { endWithMinDuration(0); }); });
			} else endWithMinDuration(0);
		}
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', afterDom, { once: true });
		else afterDom();
	}

	function init() {
		if (window.__JIP_SKIP__ || !document.documentElement.classList.contains('jip-loading')) {
			var skipEl = getEl(); if (skipEl && skipEl.parentNode) skipEl.parentNode.removeChild(skipEl);
			document.documentElement.classList.remove('jip-loading'); return;
		}
		if (!getEl()) { document.documentElement.classList.remove('jip-loading'); return; }
		bindSkip(); animateTitle(); startProgress();
		if (EFFECT === 'particles') {
			if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) getEl().classList.add('jip-particles-ready');
			else initParticlesEffect();
		}
		window.__JIP_END__ = endPreloader;
		// The critical-head timer is the single source of truth for timeout.
		// Only install a fallback if the critical script was unavailable.
		if (!window.__JIP_HARD_TIMER__) {
			maxTimer = setTimeout(function () { if (!ended) endPreloader(); }, Math.max(0, MAX - (Date.now() - startTime)));
		}
		finishByStrategy();
	}

	if (getEl()) { init(); } else if (window.MutationObserver) {
		var initObserver = new MutationObserver(function () { if (getEl()) { initObserver.disconnect(); init(); } });
		initObserver.observe(document.documentElement, { childList: true, subtree: true });
		setTimeout(function () { initObserver.disconnect(); if (getEl()) init(); else document.documentElement.classList.remove('jip-loading'); }, Math.min(MAX, 1200));
	} else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
	else init();
})();
