<?php
/** Dữ liệu từ controller: $library, $shared, $users, $projects. */
$library  = $library  ?? ['groups' => [], 'root_files' => []];
$shared   = $shared   ?? ['pending' => [], 'accepted' => []];
$users    = $users    ?? [];
$projects = $projects ?? [];
$fm_boot = json_encode([
    'library'  => $library,
    'shared'   => $shared,
    'users'    => $users,
    'projects' => $projects,
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
$fm_pending_count = count($shared['pending'] ?? []);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý file</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/file_management/file_manager.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content fm-content">

            <!-- ===== Thanh công cụ ===== -->
            <div class="fm-toolbar">
                <div class="fm-tb-left">
                    <button type="button" class="fm-btn fm-btn-primary" id="fm-add-group">
                        <i class="fa-solid fa-folder-plus"></i> Thêm nhóm file
                    </button>
                    <button type="button" class="fm-btn" id="fm-upload-root">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Tải file lên
                    </button>
                    <button type="button" class="fm-btn" id="fm-upload-folder-root">
                        <i class="fa-solid fa-folder-arrow-up"></i> Tải thư mục lên
                    </button>
                </div>

                <div class="fm-tb-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="fm-search" placeholder="Tìm file, thư mục..." autocomplete="off" spellcheck="false">
                    <button type="button" class="fm-search-clear" id="fm-search-clear" title="Xóa"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="fm-tb-right">
                    <button type="button" class="fm-chip is-active" data-tab="mine">
                        <i class="fa-solid fa-folder-tree"></i> Thư viện của tôi
                    </button>
                    <button type="button" class="fm-chip" data-tab="shared">
                        <i class="fa-solid fa-share-nodes"></i> Được chia sẻ
                        <span class="fm-badge" id="fm-pending-badge" <?php echo $fm_pending_count ? '' : 'style="display:none"'; ?>><?php echo (int) $fm_pending_count; ?></span>
                    </button>
                    <button type="button" class="fm-chip-star" id="fm-filter-star" title="Chỉ hiện mục gắn sao">
                        <i class="fa-regular fa-star"></i>
                    </button>
                    <button type="button" class="fm-chip-star" id="fm-storage-btn" title="Dung lượng lưu trữ">
                        <i class="fa-solid fa-chart-pie"></i>
                    </button>
                </div>
            </div>

            <!-- ===== Kết quả tìm kiếm ===== -->
            <div class="fm-search-results" id="fm-search-results" style="display:none;">
                <div class="fm-section-head"><i class="fa-solid fa-magnifying-glass"></i> Kết quả tìm kiếm
                    <button type="button" class="fm-link" id="fm-search-back">← Quay lại</button>
                </div>
                <div class="fm-grid" id="fm-search-grid"></div>
            </div>

            <!-- ===== TAB: Thư viện của tôi ===== -->
            <div class="fm-tab" id="fm-tab-mine">
                <!-- FAQ cố định, mặc định mở: gộp chung "File hay dùng" + "Đã lưu từ chia sẻ", nằm song song 2 nửa. -->
                <div class="fm-faq open" id="fm-frequent-faq">
                    <button type="button" class="fm-faq-header" aria-expanded="true">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span class="fm-faq-label">File hay dùng &amp; Đã lưu từ chia sẻ</span>
                        <i class="fa-solid fa-chevron-down fm-faq-icon"></i>
                    </button>
                    <div class="fm-faq-body">
                        <div class="fm-half-row">
                            <div class="fm-half-col">
                                <div class="fm-half-label"><i class="fa-solid fa-clock-rotate-left"></i> File hay dùng</div>
                                <div class="fm-grid" id="fm-frequent-grid"></div>
                                <div class="fm-muted" id="fm-frequent-empty" style="display:none;">Chưa có file nào được mở.</div>
                            </div>
                            <div class="fm-half-col">
                                <div class="fm-half-label" id="fm-library-shared-head" style="display:none;">
                                    <i class="fa-solid fa-share-nodes"></i> Đã lưu từ chia sẻ
                                </div>
                                <div class="fm-grid fm-shared-grid" id="fm-library-shared"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fm-rootfiles" id="fm-root-files"></div>
                <div class="fm-section-head"><i class="fa-solid fa-folder-tree"></i> Nhóm file</div>
                <div class="fm-groups" id="fm-groups"></div>
                <div class="fm-empty" id="fm-mine-empty" style="display:none;">
                    <i class="fa-regular fa-folder-open"></i>
                    <p>Chưa có nhóm file nào. Nhấn <b>“Thêm nhóm file”</b> để bắt đầu, hoặc <b>“Tải file lên”</b>.</p>
                </div>
            </div>

            <!-- ===== TAB: Được chia sẻ với tôi ===== -->
            <div class="fm-tab" id="fm-tab-shared" style="display:none;">
                <div class="fm-section-head"><i class="fa-solid fa-bell"></i> Lời mời chia sẻ</div>
                <div class="fm-pending" id="fm-pending"></div>
                <div class="fm-section-head"><i class="fa-solid fa-share-nodes"></i> Đang chia sẻ với tôi</div>
                <div class="fm-grid" id="fm-shared-grid"></div>
                <div class="fm-empty" id="fm-shared-empty" style="display:none;">
                    <i class="fa-regular fa-handshake"></i>
                    <p>Chưa có ai chia sẻ file với bạn.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- input tải lên ẩn (dùng chung) -->
    <input type="file" id="fm-file-input" multiple style="display:none;">
    <input type="file" id="fm-folder-input" webkitdirectory directory multiple style="display:none;">

    <!-- ===== Menu ngữ cảnh ===== -->
    <div class="fm-ctxmenu" id="fm-ctxmenu" style="display:none;"></div>

    <!-- ===== Modal dùng chung ===== -->
    <div class="fm-modal-overlay" id="fm-modal" style="display:none;">
        <div class="fm-modal">
            <div class="fm-modal-head">
                <h3 id="fm-modal-title">Tiêu đề</h3>
                <button type="button" class="fm-modal-x" id="fm-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="fm-modal-body" id="fm-modal-body"></div>
        </div>
    </div>

    <!-- Toast -->
    <div class="fm-toast" id="fm-toast"></div>

    <script type="application/json" id="fm-boot"><?php echo $fm_boot; ?></script>
    <script src="<?php echo asset_ver('public/js/file_management/file_manager.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
