<?php defined('APPPATH') OR exit('Không được quyền truy cập phần này'); ?>
<?php
$configs = isset($configs) && is_array($configs) ? $configs : [];
$users   = isset($users) && is_array($users) ? $users : [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi báo cáo tự động</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/report/auto_report.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <div class="content">
            <?php get_header('app'); ?>

            <div class="ar-wrap">
                <div class="ar-head">
                    <div>
                        <h1 class="ar-title"><i class="fa-solid fa-paper-plane"></i> Gửi báo cáo tự động</h1>
                        <p class="ar-sub">Tự động chụp báo cáo <b>BC sản xuất trong ngày</b> và gửi vào chat đúng khung giờ.
                            Người được uỷ quyền phải <b>online</b> tới giờ hẹn (trình duyệt sẽ tự chụp &amp; gửi).</p>
                    </div>
                    <button type="button" class="ar-btn ar-btn-primary" id="ar-add-btn">
                        <i class="fa-solid fa-plus"></i> Thêm lịch
                    </button>
                </div>

                <div class="ar-table-wrap">
                    <table class="ar-table">
                        <thead>
                            <tr>
                                <th>Lịch</th>
                                <th>Giờ gửi</th>
                                <th>Người uỷ quyền</th>
                                <th>Người nhận</th>
                                <th>Trạng thái</th>
                                <th class="ar-col-act">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="ar-tbody">
                            <tr class="ar-empty-row"><td colspan="6">Đang tải…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal thêm/sửa lịch -->
    <div class="ar-modal" id="ar-modal" hidden>
        <div class="ar-modal-backdrop" data-ar-close></div>
        <div class="ar-modal-box" role="dialog" aria-modal="true">
            <div class="ar-modal-head">
                <h2 id="ar-modal-title">Thêm lịch gửi</h2>
                <button type="button" class="ar-modal-x" data-ar-close aria-label="Đóng">&times;</button>
            </div>
            <div class="ar-modal-body">
                <input type="hidden" id="ar-f-id">

                <label class="ar-field">
                    <span>Tên lịch</span>
                    <input type="text" id="ar-f-title" placeholder="VD: Báo cáo cuối ca chiều" maxlength="200">
                </label>

                <div class="ar-field-row">
                    <label class="ar-field">
                        <span>Giờ gửi</span>
                        <input type="time" id="ar-f-time" value="17:00">
                    </label>
                    <label class="ar-field">
                        <span>Cửa sổ chờ (phút)</span>
                        <input type="number" id="ar-f-window" value="30" min="10" max="720" step="5">
                        <small>Trong khoảng này nếu người uỷ quyền online sẽ gửi; quá thì báo admin là "lỡ".</small>
                    </label>
                </div>

                <label class="ar-field">
                    <span>Tài khoản được uỷ quyền gửi</span>
                    <select id="ar-f-delegate">
                        <option value="">— Chọn tài khoản —</option>
                    </select>
                    <small>Người này sẽ nhận chuông xác nhận. Chỉ khi họ bấm "Nhận" lịch mới hoạt động.</small>
                </label>

                <div class="ar-field">
                    <span>Người nhận báo cáo</span>
                    <div class="ar-radio-row">
                        <label class="ar-radio"><input type="radio" name="ar-rtype" value="users" checked> Chọn nhiều người</label>
                        <label class="ar-radio"><input type="radio" name="ar-rtype" value="group"> Một nhóm chat</label>
                    </div>
                </div>

                <label class="ar-field" id="ar-users-wrap">
                    <span>Người nhận (giữ Ctrl để chọn nhiều)</span>
                    <select id="ar-f-users" multiple size="6"></select>
                </label>

                <label class="ar-field" id="ar-group-wrap" hidden>
                    <span>Nhóm nhận</span>
                    <select id="ar-f-group">
                        <option value="">— Chọn nhóm (theo người uỷ quyền) —</option>
                    </select>
                    <small>Chỉ liệt kê các nhóm mà người được uỷ quyền là thành viên.</small>
                </label>

                <label class="ar-field">
                    <span>Lời nhắn kèm ảnh (tuỳ chọn)</span>
                    <input type="text" id="ar-f-caption" placeholder="Để trống sẽ tự tạo: 📊 &lt;tên lịch&gt; — ngày dd/mm/yyyy" maxlength="480">
                </label>

                <div class="ar-modal-err" id="ar-modal-err" hidden></div>
            </div>
            <div class="ar-modal-foot">
                <button type="button" class="ar-btn" data-ar-close>Huỷ</button>
                <button type="button" class="ar-btn ar-btn-primary" id="ar-save-btn">Lưu</button>
            </div>
        </div>
    </div>

    <script>
        window.AR = {
            base: '?mod=report&controllers=report&action=',
            users: <?php echo json_encode($users, JSON_UNESCAPED_UNICODE); ?>,
            configs: <?php echo json_encode($configs, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/report/auto_report_admin.js'); ?>"></script>
</body>

</html>
