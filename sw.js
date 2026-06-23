// Kích hoạt Service Worker
self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  return self.clients.claim();
});

// Phản hồi các yêu cầu mạng mạng (Load trang PHP trực tuyến)
self.addEventListener('fetch', (e) => {
  e.respondWith(
    fetch(e.request).catch(() => {
      return new Response("Bạn đang ngoại tuyến (Offline). Hãy kết nối mạng.");
    })
  );
});
