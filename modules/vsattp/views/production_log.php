<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sổ sản xuất theo lô/mẻ</title>
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
        <?php vt_render_header('production_log', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'SỔ SẢN XUẤT THEO LÔ/MẺ', 'hasProduct' => true, 'hasDate' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: 'production_log_data',
            title: 'SỔ SẢN XUẤT THEO LÔ/MẺ',
            hasProduct: true,
            hasDate: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'date_display', label: 'Ngày sản xuất', width: '92px' },
                { kind: 'auto', key: 'product_name', label: 'Tên sản phẩm' },
                { kind: 'auto', key: 'lot', label: 'Số lô sản xuất', width: '140px' },
                { kind: 'auto', key: 'formula', label: 'Công thức, định lượng' },
                { kind: 'auto', key: 'materials', label: 'Nguyên liệu sử dụng' },
                { kind: 'auto', key: 'quantity', label: 'Số lượng thành phẩm', numeric: true, width: '90px' },
                { kind: 'auto', key: 'unit', label: 'ĐVT', width: '60px' },
                { kind: 'input', key: 'performer', label: 'Người thực hiện', copyDown: true, width: '120px' },
                { kind: 'input', key: 'supervisor', label: 'Người giám sát', copyDown: true, width: '120px' },
                { kind: 'input', key: 'note', label: 'Ghi chú', width: '120px' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
