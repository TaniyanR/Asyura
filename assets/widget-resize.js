(function () {
  'use strict';
  var box = document.querySelector('[data-asyura-widget]');
  if (!box || window.parent === window) return;
  var send = function () {
    var style = getComputedStyle(box);
    var height = box.getBoundingClientRect().bottom + (parseFloat(style.marginBottom) || 0);
    window.parent.postMessage({type: 'asyura-widget-size', height: Math.max(0, Math.ceil(height))}, '*');
  };
  if (window.ResizeObserver) new ResizeObserver(send).observe(box);
  window.addEventListener('load', send);
  window.addEventListener('resize', send);
  window.addEventListener('message', function (event) {
    if (event.source === window.parent && event.data && event.data.type === 'asyura-widget-measure') send();
  });
  send();
})();
