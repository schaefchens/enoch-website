'use strict';
const VERSION='20260925-hpb-keep-disk-3';
const CACHE='enoch-static-'+VERSION;
const ASSETS=[
  '/manifest.webmanifest',
  '/assets/favicon.svg',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/assets/apple-touch-icon.png',
  '/assets/preferences.js?v='+VERSION,
  '/assets/pwa.js?v='+VERSION,
  '/assets/app.css?v='+VERSION,
  '/assets/app.js?v='+VERSION
];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',event=>{event.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('enoch-static-')&&key!==CACHE).map(key=>caches.delete(key)))),self.clients.claim()]));});
self.addEventListener('message',event=>{if(event.data?.type==='SKIP_WAITING')self.skipWaiting();});
self.addEventListener('fetch',event=>{
  const request=event.request;if(request.method!=='GET')return;
  const url=new URL(request.url);if(url.origin!==self.location.origin)return;
  // Authenticated documents, API calls and lifecycle actions always use the network.
  if(!(url.pathname.startsWith('/assets/')||url.pathname==='/manifest.webmanifest'))return;
  event.respondWith(fetch(request).then(response=>{if(response.ok){const copy=response.clone();event.waitUntil(caches.open(CACHE).then(cache=>cache.put(request,copy)));}return response;}).catch(()=>caches.match(request).then(cached=>cached||Response.error())));
});
