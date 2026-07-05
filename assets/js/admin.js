(function () {
	'use strict';

	function post(action) {
		var data = new window.FormData();
		data.append('action', action);
		data.append('nonce', window.JLWA_ADMIN ? window.JLWA_ADMIN.nonce : '');

		return window.fetch(window.JLWA_ADMIN.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (response) {
			return response.json();
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
		if (log) log.textContent = message || '（无变更日志）';
	}

	document.addEventListener('DOMContentLoaded', function () {
		var check = document.getElementById('jlwa-check-update');
		var update = document.getElementById('jlwa-do-update');

		if (!check || !update || !window.JLWA_ADMIN) return;

		check.addEventListener('click', function () {
			check.disabled = true;
			update.disabled = true;
			setStatus('正在检查主仓库版本...', 'is-loading');
			setLog('');

			post('jlwa_check_update').then(function (json) {
				if (!json || !json.success) {
					setStatus((json && json.data && json.data.message) ? json.data.message : '检查失败。', 'is-error');
					return;
				}

				var data = json.data || {};
				setStatus(data.message || '检查完成。', data.has_update ? 'is-warning' : 'is-success');
				setLog(data.changelog || '');
				update.disabled = !data.has_update;
			}).catch(function (error) {
				setStatus('检查失败：' + error.message, 'is-error');
			}).finally(function () {
				check.disabled = false;
			});
		});

		update.addEventListener('click', function () {
			if (!window.confirm('确认从 nljie1103/wp-assistant 主仓库更新整个九流WP助手套件吗？')) return;

			check.disabled = true;
			update.disabled = true;
			setStatus('正在下载并更新套件，请不要关闭页面...', 'is-loading');

			post('jlwa_do_update').then(function (json) {
				if (!json || !json.success) {
					setStatus((json && json.data && json.data.message) ? json.data.message : '更新失败。', 'is-error');
					check.disabled = false;
					return;
				}

				setStatus(json.data.message || '更新完成，请刷新页面。', 'is-success');
			}).catch(function (error) {
				setStatus('更新失败：' + error.message, 'is-error');
				check.disabled = false;
			});
		});
	});
})();
