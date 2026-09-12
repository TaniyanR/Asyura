(function () {
  'use strict';
  if (window.__asyuraEmbedLoaded) return;
  window.__asyuraEmbedLoaded = true;
  window.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'asyura-widget-size') return;
    document.querySelectorAll('iframe[data-asyura-embed]').forEach(function (frame) {
      if (event.source !== frame.contentWindow || event.origin !== new URL(frame.src, location.href).origin) return;
      var height = Number(event.data.height);
      if (Number.isFinite(height) && height >= 0 && height <= 100000) frame.style.height = Math.ceil(height) + 'px';
    });
  });
  var request = function () {
    document.querySelectorAll('iframe[data-asyura-embed]').forEach(function (frame) {
      frame.contentWindow.postMessage({type: 'asyura-widget-measure'}, new URL(frame.src, location.href).origin);
    });
  };
  document.addEventListener('load', function (event) {
    if (event.target.matches && event.target.matches('iframe[data-asyura-embed]')) request();
  }, true);
  request();
})();
