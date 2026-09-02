document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var el=document.querySelector(b.dataset.copy);if(!el)return;navigator.clipboard.writeText(el.textContent).then(function(){var old=b.textContent;b.textContent='コピーしました';setTimeout(function(){b.textContent=old},1400)})});
document.addEventListener('submit',function(e){var f=e.target;if(!f.matches('[data-confirm]'))return;var message=f.dataset.confirm||'実行しますか？';if(!window.confirm(message))e.preventDefault()});

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

(function(){
    var sidebar=document.querySelector('[data-sidebar]');
    var overlay=document.querySelector('[data-sidebar-overlay]');
    var menuButton=document.querySelector('[data-mobile-menu-toggle]');
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
    if(overlay)overlay.addEventListener('click',closeMenu);
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeMenu()});
    sidebar.addEventListener('click',function(e){
        if(!window.matchMedia('(max-width:900px)').matches)return;
        if(e.target.closest('a'))closeMenu();
    });
    window.addEventListener('resize',function(){if(!window.matchMedia('(max-width:900px)').matches)closeMenu()});
})();
