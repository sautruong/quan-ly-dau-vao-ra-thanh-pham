<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sổ theo dõi sức khỏe & tập huấn nhân viên</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="public/css/vsattp/vsattp.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php vt_render_header('health_training_log', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'SỔ THEO DÕI SỨC KHỎE & TẬP HUẤN NHÂN VIÊN', 'hasProduct' => false, 'hasDate' => false, 'manual' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: null,
            title: 'SỔ THEO DÕI SỨC KHỎE & TẬP HUẤN NHÂN VIÊN',
            hasProduct: false,
            hasDate: false,
            manual: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'input', key: 'full_name', label: 'Họ và tên' },
                { kind: 'input', key: 'position', label: 'Vị trí công việc' },
                { kind: 'input', key: 'exam_date', label: 'Ngày khám sức khỏe gần nhất' },
                { kind: 'input', key: 'exam_result', label: 'Kết quả' },
                { kind: 'input', key: 'exam_expiry', label: 'Ngày hết hạn giấy khám' },
                { kind: 'input', key: 'training', label: 'Xác nhận tập huấn ATTP (ngày)' },
                { kind: 'input', key: 'daily', label: 'Theo dõi sức khỏe hằng ngày' },
                { kind: 'input', key: 'note', label: 'Ghi chú' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
