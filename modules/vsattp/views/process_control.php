<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu kiểm soát quá trình</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/vsattp/vsattp.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php vt_render_header('process_control', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'PHIẾU KIỂM SOÁT QUÁ TRÌNH SẢN XUẤT', 'hasProduct' => true, 'hasDate' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: 'process_control_data',
            title: 'PHIẾU KIỂM SOÁT QUÁ TRÌNH SẢN XUẤT',
            hasProduct: true,
            hasDate: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'date_display', label: 'Ngày', width: '92px' },
                { kind: 'auto', key: 'lot', label: 'Số lô', width: '140px' },
                { kind: 'auto', key: 'stage', label: 'Công đoạn', width: '120px' },
                { kind: 'auto', key: 'param', label: 'Thông số kiểm soát', width: '130px' },
                { kind: 'input', key: 'required', label: 'Giá trị yêu cầu' },
                { kind: 'input', key: 'actual', label: 'Giá trị thực tế' },
                { kind: 'input', key: 'result', label: 'Đạt/không đạt', default: 'Đạt', width: '100px' },
                { kind: 'input', key: 'corrective', label: 'Hành động khắc phục' },
                { kind: 'input', key: 'inspector', label: 'Người kiểm tra', copyDown: true, width: '130px' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
