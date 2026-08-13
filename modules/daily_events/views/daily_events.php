<?php
/** Dữ liệu từ controller: $me (id, is_admin), $feed (trang 1 "Tường của tôi"), $permalink (bài xem qua ?post=ID, có thể null). */
$me        = $me ?? ['id' => 0, 'is_admin' => false];
$feed      = $feed ?? ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 10];
$permalink = $permalink ?? null;
$dew_boot = json_encode([
    'me'        => $me,
    'feed'      => $feed,
    'permalink' => $permalink,
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thảo luận</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/invoice_upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/daily_events/daily_events.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content dew-content">

            <!-- ===== Modal đăng bài ===== -->
            <div class="dew-modal" id="dew-compose-modal" aria-hidden="true">
                <div class="dew-modal-overlay" id="dew-compose-modal-overlay"></div>
                <div class="dew-modal-box">
                    <div class="dew-modal-head">
                        <h3>Đăng bài thảo luận</h3>
                        <button type="button" class="dew-modal-close" id="dew-compose-modal-close" aria-label="Đóng">&times;</button>
                    </div>
                    <div class="dew-modal-body">
                        <div class="dew-composer">
                            <img class="dew-avatar" id="dew-my-avatar-2" src="" alt="">
                            <div class="dew-composer-body">
                                <div class="dew-format-row">
                                    <button type="button" class="dew-fmt-toggle" id="dew-format-btn" title="Định dạng chữ (bôi đen đoạn text trước)">
                                        <i class="fa-solid fa-font"></i>
                                    </button>
                                </div>
                                <div class="dew-format-pop" id="dew-format-pop" style="display:none;">
                                    <button type="button" class="dew-fmt-btn" data-fmt="b" title="Đậm"><b>B</b></button>
                                    <button type="button" class="dew-fmt-btn" data-fmt="i" title="Nghiêng"><i>I</i></button>
                                    <button type="button" class="dew-fmt-btn" data-fmt="u" title="Gạch chân"><u>U</u></button>
                                    <span class="dew-fmt-sep"></span>
                                    <button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="red" title="Đỏ" style="color:#ef4444;">A</button>
                                    <button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="yellow" title="Vàng đậm" style="color:#b45309;">A</button>
                                    <button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="blue" title="Xanh dương" style="color:#2563eb;">A</button>
                                    <button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="green" title="Xanh lá" style="color:#16a34a;">A</button>
                                </div>
                                <div class="dew-input-wrap" id="dew-compose-input-wrap">
                                    <div class="dew-input dew-input-editable" id="dew-compose-input" contenteditable="true"
                                        data-placeholder="Bạn đang nghĩ gì? Gõ #hagtag để gắn chủ đề..."></div>
                                    <ul class="dew-dropdown" id="dew-hashtag-suggest"></ul>
                                </div>

                                <div class="wp-invoice-upload dew-dropzone" id="dew-compose-dropzone">
                                    <div class="iu-dropzone">
                                        <i class="fa-solid fa-paste"></i>
                                        <p>Dán ảnh (Ctrl+V) hoặc kéo thả ảnh/file vào đây</p>
                                        <button type="button" class="iu-btn-choose">Chọn tệp</button>
                                        <!-- Chụp thẳng bằng camera điện thoại (accept + capture) — xem .dew-cam-btn trong daily_events.js -->
                                        <button type="button" class="dew-cam-btn" title="Chụp ảnh từ điện thoại">
                                            <i class="fa-solid fa-camera"></i> Chụp ảnh
                                        </button>
                                        <input type="file" class="iu-input" multiple hidden>
                                        <input type="file" class="dew-cam-input" accept="image/*" capture="environment" hidden>
                                    </div>
                                    <div class="iu-preview"></div>
                                </div>

                                <div class="dew-tag-picker">
                                    <div class="dew-tag-chips" id="dew-compose-tag-chips"></div>
                                    <div class="dew-search-wrap">
                                        <i class="fa-solid fa-user-tag dew-search-icon"></i>
                                        <input type="text" class="dew-search-input" id="dew-compose-tag-input"
                                            placeholder="Gắn thẻ người dùng khác..." autocomplete="off" spellcheck="false">
                                        <ul class="dew-dropdown" id="dew-compose-tag-dropdown"></ul>
                                    </div>
                                </div>

                                <div class="dew-composer-foot">
                                    <span class="dew-composer-msg" id="dew-compose-msg"></span>
                                    <button type="button" class="dew-btn dew-btn-primary" id="dew-compose-submit">
                                        <i class="fa-solid fa-paper-plane"></i> Đăng bài
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Thanh trên: tìm theo #hashtag + nút đăng bài (icon, nằm cạnh phải) ===== -->
            <div class="dew-topbar">
                <div class="dew-search-bar">
                    <i class="fa-solid fa-hashtag"></i>
                    <input type="text" id="dew-hashtag-search" placeholder="Tìm bài theo #hashtag..." autocomplete="off">
                    <button type="button" class="dew-search-clear" id="dew-hashtag-clear" style="display:none;" title="Xóa">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <button type="button" class="dew-composer-btn" id="dew-composer-btn" title="Đăng bài thảo luận (#hashtag để tạo chủ đề)">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
            </div>

            <!-- ===== Bài xem qua liên kết chuông (gắn thẻ, có thể chưa ghim) ===== -->
            <div class="dew-permalink" id="dew-permalink" style="display:none;"></div>

            <!-- ===== Tường ===== -->
            <div class="dew-feed" id="dew-feed"></div>
            <div class="dew-empty" id="dew-feed-empty" style="display:none;">
                <i class="fa-regular fa-comments"></i>
                <p>Chưa có bài đăng nào trên tường của bạn.</p>
            </div>
            <button type="button" class="dew-btn dew-loadmore" id="dew-load-more" style="display:none;">
                Xem thêm bài cũ hơn
            </button>

        </div>
    </div>

    <script>window.__DEW_BOOT__ = <?php echo $dew_boot; ?>;</script>
    <script src="<?php echo asset_ver('public/js/shared/invoice_dropzone.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/daily_events/daily_events.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
