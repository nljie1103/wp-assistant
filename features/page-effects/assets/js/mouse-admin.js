/** 九流WP助手：鼠标交互中心后台卡片 */
(function () {
  'use strict';
  var cfg = window.JLWA_MOUSE_ADMIN || {};
  var optionName = cfg.optionName || 'jlwa_mouse_interactions';
  var opts = cfg.options || {};

  function esc(value) {
    return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' })[ch];
    });
  }
  function checked(value) { return value === true || value === 1 || value === '1' ? ' checked' : ''; }
  function selected(value, expected) { return String(value) === String(expected) ? ' selected' : ''; }
  function fieldName(section, key) { return optionName + '[' + section + '][' + key + ']'; }
  function option(value, label, selectedValue) { return '<option value="' + esc(value) + '"' + selected(selectedValue, value) + '>' + esc(label) + '</option>'; }
  function number(section, key, label, value, min, max, step) {
    return '<label class="xjpe-field"><span>' + esc(label) + '</span><input type="number" name="' + esc(fieldName(section, key)) + '" value="' + esc(value) + '" min="' + min + '" max="' + max + '" step="' + step + '"></label>';
  }
  function select(section, key, label, value, options) {
    var html = '<label class="xjpe-field"><span>' + esc(label) + '</span><select name="' + esc(fieldName(section, key)) + '">';
    Object.keys(options).forEach(function (keyName) { html += option(keyName, options[keyName], value); });
    return html + '</select></label>';
  }
  function color(section, key, label, value) {
    return '<label class="xjpe-field"><span>' + esc(label) + '</span><input type="color" name="' + esc(fieldName(section, key)) + '" value="' + esc(value) + '"></label>';
  }
  function text(section, key, label, value) {
    return '<label class="xjpe-field"><span>' + esc(label) + '</span><input type="text" name="' + esc(fieldName(section, key)) + '" value="' + esc(value) + '" maxlength="12"></label>';
  }
  function toggle(section, key, label, value) {
    return '<label class="xjpe-inline-check"><input type="checkbox" name="' + esc(fieldName(section, key)) + '" value="1"' + checked(value) + '><span>' + esc(label) + '</span></label>';
  }
  function card(section, icon, title, desc, enabled, body) {
    return '<article class="xjpe-card jlwa-mouse-card' + (enabled ? ' is-enabled' : '') + '" data-xjpe-card>' +
      '<label class="xjpe-card-head"><input type="checkbox" class="xjpe-toggle" name="' + esc(fieldName(section, 'enabled')) + '" value="1"' + checked(enabled) + '>' +
      '<span class="xjpe-icon">' + icon + '</span><span class="xjpe-card-text"><strong>' + esc(title) + '</strong><small>' + esc(desc) + '</small><em class="xjpe-status">' + (enabled ? '● 已启用' : '○ 未启用') + '</em></span></label>' +
      '<div class="xjpe-card-body">' + body + '</div></article>';
  }

  function build() {
    var grid = document.querySelector('.xjpe-effects-grid');
    if (!grid || document.getElementById('jlwa-mouse-center-heading')) return;

    var oldCursor = grid.querySelector('input[name="xjpe_options[effects][cursor][enabled]"]');
    if (oldCursor && oldCursor.closest('.xjpe-card')) oldCursor.closest('.xjpe-card').remove();

    var heading = document.createElement('div');
    heading.id = 'jlwa-mouse-center-heading';
    heading.className = 'jlwa-mouse-center-heading';
    heading.innerHTML = '<div><strong>🖱️ 鼠标交互中心</strong><span>光标造型、移动拖尾和点击爆炸互相独立，可同时启用。</span></div><em>电脑端光标与拖尾；点击爆炸可单独允许手机端。</em>';
    grid.parentNode.insertBefore(heading, grid);

    var shape = opts.cursor_shape || {};
    var trail = opts.trail || {};
    var burst = opts.click_burst || {};

    var shapeBody = select('cursor_shape', 'preset', '光标造型', shape.preset, {
      neon_arrow:'霓虹箭头', web_hero:'蛛网英雄（蜘蛛侠风格）', pixel_plumber:'像素水管工（玛丽奥风格）', cat_paw:'可爱猫爪', magic_wand:'魔法棒', rocket:'宇宙火箭', ghost:'白色幽灵', rainbow:'彩虹箭头'
    }) + number('cursor_shape', 'size', '光标尺寸', shape.size || 32, 20, 48, 1) + toggle('cursor_shape', 'link_variant', '链接和按钮使用带光环的交互光标', shape.link_variant) + '<p class="xjpe-tip">全部为插件内置 SVG，不请求外部图片；“蛛网英雄”和“像素水管工”为原创风格图标，不打包官方素材。</p>';

    var trailBody = select('trail', 'preset', '拖尾样式', trail.preset, {
      star:'星星', heart:'爱心', firefly:'萤火虫', petal:'樱花花瓣', bubble:'梦幻气泡', comet:'彗星', snow:'雪花', music:'音符', rainbow:'彩虹粒子', pixel:'像素方块', paw:'猫爪', web:'蛛网光点', custom:'自定义符号'
    }) + text('trail', 'symbol', '自定义符号', trail.symbol || '✦') + select('trail', 'color_mode', '颜色模式', trail.color_mode, { rainbow:'随机彩虹', fixed:'固定颜色' }) + color('trail', 'color', '固定颜色', trail.color || '#ff5ba7') + number('trail', 'size', '拖尾大小', trail.size || 14, 6, 48, 1) + number('trail', 'density', '拖尾密度', trail.density || 2, 1, 8, 1) + number('trail', 'duration', '消散时间（毫秒）', trail.duration || 760, 200, 2400, 20);

    var burstBody = select('click_burst', 'preset', '点击爆炸样式', burst.preset, {
      stars:'星星爆炸', hearts:'爱心喷发', sparks:'火花四射', petals:'花瓣绽放', snow:'雪花扩散', confetti:'彩纸礼花', bubbles:'气泡爆开', music:'音符飞散', paw:'猫爪散开', web:'蛛网光点', pixel:'像素爆炸', ripple:'彩色涟漪', custom:'自定义符号'
    }) + text('click_burst', 'symbol', '自定义符号', burst.symbol || '✦') + select('click_burst', 'color_mode', '颜色模式', burst.color_mode, { rainbow:'随机彩虹', fixed:'固定颜色' }) + color('click_burst', 'color', '固定颜色', burst.color || '#ffd166') + number('click_burst', 'count', '每次喷发数量', burst.count || 14, 4, 36, 1) + number('click_burst', 'size', '粒子大小', burst.size || 18, 8, 44, 1) + number('click_burst', 'spread', '扩散半径', burst.spread || 92, 24, 180, 2) + number('click_burst', 'duration', '动画时间（毫秒）', burst.duration || 780, 280, 1800, 20) + number('click_burst', 'gravity', '重力（负数向上）', burst.gravity === undefined ? 0.55 : burst.gravity, -1.5, 2.5, 0.05) + toggle('click_burst', 'mobile', '手机和平板点击时也显示', burst.mobile) + '<p class="xjpe-tip">粒子层不接管点击，不会影响链接、按钮、输入框和页面滚动。</p>';

    grid.insertAdjacentHTML('beforeend',
      card('cursor_shape', '🕸️', '光标造型', '把系统默认三角箭头替换为内置主题图标', shape.enabled, shapeBody) +
      card('trail', '✨', '鼠标拖尾', '移动鼠标时连续生成星星、爱心、彗星等轨迹', trail.enabled, trailBody) +
      card('click_burst', '💥', '点击爆炸', '点击页面任意位置喷发星星、火花、花瓣等粒子', burst.enabled, burstBody)
    );

    grid.querySelectorAll('.jlwa-mouse-card').forEach(function (mouseCard) {
      var checkbox = mouseCard.querySelector('.xjpe-toggle');
      var status = mouseCard.querySelector('.xjpe-status');
      if (!checkbox || checkbox.__jlwaMouseBound) return;
      checkbox.__jlwaMouseBound = true;
      function syncCard() {
        mouseCard.classList.toggle('is-enabled', checkbox.checked);
        if (status) status.textContent = checkbox.checked ? '● 已启用' : '○ 未启用';
      }
      checkbox.addEventListener('change', syncCard);
      syncCard();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build, { once: true });
  else build();
})();
