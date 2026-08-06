/** 九流WP助手：根据图片真实像素修正下载页主封面横、方、竖容器。 */
(function () {
  'use strict';

  function applyOrientation(cover) {
    if (!cover) return;
    var image = cover.querySelector('.jld-cover-image');
    if (!image) return;

    function update() {
      var width = image.naturalWidth || parseInt(image.getAttribute('width'), 10) || 0;
      var height = image.naturalHeight || parseInt(image.getAttribute('height'), 10) || 0;
      if (!width || !height) return;

      var ratio = width / height;
      var orientation = ratio < 0.82 ? 'is-portrait' : (ratio <= 1.22 ? 'is-square' : 'is-landscape');
      cover.classList.remove('is-landscape', 'is-square', 'is-portrait');
      cover.classList.add(orientation);
      cover.style.setProperty('--jlwa-cover-ratio', width + ' / ' + height);
      cover.setAttribute('data-jlwa-orientation', orientation.replace('is-', ''));
      cover.setAttribute('data-jlwa-image-size', width + 'x' + height);
    }

    if (image.complete && image.naturalWidth && image.naturalHeight) {
      update();
    } else {
      image.addEventListener('load', update, { once: true });
    }
  }

  function scan(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('.jld-page .jld-cover.has-cover').forEach(applyOrientation);
    if (root && root.matches && root.matches('.jld-page .jld-cover.has-cover')) {
      applyOrientation(root);
    }
  }

  function start() {
    scan(document);
    window.addEventListener('load', function () { scan(document); }, { once: true });

    if ('MutationObserver' in window && document.body) {
      var observer = new MutationObserver(function (records) {
        records.forEach(function (record) {
          record.addedNodes.forEach(function (node) {
            if (node && node.nodeType === 1) scan(node);
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
      window.setTimeout(function () { observer.disconnect(); }, 8000);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
