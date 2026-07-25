/* =====================================================================
   STICKY TABLE — cố định "các cụm trên bảng" + tiêu đề bảng khi cuộn.
   Dùng chung cho các view danh sách/dữ liệu của admin_factory.

   Cách dùng trong view:
     - Gắn data-sticky-top   cho mỗi CỤM phía trên bảng cần ghim
       (theo thứ tự DOM: vd .data-actions rồi .datatable-toolbar, hoặc .toolbar).
     - Gắn data-sticky-thead cho <table> có thead cần ghim.

   Script tự đo chiều cao các cụm, xếp chồng (sticky top cộng dồn) rồi đặt
   top cho thead = tổng chiều cao cụm. Hoạt động cho cả các kiểu layout:
     - .content cuộn dọc (manage_* align-items:center; data view has-sider stretch)
   vì position:sticky tự bám theo phần tử cuộn gần nhất.
   ===================================================================== */
(function () {
    'use strict';

    var STYLE_ID = 'af-sticky-style';

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) return;
        var css =
            /* Cụm ghim: nền đặc + LUÔN tràn hết bề ngang (kể cả khi .content
               căn giữa) để dòng cuộn không lộ qua hai mép trên. */
            '[data-sticky-top]{background:#fff !important;align-self:stretch !important;' +
                'width:100% !important;box-sizing:border-box !important;}' +
            'table[data-sticky-thead] thead th{position:sticky;z-index:20;' +
                'background:#f0f3f7;' +
                'box-shadow:inset 0 1px 0 #ccc, inset 0 -1px 0 #ccc;}' +
            /* Dải trắng phủ NGAY TRÊN tiêu đề: che dòng lọt qua khe giữa cụm và thead.
               Cao đúng bằng tổng chiều cao cụm (JS đặt --af-sticky-offset);
               tràn -1px mỗi bên để phủ luôn border dọc giữa các cột. */
            'table[data-sticky-thead] thead th::before{content:"";position:absolute;' +
                'left:-1px;right:-1px;bottom:100%;height:var(--af-sticky-offset,60px);' +
                'background:#fff;z-index:1;}';
        var st = document.createElement('style');
        st.id = STYLE_ID;
        st.textContent = css;
        (document.head || document.documentElement).appendChild(st);
    }

    function layout() {
        var tops = Array.prototype.slice.call(document.querySelectorAll('[data-sticky-top]'));
        var offset = 0;
        var z = 40;
        tops.forEach(function (el) {
            el.style.position = 'sticky';
            el.style.top = offset + 'px';
            el.style.zIndex = String(z);
            // offsetHeight: số nguyên, gồm padding+border, KHÔNG gồm margin → xếp chồng chuẩn.
            offset += el.offsetHeight;
            z -= 1;
        });
        var tables = document.querySelectorAll('table[data-sticky-thead]');
        Array.prototype.forEach.call(tables, function (t) {
            t.style.setProperty('--af-sticky-offset', offset + 'px');
            Array.prototype.forEach.call(t.querySelectorAll('thead th'), function (th) {
                th.style.top = offset + 'px';
            });
        });
    }

    function init() {
        injectStyle();
        layout();
        // Tính lại nhiều lần để bắt thay đổi chiều cao cụm sau khi font/icon tải xong.
        window.addEventListener('resize', layout);
        window.addEventListener('load', layout);
        [120, 350, 800, 1500].forEach(function (ms) { setTimeout(layout, ms); });
        // Lần cuộn đầu tiên: tính lại 1 lần nữa cho chắc (layout đã ổn định).
        var once = function () { layout(); window.removeEventListener('scroll', once, true); };
        window.addEventListener('scroll', once, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Cho phép view khác gọi lại sau khi thay đổi layout (vd phân trang đổi chiều cao cụm).
    window.afStickyRelayout = layout;
})();
