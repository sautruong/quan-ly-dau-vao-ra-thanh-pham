<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sổ nhập – xuất kho thành phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/vsattp/vsattp.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php vt_render_header('finished_goods_ledger', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'SỔ NHẬP – XUẤT KHO THÀNH PHẨM', 'hasProduct' => true, 'hasDate' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="<?php echo asset_ver('public/js/vsattp/vsattp_table.js'); ?>"></script>
    <script>
        VsattpTable({
            action: 'finished_goods_ledger_data',
            title: 'SỔ NHẬP – XUẤT KHO THÀNH PHẨM',
            hasProduct: true,
            hasDate: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'date_display', label: 'Ngày', width: '92px' },
                { kind: 'auto', key: 'loai', label: 'Loại (nhập/xuất)', width: '90px' },
                { kind: 'auto', key: 'product_name', label: 'Tên sản phẩm' },
                { kind: 'auto', key: 'lot', label: 'Số lô', width: '140px' },
                { kind: 'auto', key: 'expiry', label: 'Hạn sử dụng', width: '100px' },
                { kind: 'auto', key: 'quantity', label: 'Số lượng xuất', numeric: true, width: '90px' },
                { kind: 'auto', key: 'ton', label: 'Tồn kho', numeric: true, width: '90px' },
                { kind: 'auto', key: 'customer_name', label: 'Khách hàng' },
                { kind: 'input', key: 'performer', label: 'Người thực hiện', copyDown: true, width: '120px' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
