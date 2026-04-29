<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý tồn kho sản phẩm</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/inventory_management/sales_return_receipt.css">

</head>

<body>
    <div id="wrapper">
        <div class="header">
            <div class="wp-logo-title">
                <div class="logo">
                    <a href="">
                        <img src="public/images/logo/logo_vat_png.png" alt="" style="width:40px">
                    </a>
                </div>
                <div class="title">
                    <h1>QUẢN LÝ TỒN KHO SẢN PHẨM</h1>
                </div>
                <div></div>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=dashboard">Nhập thành phẩm sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=investment_products">Nhập giá vốn sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=product_buy">Nhập thành phẩm mua hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=other_receipt">Nhập kho khác</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=sales_return_receipt">Nhập hàng bán trả lại</a>
                    </li>

                    <p style="margin-right: 20px;">|</p>

                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=sales_issue">Xuất kho bán hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="">Xuất kho khác</a>
                    </li>

                    <p style="margin-right: 20px;">|</p>

                    <li class="tab-item">
                        <a href="" style="color: red;">Báo cáo tồn</a>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="content">
            <div class="wp-customer">
                <label for="customer">Tên khách hàng:</label>
                <input type="text" id="customer" autocomplete="off" placeholder="Tìm theo tên khách hàng...">
                <ul class="customer-dropdown" id="customer-dropdown"></ul>
            </div>
            <div class="wp-custom-acronym">
                <label for="custom-acronym">Tên gọi viết tắt:</label>
                <input type="text" id="custom-acronym" readonly placeholder="(tự động hiển thị)">
            </div>
            <div class="wp-date-picker">
                <label for="record-datetime">Ngày giờ ghi:</label>
                <input type="datetime-local" id="record-datetime" step="1">
            </div>

            <div class="wp-sales-return">
                <div class="wp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="search-product" name="search-product" placeholder="Tìm kiếm sản phẩm..." autocomplete="off">
                    <ul class="search-dropdown" id="search-dropdown"></ul>
                </div>

                <ul class="list-product" id="list-product"></ul>

                <div class="wp-button">
                    <div class="btn-record" id="btn-record">
                        <p>Ghi</p>
                    </div>
                    <div class="btn-record" id="btn-edit-confirm" style="display:none;">
                        <p>Xác nhận sửa</p>
                    </div>
                    <div class="btn-record" id="btn-handle-confirm" style="display:none;">
                        <p>Xác nhận xử lý</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="line"></div>
        <div class="history">
            <p>Lịch sử</p>
            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang <strong id="edit-mode-label">sửa</strong> nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>
        </div>
        <table class="history-table" id="history-table">
            <thead>
                <tr>
                    <td>Ngày</td>
                    <td class="col-status">Trạng thái</td>
                    <td>Diễn giải</td>
                    <td>Thao tác</td>
                </tr>
            </thead>
            <tbody id="history-tbody">
            </tbody>
        </table>
        <div class="history-pagination" id="history-pagination"></div>


    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_management&controllers=inventory_management&action='
        };
        window.INVENTORY_DATA = {
            planDate: <?php echo json_encode($plan_date ?? date('d/m/Y')); ?>,
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            typeImport: <?php echo json_encode($type_import ?? 'sales_return_receipt'); ?>
        };
    </script>
    <script src="public/js/inventory_management/sales_return_receipt.js"></script>
</body>

</html>