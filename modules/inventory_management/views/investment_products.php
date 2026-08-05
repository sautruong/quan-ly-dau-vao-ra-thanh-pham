<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập kho</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/investment_products.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/accounting/journal_entry.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/datetime_picker.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/history_filter.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="header" style="display:none" aria-hidden="true">
            <input type="checkbox" id="menu-toggle" class="menu-toggle">
            <div class="wp-logo-title">
                <div class="logo">
                    <a href="?mod=home">
                        <img src="public/images/logo/logo_vat_png.png" alt="" style="width:40px">
                    </a>
                </div>
                <div class="title">
                    <h1>NHẬP KHO</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'dashboard'); ?>">Nhập thành phẩm sản xuất</a>
                    </li>
                    <li class="tab-item active">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'investment_products'); ?>">Nhập giá vốn sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'other_receipt'); ?>">Nhập kho khác</a>
                    </li>
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'sales_return_receipt'); ?>">Nhập hàng bán trả lại</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=row_material_receiving">Nhập mua hàng hóa</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=other_row_material_receiving">Nhập NVL (khác)</a>
                    </li>
                    <!-- <p style="margin-right: 20px;">|</p>

                    <li class="tab-item">
                        <a href="?mod=inventory_management&controllers=inventory_management&action=sales_issue">Xuất kho bán hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="">Xuất kho khác</a>
                    </li>
                    <p style="margin-right: 20px;">|</p>

                    <li class="tab-item">
                        <a href="" style="color: red;">Báo cáo tồn</a>
                    </li> -->
                </ul>
            </nav>
            <?php if (!permission_is_view_only('inventory_management', 'inventory_management', 'investment_products')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="finished_product_production_data,raw_material_production_issue_data,production_materials,production_costs_daily,material_inventory,finished_goods_inventory,product_materials">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="content">
            <div class="wp-date-picker">
                <label for="record-datetime">Ngày giờ ghi</label>
                <input type="datetime-local" id="record-datetime" class="js-green-datetime js-green-datetime-highlight" step="1">
            </div>

            <?php render_journal_entry_block('investment_products', 'je-card', true, 'all', 'auto', true); ?>

            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <ul class="list-product" id="list-product"></ul>

            <div class="total-wrap">
                <div class="total-list" id="total-list">
                    <p>Tổng vốn sản xuất:</p>
                    <p class="total-list-value-cost">0 đ</p>
                </div>
                <div class="total-list-value" id="total-list-value">
                    <p>Giá trị hàng hóa:</p>
                    <p class="total-list-value-amount">0 đ</p>
                </div>
            </div>

            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit" style="display:none;">
                    <p>Sửa</p>
                </div>
            </div>
            <div class="line"></div>
            <div class="history-bar">
                <?php
                /*
                  BỌC [Cài đặt][Lịch sử] vào 1 wrapper: .history-bar dùng justify-content:
                  space-between, thả nút thành con thứ 3 là 3 phần tử bị dàn đều và "Lịch sử"
                  văng ra giữa màn hình.
                  GIỮ NGUYÊN class .history (không đổi tên, không bỏ): app_shell.js nhắm
                  '.history-bar .history' cho hiệu ứng bay-vào-lịch sử — bọc thêm div cha thì
                  vẫn khớp vì đó là descendant selector.
                */
                ?>
                <div class="history-left">
                    <?php if (permission_is_admin()): ?>
                    <button type="button" class="iac-gear" id="btn-iac-setting"
                            title="Cài đặt: ghi giá vốn tự động theo lúc ghi sản lượng">
                        <i class="fa-solid fa-gear"></i> Cài đặt
                    </button>
                    <?php endif; ?>
                    <div class="history">
                        <p>Lịch sử</p>
                    </div>
                </div>
                <div class="history-filter" id="history-filter">
                <div class="hf-group hf-daterange">
                    <span class="hf-cal-icon"><i class="fa-regular fa-calendar-days"></i></span>
                    <label for="hf-date-from">Từ ngày</label>
                    <input type="date" id="hf-date-from" class="hf-date">
                    <span class="hf-arrow"><i class="fa-solid fa-arrow-right-long"></i></span>
                    <label for="hf-date-to">đến ngày</label>
                    <input type="date" id="hf-date-to" class="hf-date">
                </div>
                <div class="hf-group hf-rows">
                    <label for="hf-page-size">Số dòng</label>
                    <select id="hf-page-size" class="hf-select">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <span class="hf-count" id="hf-count"></span>
                <button type="button" class="hf-reset" id="hf-reset" title="Bỏ tất cả bộ lọc">
                    <i class="fa-solid fa-rotate-left"></i> Bỏ lọc
                </button>
                </div>
            </div>
            <table class="history-table" id="history-table">
                <thead>
                    <tr>
                        <td>Ngày</td>
                        <td>
                            <div class="th-filterable">
                                <span>Diễn giải</span>
                                <button type="button" class="th-filter-btn" id="hf-keyword-btn" title="Lọc theo sản phẩm đã nhập">
                                    <i class="fa-solid fa-filter"></i>
                                </button>
                                <div class="th-filter-pop" id="hf-keyword-pop">
                                    <input type="text" id="hf-keyword" placeholder="Lọc sản phẩm đã nhập..." autocomplete="off">
                                </div>
                            </div>
                        </td>
                        <td>Thao tác</td>
                    </tr>
                </thead>
                <tbody id="history-tbody"></tbody>
            </table>
            <div class="history-pagination" id="history-pagination"></div>
        </div>



    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_management&controllers=inventory_management&action='
        };
        window.INVENTORY_DATA = {
            items: <?php echo json_encode($items ?? [], JSON_UNESCAPED_UNICODE); ?>,
            planDate: <?php echo json_encode($plan_date ?? date('d/m/Y')); ?>,
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            typeImport: <?php echo json_encode($type_import ?? 'investment_production'); ?>,
            productionCostRate: <?php echo json_encode((float) ($production_cost_rate ?? 6000)); ?>
        };
    </script>
    <?php if (permission_is_admin()): ?>
    <?php /*
      MODAL "Cài đặt" — ghi giá vốn TỰ ĐỘNG theo lúc ghi sản lượng (YC3, 5/8/2026).
      Bật lên thì nút "Ghi" của trang này thành phương án dự phòng: giá vốn đã được dựng sẵn
      mỗi khi bên "Nhập sản lượng sản xuất" ghi/sửa/xoá. Người dùng vẫn vào đây xem lại và sửa.
    */ ?>
    <div class="iac-mask" id="iac-mask" style="display:none;">
        <div class="iac-box">
            <div class="iac-head">
                <i class="fa-solid fa-gear" style="color:#096926"></i>
                <span>Ghi giá vốn tự động</span>
                <span class="iac-x" id="iac-close">&times;</span>
            </div>
            <div class="iac-body">
                <p class="iac-note">
                    Khi bật, mỗi lần bên <strong>Nhập sản lượng sản xuất</strong> bấm Ghi (hoặc sửa,
                    xoá), hệ thống tự dựng lại giá vốn của đúng ngày đó — thay cho việc phải sang
                    đây bấm Ghi bằng tay. Số tiền tính đúng theo <strong>giá vốn 2 lớp</strong> và
                    luật <strong>lần sản xuất tương đồng ±3%</strong>, tức bằng đúng con số trang này
                    hiển thị khi mới mở. Bạn vẫn vào đây xem lại và sửa được như thường.
                </p>

                <label class="iac-row"><input type="checkbox" id="iac-active"> <strong>Bật ghi giá vốn tự động</strong></label>
                <label class="iac-row">
                    <input type="checkbox" id="iac-warn" checked>
                    <span>Đẩy chuông khi có sản phẩm chưa khai công thức (vẫn ghi giá vốn)</span>
                </label>

                <div class="iac-nobom" id="iac-nobom"></div>
            </div>
            <div class="iac-foot">
                <span class="iac-msg" id="iac-msg"></span>
                <button type="button" class="iac-save" id="iac-save">Lưu</button>
            </div>
        </div>
    </div>

    <style>
        .history-left { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }
        .iac-gear {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 6px; border: 1px solid #cfd6dd;
            background: #fff; color: #33475b; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .iac-gear:hover { border-color: #096926; color: #096926; }

        .iac-mask {
            position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9998;
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .iac-box {
            background: #fff; border-radius: 10px; width: min(560px, 100%);
            max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
            box-shadow: 0 12px 40px rgba(0,0,0,.25);
        }
        .iac-head {
            display: flex; align-items: center; gap: 10px; padding: 14px 18px;
            border-bottom: 1px solid #e1e5ea; font-weight: 700; font-size: 15px;
        }
        .iac-head .iac-x { margin-left: auto; cursor: pointer; font-size: 22px; color: #888; line-height: 1; }
        .iac-body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
        .iac-note {
            margin: 0; padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.55;
            background: #f2f7f3; border: 1px solid #cfe3d4; color: #2c4a33;
        }
        .iac-row { display: flex; align-items: flex-start; gap: 8px; font-size: 13.5px; cursor: pointer; }
        .iac-row input { margin-top: 2px; }
        .iac-nobom { font-size: 12.5px; color: #8a5a00; line-height: 1.5; }
        .iac-foot { display: flex; align-items: center; gap: 10px; padding: 12px 18px; border-top: 1px solid #e1e5ea; }
        .iac-msg { flex: 1; font-size: 12.5px; color: #555; }
        .iac-save {
            padding: 8px 18px; border: 0; border-radius: 5px; cursor: pointer;
            background: #096926; color: #fff; font-size: 14px; font-weight: 600;
        }
        .iac-save:disabled { background: #9bbfa6; cursor: not-allowed; }
        @media (max-width: 768px) {
            .history-left { width: 100%; justify-content: space-between; }
            .iac-box { width: 100%; }
        }
    </style>

    <script>
    (function () {
        var AJAX = '?mod=inventory_management&controllers=inventory_management&action=';
        var mask = document.getElementById('iac-mask');
        var open = document.getElementById('btn-iac-setting');
        if (!mask || !open) return;

        var elA = document.getElementById('iac-active');
        var elW = document.getElementById('iac-warn');
        var elN = document.getElementById('iac-nobom');
        var elM = document.getElementById('iac-msg');
        var elS = document.getElementById('iac-save');

        function load() {
            fetch(AJAX + 'investment_auto_cost_config', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) return;
                    var c = res.config;
                    if (c) {
                        elA.checked = !!Number(c.is_active);
                        elW.checked = !!Number(c.warn_no_bom);
                    }
                    var list = res.no_bom || [];
                    elN.textContent = list.length
                        ? ('Đang có ' + list.length + ' sản phẩm chưa khai công thức: ' + list.slice(0, 8).join(', ')
                           + (list.length > 8 ? '…' : '') + '. Những sản phẩm này vẫn được ghi giá vốn, nhưng chỉ gồm chi phí sản xuất.')
                        : '';
                });
        }

        open.addEventListener('click', function () { mask.style.display = 'flex'; load(); });
        document.getElementById('iac-close').addEventListener('click', function () { mask.style.display = 'none'; });
        mask.addEventListener('click', function (e) { if (e.target === mask) mask.style.display = 'none'; });

        elS.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('is_active',   elA.checked ? 1 : '');
            fd.append('warn_no_bom', elW.checked ? 1 : '');
            /* Bật xong dựng lại luôn giá vốn của NGÀY ĐANG XEM để thấy kết quả ngay. */
            if (elA.checked && window.INVENTORY_DATA && window.INVENTORY_DATA.planDate) {
                var p = String(window.INVENTORY_DATA.planDate).split('/');   /* d/m/Y */
                if (p.length === 3) fd.append('sync_date', p[2] + '-' + p[1] + '-' + p[0]);
            }
            elS.disabled = true; elM.textContent = 'Đang lưu...';
            fetch(AJAX + 'investment_auto_cost_save', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    elS.disabled = false;
                    if (res && res.ok) {
                        elM.textContent = (res.synced !== null && res.synced !== undefined)
                            ? ('Đã lưu. Đã dựng lại giá vốn ' + res.synced + ' sản phẩm — tải lại trang để xem.')
                            : 'Đã lưu.';
                    } else {
                        elM.textContent = (res && res.message) || 'Lưu thất bại.';
                    }
                })
                .catch(function () { elS.disabled = false; elM.textContent = 'Lỗi mạng.'; });
        });
    })();
    </script>
    <?php endif; ?>

    <script src="<?php echo asset_ver('public/js/accounting/journal_entry.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/inventory_management/investment_products.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/datetime_picker.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>