/* =====================================================================
   MOBILE (≤768px) — bấm 1 ngày trên lịch .ltp-board -> mở modal xem nội dung
   của ngày đó. Clone nội dung card (chỉ để xem) nên không ảnh hưởng trạng thái
   board khi render lại.

   Dùng chung cho mọi view vẽ lịch bằng bộ class .ltp-*:
     - production_staff/long_term_production_plan (Kế hoạch sản xuất dài ngày)
     - sell_factory/production_forecast (tab KHSX dự kiến của Đặt hàng nhà máy)
   Đi kèm layout/day-plan-modal.php (markup #ltp-day-modal) — include cả 2.

   GOTCHA: rule ẩn card trên mobile PHẢI scope vào cha (.ltp-card > .ltp-card-body),
   nếu để selector class trần thì chính bản CLONE trong modal cũng bị display:none
   và modal mở ra trống trơn.
   ===================================================================== */
(function () {
    var modal = document.getElementById('ltp-day-modal');
    if (!modal) return;
    var titleEl = document.getElementById('ltp-day-modal-title');
    var bodyEl  = document.getElementById('ltp-day-modal-body');

    function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }

    function openDay(card) {
        var wd = card.querySelector('.ltp-day .wd');
        var dt = card.querySelector('.ltp-day .dt');
        titleEl.textContent = ((wd ? wd.textContent : '') + ' ' + (dt ? dt.textContent : '')).trim() || 'Kế hoạch ngày';
        bodyEl.innerHTML = '';
        var content = card.querySelector('.ltp-card-body');
        bodyEl.appendChild(content ? content.cloneNode(true) : document.createTextNode('Chưa có nội dung.'));
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeDay() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        bodyEl.innerHTML = '';
    }

    modal.querySelectorAll('[data-ltp-day-close]').forEach(function (el) { el.addEventListener('click', closeDay); });

    document.addEventListener('click', function (e) {
        if (!isMobile()) return;
        var head = e.target.closest('.ltp-card-head');
        if (!head) return;
        // Bỏ qua khi bấm nút xóa ngày (×) hoặc bất kỳ nút nào trong tiêu đề.
        if (e.target.closest('.ltp-card-del') || e.target.closest('button')) return;
        var card = head.closest('.ltp-card');
        if (card) openDay(card);
    });
})();
