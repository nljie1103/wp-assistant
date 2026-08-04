(function () {
	'use strict';

	var canUpdate = false;

	function request(action, extra) {
		if (!window.JLWA_ADMIN || !window.JLWA_ADMIN.ajaxUrl) {
			return Promise.reject(new Error('后台更新配置未加载，请刷新页面后重试。'));
		}

		var data = new window.FormData();
		data.append('action', action);
		data.append('nonce', window.JLWA_ADMIN.nonce || '');
		Object.keys(extra || {}).forEach(function (key) {
			data.append(key, String(extra[key]));
		});

		return window.fetch(window.JLWA_ADMIN.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
			headers: { Accept: 'application/json' }
		}).then(function (response) {
			return response.text().then(function (text) {
				var json;
				try {
					json = JSON.parse(text);
				} catch (error) {
					throw new Error('服务器返回的不是有效 JSON（HTTP ' + response.status + '）。');
				}
				if (!response.ok) {
					throw new Error((json && json.data && json.data.message) || ('HTTP ' + response.status));
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

	function setButtons(check, update, busy) {
		if (check) {
			check.disabled = !!busy;
			check.classList.toggle('updating-message', !!busy);
		}
		if (update) {
			update.disabled = !!busy || !canUpdate;
			update.classList.toggle('updating-message', !!busy);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var check = document.getElementById('jlwa-check-update');
		var update = document.getElementById('jlwa-do-update');
		if (!check || !update || !window.JLWA_ADMIN) return;

		check.addEventListener('click', function () {
			canUpdate = false;
			setButtons(check, update, true);
			setStatus('正在检查主仓库版本和完整性信息…', 'is-loading');
			setLog('');

			request('jlwa_check_update', { force: 1 }).then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || '检查失败。');
				}
				var payload = json.data || {};
				canUpdate = !!payload.has_update;
				setStatus(payload.message || '检查完成。', canUpdate ? 'is-warning' : 'is-success');
				setLog(payload.changelog || '');
			}).catch(function (error) {
				canUpdate = false;
				setStatus('检查失败：' + error.message, 'is-error');
			}).then(function () {
				setButtons(check, update, false);
			});
		});

		update.addEventListener('click', function () {
			if (!canUpdate) return;
			if (!window.confirm('确认安全更新整个九流WP助手吗？更新器会先完整备份，失败时自动回滚。')) return;

			setButtons(check, update, true);
			setStatus('正在下载、校验、备份并安装，请不要关闭页面…', 'is-loading');

			request('jlwa_do_update').then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || '更新失败。');
				}
				canUpdate = false;
				setStatus((json.data && json.data.message) || '更新完成，页面即将刷新。', 'is-success');
				window.setTimeout(function () {
					window.location.reload();
				}, 1500);
			}).catch(function (error) {
				setStatus('更新失败：' + error.message, 'is-error');
				setButtons(check, update, false);
			});
		});
	});
})();
