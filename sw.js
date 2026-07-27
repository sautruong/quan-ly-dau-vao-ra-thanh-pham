/* =====================================================================
 *  Service Worker — Vua An Toàn (PWA "Thêm vào màn hình chính")
 *  ---------------------------------------------------------------------
 *  App PHP động: dùng chiến lược NETWORK-FIRST cho mọi request GET
 *  (luôn lấy dữ liệu mới; chỉ dùng cache khi mất mạng), để không hiển
 *  thị dữ liệu cũ. Precache 1 vài asset tĩnh + trang gốc làm fallback
 *  offline tối thiểu. Không cache response POST / query động nhạy cảm.
 * ===================================================================== */
var CACHE = 'nvsxvat-shell-v1';

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
