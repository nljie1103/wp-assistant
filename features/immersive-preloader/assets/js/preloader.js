/** 九流沉浸式预加载 1.1.0 — performance-first lifecycle. */
(function () {
	'use strict';

	var CFG = window.JIP_CFG || {};
	var MIN = Math.max(0, parseFloat(CFG.minDuration || 0.45)) * 1000;
	var MAX = Math.max(500, parseFloat(CFG.maxDuration || 3) * 1000);
	var EFFECT = CFG.effect || 'logo3d';
	var COMPLETION = CFG.completion || 'dom';
	var ALLOW_SKIP = !!parseInt(CFG.allowSkip, 10);
	var startTime = Date.now();
	var ended = false;
	var cleanupCallbacks = [];
	var progressTimer = 0;
	var maxTimer = 0;

	function getEl() { return document.getElementById('jip-preloader'); }
	function onCleanup(fn) { cleanupCallbacks.push(fn); }
	function cleanupEffects() {
		cleanupCallbacks.splice(0).forEach(function (fn) { try { fn(); } catch (e) {} });
		if (progressTimer) { clearInterval(progressTimer); progressTimer = 0; }
		if (maxTimer) { clearTimeout(maxTimer); maxTimer = 0; }
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
		setTimeout(cleanup, 750);
	}

	function endWithMinDuration(extra) {
		var remain = Math.max(0, MIN - (Date.now() - startTime));
		setTimeout(endPreloader, remain + (extra || 0));
	}

	function bindSkip() {
		if (!ALLOW_SKIP) return;
		function remove() {
			document.removeEventListener('click', handler, true);
			document.removeEventListener('touchstart', handler, true);
			document.removeEventListener('keydown', handler);
		}
		function handler(ev) {
			if (ev && ev.type === 'keydown' && ev.key !== 'Escape' && ev.key !== 'Enter' && ev.key !== ' ') return;
			remove();
			endWithMinDuration(0);
		}
		document.addEventListener('click', handler, { capture: true });
		document.addEventListener('touchstart', handler, { capture: true, passive: true });
		document.addEventListener('keydown', handler);
		onCleanup(remove);
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

	function finishByStrategy() {
		if (COMPLETION === 'load') {
			if (document.readyState === 'complete') endWithMinDuration(80);
			else window.addEventListener('load', function () { endWithMinDuration(80); }, { once: true });
			return;
		}
		function afterDom() {
			if (COMPLETION === 'paint' && window.requestAnimationFrame) {
				requestAnimationFrame(function () { requestAnimationFrame(function () { endWithMinDuration(40); }); });
			} else endWithMinDuration(40);
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
		maxTimer = setTimeout(function () { if (!ended) endPreloader(); }, MAX);
		finishByStrategy();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
	else init();
})();
