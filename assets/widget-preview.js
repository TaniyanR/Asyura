(function () {
  'use strict';
  var form = document.querySelector('[data-widget-editor]');
  var dataNode = document.getElementById('widget-preview-data');
  if (!form || !dataNode) return;
  var data = JSON.parse(dataNode.textContent);
  var frame = form.querySelector('[data-widget-preview]');
  var source = form.querySelector('[data-preview-source]');
  var width = form.querySelector('[data-preview-width]');
  var status = form.querySelector('[data-preview-status]');
  var escape = function (text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[c];
    });
  };
  var render = function () {
    var html = form.elements.template_html.value;
    var css = form.elements.custom_css.value.replace(/<\/style/gi, '<\\/style');
    var limit = form.elements.item_limit ? Math.max(1, Math.min(100, Number(form.elements.item_limit.value) || 1)) : 100;
    var rows = data[source.value].slice(0, limit);
    var content = rows.map(function (item, index) {
      var image = /^https?:\/\//i.test(item.image_url || '') ? item.image_url : '';
      var map = {
        title: escape(item.title), url: '#', description: escape(item.description), category: escape(item.category),
        rank: escape(item.rank || index+1), in_count: Number(item.in_count || 0).toLocaleString('en-US'),
        out_count: Number(item.out_count || 0).toLocaleString('en-US'), site_name: escape(item.site_name),
        rss_name: escape(item.rss_name), image: escape(image), image_tag: image ? '<img src="'+escape(image)+'" alt="" loading="lazy">' : '',
        published_at: escape(item.published_at), rel: escape(item.rel || 'nofollow'), target: '_self'
      };
      return html.replace(/\{([a-z_]+)\}/g, function (match, key) {return Object.prototype.hasOwnProperty.call(map,key) ? map[key] : match;});
    }).join('');
    // The preview is an opaque sandbox; also remove navigation and active elements.
    var parsed = new DOMParser().parseFromString(content, 'text/html');
    parsed.querySelectorAll('script,iframe,object,embed,form,link,meta,base').forEach(function (node) {node.remove();});
    parsed.querySelectorAll('*').forEach(function (node) {
      Array.from(node.attributes).forEach(function (attr) {
        if (/^on/i.test(attr.name) || /javascript\s*:/i.test(attr.value)) node.removeAttribute(attr.name);
      });
    });
    frame.style.maxWidth = width.value;
    frame.srcdoc = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src https: http: data:; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;"><style>html,body{margin:0;padding:0;background:transparent}*{box-sizing:border-box}a{pointer-events:none}'+css+'</style></head><body><div class="asyura-'+escape(data.type)+'">'+parsed.body.innerHTML+'</div></body></html>';
    status.textContent = source.value === 'samples' ? 'サンプルで表示中です。編集内容をすぐに反映します。サンプルは公開サイトには表示されません。' : (rows.length ? '現在の配信候補で表示中です。プレビューのリンクはクリック計測されません。' : '現在の配信候補はありません。RSS取得状況・変換率・返還残数・除外設定を確認してください。サンプルに切り替えるとデザインを確認できます。');
  };
  var timer;
  form.addEventListener('input', function () {clearTimeout(timer); timer = setTimeout(render, 200);});
  source.addEventListener('change', render);
  width.addEventListener('change', render);
  render();
})();
