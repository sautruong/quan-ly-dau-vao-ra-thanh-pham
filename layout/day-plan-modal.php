<?php defined('APPPATH') OR exit('Không được quyền truy cập phần này'); ?>
<!-- MOBILE: modal xem nội dung kế hoạch của 1 ngày.
     Dùng chung cho mọi view vẽ lịch bằng bộ class .ltp-* (Kế hoạch sản xuất dài ngày,
     KHSX dự kiến ở Đặt hàng nhà máy...). Trên phone các card ngày bị rút gọn còn phần tiêu đề
     (xem @media 768px trong long_term_production_plan.css) nên phải có modal này mới xem được
     danh sách. Cặp với public/js/shared/day_plan_modal.js — cả 2 phải include cùng nhau. -->
<div class="app-modal" id="ltp-day-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-ltp-day-close></div>
    <div class="app-modal-box">
        <div class="app-modal-head">
            <h3 id="ltp-day-modal-title"></h3>
            <button type="button" class="app-modal-close" data-ltp-day-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body" id="ltp-day-modal-body"></div>
        <!-- Chân modal: chỉ hiện khi card ngày ĐANG XEM có nút "XUẤT KH" (.ltp-export-plan).
             Trên mobile .ltp-card > .ltp-card-foot bị display:none (xem @media 768px trong
             long_term_production_plan.css) nên nút gốc không bấm tới được — nút này là lối vào
             duy nhất. View nào không có nút gốc (KHSX dự kiến ở Đặt hàng nhà máy) thì chân modal
             tự ẩn, không cần khai báo gì thêm. -->
        <div class="app-modal-foot" id="ltp-day-modal-foot" hidden>
            <?php /* Thêm SP / Việc khác NGAY TRONG modal — trên mobile .ltp-card-actions bị
                     display:none nên 2 nút gốc không bấm tới được, không lập kế hoạch dài ngày
                     bằng điện thoại được. 2 nút này chỉ bấm hộ nút gốc của card đang xem. */ ?>
            <button type="button" class="ltp-btn ltp-day-add-product" hidden>+ Sản phẩm</button>
            <button type="button" class="ltp-btn ltp-day-add-task" hidden>Việc khác</button>
            <button type="button" class="ltp-btn ltp-day-export"
                    title="Ghi đè kế hoạch sản xuất trong ngày bằng danh sách của ngày này">
                <i class="fa-solid fa-paper-plane"></i> Xuất kế hoạch
            </button>
        </div>
    </div>
</div>
