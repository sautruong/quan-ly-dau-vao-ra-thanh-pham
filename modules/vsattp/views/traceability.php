<?php require __DIR__ . '/_tabs.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ truy xuất nguồn gốc lô sản phẩm</title>
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
        <?php vt_render_header('traceability', 'BIỂU MẪU QL VSATTP'); ?>
        <?php vt_render_form_page(['form_title' => 'HỒ SƠ TRUY XUẤT NGUỒN GỐC LÔ SẢN PHẨM', 'hasProduct' => true, 'hasDate' => true]); ?>
    </div>

    <script>window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };</script>
    <script src="public/js/vsattp/vsattp_table.js"></script>
    <script>
        VsattpTable({
            action: 'traceability_data',
            title: 'HỒ SƠ TRUY XUẤT NGUỒN GỐC LÔ SẢN PHẨM',
            hasProduct: true,
            hasDate: true,
            columns: [
                { kind: 'stt',  label: 'STT', width: '42px' },
                { kind: 'auto', key: 'product_name', label: 'Tên sản phẩm' },
                { kind: 'auto', key: 'lot', label: 'Số lô sản xuất', width: '140px' },
                { kind: 'auto', key: 'date_display', label: 'Ngày sản xuất', width: '92px' },
                { kind: 'auto', key: 'expiry', label: 'Hạn sử dụng', width: '100px' },
                { kind: 'input', key: 'code', label: 'Mã nhận diện sản phẩm', width: '120px' },
                { kind: 'auto', key: 'quantity', label: 'Số lượng đã sản xuất', numeric: true, width: '90px' },
                { kind: 'auto', key: 'materials', label: 'Nguyên liệu/phụ gia' },
                { kind: 'auto', key: 'issue_inventory', label: 'Số lượng Xuất/tồn còn', width: '140px' },
                { kind: 'auto', key: 'customer_name', label: 'Khách hàng' }
            ]
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
