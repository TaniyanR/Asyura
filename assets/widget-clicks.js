(function () {
  'use strict';
  var box = document.querySelector('[data-asyura-widget]');
  var data = document.getElementById('asyura-click-targets');
  if (!box || !data) return;
  var targets = new Map();
  try {
    var entries = JSON.parse(data.textContent);
    Object.keys(entries).forEach(function (href) {
      var endpoint = new URL(entries[href], location.href);
      if (endpoint.origin === location.origin) targets.set(new URL(href, location.href).href, endpoint.href);
    });
  } catch (_) { return; }
  var record = function (event) {
    if (!event.isTrusted || event.defaultPrevented) return;
    if (event.type === 'auxclick' ? event.button !== 1 : event.button !== 0) return;
    var link = event.target.closest && event.target.closest('a[href]');
    if (!link || !box.contains(link)) return;
    var endpoint = targets.get(link.href);
    if (!endpoint) return;
    // Never replace href, prevent navigation, or wait for the measurement request.
    try { if (navigator.sendBeacon && navigator.sendBeacon(endpoint, '')) return; } catch (_) {}
    if (window.fetch) fetch(endpoint, {method:'POST',keepalive:true,credentials:'omit'}).catch(function () {});
  };
  box.addEventListener('click', record);
  box.addEventListener('auxclick', record);
})();
