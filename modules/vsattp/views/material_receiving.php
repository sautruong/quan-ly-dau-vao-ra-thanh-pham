<?php
require __DIR__ . '/_tabs.php';
$today_ymd = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu tiếp nhận nguyên liệu đầu vào</title>
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
        <?php vt_render_header('material_receiving', 'BIỂU MẪU QL VSATTP'); ?>

        <div class="content">
            <!-- Thanh điều khiển: khoảng ngày + chọn NVL -->
            <div class="vt-controls">
                <div class="vt-daterange">
                    <div class="vt-field">
                        <label for="vt-from">Từ ngày</label>
                        <input type="date" id="vt-from" value="<?php echo $today_ymd; ?>">
                    </div>
                    <div class="vt-field">
                        <label for="vt-to">Đến ngày</label>
                        <input type="date" id="vt-to" value="<?php echo $today_ymd; ?>">
                    </div>
                </div>
                <button type="button" class="vt-btn vt-btn-primary" id="vt-open-modal">
                    <i class="fa-solid fa-list-check"></i> Chọn NVL hiển thị
                </button>
            </div>

            <!-- Hành động trên bảng -->
            <div class="vt-table-actions">
                <div class="vt-page-size">
                    <label for="vt-per-page">Số dòng/trang</label>
                    <select id="vt-per-page">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="0">Tất cả</option>
                    </select>
                </div>
                <div class="vt-actions-right">
                    <button type="button" class="vt-btn vt-btn-excel" id="vt-export-excel">
                        <i class="fa-solid fa-file-excel"></i> Xuất excel
                    </button>
                    <button type="button" class="vt-btn vt-btn-print" id="vt-print">
                        <i class="fa-solid fa-print"></i> In biểu mẫu
                    </button>
                </div>
            </div>

            <!-- Vùng in/xuất: tiêu đề + bảng + chữ ký -->
            <div class="vt-sheet" id="vt-sheet">
                <div class="vt-sheet-head">
                    <p class="vt-company">Công ty TNHH Vua An Toàn</p>
                    <h2 class="vt-form-title">PHIẾU TIẾP NHẬN NGUYÊN LIỆU ĐẦU VÀO</h2>
                    <p class="vt-period" id="vt-period"></p>
                </div>

                <table class="vt-table" id="vt-table">
                    <thead>
                        <tr>
                            <th style="width:42px">STT</th>
                            <th style="width:92px">Ngày nhập</th>
                            <th>Tên nguyên vật liệu/phụ gia</th>
                            <th>Nhà cung cấp</th>
                            <th style="width:92px">Số lô/NSX</th>
                            <th style="width:100px">Hạn sử dụng</th>
                            <th style="width:80px">Số lượng</th>
                            <th style="width:64px">ĐVT</th>
                            <th style="width:110px">Giấy tờ kèm theo</th>
                            <th style="width:110px">Kiểm tra cảm quan</th>
                            <th style="width:90px">Kết luận</th>
                            <th style="width:130px">Người kiểm tra</th>
                        </tr>
                    </thead>
                    <tbody id="vt-tbody">
                        <tr class="vt-empty-row">
                            <td colspan="12">Chưa có dữ liệu. Bấm “Chọn NVL hiển thị” rồi “Hiển thị dữ liệu”.</td>
                        </tr>
                    </tbody>
                </table>

                <div class="vt-pagination" id="vt-pagination"></div>

                <div class="vt-signatures">
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Người lập biểu</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Người kiểm tra</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Phụ trách cơ sở</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: chọn NVL hiển thị -->
    <div class="vt-modal" id="vt-modal" aria-hidden="true">
        <div class="vt-modal-overlay" data-close-modal></div>
        <div class="vt-modal-box">
            <div class="vt-modal-head">
                <h3>Chọn nguyên vật liệu hiển thị</h3>
                <button type="button" class="vt-modal-close" data-close-modal aria-label="Đóng">&times;</button>
            </div>
            <div class="vt-modal-body">
                <div class="vt-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="vt-search-material" placeholder="Tìm theo tên nguyên vật liệu..." autocomplete="off">
                    <ul class="vt-search-dropdown" id="vt-search-dropdown"></ul>
                </div>
                <p class="vt-selected-label">Danh sách NVL đã chọn:</p>
                <ul class="vt-selected-list" id="vt-selected-list">
                    <li class="vt-selected-empty">Chưa chọn NVL nào (để trống = lấy tất cả NVL).</li>
                </ul>
            </div>
            <div class="vt-modal-foot">
                <button type="button" class="vt-btn" data-close-modal>Đóng</button>
                <button type="button" class="vt-btn vt-btn-primary" id="vt-show-data">
                    <i class="fa-solid fa-table"></i> Hiển thị dữ liệu
                </button>
            </div>
        </div>
    </div>

    <script>
        window.VSATTP_CFG = { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };
    </script>
    <script src="public/js/vsattp/material_receiving.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
