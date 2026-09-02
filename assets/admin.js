document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var el=document.querySelector(b.dataset.copy);if(!el)return;navigator.clipboard.writeText(el.textContent).then(function(){var old=b.textContent;b.textContent='コピーしました';setTimeout(function(){b.textContent=old},1400)})});
document.addEventListener('submit',function(e){var f=e.target;if(!f.matches('[data-confirm]'))return;var message=f.dataset.confirm||'実行しますか？';if(!window.confirm(message))e.preventDefault()});

document.addEventListener('click',function(e){
    var toggle=e.target.closest('[data-nav-toggle]');
    if(!toggle)return;
    var group=toggle.closest('[data-nav-group]');
    if(!group)return;
    var open=group.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded',open?'true':'false');
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
    }
    function closeMenu(){
        sidebar.classList.remove('is-open');
        if(overlay)overlay.classList.remove('is-open');
        document.body.classList.remove('menu-open');
        menuButton.setAttribute('aria-expanded','false');
    }

    menuButton.addEventListener('click',function(){sidebar.classList.contains('is-open')?closeMenu():openMenu()});
    if(closeButton)closeButton.addEventListener('click',closeMenu);
    if(overlay)overlay.addEventListener('click',closeMenu);
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeMenu()});
    sidebar.addEventListener('click',function(e){if(window.matchMedia('(max-width:860px)').matches&&e.target.closest('a'))closeMenu()});
})();
