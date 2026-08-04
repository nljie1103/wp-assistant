(function () {
	'use strict';

	function setDirty(form) {
		if (!form || form.classList.contains('is-submitting')) return;
		form.classList.add('is-dirty');
	}

	function normalizeForms() {
		document.querySelectorAll('.jlwa-feature-host form').forEach(function (form) {
			form.classList.add('jlwa-feature-form');
			form.addEventListener('change', function () { setDirty(form); });
			form.addEventListener('input', function () { setDirty(form); });
			form.addEventListener('submit', function () {
				form.classList.remove('is-dirty');
			});
		});
	}

	function normalizeTables() {
		document.querySelectorAll('.jlwa-feature-host .form-table').forEach(function (table) {
			table.classList.add('jlwa-settings-table');
			table.querySelectorAll('tr').forEach(function (row) {
				row.classList.add('jlwa-setting-row');
			});
		});
	}

	function syncSelectableCards(selector, inputSelector, activeClass) {
		document.querySelectorAll(selector).forEach(function (card) {
			var input = card.querySelector(inputSelector);
			if (!input) return;
			function sync() {
				if (input.type === 'radio' && input.name) {
					document.querySelectorAll(selector + ' ' + inputSelector + '[name="' + window.CSS.escape(input.name) + '"]').forEach(function (peer) {
						var peerCard = peer.closest(selector);
						if (peerCard) peerCard.classList.toggle(activeClass, !!peer.checked);
					});
				} else {
					card.classList.toggle(activeClass, !!input.checked);
				}
			}
			input.addEventListener('change', sync);
			sync();
		});
	}

	function normalizeFeatureCards() {
		syncSelectableCards('.xjpe-card', 'input.xjpe-toggle', 'is-enabled');
		syncSelectableCards('.jip-effect-card', 'input[type="radio"]', 'is-active');
		syncSelectableCards('.wpaias-style-card', 'input[type="radio"]', 'active');
	}

	function improveNotices() {
		document.querySelectorAll('.jlwa-feature-host .notice').forEach(function (notice) {
			notice.classList.add('jlwa-feature-notice');
		});
	}

	function preserveTabScroll() {
		document.querySelectorAll('.jlwa-feature-host .nav-tab-wrapper').forEach(function (tabs) {
			var active = tabs.querySelector('.nav-tab-active');
			if (active && typeof active.scrollIntoView === 'function') {
				window.setTimeout(function () {
					active.scrollIntoView({ block: 'nearest', inline: 'center' });
				}, 0);
			}
		});
	}

	function init() {
		normalizeForms();
		normalizeTables();
		normalizeFeatureCards();
		improveNotices();
		preserveTabScroll();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
