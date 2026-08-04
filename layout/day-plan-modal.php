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
    </div>
</div>
