<?php
/* "Cài đặt" hình nền trang chủ: chọn tối đa HOME_BG_MAX_SLIDES hình từ thư viện
 * dùng chung (admin quản lý) hoặc tải ảnh cá nhân, trình chiếu luân phiên làm
 * nền trang chủ. Xem libraries/home_layout.php + public/js/home/header_home.js. */
?>
<div class="home-bgset" id="home-bgset">
    <button type="button" class="home-icon-btn" id="home-bgset-btn" aria-label="Cài đặt hình nền" title="Cài đặt hình nền">
        <i class="fa-solid fa-gear"></i>
    </button>
</div>

<div class="app-modal" id="home-bgset-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-bgset-close></div>
    <div class="app-modal-box home-bgset-box">
        <div class="app-modal-head">
            <h3><i class="fa-solid fa-image"></i> Cài đặt hình nền trang chủ</h3>
            <button type="button" class="app-modal-close" data-bgset-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <p class="app-set-hint">
                Chọn tối đa <?php echo (int) HOME_BG_MAX_SLIDES; ?> hình để trình chiếu luân phiên
                làm nền trang chủ. Chỉ nhận ảnh nằm ngang (chữ nhật ngang) để tránh vỡ hình.
            </p>

            <div class="home-bgset-block">
                <div class="home-bgset-block-title">Hình nền của tôi</div>
                <div class="home-bgset-slides" id="home-bgset-slides"></div>
            </div>

            <div class="home-bgset-block">
                <div class="home-bgset-block-title">
                    <span>Thư viện dùng chung</span>
                    <label class="home-bgset-lib-add" id="home-bgset-lib-add-wrap" style="display:none;">
                        <i class="fa-solid fa-plus"></i> Thêm vào thư viện
                        <input type="file" id="home-bgset-lib-add-input" accept="image/*" hidden>
                    </label>
                </div>
                <div class="home-bgset-library" id="home-bgset-library"><div class="home-bgset-empty">Đang tải…</div></div>
            </div>

            <div class="home-bgset-block">
                <label class="home-bgset-upload" for="home-bgset-upload-input">
                    <i class="fa-solid fa-upload"></i> Tải ảnh từ máy tính
                </label>
                <input type="file" id="home-bgset-upload-input" accept="image/*" hidden>
            </div>

            <div class="app-set-msg" id="home-bgset-msg"></div>
        </div>
    </div>
</div>
