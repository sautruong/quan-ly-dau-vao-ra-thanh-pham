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
    var addProdBtn = footEl && footEl.querySelector('.ltp-day-add-product');
    var addTaskBtn = footEl && footEl.querySelector('.ltp-day-add-task');
    var openedCard = null;   // card ngày THẬT đang xem
    /* Node .ltp-card-body đang được MƯỢN vào modal, và chỗ cũ để trả về khi đóng. */
    var muonBody = null;
    var muonChoCu = null;

    function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }

    function openDay(card) {
        openedCard = card;
        var wd = card.querySelector('.ltp-day .wd');
        var dt = card.querySelector('.ltp-day .dt');
        titleEl.textContent = ((wd ? wd.textContent : '') + ' ' + (dt ? dt.textContent : '')).trim() || 'Kế hoạch ngày';
        /* MƯỢN NODE THẬT, KHÔNG CLONE (đổi 11/8/2026).
           Trước đây modal hiển thị bản sao — xem thì được, nhưng mọi thao tác sửa/xoá bên trong
           đều rơi vào bản sao rồi biến mất khi đóng modal, nên trên điện thoại không đổi tên hay
           xoá sản phẩm được. Mượn chính node của card thì TẤT CẢ handler sẵn có chạy y như trên
           máy tính, không phải viết lại cái nào.
           Nhớ đúng chỗ cũ để trả về; closeDay() luôn trả trước khi rời modal. */
        bodyEl.innerHTML = '';
        muonBody = card.querySelector('.ltp-card-body');
        if (muonBody) {
            muonChoCu = { cha: muonBody.parentNode, ke: muonBody.nextSibling };
            bodyEl.appendChild(muonBody);
        } else {
            muonChoCu = null;
            bodyEl.appendChild(document.createTextNode('Chưa có nội dung.'));
        }
        /* Chân modal hiện khi card có ÍT NHẤT MỘT nút gốc tương ứng. Mỗi nút trong chân modal
           bật/tắt độc lập theo đúng nút gốc của card đang xem — view "KHSX dự kiến" bên Đặt hàng
           nhà máy chỉ xem, không có nút nào, nên chân modal tự ẩn hoàn toàn. */
        if (footEl) {
            var coXuat = !!card.querySelector('.ltp-export-plan');
            var coThemSP = !!card.querySelector('.ltp-btn-add-product');
            var coThemVK = !!card.querySelector('.ltp-btn-add-task');
            if (exportBtn) {
                exportBtn.hidden = !coXuat;
                exportBtn.disabled = false;
                exportBtn.classList.remove('is-busy');
            }
            if (addProdBtn) addProdBtn.hidden = !coThemSP;
            if (addTaskBtn) addTaskBtn.hidden = !coThemVK;
            footEl.hidden = !(coXuat || coThemSP || coThemVK);
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeDay() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        /* TRẢ node mượn về đúng chỗ TRƯỚC khi dọn ruột modal — nếu không, innerHTML='' sẽ xoá
           luôn nội dung thật của card. Kiểm cha còn nằm trong tài liệu: nếu bảng đã vẽ lại
           trong lúc modal mở thì card cũ đã bị thay, trả về là tạo node mồ côi. */
        if (muonBody && muonChoCu && muonChoCu.cha && document.contains(muonChoCu.cha)) {
            muonChoCu.cha.insertBefore(muonBody, muonChoCu.ke);
        }
        muonBody = null;
        muonChoCu = null;
        bodyEl.innerHTML = '';
        openedCard = null;
        if (footEl) footEl.hidden = true;
    }

    /* "Xuất kế hoạch" — KHÔNG chép lại logic xuất, chỉ bấm hộ nút gốc .ltp-export-plan của
       card ngày đang xem. Nút gốc nằm trong .ltp-card-foot (bị display:none trên mobile) nhưng
       click() theo lệnh vẫn kích hoạt được, và handler bên long_term_production_plan.js đọc dữ
       liệu từ card THẬT nên không dính bản clone trong modal (clone giữ nguyên id/class, tự đọc
       lại là nhân đôi dòng). Xuất xong handler tự chuyển sang trang plan_for_staff. */
    /* "+ Sản phẩm" / "Việc khác" — bấm hộ nút gốc của card đang xem rồi ĐÓNG modal ngày.
       Phải đóng: 2 nút gốc mở tiếp modal chọn sản phẩm/việc khác, để chồng 2 lớp modal trên
       điện thoại thì lớp dưới che mất ô nhập và bấm ra ngoài là đóng nhầm lớp.
       Handler bên long_term_production_plan.js đọc activeCard từ closest('.ltp-card') của chính
       nút gốc, nên nó luôn trỏ đúng card THẬT — không dính bản clone trong modal này. */
    function bamHoNutGoc(selector) {
        return function () {
            if (!openedCard) return;
            var goc = openedCard.querySelector(selector);
            if (!goc) return;
            closeDay();
            setTimeout(function () { goc.click(); }, 0);
        };
    }
    if (addProdBtn) addProdBtn.addEventListener('click', bamHoNutGoc('.ltp-btn-add-product'));
    if (addTaskBtn) addTaskBtn.addEventListener('click', bamHoNutGoc('.ltp-btn-add-task'));

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
