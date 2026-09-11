document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var el=document.querySelector(b.dataset.copy);if(!el)return;navigator.clipboard.writeText(el.textContent).then(function(){var old=b.textContent;b.textContent='コピーしました';setTimeout(function(){b.textContent=old},1400)})});
document.addEventListener('submit',function(e){var f=e.target;if(!f.matches('[data-confirm]'))return;var message=f.dataset.confirm||'実行しますか？';if(!window.confirm(message))e.preventDefault()});

document.addEventListener('click',function(e){
    var button=e.target.closest('[data-fetch-site-name]');
    if(!button)return;
    var form=button.closest('form');
    var urlInput=form&&form.querySelector('[data-partner-url]');
    var nameInput=form&&form.querySelector('[data-partner-name]');
    var result=form&&form.querySelector('[data-site-name-result]');
    var csrf=form&&form.querySelector('input[name="csrf_token"]');
    if(!urlInput||!nameInput||!csrf)return;
    if(!urlInput.reportValidity())return;
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='取得中…';
    if(result){result.textContent='相手サイトを確認しています。';result.classList.remove('is-error','is-success')}
    var data=new FormData();data.append('url',urlInput.value);data.append('csrf_token',csrf.value);
    fetch(button.dataset.endpoint,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(response){return response.json().catch(function(){throw new Error('サーバーから正しい応答がありません。')}).then(function(json){if(!response.ok||!json.ok)throw new Error(json.message||'サイト名を取得できませんでした。');return json})})
        .then(function(json){nameInput.value=json.name;nameInput.focus();nameInput.select();if(result){result.textContent=json.source==='domain'?'ページ内にサイト名がなかったため、ドメイン名を入力しました。':'サイト名を取得しました。必要なら修正してください。';result.classList.add('is-success')}})
        .catch(function(error){if(result){result.textContent=error.message;result.classList.add('is-error')}})
        .finally(function(){button.disabled=false;button.textContent=oldText});
});

document.addEventListener('click',function(e){
    var toggle=e.target.closest('[data-nav-toggle]');
    if(!toggle)return;
    var group=toggle.closest('[data-nav-group]');
    if(!group)return;
    var open=!group.classList.contains('is-open');
    document.querySelectorAll('[data-nav-group].is-open').forEach(function(other){
        if(other===group)return;
        other.classList.remove('is-open');
        var otherToggle=other.querySelector('[data-nav-toggle]');
        if(otherToggle)otherToggle.setAttribute('aria-expanded','false');
    });
    group.classList.toggle('is-open',open);
    toggle.setAttribute('aria-expanded',open?'true':'false');
});

(function(){
    var siteMenu=document.querySelector('[data-site-menu]');
    var siteToggle=document.querySelector('[data-site-menu-toggle]');
    if(!siteMenu||!siteToggle)return;
    function closeSiteMenu(){siteMenu.classList.remove('is-open');siteToggle.setAttribute('aria-expanded','false')}
    siteToggle.addEventListener('click',function(e){e.stopPropagation();var open=!siteMenu.classList.contains('is-open');siteMenu.classList.toggle('is-open',open);siteToggle.setAttribute('aria-expanded',open?'true':'false')});
    document.addEventListener('click',function(e){if(!siteMenu.contains(e.target))closeSiteMenu()});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeSiteMenu()});
})();

document.addEventListener('click',function(e){
    var add=e.target.closest('[data-add-rss-feed]');
    if(add){
        var form=add.closest('form');
        var list=form&&form.querySelector('[data-rss-feed-list]');
        var template=form&&form.querySelector('[data-rss-feed-template]');
        if(!list||!template)return;
        var index=String(Date.now())+String(Math.floor(Math.random()*1000));
        list.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',index));
        var rows=list.querySelectorAll('.rss-feed-row');
        var input=rows[rows.length-1].querySelector('input:not([type="hidden"])');
        if(input)input.focus();
        return;
    }
    var remove=e.target.closest('[data-remove-rss-feed]');
    if(!remove)return;
    var row=remove.closest('.rss-feed-row');
    var list=row&&row.closest('[data-rss-feed-list]');
    if(!row||!list)return;
    if(list.querySelectorAll('.rss-feed-row').length===1){
        row.querySelectorAll('input').forEach(function(input){if(input.type==='hidden')input.value='0';else if(input.type==='checkbox')input.checked=true;else input.value=''});
        return;
    }
    row.remove();
});

(function(){
    var sidebar=document.querySelector('[data-sidebar]');
    var overlay=document.querySelector('[data-sidebar-overlay]');
    var menuButton=document.querySelector('[data-mobile-menu-toggle]');
    var closeButton=document.querySelector('[data-mobile-menu-close]');
    if(!sidebar||!menuButton)return;

    function openMenu(){
        sidebar.classList.add('is-open');
        if(overlay)overlay.classList.add('is-open');
        document.body.classList.add('menu-open');
        menuButton.setAttribute('aria-expanded','true');
        menuButton.setAttribute('aria-label','メニューを閉じる');
    }
    function closeMenu(){
        sidebar.classList.remove('is-open');
        if(overlay)overlay.classList.remove('is-open');
        document.body.classList.remove('menu-open');
        menuButton.setAttribute('aria-expanded','false');
        menuButton.setAttribute('aria-label','メニューを開く');
    }

    menuButton.addEventListener('click',function(){sidebar.classList.contains('is-open')?closeMenu():openMenu()});
    if(closeButton)closeButton.addEventListener('click',closeMenu);
    if(overlay)overlay.addEventListener('click',closeMenu);
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeMenu()});
    sidebar.addEventListener('click',function(e){if(window.matchMedia('(max-width:900px)').matches&&e.target.closest('a'))closeMenu()});
    window.addEventListener('resize',function(){if(!window.matchMedia('(max-width:900px)').matches)closeMenu()});
})();
