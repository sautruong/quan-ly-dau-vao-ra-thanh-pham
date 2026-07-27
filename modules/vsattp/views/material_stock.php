<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tồn kho nguyên liệu</title>
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
        <?php vt_render_header('material_stock', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'TỒN KHO NGUYÊN LIỆU']); ?>
        <p class="vt-hint" style="padding:0 16px;color:#64748b;font-size:13px;">
            Tồn kho nguyên liệu là các nguyên liệu dùng cho sản phẩm đang chọn ở "Sổ sản xuất theo lô/mẻ".
        </p>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: 'material_stock_data',
            title: 'TỒN KHO NGUYÊN LIỆU',
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'material_name', label: 'Tên nguyên liệu', cls: 'vt-cell-name' },
                { kind: 'auto', key: 'unit', label: 'Đơn vị', width: '100px' },
                { kind: 'auto', key: 'quantity', label: 'Tồn kho hiện tại', numeric: true, width: '140px' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
