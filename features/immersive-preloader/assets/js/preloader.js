/** 九流沉浸式预加载 1.2.1 — safe skip handling and first-frame lifecycle. */
(function () {
	'use strict';

	var CFG = window.JIP_CFG || {};
	var MIN = Math.max(0, parseFloat(CFG.minDuration || 0.45)) * 1000;
	var MAX = Math.max(500, parseFloat(CFG.maxDuration || 3) * 1000);
	var EFFECT = CFG.effect || 'logo3d';
	var COMPLETION = CFG.completion || 'dom';
	var ALLOW_SKIP = !!parseInt(CFG.allowSkip, 10);
	var startTime = window.__JIP_START__ || Date.now();
	var ended = false;
	var cleanupCallbacks = [];
	var progressTimer = 0;
	var maxTimer = 0;
	var criticalTimer = 0;

	function getEl() { return document.getElementById('jip-preloader'); }
	function onCleanup(fn) { cleanupCallbacks.push(fn); }
	function cleanupEffects() {
		cleanupCallbacks.splice(0).forEach(function (fn) { try { fn(); } catch (e) {} });
		if (progressTimer) { clearInterval(progressTimer); progressTimer = 0; }
		if (maxTimer) { clearTimeout(maxTimer); maxTimer = 0; }
		if (criticalTimer) { clearTimeout(criticalTimer); criticalTimer = 0; }
		if (window.__JIP_HARD_TIMER__) { clearTimeout(window.__JIP_HARD_TIMER__); window.__JIP_HARD_TIMER__ = 0; }
	}

	function updateProgress(value, label) {
		var el = getEl(); if (!el) return;
		var bar = el.querySelector('.jip-progress span');
		var text = el.querySelector('.jip-progress em');
		if (bar) bar.style.width = Math.max(0, Math.min(100, value)) + '%';
		if (text && label) text.textContent = label;
		var progress = el.querySelector('.jip-progress');
		if (progress) progress.setAttribute('aria-valuenow', String(Math.round(value)));
	}

	function startProgress() {
		var value = 10;
		updateProgress(value);
		progressTimer = setInterval(function () {
			if (ended) return;
			value = Math.min(88, value + Math.max(1, (88 - value) * 0.08));
			updateProgress(value);
		}, 120);
	}

	function endPreloader() {
		if (ended) return;
		ended = true;
		cleanupEffects();
		updateProgress(100, '页面已准备完成');
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
		el.addEventListener('transitionend', cleanup, { once: true });
		setTimeout(cleanup, 430);
	}

	function endWithMinDuration(extra) {
		var remain = Math.max(0, MIN - (Date.now() - startTime));
		setTimeout(endPreloader, remain + (extra || 0));
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
			cssWidth = Math.max(1, el.clientWidth); cssHeight = Math.max(1, el.clientHeight);
			dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
			canvas.width = Math.round(cssWidth * dpr); canvas.height = Math.round(cssHeight * dpr);
			canvas.style.width = cssWidth + 'px'; canvas.style.height = cssHeight + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		}
		resize(); window.addEventListener('resize', resize, { passive: true });
		onCleanup(function () { window.removeEventListener('resize', resize); if (rafId) cancelAnimationFrame(rafId); rafId = 0; particles = []; });

		function fallback() {
			particles = [];
			var n = Math.min(320, Math.round(cssWidth / 3));
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
				for (var y = 0; y < 90; y += 4) for (var x = 0; x < 90; x += 4) {
					var i = (y * 90 + x) * 4; if (data[i + 3] < 130) continue;
					particles.push({ x: Math.random() * cssWidth, y: Math.random() * cssHeight, tx: cssWidth / 2 + (x - 45) * scale, ty: cssHeight / 2 + (y - 45) * scale, vx: 0, vy: 0, c: 'rgba(' + data[i] + ',' + data[i+1] + ',' + data[i+2] + ',.95)' });
				}
				if (!particles.length) fallback();
			} catch (e) { fallback(); }
		}

		function loop() {
			if (ended) return;
			ctx.clearRect(0, 0, cssWidth, cssHeight);
			for (var i = 0; i < particles.length; i++) {
				var p = particles[i]; var dx = p.tx - p.x, dy = p.ty - p.y;
				p.vx = (p.vx + dx * .004) * .9; p.vy = (p.vy + dy * .004) * .9; p.x += p.vx; p.y += p.vy;
				ctx.fillStyle = p.c; ctx.fillRect(p.x, p.y, 2, 2);
			}
			rafId = requestAnimationFrame(loop);
		}
		if (sourceImg.complete) buildFromImage(); else { sourceImg.addEventListener('load', buildFromImage, { once: true }); sourceImg.addEventListener('error', fallback, { once: true }); }
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
		}).slice(0, 6);
		images.forEach(function (img) {
			if (img.complete && img.naturalWidth > 0) return;
			pending += 1;
			var settled = false;
			function settle() { if (settled) return; settled = true; oneDone(); }
			img.addEventListener('load', settle, { once: true });
			img.addEventListener('error', settle, { once: true });
			if (img.decode) img.decode().then(settle).catch(function () {});
		});
		if (document.fonts && document.fonts.ready) {
			pending += 1;
			document.fonts.ready.then(oneDone).catch(oneDone);
		}
		if (!pending) finish();
		criticalTimer = setTimeout(finish, Math.min(900, Math.max(180, MAX - (Date.now() - startTime) - 80)));
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
		if (EFFECT === 'particles') initParticlesEffect();
		var remainingMax = Math.max(0, MAX - (Date.now() - startTime));
		maxTimer = setTimeout(function () { if (!ended) endPreloader(); }, remainingMax);
		finishByStrategy();
	}

	if (getEl()) { init(); } else if (window.MutationObserver) {
		var initObserver = new MutationObserver(function () { if (getEl()) { initObserver.disconnect(); init(); } });
		initObserver.observe(document.documentElement, { childList: true, subtree: true });
		setTimeout(function () { initObserver.disconnect(); if (getEl()) init(); else document.documentElement.classList.remove('jip-loading'); }, Math.min(MAX, 1200));
	} else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
	else init();
})();
