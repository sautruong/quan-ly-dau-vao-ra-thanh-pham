/* =====================================================================
 *  Thanh kéo chia đôi 2 khối (dùng chung nhiều view)
 *  Đánh dấu: <div class="rs-resizer" data-target="#id-khoi-co-dinh"
 *                 data-storage-key="ten_key_localstorage"
 *                 data-min="360" data-max="900"></div>
 * ===================================================================== */
(function () {
    'use strict';

    function initResizer(resizer) {
        var targetSel = resizer.getAttribute('data-target');
        var target = targetSel ? document.querySelector(targetSel) : null;
        var container = resizer.parentElement;
        if (!target || !container) return;

        var storageKey = resizer.getAttribute('data-storage-key') || '';
        var minWidth = parseInt(resizer.getAttribute('data-min'), 10) || 320;
        var maxWidth = parseInt(resizer.getAttribute('data-max'), 10) || 900;

        function applyWidth(px) {
            target.style.width = px + 'px';
        }

        var saved = storageKey ? localStorage.getItem(storageKey) : null;
        if (saved) {
            applyWidth(Math.max(minWidth, Math.min(parseInt(saved, 10), maxWidth)));
        } else {
            applyWidth(Math.round(target.getBoundingClientRect().width));
        }

        var dragging = false;
        var startX = 0;
        var startWidth = 0;
        var growsWhenDraggedLeft = resizer.nextElementSibling === target;

        resizer.addEventListener('mousedown', function (e) {
            dragging = true;
            startX = e.clientX;
            startWidth = target.getBoundingClientRect().width;
            resizer.classList.add('is-dragging');
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'col-resize';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            var newWidth = growsWhenDraggedLeft ? startWidth - dx : startWidth + dx;
            var containerWidth = container.getBoundingClientRect().width;
            var maxAllowed = Math.min(maxWidth, containerWidth - minWidth - 40);
            newWidth = Math.max(minWidth, Math.min(newWidth, maxAllowed));
            applyWidth(newWidth);
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            resizer.classList.remove('is-dragging');
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
            if (storageKey) {
                localStorage.setItem(storageKey, Math.round(target.getBoundingClientRect().width));
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var resizers = document.querySelectorAll('.rs-resizer');
        for (var i = 0; i < resizers.length; i++) initResizer(resizers[i]);
    });
})();
