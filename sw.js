/* WebWatch service worker: push pranešimai */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  var title = data.title || 'WebWatch';
  var options = {
    body: data.body || 'Puslapis pasikeitė',
    icon: 'icons/icon-192.png',
    badge: 'icons/badge-72.png',
    tag: data.tag || 'webwatch',
    renotify: true,
    data: { url: data.url || './' }
  };
  var jobs = [self.registration.showNotification(title, options)];
  if (typeof data.badge === 'number' && self.navigator && 'setAppBadge' in self.navigator) {
    jobs.push(self.navigator.setAppBadge(data.badge).catch(function () {}));
  }
  event.waitUntil(Promise.all(jobs));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = new URL((event.notification.data && event.notification.data.url) || './', self.registration.scope).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if ('focus' in c && c.url.indexOf(self.registration.scope) === 0) {
          return c.focus().then(function (client) { return client.navigate ? client.navigate(url) : null; });
        }
      }
      return self.clients.openWindow(url);
    })
  );
});

self.addEventListener('pushsubscriptionchange', function () {
  // Naršyklė atnaujino prenumeratą – ji bus užregistruota iš naujo atidarius programėlę.
});
