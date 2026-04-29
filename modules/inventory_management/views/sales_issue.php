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
    <link rel="stylesheet" href="public/css/inventory_management/sales_issue.css">

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
                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=sales_return_receipt">Nhập hàng bán trả lại</a>
                    </li>
                    <p style="margin-right: 20px;">|</p>

                    <li class="tab-item active">
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
            <div class="wp-custom-imfo">
                <div class="wp-customer">
                    <label for="customer">Tên khách hàng:</label>
                    <input type="text" id="customer" autocomplete="off" placeholder="Tìm theo tên khách hàng...">
                    <ul class="customer-dropdown" id="customer-dropdown"></ul>
                </div>
                <div class="wp-address">
                    <label for="address">Địa chỉ:</label>
                    <input type="text" id="address" readonly placeholder="(tự động hiển thị)">
                </div>
                <div class="wp-receiver">
                    <label for="receiver">Người nhận:</label>
                    <input type="text" id="receiver" readonly placeholder="(tự động hiển thị)">
                </div>
            </div>
            <div class="list-sale-product">
                <table>
                    <thead>
                        <tr>
                            <td>Tên sản phẩm</td>
                            <td>Số lượng</td>
                            <td>Đơn vị</td>
                            <td>Kho</td>
                            <td>Khối lượng</td>
                            <td>Tổng khối lượng</td>
                            <td>Đơn giá</td>
                            <td>Thành tiền</td>
                            <td></td>
                        </tr>
                    </thead>
                    <tbody id="sale-tbody">
                    </tbody>
                </table>
                <div class="wp-total">
                    <div class="wp-weight">
                        <div class="label">Tổng khối lượng:</div>
                        <div class="result">500 kg</div>
                    </div>
                    <div class="wp-value">
                        <div class="label">Giá trị hàng hóa:</div>
                        <div class="result">197,000,000 đ</div>
                    </div>
                </div>
            </div>
            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit">
                    <p>Sửa</p>
                </div>
            </div>
            <div class="line"></div>
            <div class="history">
                <p>Lịch sử</p>
                <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                    <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                    <a href="#" id="cancel-edit-batch">Hủy</a>
                </div>
            </div>
            <table class="history-table" id="history-table">
                <thead>
                    <tr>
                        <td>Ngày</td>
                        <td>Diễn giải</td>
                        <td>Thao tác</td>
                    </tr>
                </thead>

                <tbody id="history-tbody">
                </tbody>
            </table>
            <div class="history-pagination" id="history-pagination"></div>
        </div>
    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_management&controllers=inventory_management&action='
        };
        window.INVENTORY_DATA = {
            planDate: <?php echo json_encode($plan_date ?? date('d/m/Y')); ?>,
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            typeExport: <?php echo json_encode($type_export ?? 'sales_issue'); ?>
        };
    </script>
    <script src="public/js/inventory_management/sales_issue.js"></script>
</body>

</html>