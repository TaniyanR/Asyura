(function () {
  'use strict';
  if (window.__asyuraEmbedLoaded) return;
  var script = document.currentScript;
  if (!script) return;
  var source = new URL(script.src, location.href);
  var widgetPath = source.pathname.replace(/\/assets\/widget-embed\.js$/, '/widgets/');
  window.__asyuraEmbedLoaded = true;
  // Recognize old iframe-only tags, but only from this Asyura installation.
  var widgetUrl = function (frame) {
    if (!frame || !frame.matches || !frame.matches('iframe[src]')) return null;
    try {
      var url = new URL(frame.src, location.href);
      if (url.origin !== source.origin || !url.pathname.startsWith(widgetPath)) return null;
      if (!/^(links|rss|ranking|notices)\.php$/.test(url.pathname.slice(widgetPath.length))) return null;
      return url.searchParams.get('id') ? url : null;
    } catch (_) { return null; }
  };
  window.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'asyura-widget-size') return;
    document.querySelectorAll('iframe[src]').forEach(function (frame) {
      var url = widgetUrl(frame);
      if (!url || event.source !== frame.contentWindow || event.origin !== url.origin) return;
      var height = Number(event.data.height);
      if (Number.isFinite(height) && height >= 0 && height <= 100000) frame.style.height = Math.ceil(height) + 'px';
    });
  });
  var requestFrame = function (frame) {
    var url = widgetUrl(frame);
    if (url && frame.contentWindow) frame.contentWindow.postMessage({type: 'asyura-widget-measure'}, url.origin);
  };
  var request = function () { document.querySelectorAll('iframe[src]').forEach(requestFrame); };
  // Covers lazy frames and frames inserted by WordPress after initial parsing.
  document.addEventListener('load', function (event) { requestFrame(event.target); }, true);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', request, {once:true});
  request();
})();
