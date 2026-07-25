<?php
/* Lịch cá nhân (cạnh trái todo): mini lịch tháng, click ngày để xem/thêm sự kiện + lời nhắc.
 * Cần $__hd_cal_today (từ _bootstrap.php). */
?>
<div class="app-cal" id="app-cal">
    <button type="button" class="app-cal-btn" id="app-cal-btn" aria-label="Lịch">
        <i class="fa-solid fa-calendar-days"></i>
        <span class="app-cal-badge" id="app-cal-badge" <?php echo $__hd_cal_today > 0 ? '' : 'style="display:none;"'; ?>>
            <?php echo $__hd_cal_today > 99 ? '99+' : (int) $__hd_cal_today; ?>
        </span>
    </button>
    <div class="app-cal-dropdown" id="app-cal-dropdown">
        <div class="app-cal-head">
            <button type="button" class="app-cal-nav" id="app-cal-prev" title="Tháng trước"><i class="fa-solid fa-chevron-left"></i></button>
            <span class="app-cal-title" id="app-cal-title"></span>
            <button type="button" class="app-cal-nav" id="app-cal-next" title="Tháng sau"><i class="fa-solid fa-chevron-right"></i></button>
            <span class="app-cal-head-tools">
                <button type="button" class="app-cal-tool" id="app-cal-today-btn" title="Về hôm nay"><i class="fa-solid fa-calendar-day"></i></button>
                <button type="button" class="app-cal-tool" id="app-cal-zoom-btn" title="Xem trang lịch đầy đủ"><i class="fa-solid fa-expand"></i></button>
            </span>
        </div>
        <div class="app-cal-weekdays">
            <span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span><span class="sun">CN</span>
        </div>
        <div class="app-cal-grid" id="app-cal-grid"></div>

        <div class="app-cal-day-panel" id="app-cal-day-panel">
            <div class="app-cal-day-title" id="app-cal-day-title"></div>
            <div class="app-cal-day-events" id="app-cal-day-events"></div>
            <div class="app-cal-add">
                <input type="text" class="app-cal-input" id="app-cal-input"
                       placeholder="Thêm sự kiện hoặc lời nhắc cho ngày này…" maxlength="500" autocomplete="off">
                <div class="app-cal-add-time-row" id="app-cal-add-time-row" style="display:none;">
                    <i class="fa-regular fa-clock"></i>
                    <input type="time" class="app-cal-time-input" id="app-cal-time-input" title="Giờ nhắc (để trống = sự kiện cả ngày)">
                    <button type="button" class="app-cal-save-btn" id="app-cal-save-btn">Lưu</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal đổi giờ nhắc của 1 sự kiện (click vào chip giờ trong ngày) -->
<div class="app-modal" id="app-cal-time-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-cal-time-close></div>
    <div class="app-modal-box app-cal-time-box">
        <div class="app-modal-head">
            <h3>Đổi giờ nhắc</h3>
            <button type="button" class="app-modal-close" data-cal-time-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <label class="app-pw-label">Giờ nhắc</label>
            <input type="time" class="app-pw-input" id="app-cal-time-modal-input">
            <label class="app-cal-time-modal-clear">
                <input type="checkbox" id="app-cal-time-modal-noclock"> Không nhắc giờ (sự kiện cả ngày)
            </label>
            <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-cal-time-modal-save">Lưu</button>
            <p class="app-pw-msg" id="app-cal-time-modal-msg"></p>
        </div>
    </div>
</div>
