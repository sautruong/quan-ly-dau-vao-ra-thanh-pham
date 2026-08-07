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
    var footEl  = document.getElementById('ltp-day-modal-foot');
    var exportBtn = footEl && footEl.querySelector('.ltp-day-export');
    var openedCard = null;   // card ngày THẬT đang xem (không phải bản clone trong modal)

    function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }

    function openDay(card) {
        openedCard = card;
        var wd = card.querySelector('.ltp-day .wd');
        var dt = card.querySelector('.ltp-day .dt');
        titleEl.textContent = ((wd ? wd.textContent : '') + ' ' + (dt ? dt.textContent : '')).trim() || 'Kế hoạch ngày';
        bodyEl.innerHTML = '';
        var content = card.querySelector('.ltp-card-body');
        bodyEl.appendChild(content ? content.cloneNode(true) : document.createTextNode('Chưa có nội dung.'));
        // Chân modal chỉ hiện khi card có nút gốc — view nào không có thì không mọc nút thừa.
        if (footEl) {
            var coNutGoc = !!card.querySelector('.ltp-export-plan');
            footEl.hidden = !coNutGoc;
            if (exportBtn) { exportBtn.disabled = false; exportBtn.classList.remove('is-busy'); }
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeDay() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        bodyEl.innerHTML = '';
        openedCard = null;
        if (footEl) footEl.hidden = true;
    }

    /* "Xuất kế hoạch" — KHÔNG chép lại logic xuất, chỉ bấm hộ nút gốc .ltp-export-plan của
       card ngày đang xem. Nút gốc nằm trong .ltp-card-foot (bị display:none trên mobile) nhưng
       click() theo lệnh vẫn kích hoạt được, và handler bên long_term_production_plan.js đọc dữ
       liệu từ card THẬT nên không dính bản clone trong modal (clone giữ nguyên id/class, tự đọc
       lại là nhân đôi dòng). Xuất xong handler tự chuyển sang trang plan_for_staff. */
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            if (!openedCard) return;
            var goc = openedCard.querySelector('.ltp-export-plan');
            if (!goc) return;
            exportBtn.disabled = true;
            exportBtn.classList.add('is-busy');
            goc.click();
            // Thất bại thì handler gốc alert rồi bật lại nút gốc — mở khoá nút này theo để bấm lại.
            setTimeout(function () {
                if (!goc.disabled) { exportBtn.disabled = false; exportBtn.classList.remove('is-busy'); }
            }, 1200);
        });
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
