<?php
/* Cụm người dùng (avatar + fullname/username) -> dropdown (avatar lớn, thông tin
 * căn bản, đổi ảnh, đăng xuất) + các modal tài khoản / đổi mật khẩu / rời hệ thống.
 * Cần $__hd_user, $__avatar, $__initial, $__fullname, $__username, $__email, $__role
 * (từ _bootstrap.php) và hàm app_header_avatar_html(). */

// Tách ngày sinh hiện có để đổ sẵn vào 3 select. dateofbirth dạng 'Y-m-d'.
$__dob       = trim((string) ($__hd_user['dateofbirth'] ?? ''));
$__dob_y = $__dob_m = $__dob_d = '';
if ($__dob !== '' && preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $__dob, $__mm)) {
    $__dob_y = (int) $__mm[1];
    $__dob_m = (int) $__mm[2];
    $__dob_d = (int) $__mm[3];
}
$__gender_cur = (string) ($__hd_user['gender'] ?? '');
$__phone_cur  = (string) ($__hd_user['phone'] ?? '');
?>
<div class="app-user" id="app-user">
    <button type="button" class="app-user-btn" id="app-user-btn">
        <?php echo app_header_avatar_html($__avatar, $__initial); ?>
        <span class="app-user-meta">
            <span class="app-user-name"><?php echo htmlspecialchars($__fullname, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="app-user-sub"><?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
        <i class="fa-solid fa-chevron-down app-user-caret"></i>
    </button>

    <div class="app-user-dropdown" id="app-user-dropdown">
        <div class="app-user-card">
            <label class="app-avatar-edit" for="app-avatar-input" title="Đổi ảnh đại diện">
                <?php echo app_header_avatar_html($__avatar, $__initial, 'app-avatar-lg'); ?>
                <span class="app-avatar-cam"><i class="fa-solid fa-camera"></i></span>
            </label>
            <input type="file" id="app-avatar-input" accept="image/*" hidden>
            <div class="app-user-card-info">
                <div class="app-user-card-name" id="app-user-card-name" data-username="<?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="app-user-name-text" id="app-user-name-text"><?php echo htmlspecialchars($__fullname, ENT_QUOTES, 'UTF-8'); ?></span>
                    <button type="button" class="app-user-name-edit" id="app-user-name-edit" title="Sửa tên hiển thị"><i class="fa-solid fa-pen"></i></button>
                </div>
                <div class="app-user-card-row"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($__email !== ''): ?>
                    <div class="app-user-card-row"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($__email, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div class="app-user-card-row"><i class="fa-solid fa-id-badge"></i> <?php echo $__role === 'admin' ? 'Quản trị viên' : 'Người dùng'; ?></div>
            </div>
        </div>
        <div class="app-user-actions">
            <button type="button" class="app-user-action" id="app-open-account">
                <i class="fa-solid fa-user-gear"></i> Cài đặt tài khoản
            </button>
            <button type="button" class="app-user-action" id="app-open-change-pw">
                <i class="fa-solid fa-key"></i> Thay đổi mật khẩu
            </button>
            <?php /* Thông báo đẩy xuống điện thoại — nhãn tự đổi theo trạng thái máy
                     đang dùng, xem khối "THÔNG BÁO ĐẨY" trong app_shell.js. Ẩn sẵn,
                     chỉ hiện khi trình duyệt có hỗ trợ. */ ?>
            <button type="button" class="app-user-action" id="app-push-toggle" style="display:none;">
                <i class="fa-solid fa-mobile-screen-button"></i> <span id="app-push-label">Thông báo trên điện thoại</span>
            </button>
            <button type="button" class="app-user-action danger" id="app-open-leave">
                <i class="fa-solid fa-user-xmark"></i> Xóa tài khoản
            </button>
            <a class="app-user-action danger" href="?mod=auth&controllers=index&action=logout">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Đăng xuất
            </a>
        </div>
    </div>
</div>

<!-- Modal "Cài đặt tài khoản": sửa hồ sơ (họ tên, ngày sinh, giới tính, phone, avatar) -->
<div class="app-modal" id="app-account-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-account-close></div>
    <div class="app-modal-box">
        <div class="app-modal-head">
            <h3>Cài đặt tài khoản</h3>
            <button type="button" class="app-modal-close" data-account-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <!-- Avatar: hover hiện 2 nút (xem phóng to / đổi ảnh) -->
            <div class="app-account-avatar">
                <div class="app-account-ava-wrap" id="app-account-ava-wrap">
                    <?php echo app_header_avatar_html($__avatar, $__initial, 'app-avatar-lg app-account-avatar-img'); ?>
                    <div class="app-account-ava-actions">
                        <button type="button" class="app-account-ava-btn" id="app-account-ava-view" title="Xem ảnh">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <button type="button" class="app-account-ava-btn" id="app-account-ava-cam" title="Đổi ảnh đại diện">
                            <i class="fa-solid fa-camera"></i>
                        </button>
                    </div>
                </div>
                <input type="file" id="app-account-avatar-input" accept="image/*" hidden>
            </div>

            <label class="app-pw-label">Họ và tên</label>
            <input type="text" class="app-pw-input" id="app-account-fullname"
                   value="<?php echo htmlspecialchars($__fullname, ENT_QUOTES, 'UTF-8'); ?>" maxlength="100">

            <label class="app-pw-label">Ngày sinh</label>
            <div class="app-account-dob">
                <select id="app-account-day">
                    <option value="" disabled <?php echo $__dob_d === '' ? 'selected' : ''; ?> hidden>Ngày</option>
                    <?php for ($i = 1; $i <= 31; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($__dob_d === $i ? 'selected' : ''); ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <select id="app-account-month">
                    <option value="" disabled <?php echo $__dob_m === '' ? 'selected' : ''; ?> hidden>Tháng</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($__dob_m === $i ? 'selected' : ''); ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <select id="app-account-year">
                    <option value="" disabled <?php echo $__dob_y === '' ? 'selected' : ''; ?> hidden>Năm</option>
                    <?php for ($i = (int) date('Y'); $i >= 1950; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($__dob_y === $i ? 'selected' : ''); ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <label class="app-pw-label">Giới tính</label>
            <select id="app-account-gender" class="app-pw-input">
                <option value="" disabled <?php echo !in_array($__gender_cur, ['M', 'F'], true) ? 'selected' : ''; ?> hidden>Chọn giới tính</option>
                <option value="M" <?php echo $__gender_cur === 'M' ? 'selected' : ''; ?>>Nam</option>
                <option value="F" <?php echo $__gender_cur === 'F' ? 'selected' : ''; ?>>Nữ</option>
            </select>

            <label class="app-pw-label">Số điện thoại</label>
            <input type="text" class="app-pw-input" id="app-account-phone"
                   value="<?php echo htmlspecialchars($__phone_cur, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Có thể bỏ trống">

            <!-- Thông tin khác: chỉ xem -->
            <div class="app-account-readonly">
                <div class="app-account-ro-title">Thông tin khác (chỉ xem)</div>
                <div class="app-account-ro-row"><i class="fa-solid fa-user"></i> <span>Tên đăng nhập:</span> <b><?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?></b></div>
                <div class="app-account-ro-row"><i class="fa-solid fa-envelope"></i> <span>Email:</span> <b><?php echo htmlspecialchars($__email, ENT_QUOTES, 'UTF-8'); ?></b></div>
            </div>

            <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-account-save">Lưu thay đổi</button>
            <p class="app-pw-msg" id="app-account-msg"></p>
        </div>
    </div>
</div>

<!-- Lightbox xem ảnh đại diện phóng to (mở từ nút "con mắt") -->
<div class="app-ava-lightbox" id="app-ava-lightbox" aria-hidden="true">
    <button type="button" class="app-ava-lightbox-close" id="app-ava-lightbox-close" aria-label="Đóng">&times;</button>
    <div class="app-ava-lightbox-inner" id="app-ava-lightbox-inner"></div>
</div>

<!-- Modal đổi mật khẩu (gửi mã 6 ký tự qua email -> nhập mã -> đặt mật khẩu mới) -->
<div class="app-modal" id="app-pw-modal" aria-hidden="true" data-identifier="<?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="app-modal-overlay" data-pw-close></div>
    <div class="app-modal-box">
        <div class="app-modal-head">
            <h3>Thay đổi mật khẩu</h3>
            <button type="button" class="app-modal-close" data-pw-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <!-- Bước 1: gửi & nhập mã -->
            <div class="app-pw-step" id="app-pw-step-1">
                <p class="app-pw-hint">Nhấn “Gửi mã” để nhận mã xác thực 6 ký tự qua email của bạn.</p>
                <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-pw-send">Gửi mã</button>

                <div class="app-pw-code-wrap" id="app-pw-code-wrap" style="display:none;">
                    <div class="app-pw-code" id="app-pw-code">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" inputmode="text" maxlength="1" class="app-pw-digit" autocomplete="off">
                        <?php endfor; ?>
                    </div>
                    <div class="app-pw-timer-row">
                        <span class="app-pw-timer" id="app-pw-timer"></span>
                        <button type="button" class="app-btn-link" id="app-pw-resend" disabled>Gửi lại mã</button>
                    </div>
                    <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-pw-verify">Xác nhận mã</button>
                </div>
                <p class="app-pw-msg" id="app-pw-msg-1"></p>
            </div>

            <!-- Bước 2: đặt mật khẩu mới -->
            <div class="app-pw-step" id="app-pw-step-2" style="display:none;">
                <label class="app-pw-label">Nhập mật khẩu mới</label>
                <input type="password" class="app-pw-input" id="app-pw-new" autocomplete="new-password">
                <label class="app-pw-label">Nhập lại mật khẩu</label>
                <input type="password" class="app-pw-input" id="app-pw-confirm" autocomplete="new-password">
                <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-pw-submit">Xác nhận</button>
                <p class="app-pw-msg" id="app-pw-msg-2"></p>
            </div>
        </div>
    </div>
</div>

<!-- Modal "Rời hệ thống" (cảnh báo -> gửi mã 6 ký tự -> xác nhận -> xóa dữ liệu cá nhân hóa) -->
<div class="app-modal" id="app-leave-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-leave-close></div>
    <div class="app-modal-box">
        <div class="app-modal-head">
            <h3>Xóa tài khoản</h3>
            <button type="button" class="app-modal-close" data-leave-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <!-- Bước 0: cảnh báo -->
            <div class="app-pw-step" id="app-leave-step-warn">
                <p class="app-pw-hint" style="color:#b22;">
                    Việc xóa tài khoản có nghĩa là bạn sẽ không còn làm việc trên hệ thống này nữa.
                    Các thông tin dữ liệu cá nhân và dữ liệu cá nhân hóa trong quá trình làm việc
                    (Todo, Task, Project…) sẽ tự động xóa khỏi hệ thống.
                    Tài khoản sẽ tự xóa hoàn toàn trong vòng 24 giờ.
                </p>
                <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-leave-continue"
                        style="background:#b22;">Tôi hiểu, tiếp tục xóa tài khoản</button>
            </div>

            <!-- Bước 1: gửi & nhập mã -->
            <div class="app-pw-step" id="app-leave-step-code" style="display:none;">
                <p class="app-pw-hint">Nhấn “Gửi mã” để nhận mã xác nhận 6 ký tự qua email của bạn.</p>
                <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-leave-send">Gửi mã</button>
                <div class="app-pw-code-wrap" id="app-leave-code-wrap" style="display:none;">
                    <div class="app-pw-code" id="app-leave-code">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" inputmode="text" maxlength="1" class="app-pw-digit app-leave-digit" autocomplete="off">
                        <?php endfor; ?>
                    </div>
                    <div class="app-pw-timer-row">
                        <span class="app-pw-timer" id="app-leave-timer"></span>
                        <button type="button" class="app-btn-link" id="app-leave-resend" disabled>Gửi lại mã</button>
                    </div>
                    <button type="button" class="app-btn app-btn-primary app-btn-block" id="app-leave-verify"
                            style="background:#b22;">Xác nhận xóa tài khoản</button>
                </div>
                <p class="app-pw-msg" id="app-leave-msg"></p>
            </div>
        </div>
    </div>
</div>

<!-- Modal "Bạn vừa bị buộc rời hệ thống" (hiện khi admin buộc rời, tự logout sau 3s) -->
<div class="app-modal" id="app-forced-leave-modal" aria-hidden="true">
    <div class="app-modal-overlay"></div>
    <div class="app-modal-box">
        <div class="app-modal-head" style="background:#b22;color:#fff;">
            <h3 style="color:#fff;">Thông báo</h3>
        </div>
        <div class="app-modal-body">
            <p class="app-pw-hint" style="color:#b22;font-weight:600;">
                <span id="app-forced-text">Bạn vừa bị buộc rời hệ thống.</span>
                Hệ thống sẽ tự đăng xuất sau <span id="app-forced-count">3</span> giây…
            </p>
        </div>
    </div>
</div>
