/*
 * tabs.js — SharedTabs: bộ chuyển tab ngang dùng chung (tách ra từ
 * .de-tabs cũ của module daily_events, xem public/css/shared/tabs.css).
 *
 * API:
 *   var t = SharedTabs.init(rootEl, { onChange: function(key) {} });
 *   t.activate('key')  -> chuyển sang tab có data-tab="key"
 *
 * Markup cần có bên trong rootEl (không nhất thiết là con trực tiếp):
 *   <button class="shared-tab" data-tab="key">...</button>  (nhiều nút)
 *   <section class="shared-panel" data-panel="key">...</section> (nhiều panel)
 */
(function () {
    'use strict';

    function init(root, opts) {
        root = root || document;
        opts = opts || {};
        var tabs = root.querySelectorAll('.shared-tab');
        var panels = root.querySelectorAll('.shared-panel');

        function activate(key) {
            tabs.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-tab') === key); });
            panels.forEach(function (p) {
                var show = p.getAttribute('data-panel') === key;
                p.style.display = show ? '' : 'none';
                p.classList.toggle('is-active', show);
            });
            if (typeof opts.onChange === 'function') opts.onChange(key);
        }

        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activate(btn.getAttribute('data-tab'));
            });
        });

        return { activate: activate };
    }

    window.SharedTabs = { init: init };
})();
