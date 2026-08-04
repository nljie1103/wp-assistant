(function () {
	'use strict';

	function post(action, extra) {
		var data = new window.FormData();
		var config = window.JLWA_ADMIN || {};
		data.append('action', action);
		data.append('nonce', config.nonce || '');
		Object.keys(extra || {}).forEach(function (key) {
			data.append(key, extra[key]);
		});
		return window.fetch(config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
			headers: { 'Accept': 'application/json' }
		}).then(function (response) {
			return response.text().then(function (text) {
				var json;
				try {
					json = JSON.parse(text);
				} catch (error) {
					throw new Error('服务器返回的不是有效 JSON（HTTP ' + response.status + '）。');
				}
				if (!response.ok && (!json || json.success)) {
					throw new Error('请求失败（HTTP ' + response.status + '）。');
				}
				return json;
			});
		});
	}

	function setStatus(message, type) {
		var status = document.getElementById('jlwa-update-status');
		if (!status) return;
		status.className = 'jlwa-update-status ' + (type || '');
		status.textContent = message;
	}

	function setLog(message) {
		var log = document.getElementById('jlwa-update-log');
		if (log) log.textContent = message || '（无版本说明）';
	}

	function bindUpdater() {
		var check = document.getElementById('jlwa-check-update');
		var update = document.getElementById('jlwa-do-update');
		if (!check || !update || !window.JLWA_ADMIN) return;

		check.addEventListener('click', function () {
			check.disabled = true;
			update.disabled = true;
			setStatus('正在检查主仓库版本与完整性信息…', 'is-loading');
			setLog('');
			post('jlwa_check_update', { force: '1' }).then(function (json) {
				if (!json || !json.success) {
					throw new Error(json && json.data && json.data.message ? json.data.message : '检查失败。');
				}
				var result = json.data || {};
				setStatus(result.message || '检查完成。', result.has_update ? 'is-warning' : 'is-success');
				setLog(result.changelog || '');
				update.disabled = !result.has_update;
			}).catch(function (error) {
				setStatus('检查失败：' + error.message, 'is-error');
				update.disabled = true;
			}).then(function () {
				check.disabled = false;
			});
		});

		update.addEventListener('click', function () {
			if (!window.confirm('将从唯一主仓库更新整个九流WP助手。更新前会完整备份，失败会自动回滚。确认继续？')) return;
			check.disabled = true;
			update.disabled = true;
			setStatus('正在下载、校验、备份并安装完整插件，请不要关闭页面…', 'is-loading');
			post('jlwa_do_update').then(function (json) {
				if (!json || !json.success) {
					throw new Error(json && json.data && json.data.message ? json.data.message : '更新失败。');
				}
				setStatus(json.data.message || '更新完成，正在刷新页面…', 'is-success');
				window.setTimeout(function () { window.location.reload(); }, 1400);
			}).catch(function (error) {
				setStatus('更新失败：' + error.message, 'is-error');
				check.disabled = false;
			});
		});
	}

	function enhanceFeatureForms() {
		document.querySelectorAll('.jlwa-feature-host form').forEach(function (form) {
			form.addEventListener('submit', function (event) {
				form.classList.add('is-submitting');
				form.setAttribute('aria-busy', 'true');
				var button = event.submitter || null;
				if (!button || button.dataset.jlwaOriginalLabel) return;
				button.dataset.jlwaOriginalLabel = button.value || button.textContent || '';
				if (button.tagName === 'INPUT') button.value = '正在保存…';
				else button.textContent = '正在保存…';
			});
		});
	}

	function markExternalLinks() {
		document.querySelectorAll('.jlwa-app a[target="_blank"]').forEach(function (link) {
			link.setAttribute('rel', 'noopener noreferrer');
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindUpdater();
		enhanceFeatureForms();
		markExternalLinks();
	});
})();
