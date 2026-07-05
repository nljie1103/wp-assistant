/**
 * 九流沉浸式预加载 - 后台脚本
 * 版本：1.0.6
 *
 * 功能：
 *  1. 颜色选择器初始化。
 *  2. 媒体上传器（选择 Logo）。
 *  3. 效果卡片选中视觉同步、显示/隐藏 Logo 设置区。
 */
(function ($) {
	'use strict';

	$(function () {
		var adminCfg = window.JIP_ADMIN || {};
		var ajaxUrl = adminCfg.ajaxUrl || window.ajaxurl || '';

		bindUpdateActions();

		if ($('#jip-check-update').length) {
			doCheckUpdate(false);
		}

		// ----- 颜色选择器 -----
		if ($.fn.wpColorPicker) {
			$('.jip-color-field').wpColorPicker();
		}

		// ----- 效果卡片交互 -----
		var $cards = $('.jip-effect-card');
		var $logoCard = $('.jip-logo-card');

		function syncEffect() {
			var current = $('input[name$="[effect]"]:checked').val();
			$cards.each(function () {
				var $c = $(this);
				$c.toggleClass('is-active', $c.data('effect') === current);
			});
			// 仅 logo3d 显示 Logo 设置卡片。
			if ($logoCard.length) {
				if (current === $logoCard.data('show-when-effect')) {
					$logoCard.slideDown(180);
				} else {
					$logoCard.slideUp(180);
				}
			}
		}

		$cards.on('click', function () {
			$(this).find('input[type="radio"]').prop('checked', true).trigger('change');
		});

		$('input[name$="[effect]"]').on('change', syncEffect);
		syncEffect();

		// ----- 媒体上传器 -----
		var frame;
		$('#jip_upload_logo').on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: (window.JIP_ADMIN && window.JIP_ADMIN.mediaTitle) || '选择 Logo 图片',
				button: { text: (window.JIP_ADMIN && window.JIP_ADMIN.mediaButton) || '使用此图片' },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$('#jip_logo_id').val(attachment.id);
				$('#jip_logo_url').val(attachment.url);
				$('#jip_logo_preview_img').attr('src', attachment.url);
			});
			frame.open();
		});

		$('#jip_reset_logo').on('click', function (e) {
			e.preventDefault();
			$('#jip_logo_id').val(0);
			$('#jip_logo_url').val('');
			var defaultLogo = (window.JIP_ADMIN && window.JIP_ADMIN.defaultLogo) || '';
			if (defaultLogo) {
				$('#jip_logo_preview_img').attr('src', defaultLogo);
			}
		});

		function bindUpdateActions() {
			$(document).on('click', '#jip-check-update', function (e) {
				e.preventDefault();
				doCheckUpdate(true);
			});

			$(document).on('click', '#jip-do-update', function (e) {
				e.preventDefault();
				if ($(this).prop('disabled')) return;
				if (!window.confirm('即将下载并覆盖本地插件文件，确认继续？')) return;

				var $btn = $(this);
				var $check = $('#jip-check-update');
				var $msg = $('#jip-update-status');

				$btn.prop('disabled', true).addClass('updating-message');
				$check.prop('disabled', true);
				$msg.removeClass('ok fail warn').addClass('pending').html('正在下载并解压最新版本，请勿关闭页面...');

				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					timeout: 120000,
					data: {
						action: 'jip_do_update',
						nonce: adminCfg.nonce || ''
					}
				}).done(function (resp) {
					if (resp && resp.success) {
						$msg.removeClass('pending fail warn').addClass('ok').html(
							(resp.data.message || '更新完成。') +
							'<br><small>页面将在 2 秒后自动刷新加载新版本...</small>'
						);
						window.setTimeout(function () { window.location.reload(); }, 2000);
					} else {
						var msg = resp && resp.data && resp.data.message ? resp.data.message : '更新失败';
						$msg.removeClass('pending ok warn').addClass('fail').html(msg);
						$btn.prop('disabled', false).removeClass('updating-message');
						$check.prop('disabled', false);
					}
				}).fail(function (xhr) {
					$msg.removeClass('pending ok warn').addClass('fail').html('网络错误：' + xhr.status + ' ' + xhr.statusText);
					$btn.prop('disabled', false).removeClass('updating-message');
					$check.prop('disabled', false);
				});
			});
		}

		function doCheckUpdate(force) {
			var $btn = $('#jip-check-update');
			var $upd = $('#jip-do-update');
			var $msg = $('#jip-update-status');
			var $log = $('#jip-changelog');

			$btn.prop('disabled', true);
			$upd.prop('disabled', true);
			$msg.removeClass('ok fail warn').addClass('pending').html('正在从 GitHub 获取最新版本信息...');

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'jip_check_update',
					nonce: adminCfg.nonce || '',
					force: force ? 1 : 0
				}
			}).done(function (resp) {
				if (resp && resp.success) {
					var d = resp.data || {};
					var current = d.current_version || '?';
					var latest = d.latest_version || '?';
					var has = !!d.has_update;
					var html;

					if (has) {
						html = '检测到新版本：本地 v' + current + ' -> 远程 v' + latest +
							'<br><small>点击右侧“一键在线更新”立即升级。</small>';
						$msg.removeClass('pending ok fail').addClass('warn').html(html);
						$upd.prop('disabled', false).removeClass('updating-message');
					} else {
						html = '当前已是最新版本（v' + current + '）。';
						$msg.removeClass('pending warn fail').addClass('ok').html(html);
						$upd.prop('disabled', true);
					}

					if (d.changelog) {
						$log.text(d.changelog);
					}
				} else {
					var msg = resp && resp.data && resp.data.message ? resp.data.message : '检查失败';
					$msg.removeClass('pending ok warn').addClass('fail').html(msg);
				}
			}).fail(function (xhr) {
				$msg.removeClass('pending ok warn').addClass('fail').html('网络错误：' + xhr.status + ' ' + xhr.statusText);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}
	});
})(jQuery);
