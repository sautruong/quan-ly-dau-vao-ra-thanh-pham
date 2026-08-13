/* =====================================================================
 *  Service Worker — Vua An Toàn (PWA "Thêm vào màn hình chính")
 *  ---------------------------------------------------------------------
 *  App PHP động: dùng chiến lược NETWORK-FIRST cho mọi request GET
 *  (luôn lấy dữ liệu mới; chỉ dùng cache khi mất mạng), để không hiển
 *  thị dữ liệu cũ. Precache 1 vài asset tĩnh + trang gốc làm fallback
 *  offline tối thiểu. Không cache response POST / query động nhạy cảm.
 * ===================================================================== */
var CACHE = 'nvsxvat-shell-v2';

// Đường dẫn tương đối theo vị trí của sw.js (đặt ở gốc app) -> an toàn cả khi app nằm trong thư mục con.
var PRECACHE = [
    'public/images/logo/logo_vat_png.png',
    'manifest.webmanifest'
];

self.addEventListener('install', function (e) {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE).then(function (c) {
            return Promise.all(PRECACHE.map(function (url) {
                return c.add(url).catch(function () {}); // bỏ qua nếu 1 file lỗi, không chặn cài đặt
            }));
        })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) {
                if (k !== CACHE) return caches.delete(k);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

/* =====================================================================
 *  THÔNG BÁO ĐẨY (Web Push)
 *  ---------------------------------------------------------------------
 *  Hai handler dưới đây là thứ DUY NHẤT chạy được khi người dùng đã thoát
 *  app — trình duyệt đánh thức service worker để xử lý, rồi cho ngủ lại.
 *
 *  Lưu ý bắt buộc: iOS/Safari KHÔNG cho phép nhận push mà không hiện thông
 *  báo. Nếu handler 'push' chạy xong mà không gọi showNotification(), hệ
 *  điều hành sẽ tự hiện một thông báo mặc định, và nếu tái phạm nhiều lần
 *  thì thu hồi luôn quyền push của trang. Vì vậy LUÔN showNotification().
 * ===================================================================== */

self.addEventListener('push', function (e) {
    var data = {};
    try {
        data = e.data ? e.data.json() : {};
    } catch (err) {
        // Máy chủ push có thể gửi gói rỗng hoặc không phải JSON — vẫn phải hiện gì đó.
        data = { title: 'Vua An Toàn', body: e.data ? e.data.text() : '' };
    }

    var title = data.title || 'Vua An Toàn';
    var opts  = {
        body:     data.body || '',
        icon:     'public/images/logo/icon-192.png',
        badge:    'public/images/logo/icon-badge.png',
        tag:      data.tag || 'nvsxvat',
        renotify: true,                       // cùng tag vẫn rung/kêu lại khi có cái mới
        data:     { url: data.url || '' }
    };

    e.waitUntil(
        self.registration.showNotification(title, opts).then(function () {
            // Gắn con số lên icon app ngoài màn hình chính.
            if (typeof data.count !== 'number' || !('setAppBadge' in self.navigator)) return;
            return data.count > 0
                ? self.navigator.setAppBadge(data.count)
                : self.navigator.clearAppBadge();
        }).catch(function () {})
    );
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var url = (e.notification.data && e.notification.data.url) || '';

    // Đang mở sẵn thì nhảy vào tab đó, chưa mở thì mở tab mới.
    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                if ('focus' in c) {
                    if (url && 'navigate' in c) { try { c.navigate(url); } catch (err) {} }
                    return c.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url || './');
        }).catch(function () {})
    );
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;                    // không đụng POST/PUT...
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;     // chỉ xử lý cùng origin

    e.respondWith(
        fetch(req).then(function (res) {
            // Cache lại asset tĩnh (ảnh/css/js/font) để dùng khi offline.
            if (res && res.ok && /\.(png|jpg|jpeg|gif|webp|svg|css|js|woff2?|ttf|ico)$/i.test(url.pathname)) {
                var copy = res.clone();
                caches.open(CACHE).then(function (c) { c.put(req, copy); });
            }
            return res;
        }).catch(function () {
            // Mất mạng -> trả từ cache nếu có.
            return caches.match(req);
        })
    );
});
