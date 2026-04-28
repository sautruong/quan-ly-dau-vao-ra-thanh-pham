<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kế hoạch sản xuất</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/production_staff/production_plan.css">
</head>

<body>
    <div id="wrapper">
        <div class="wp-header-content">
            <div class="header">
                <div class="logo">
                    <a href="?mod=home">
                        <img src="public/images/logo/logo_vat_png.png" alt="">
                    </a>
                    <div class="date">
                        <p><?php echo date('d') . ' tháng ' . date('n') . ' ' . date('Y'); ?></p>
                    </div>
                </div>
                <div class="wp-title">
                    <div class="title">
                        <h1>LẬP KẾ HOẠCH SẢN XUẤT</h1>
                    </div>

                </div>

                <div class="header-right">
                    <div class="choose-display">
                        <select name="display-col" id="display-col">
                            <option value="2">2 cột</option>
                            <option value="4" selected>4 cột</option>
                        </select>
                    </div>
                    <button type="button" class="btn-reset" id="btn-reset">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>
                </div>

            </div>

            <div class="content">
                <div class="wp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="search-product" name="search-product" placeholder="Tìm kiếm sản phẩm..." autocomplete="off">
                    <ul class="search-dropdown" id="search-dropdown"></ul>
                </div>
                <ul class="list-product" id="list-product"></ul>

                <div class="wp-other-work">
                    <div class="add-other-work" id="btn-add-other-work">
                        <p>+ Việc khác</p>
                    </div>
                    <div class="other-work-form" id="other-work-form" style="display:none;">
                        <input type="text" id="other-work-input" placeholder="Nhập nội dung công việc...">
                        <button type="button" class="btn-add-task" id="btn-add-task">Thêm</button>
                        <button type="button" class="btn-cancel-task" id="btn-cancel-task">Hủy</button>
                    </div>
                    <ul class="list-other-work" id="list-other-work"></ul>
                </div>
            </div>

        </div>
        <div class="footer">
            <button type="button" class="btn-sent-pan" id="btn-sent-pan">
                <p>GỬI KHSX CHO NVSX</p>
            </button>
        </div>
    </div>

    <script>
        window.PRODUCTION_PLAN_CONFIG = {
            baseUrl: '?mod=production_staff&controllers=production_staff&action='
        };
        window.PRODUCTION_PLAN_DATA = {
            plans: <?php echo json_encode($plans ?? [], JSON_UNESCAPED_UNICODE); ?>,
            tasks: <?php echo json_encode(array_map(function ($t) {
                        return $t['description'];
                    }, $tasks ?? []), JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="public/js/production_staff/production_plan.js"></script>
</body>

</html>