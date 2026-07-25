<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh sách công việc — Việc cần làm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/tasks/task_list.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <div class="tk-toolbar">
                <button type="button" class="tk-btn-new-group" id="tk-new-group">
                    <i class="fa-solid fa-plus"></i> Tạo danh sách
                </button>
            </div>

            <!-- Hàng ngang các nhóm công việc (card) -->
            <div class="tk-board" id="tk-board">
                <!-- JS render các .tk-card vào đây -->
            </div>
        </div>
    </div>

    <!-- ===== Template 1 card nhóm công việc ===== -->
    <template id="tk-card-tpl">
        <div class="tk-card" data-group-id="">
            <div class="tk-card-head" draggable="true">
                <h3 class="tk-card-title" title="Đổi tên (nhấp đúp)"></h3>
                <div class="tk-card-menu-wrap">
                    <button type="button" class="tk-card-menu-btn" title="Tùy chọn">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>
                    <ul class="tk-card-menu">
                        <li data-act="rename"><i class="fa-solid fa-pen"></i> Đổi tên tiêu đề</li>
                        <li data-act="clear-done"><i class="fa-solid fa-broom"></i> Xóa công việc hoàn thành</li>
                        <li data-act="delete" class="tk-danger"><i class="fa-solid fa-trash"></i> Xóa nhóm công việc</li>
                    </ul>
                </div>
            </div>

            <button type="button" class="tk-add-task">
                <i class="fa-solid fa-plus"></i> Thêm việc cần làm
            </button>

            <!-- Ô nhập việc lever 1 (ẩn cho tới khi bấm "Thêm việc cần làm") -->
            <div class="tk-add-box" hidden>
                <input type="text" class="tk-add-input" placeholder="Nhập việc cần làm rồi Enter…" maxlength="500">
                <button type="button" class="tk-add-cancel">Hủy</button>
            </div>

            <!-- Danh sách việc chưa hoàn thành (lever 1) -->
            <ul class="tk-list" data-parent="0"></ul>

            <!-- Khu "Đã hoàn thành (n)" -->
            <div class="tk-done-wrap" hidden>
                <button type="button" class="tk-done-toggle">
                    <i class="fa-solid fa-chevron-right tk-done-caret"></i>
                    Đã hoàn thành (<span class="tk-done-count">0</span>)
                </button>
                <ul class="tk-done-list" hidden></ul>
            </div>
        </div>
    </template>

    <!-- ===== Template 1 việc lever 1 ===== -->
    <template id="tk-item-tpl">
        <li class="tk-item" data-id="" draggable="true">
            <div class="tk-item-row">
                <span class="tk-check" role="checkbox" tabindex="0" title="Đánh dấu hoàn thành"></span>
                <span class="tk-item-text"></span>
                <span class="tk-item-tools">
                    <button type="button" class="tk-item-remind-btn" title="Đặt nhắc hẹn"><i class="fa-solid fa-bell"></i></button>
                    <button type="button" class="tk-item-edit-btn" title="Sửa tiêu đề"><i class="fa-solid fa-pen"></i></button>
                    <button type="button" class="tk-item-del" title="Xóa"><i class="fa-solid fa-xmark"></i></button>
                </span>
            </div>
            <div class="tk-remind-tag" hidden></div>
            <!-- Vùng sửa: mô tả + nhắc hẹn (chỉ hiện khi click vào nội dung) -->
            <div class="tk-item-edit" hidden>
                <textarea class="tk-desc" rows="2" placeholder="Thêm mô tả…" maxlength="2000"></textarea>
                <label class="tk-detail-remind">
                    <i class="fa-solid fa-bell"></i> Nhắc hẹn:
                    <input type="datetime-local" class="tk-detail-remind-input">
                    <button type="button" class="tk-remind-clear" title="Xóa nhắc hẹn" hidden>&times;</button>
                </label>
            </div>
            <!-- Việc lever 2 — luôn hiển thị (không đóng mở) -->
            <ul class="tk-sublist" data-parent=""></ul>
            <input type="text" class="tk-sub-input" placeholder="Chi tiết công việc" maxlength="500">
        </li>
    </template>

    <!-- ===== Template 1 việc lever 2 ===== -->
    <template id="tk-subitem-tpl">
        <li class="tk-subitem" data-id="" draggable="true">
            <div class="tk-subitem-row">
                <span class="tk-check tk-check-sm" role="checkbox" tabindex="0" title="Đánh dấu hoàn thành"></span>
                <span class="tk-subitem-text"></span>
                <button type="button" class="tk-subitem-edit-btn" title="Sửa tiêu đề"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="tk-item-del" title="Xóa"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <textarea class="tk-sub-desc" rows="2" placeholder="Thêm mô tả…" maxlength="2000" hidden></textarea>
        </li>
    </template>

    <script>
        window.TK_CONFIG = { baseUrl: '?mod=tasks&controllers=tasks&action=' };
        window.TK_DATA = { groups: <?php echo json_encode($groups ?? [], JSON_UNESCAPED_UNICODE); ?> };
    </script>
    <script src="public/js/tasks/task_list.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
