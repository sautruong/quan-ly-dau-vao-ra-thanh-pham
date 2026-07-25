<?php defined('APPPATH') OR exit('Không được quyền truy cập phần này'); ?>
<?php
$configs = isset($configs) && is_array($configs) ? $configs : [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uỷ quyền gửi báo cáo của tôi</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
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
                        <h1 class="ar-title"><i class="fa-solid fa-user-check"></i> Uỷ quyền gửi báo cáo của tôi</h1>
                        <p class="ar-sub">Các lịch bạn được uỷ quyền gửi báo cáo tự động. Để lịch chạy, bạn cần bấm
                            <b>Nhận</b> và giữ trình duyệt <b>online</b> tới giờ hẹn — hệ thống sẽ báo trước 5 giây rồi tự chụp &amp; gửi.</p>
                    </div>
                </div>

                <div class="ar-mine-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Bạn có thể <b>tạm ngưng</b> phần việc của mình bất kỳ lúc nào; khi tạm ngưng, quản trị viên sẽ nhận được thông báo.
                </div>

                <div class="ar-table-wrap">
                    <table class="ar-table">
                        <thead>
                            <tr>
                                <th>Lịch</th>
                                <th>Giờ gửi</th>
                                <th>Người nhận</th>
                                <th>Trạng thái</th>
                                <th class="ar-col-act">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="arm-tbody">
                            <tr class="ar-empty-row"><td colspan="5">Đang tải…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.ARM = {
            base: '?mod=report&controllers=report&action=',
            configs: <?php echo json_encode($configs, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/report/auto_report_mine.js'); ?>"></script>
</body>

</html>
