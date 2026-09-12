(function(){
  'use strict';
  if(window.__asyuraTrackerLoaded)return;
  window.__asyuraTrackerLoaded=true;
  var script=document.currentScript;if(!script)return;
  var site=script.dataset.siteId||'',key=script.dataset.siteKey||'';if(!site||!key)return;
  var src=new URL(script.src),endpoint=src.origin+src.pathname.replace(/\/assets\/tracker\.js$/,'/api/collect.php');
  // Existing tracker tags also enable resizing for legacy iframe-only widgets.
  if(!window.__asyuraEmbedLoaded&&!window.__asyuraEmbedLoading){
    window.__asyuraEmbedLoading=true;
    var embedScript=document.createElement('script');
    embedScript.src=src.origin+src.pathname.replace(/\/assets\/tracker\.js$/,'/assets/widget-embed.js');
    embedScript.async=true;
    embedScript.onerror=function(){window.__asyuraEmbedLoading=false;};
    (document.head||document.documentElement).appendChild(embedScript);
  }
  var randomId=function(){if(window.crypto&&window.crypto.randomUUID)return window.crypto.randomUUID().replace(/-/g,'');var a=new Uint8Array(16);if(window.crypto&&window.crypto.getRandomValues)window.crypto.getRandomValues(a);else for(var i=0;i<a.length;i++)a[i]=Math.floor(Math.random()*256);return Array.from(a,function(v){return v.toString(16).padStart(2,'0')}).join('')};
  var cookieGet=function(name){var prefix=encodeURIComponent(name)+'=';var parts=document.cookie?document.cookie.split('; '):[];for(var i=0;i<parts.length;i++)if(parts[i].indexOf(prefix)===0)return decodeURIComponent(parts[i].slice(prefix.length));return''};
  var cookieSet=function(name,value,maxAge){try{document.cookie=encodeURIComponent(name)+'='+encodeURIComponent(value)+'; Path=/; Max-Age='+maxAge+'; SameSite=Lax'}catch(_){}};
  var getStored=function(name){try{var value=localStorage.getItem(name);if(value)return value}catch(_){}return cookieGet(name)};
  var setStored=function(name,value,maxAge){try{localStorage.setItem(name,value)}catch(_){}cookieSet(name,value,maxAge||2592000)};
  var visitorKey='asyura_visitor_'+site,sessionKey='asyura_session_'+site,sessionTimeKey=sessionKey+'_at',queueKey='asyura_queue_'+site;
  var visitorId=getStored(visitorKey)||randomId();setStored(visitorKey,visitorId,31536000);
  var now=Date.now(),last=parseInt(getStored(sessionTimeKey)||'0',10),sessionId=(now-last<1800000?getStored(sessionKey):'')||randomId();setStored(sessionKey,sessionId);setStored(sessionTimeKey,String(now));
  var pageviewId=randomId(),pageReferrer=document.referrer,visibleSince=document.visibilityState==='visible'?Date.now():0,engaged=0,maxScroll=0,lastSent=0,sequence=0,lastUrl=location.href;
  var readQueue=function(){try{var rows=JSON.parse(localStorage.getItem(queueKey)||'[]');return Array.isArray(rows)?rows:[]}catch(_){return[]}};
  var writeQueue=function(rows){try{localStorage.setItem(queueKey,JSON.stringify(rows.slice(-20)))}catch(_){}};
  var enqueue=function(data){var rows=readQueue();if(!rows.some(function(row){return row.event_id===data.event_id}))rows.push(data);writeQueue(rows)};
  var post=function(data){if(!window.fetch)return Promise.reject(new Error('fetch unavailable'));return fetch(endpoint,{method:'POST',mode:'cors',keepalive:true,headers:{'Content-Type':'text/plain;charset=UTF-8'},body:JSON.stringify(data),credentials:'omit'}).then(function(response){if(!response.ok)throw new Error('collect failed')})};
  var transmit=function(data,urgent){if(urgent&&navigator.sendBeacon){try{if(navigator.sendBeacon(endpoint,new Blob([JSON.stringify(data)],{type:'text/plain;charset=UTF-8'})))return}catch(_){}}post(data).catch(function(){enqueue(data)})};
  var flushQueue=function(){var rows=readQueue();if(!rows.length||!window.fetch)return;var remaining=rows.slice();rows.forEach(function(row){post(row).then(function(){remaining=remaining.filter(function(item){return item.event_id!==row.event_id});writeQueue(remaining)}).catch(function(){})})};
  var ensureSession=function(){var stamp=parseInt(getStored(sessionTimeKey)||'0',10),current=Date.now();if(current-stamp>=1800000){sessionId=randomId();setStored(sessionKey,sessionId)}setStored(sessionTimeKey,String(current));return sessionId};
  var widget=function(el){var w=el.closest('[data-asyura-widget]');return w?w.getAttribute('data-asyura-widget'):''};
  var base=function(type){sequence++;return{site_id:site,site_key:key,event_type:type,event_id:randomId(),pageview_id:pageviewId,visitor_id:visitorId,session_id:ensureSession(),page_url:location.href,referrer:pageReferrer,title:document.title,screen:screen.width+'x'+screen.height,language:navigator.language||'',timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'',client_time:new Date().toISOString(),sequence:sequence,automation:navigator.webdriver===true}};
  var send=function(type,target,wid,urgent){var data=base(type);data.target_url=target||'';data.widget_id=wid||'';transmit(data,!!urgent)};
  var updateVisible=function(){if(visibleSince){engaged+=Math.max(0,Date.now()-visibleSince);visibleSince=0}};
  var sendEngagement=function(force){updateVisible();if(!force&&engaged-lastSent<5000){if(document.visibilityState==='visible')visibleSince=Date.now();return}var data=base('engagement');data.engagement_ms=Math.round(engaged);data.scroll_depth=maxScroll;transmit(data,force);lastSent=engaged;if(document.visibilityState==='visible')visibleSince=Date.now()};
  var beginPageview=function(referrer){pageviewId=randomId();pageReferrer=referrer||'';engaged=0;lastSent=0;maxScroll=0;visibleSince=document.visibilityState==='visible'?Date.now():0;send('pageview','','',false)};
  var routeChanged=function(){if(location.href===lastUrl)return;var previous=lastUrl;sendEngagement(true);lastUrl=location.href;beginPageview(previous)};
  flushQueue();send('pageview','','',false);
  document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href]');if(!a)return;var u;try{u=new URL(a.href,location.href)}catch(_){return}if(!/^https?:$/.test(u.protocol))return;var outside=u.host!==location.host,wid=widget(a);send(wid?'widget_click':(outside?'outbound':'internal_click'),u.href,wid,true)},{capture:true,passive:true});
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')sendEngagement(true);else{if(Date.now()-parseInt(getStored(sessionTimeKey)||'0',10)>=1800000)beginPageview(location.href);else visibleSince=Date.now()}});
  document.addEventListener('scroll',function(){var h=Math.max(document.documentElement.scrollHeight-document.documentElement.clientHeight,1);maxScroll=Math.max(maxScroll,Math.min(100,Math.round(window.scrollY/h*100)))},{passive:true});
  var originalPush=history.pushState;history.pushState=function(){var result=originalPush.apply(this,arguments);setTimeout(routeChanged,0);return result};
  window.addEventListener('popstate',routeChanged);window.addEventListener('hashchange',routeChanged);window.addEventListener('online',flushQueue);
  window.addEventListener('pagehide',function(){sendEngagement(true)});window.setInterval(function(){sendEngagement(false)},15000);window.setInterval(flushQueue,30000);
})();
