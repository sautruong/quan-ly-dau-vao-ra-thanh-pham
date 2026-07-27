<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sổ vệ sinh nhà xưởng – thiết bị</title>
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
        <?php vt_render_header('sanitation_log', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'SỔ VỆ SINH NHÀ XƯỞNG – THIẾT BỊ', 'hasProduct' => false, 'hasDate' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: 'sanitation_log_data',
            title: 'SỔ VỆ SINH NHÀ XƯỞNG – THIẾT BỊ',
            hasProduct: false,
            hasDate: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'date_display', label: 'Ngày', width: '92px' },
                { kind: 'auto', key: 'areas', label: 'Khu vực/thiết bị' },
                { kind: 'auto', key: 'content', label: 'Nội dung vệ sinh' },
                { kind: 'input', key: 'chemicals', label: 'Hóa chất/dụng cụ sử dụng' },
                { kind: 'input', key: 'performer', label: 'Người thực hiện', copyDown: true, width: '120px' },
                { kind: 'input', key: 'result', label: 'Kết quả kiểm tra', default: 'Đạt', width: '100px' },
                { kind: 'input', key: 'inspector', label: 'Người kiểm tra', copyDown: true, width: '130px' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
