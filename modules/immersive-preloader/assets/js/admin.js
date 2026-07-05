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
			if ($logoCard.length) {
				$logoCard.show();
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

	});
})(jQuery);
