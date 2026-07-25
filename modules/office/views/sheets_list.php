<?php
/** Dữ liệu từ controller: $type, $mine, $shared, $byme, $users. */
$type   = $type   ?? 'sheet';
$mine   = $mine   ?? [];
$shared = $shared ?? [];
$byme   = $byme   ?? [];
$users  = $users  ?? [];
$of_boot = json_encode([
    'type'   => $type,
    'mine'   => $mine,
    'shared' => $shared,
    'byme'   => $byme,
    'users'  => $users,
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sheets</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo office_asset('public/css/office/office.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content of-content">
            <div class="of-toolbar">
                <div class="of-tb-left">
                    <h2 class="of-page-title"><i class="fa-solid fa-table-cells"></i> Sheets</h2>
                    <a class="of-page-switch" href="?mod=office&controllers=office&action=docs">
                        <i class="fa-solid fa-file-lines"></i> Docs
                    </a>
                    <button type="button" class="of-btn of-btn-primary" id="of-create-btn">
                        <i class="fa-solid fa-plus"></i> Tạo bảng tính
                    </button>
                </div>
                <div class="of-tb-right">
                    <button type="button" class="of-btn of-btn-danger" id="of-bulk-delete-btn" style="display:none;">
                        <i class="fa-solid fa-trash-can"></i> Xóa (<span id="of-bulk-delete-count">0</span>)
                    </button>
                    <button type="button" class="of-btn" id="of-bulk-share-btn" style="display:none;">
                        <i class="fa-solid fa-user-plus"></i> Chia sẻ
                    </button>
                    <button type="button" class="of-btn" id="of-merge-btn">
                        <i class="fa-solid fa-file-import"></i> Trộn file
                    </button>
                    <button type="button" class="of-chip is-active" data-tab="mine">
                        <i class="fa-solid fa-user"></i> Của tôi
                    </button>
                    <button type="button" class="of-chip" data-tab="shared">
                        <i class="fa-solid fa-share-nodes"></i> Được chia sẻ
                    </button>
                    <button type="button" class="of-chip" data-tab="byme">
                        <i class="fa-solid fa-paper-plane"></i> Đã chia sẻ
                    </button>
                </div>
            </div>

            <div class="of-tab" id="of-tab-mine">
                <div class="of-grid" id="of-grid-mine"></div>
                <div class="of-empty" id="of-empty-mine" style="display:none;">
                    <i class="fa-regular fa-file-lines"></i>
                    <p>Chưa có bảng tính nào. Nhấn <b>"Tạo bảng tính"</b> để bắt đầu.</p>
                </div>
            </div>
            <div class="of-tab" id="of-tab-shared" style="display:none;">
                <div class="of-grid" id="of-grid-shared"></div>
                <div class="of-empty" id="of-empty-shared" style="display:none;">
                    <i class="fa-regular fa-handshake"></i>
                    <p>Chưa có ai chia sẻ bảng tính với bạn.</p>
                </div>
            </div>
            <div class="of-tab" id="of-tab-byme" style="display:none;">
                <div class="of-grid" id="of-grid-byme"></div>
                <div class="of-empty" id="of-empty-byme" style="display:none;">
                    <i class="fa-regular fa-paper-plane"></i>
                    <p>Bạn chưa chia sẻ bảng tính nào.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="of-modal-overlay" id="of-modal" style="display:none;">
        <div class="of-modal">
            <div class="of-modal-head">
                <h3 id="of-modal-title">Tiêu đề</h3>
                <button type="button" class="of-modal-x" id="of-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="of-modal-body" id="of-modal-body"></div>
        </div>
    </div>
    <div class="of-toast" id="of-toast"></div>

    <script type="application/json" id="of-boot"><?php echo $of_boot; ?></script>
    <script src="<?php echo office_asset('public/js/office/list.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
