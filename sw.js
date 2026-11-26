const C='nsr-v2',SHELL=['./','index.html','manifest.webmanifest','icon-192.png'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(SHELL)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x!==C).map(x=>caches.delete(x)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{const r=e.request;const u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin||u.pathname.startsWith('/api/'))return;
 e.respondWith(fetch(r).then(res=>{const cp=res.clone();caches.open(C).then(c=>c.put(r,cp));return res}).catch(()=>caches.match(r)))});
// upozorneni spravci: zprava je prazdna, text se nacte ze stavu dat
self.addEventListener('push',e=>{e.waitUntil((async()=>{
 let title='Nákup s rozumem',body='Zkontrolujte stav dat ve správě.';
 try{const t=await (await fetch('cron/status.php?_='+Date.now(),{cache:'no-store'})).text();
  if(t.startsWith('STAV: PROBLEM')){title='Nákup s rozumem: problém s daty';body=t.split('\n').filter(l=>l.startsWith('- ')).join('\n')||body}
  else if(t.startsWith('STAV: OK')){title='Nákup s rozumem: upozornění fungují';body='Data jsou v pořádku.'}}catch(_){}
 await self.registration.showNotification(title,{body,icon:'icon-192.png',tag:'nsr-stav',renotify:true});
})())});
self.addEventListener('notificationclick',e=>{e.notification.close();e.waitUntil(self.clients.openWindow(new URL('admin/',self.registration.scope).href))});
