<?php
global $config;
$affected_table  = $_GET['affected_table'] ?? '';
$affected_id     = (int) ($_GET['affected_id'] ?? 0);
$checkdb_default = $affected_table !== '' ? $affected_table : 'products';
$detail_error    = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết sản phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/detail.css">
    <link rel="stylesheet" href="public/css/product_profile/modal.css">


    <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>

    <!--Định nghĩa thư viện js-->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>

    <!--app-->
    <script src="public/js/product_profile_detail.js" defer></script>
    <!-- Modal dùng chung (xem thông tin, thêm/thay thế file, thay thế thành phần, check DB) -->
    <script src="public/js/product_profile_modal.js" defer></script>
    <!-- Kéo-thả file vào vùng NCC / nguyên liệu -->
    <script src="public/js/product_profile_dragdrop.js" defer></script>
    <!-- Kéo sắp xếp thứ tự thành phần (Trello-like) -->
    <script src="public/js/product_profile_sortable.js" defer></script>
    <!-- Sửa thông tin sản phẩm trong modal (ảnh + các trường) -->
    <script src="public/js/product_profile_info.js" defer></script>
    <!-- Tìm & đổi sang xem sản phẩm khác -->
    <script src="public/js/product_profile_switch_search.js" defer></script>
    <!-- Chụp ảnh modal "Hóa đơn còn thiếu" để Ctrl+V dán sang app khác -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- Nén ZIP cho modal "Tần suất" (tải xuống tất cả file) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    #name-dashboard {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    h1 {
        margin-bottom: 0;
    }

</style>

<body>
    <div id="wrapper">
        <div id="sidebar-right">
            <!-- Require Sidebar-right -->
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">

                <!-- (4) Tiêu đề + nút Xem thông tin sản phẩm + Check database -->
                <div class="detail-header">
                    <div class="detail-header-left">
                        <a href="?mod=product_profile&controllers=product_profile&action=list" class="btn-back-list" title="Quay lại danh sách hồ sơ sản phẩm">
                            <i class="fa-solid fa-arrow-left"></i> Danh sách sản phẩm
                        </a>
                        <div class="pp-switch-search">
                            <i class="fa-solid fa-magnifying-glass pp-switch-icon"></i>
                            <input type="text" id="pp-switch-input" autocomplete="off" placeholder="Tìm sản phẩm khác để xem...">
                            <ul class="pp-switch-dropdown" id="pp-switch-dropdown"></ul>
                        </div>
                    </div>
                    <?php $pname = $data['detail']['product_name'] ?? ($data['info'][0]['product_name'] ?? ''); ?>
                    <?php if (!empty($pname)): ?>
                        <div class="detail-header-title">
                            <h1><?= mb_strtoupper($pname) ?></h1>
                            <?php if (!empty($data['product_files'])): ?>
                                <div class="pp-product-files-row">
                                    <span class="pp-product-files-label"><i class="fa-solid fa-paperclip"></i> Hồ sơ SP:</span>
                                    <?php foreach ($data['product_files'] as $pf): ?>
                                        <a href="<?= htmlspecialchars($pf['web_path']) ?>" target="_blank" class="pp-product-file-chip" title="<?= htmlspecialchars($pf['file_name']) ?>">
                                            <i class="fa-solid fa-file-lines"></i> <span><?= htmlspecialchars($pf['file_name']) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="detail-actions">
                        <button type="button" class="btn-view-info" data-pp-open="#info-basic-modal">
                            <i class="fa-solid fa-circle-info"></i> Xem thông tin sản phẩm
                        </button>
                        <button type="button" class="btn-check-db" data-pp-open="#checkdb-modal"
                            data-default-table="<?= htmlspecialchars($checkdb_default) ?>"
                            data-affected-id="<?= $affected_id ?>"
                            data-affected-table="<?= htmlspecialchars($affected_table) ?>">
                            <i class="fa-solid fa-database"></i> Check database
                        </button>
                    </div>
                </div>

                <?php if (!empty($detail_error)): ?>
                    <p class="error-msg"><?= htmlspecialchars($detail_error) ?></p>
                <?php endif; ?>

                <div class="wp-content">
                    <div class="wp-ingredient">
                        <div class="wp-title">
                            <div class="title">
                                <p>Thành phần cấu tạo</p>
                            </div>
                            <div class="wp-title-actions">
                                <label class="wp-standard-set" title="Đánh dấu bộ hồ sơ chuẩn">
                                    <span class="app-round-check">
                                        <input type="checkbox" class="chk-standard-set" data-product-id="<?= $data['product_id'] ?>" <?= !empty($data['detail']['is_standard']) ? 'checked' : '' ?>>
                                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                    </span>
                                    Bộ hồ sơ chuẩn
                                </label>
                                <!-- Thiết lập NCC: bật/tắt "xét hồ sơ" từng NCC (toàn hệ thống) -->
                                <button type="button" class="btn-supplier-setting" data-pp-open="#supplier-checkprofile-modal" title="Thiết lập nhà cung cấp nào được xét hồ sơ">
                                    <i class="fa-solid fa-sliders"></i> Thiết lập NCC
                                </button>
                                <!-- Tần suất lặp lại file hồ sơ, giới hạn theo NCC/NVL của riêng sản phẩm này -->
                                <button type="button" class="btn-frequency" id="btn-file-frequency" title="Xem tần suất lặp lại của file hồ sơ (trong phạm vi sản phẩm này)">
                                    <i class="fa-solid fa-chart-column"></i> Tần suất
                                </button>
                                <!-- Xem hồ sơ/hóa đơn còn thiếu của sản phẩm -->
                                <button type="button" class="btn-missing-docs" data-pp-open="#missing-docs-modal" title="Xem hóa đơn, chứng từ còn thiếu">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Hóa đơn còn thiếu
                                </button>
                                <!-- (1) Nút '+' mở modal thêm thành phần -->
                                <button type="button" class="btn-add-composition" data-pp-open="#add-composition-modal" title="Thêm thành phần">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="container-ingredient">
                            <ul class="list-ingredient" data-product-id="<?= $data['product_id'] ?>">
                                <?php if (!empty($data['composition'])): ?>
                                    <?php foreach ($data['composition'] as $item): ?>
                                        <li class="ingredient-item" data-bom-id="<?= $item['bom_id'] ?>">
                                            <div class="name-ingredient">
                                                <span class="drag-handle" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span>
                                                <p><strong><?= $item['material_name'] ?></strong> - <?= $item['supplier_name']  ?><?php
                                                    if (!empty($item['last_purchase_date'])):
                                                        $pd = date('d/m/Y', strtotime($item['last_purchase_date']));
                                                        $pq = rtrim(rtrim(number_format((float) $item['last_purchase_qty'], 2, '.', ''), '0'), '.');
                                                ?> - <span class="last-purchase">Mua gần đây: <?= $pd ?> (<?= $pq ?> <?= htmlspecialchars($item['material_unit'] ?? '') ?>)</span><?php endif; ?> </p>
                                                <button type="button" class="btn-delete-composition"
                                                    data-bom-id="<?= $item['bom_id'] ?>"
                                                    data-name="<?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>"
                                                    title="Xóa thành phần">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </div>
                                            <div class="container-content-ingredient">
                                                <div class="wp-button">
                                                    <!-- (5) Thay thế thành phần -> modal -->
                                                    <button type="button" class="tbn-replace js-open-replace-material"
                                                        data-old-material-id="<?= $item['material_id'] ?>"
                                                        data-current="<?= htmlspecialchars($item['material_name'] . ' - ' . $item['supplier_name'], ENT_QUOTES) ?>">
                                                        Thay thế
                                                    </button>
                                                    <a href="" class="btn-delete">
                                                        Xóa thành phần
                                                    </a>
                                                </div>
                                                <?php /* "Thiết lập NCC" tắt "Xét hồ sơ" cho NCC này -> ẩn cả khối "Hồ sơ nhà cung cấp". */ ?>
                                                <div class="wp-supplier-profile" data-dropzone="composition"
                                                    data-entity-type="supplier"
                                                    data-entity-id="<?= $item['mi_supplier_id'] ?>"
                                                    data-folder-name="<?= htmlspecialchars($item['supplier_name'], ENT_QUOTES) ?>"
                                                    data-product-id="<?= $data['product_id'] ?>"
                                                    <?= empty($item['supplier_check_profile']) ? 'style="display:none"' : '' ?>>

                                                    <div class="wp-title">

                                                        <div class="level-1">
                                                            <p> - Hồ sơ nhà cung cấp </p>
                                                        </div>
                                                        <!-- (5) + Thêm file (NCC) -> modal -->
                                                        <button type="button" class="btn-add-file js-open-cfile"
                                                            data-action="?mod=product_profile&controllers=product_profile&action=ready_add_file_supplier&id=<?= $item['mi_supplier_id'] ?>&product_id=<?= $data['product_id'] ?>"
                                                            data-subtitle="Thêm file cho nhà cung cấp: <?= htmlspecialchars($item['supplier_name'], ENT_QUOTES) ?>"
                                                            data-submit="THÊM">
                                                            + Thêm file
                                                        </button>
                                                    </div>
                                                    <ul class="list-file">
                                                        <?php if (!empty($item['files']['supplier'])): ?>
                                                            <?php foreach ($item['files']['supplier'] as $item_file_supplier): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file file-name-edit">
                                                                        <a href="<?= $config['base_url'].$item_file_supplier['file_path'] ?>" target="_blank">
                                                                            <?= $item_file_supplier['file_name'] ?>
                                                                        </a>
                                                                        <a href="#" class="btn-edit-filename" data-kind="composition" data-file-id="<?= $item_file_supplier['id'] ?>" title="Sửa tên file">
                                                                            <i class="fa-solid fa-pen"></i>
                                                                        </a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>" class="download-file" title="Tải file">
                                                                            <i class="fa-solid fa-download"></i>
                                                                        </a>
                                                                        <!-- (5) Thay thế file (fa-rotate) -> modal -->
                                                                        <a href="#" class="replace-file js-open-cfile" title="Thay thế file"
                                                                            data-action="?mod=product_profile&controllers=product_profile&action=ready_replace_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>"
                                                                            data-subtitle="Thay thế file của nhà cung cấp: <?= htmlspecialchars($item['supplier_name'], ENT_QUOTES) ?>"
                                                                            data-old="<?= htmlspecialchars($item_file_supplier['file_name'], ENT_QUOTES) ?>"
                                                                            data-label="<?= htmlspecialchars($item_file_supplier['file_name'], ENT_QUOTES) ?>"
                                                                            data-submit="THAY THẾ">
                                                                             <i class="fa-solid fa-rotate"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>" class="delete-file" title="Xóa file" onclick="return confirm('Bạn có chắc chắn muốn xóa file \'<?= htmlspecialchars($item_file_supplier['file_name'], ENT_QUOTES) ?>\' không?');">
                                                                             <i class="fa-regular fa-trash-can"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </ul>

                                                </div>
                                                <div class="wp-row-materials" data-dropzone="composition"
                                                    data-entity-type="material"
                                                    data-entity-id="<?= $item['material_info_id'] ?>"
                                                    data-folder-name="<?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>"
                                                    data-product-id="<?= $data['product_id'] ?>">
                                                    <div class="wp-title">
                                                        <div class="level-1">
                                                            <p> - Hồ sơ nguyên liệu</p>
                                                        </div>
                                                        <!-- (5) + Thêm file (nguyên liệu) -> modal -->
                                                        <button type="button" class="btn-add-file js-open-cfile"
                                                            data-action="?mod=product_profile&controllers=product_profile&action=ready_add_file_material&id=<?= $item['material_info_id'] ?>&product_id=<?= $data['product_id'] ?>"
                                                            data-subtitle="Thêm file cho nguyên liệu: <?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>"
                                                            data-submit="THÊM">
                                                            + Thêm file
                                                        </button>
                                                    </div>
                                                    <ul class="list-file">
                                                        <?php if (!empty($item['files']['material'])): ?>
                                                            <?php foreach ($item['files']['material'] as $item_file_material): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file file-name-edit">
                                                                        <a href="<?= $config['base_url'] . $item_file_material['file_path'] ?>" target="_blank">
                                                                            <?= $item_file_material['file_name'] ?>
                                                                        </a>
                                                                        <a href="#" class="btn-edit-filename" data-kind="composition" data-file-id="<?= $item_file_material['id'] ?>" title="Sửa tên file">
                                                                            <i class="fa-solid fa-pen"></i>
                                                                        </a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>" class="download-file" title="Tải file">
                                                                            <i class="fa-solid fa-download"></i>
                                                                        </a>
                                                                        <!-- (5) Thay thế file (fa-rotate) -> modal -->
                                                                        <a href="#" class="replace-file js-open-cfile" title="Thay thế file"
                                                                            data-action="?mod=product_profile&controllers=product_profile&action=ready_replace_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>"
                                                                            data-subtitle="Thay thế file của nguyên liệu: <?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>"
                                                                            data-old="<?= htmlspecialchars($item_file_material['file_name'], ENT_QUOTES) ?>"
                                                                            data-label="<?= htmlspecialchars($item_file_material['file_name'], ENT_QUOTES) ?>"
                                                                            data-submit="THAY THẾ">
                                                                            <i class="fa-solid fa-rotate"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>" class="delete-file" title="Xóa file" onclick="return confirm('Bạn có chắc chắn muốn xóa file \'<?= htmlspecialchars($item_file_material['file_name'], ENT_QUOTES) ?>\' không?');">
                                                                            <i class="fa-regular fa-trash-can"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                                <div class="wp-invoice">
                                                    <div class="wp-title">
                                                        <div class="level-1">
                                                            <p> - Hóa đơn</p>
                                                        </div>
                                                        <!-- + Thêm hóa đơn mua hàng (modal cfile dùng chung) -->
                                                        <button type="button" class="btn-add-file js-open-cfile"
                                                            data-action="?mod=product_profile&controllers=product_profile&action=ready_add_material_invoice&id=<?= $item['material_info_id'] ?>&product_id=<?= $data['product_id'] ?>"
                                                            data-subtitle="Thêm hóa đơn cho nguyên liệu: <?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>"
                                                            data-submit="THÊM">
                                                            + Thêm hóa đơn
                                                        </button>
                                                    </div>
                                                    <ul class="list-file list-invoice">
                                                        <?php if (!empty($item['files']['invoice'])): ?>
                                                            <?php foreach ($item['files']['invoice'] as $inv): ?>
                                                                <li class="file-item invoice-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file">
                                                                        <span class="file-name-edit">
                                                                            <a href="<?= $config['base_url'] . $inv['file_path'] ?>" target="_blank">
                                                                                <?= htmlspecialchars($inv['file_name']) ?>
                                                                            </a>
                                                                            <a href="#" class="btn-edit-filename" data-kind="invoice" data-file-id="<?= $inv['id'] ?>" title="Sửa tên hóa đơn">
                                                                                <i class="fa-solid fa-pen"></i>
                                                                            </a>
                                                                        </span>
                                                                        <span class="invoice-uploaded">Tải lên: <?= date('d/m/Y', strtotime($inv['uploaded_at'])) ?></span>
                                                                        <span class="invoice-remind<?= $inv['is_overdue'] ? ' is-overdue' : '' ?>"
                                                                            data-invoice-id="<?= $inv['id'] ?>"
                                                                            data-remind="<?= $inv['remind_at'] ?>"
                                                                            title="Bấm để sửa ngày nhắc">
                                                                            <i class="fa-solid fa-bell"></i> Nhắc: <?= date('d/m/Y', strtotime($inv['remind_at'])) ?><?= $inv['is_overdue'] ? ' — Cảnh báo!' : '' ?>
                                                                        </span>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_material_invoice&invoice_id=<?= $inv['id'] ?>&product_id=<?= $data['product_id'] ?>" title="Tải xuống hóa đơn">
                                                                            <i class="fa-solid fa-download"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_material_invoice&invoice_id=<?= $inv['id'] ?>&product_id=<?= $data['product_id'] ?>" class="delete-file" title="Xóa hóa đơn" onclick="return confirm('Xóa hóa đơn \'<?= htmlspecialchars($inv['file_name'], ENT_QUOTES) ?>\'?');">
                                                                            <i class="fa-regular fa-trash-can"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

            </div>


        </div>
    </div>

    <!-- ============================================================
         (4) MODAL THÔNG TIN SẢN PHẨM (wp-info-product-basic)
         ============================================================ -->
    <div class="pp-modal-overlay" id="info-basic-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thông tin sản phẩm</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <?php
                    $det = $data['detail'] ?? [];
                    $img_raw = $det['image_url'] ?? '';
                    $has_img = !empty($img_raw);
                ?>
                <div class="info-edit" data-product-id="<?= $data['product_id'] ?>">
                    <!-- Hình ảnh: nếu chưa có -> chỉ hiện nút 'Cập nhật' -->
                    <div class="info-row info-row-image">
                        <label>Hình ảnh sản phẩm:</label>
                        <div class="info-image-cell">
                            <img class="info-thumb" src="<?= $has_img ? 'public/images/' . htmlspecialchars($img_raw) : '' ?>" alt="" style="<?= $has_img ? '' : 'display:none' ?>">
                            <input type="file" class="info-image-input" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp" hidden>
                            <button type="button" class="btn-update-image">Cập nhật</button>
                        </div>
                    </div>

                    <div class="info-row">
                        <label>Đơn vị:</label>
                        <input type="text" class="info-field" data-field="unit" value="<?= htmlspecialchars($det['unit'] ?? '') ?>" placeholder="Nhập đơn vị...">
                    </div>

                    <div class="info-row">
                        <label>Bao bì trong:</label>
                        <div class="pkg-picker material-search-inline" data-field="inner_packaging_id" data-id="<?= (int) ($det['inner_packaging_id'] ?? 0) ?>">
                            <input type="text" class="pkg-search" value="<?= htmlspecialchars($det['inner_packaging_name'] ?? '') ?>" placeholder="Tìm nguyên liệu bao bì..." autocomplete="off">
                            <div class="search-results"></div>
                        </div>
                    </div>

                    <div class="info-row">
                        <label>Bao bì ngoài:</label>
                        <div class="pkg-picker material-search-inline" data-field="outer_packaging_id" data-id="<?= (int) ($det['outer_packaging_id'] ?? 0) ?>">
                            <input type="text" class="pkg-search" value="<?= htmlspecialchars($det['outer_packaging_name'] ?? '') ?>" placeholder="Tìm nguyên liệu bao bì..." autocomplete="off">
                            <div class="search-results"></div>
                        </div>
                    </div>

                    <div class="info-row">
                        <label>Quy cách bao bì trong:</label>
                        <input type="text" class="info-field" data-field="inner_packaging_spec" value="<?= htmlspecialchars($det['inner_packaging_spec'] ?? '') ?>" placeholder="Nhập quy cách...">
                    </div>

                    <div class="info-row">
                        <label>Quy cách bao bì ngoài:</label>
                        <input type="text" class="info-field" data-field="outer_packaging_spec" value="<?= htmlspecialchars($det['outer_packaging_spec'] ?? '') ?>" placeholder="Nhập quy cách...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         (5) MODAL THÊM / THAY THẾ FILE THÀNH PHẦN (dùng chung)
         ============================================================ -->
    <div class="pp-modal-overlay" id="composition-file-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>File thành phần</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-addfile">
                    <p class="cfile-subtitle"></p>
                    <form enctype="multipart/form-data" method="POST" id="cfile-form" action="">
                        <div class="wp-old-file" style="display:none">
                            <i class="fa-solid fa-file"></i>
                            <span class="cfile-oldname"></span>
                        </div>
                        <div class="wp-name-file">
                            <label for="cfile_label">Đặt tên file:</label>
                            <input type="text" id="cfile_label" name="file_label">
                        </div>
                        <div class="wp-upload">
                            <input type="file" name="file_upload" hidden>
                            <input type="text" class="file-name-display" placeholder="Chưa chọn file" readonly>
                            <button type="button" class="btn-choose">Chọn file</button>
                        </div>
                        <button type="submit" class="btn-submit cfile-submit">LƯU</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         (1) MODAL THÊM THÀNH PHẦN (nút '+')
         ============================================================ -->
    <div class="pp-modal-overlay" id="add-composition-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thêm thành phần</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-add-product">
                    <form method="POST" id="add-composition-form"
                        action="?mod=product_profile&controllers=product_profile&action=ready_add_composition&product_id=<?= $data['product_id'] ?>">
                        <div class="wp-search-material material-search" data-mode="multi">
                            <input type="text" class="material-search-input" placeholder="Tìm nguyên liệu... (có thể chọn nhiều)" autocomplete="off">
                            <div class="search-results"></div>
                        </div>
                        <div class="selected-materials"></div>
                        <button type="submit" class="btn-submit">THÊM</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "HÓA ĐƠN CÒN THIẾU" — chụp gửi được (mỗi dòng có thể bỏ
         khỏi màn hình, chỉ để chụp ảnh, không đụng dữ liệu thật)
         ============================================================ -->
    <div class="pp-modal-overlay" id="missing-docs-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Hóa đơn, chứng từ còn thiếu</h3>
                <div class="pp-modal-header-actions">
                    <button type="button" class="btn-print-missing" id="missing-docs-print" title="In khổ A4">
                        <i class="fa-solid fa-print"></i> In
                    </button>
                    <button type="button" class="btn-capture-missing" id="missing-docs-capture" title="Chụp ảnh để Ctrl+V dán sang app khác">
                        <i class="fa-solid fa-camera"></i> Chụp
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body" id="missing-docs-capture-area">
                <?php if (!empty($pname)): ?>
                    <p class="missing-docs-product-name">Sản phẩm: <strong><?= htmlspecialchars($pname) ?></strong></p>
                <?php endif; ?>
                <?php if (empty($data['missing_docs'])): ?>
                    <p class="missing-docs-empty">Không thiếu hồ sơ/hóa đơn nào.</p>
                <?php else: ?>
                    <table class="missing-docs-table" id="missing-docs-table">
                        <thead>
                            <tr>
                                <th>Tên nguyên liệu</th>
                                <th>Hóa đơn, chứng từ thiếu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['missing_docs'] as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['material_name']) ?></td>
                                    <td>
                                        <ul class="missing-list">
                                            <?php foreach ($row['missing'] as $m): ?>
                                                <li>
                                                    <span>
                                                        <?= htmlspecialchars($m['label']) ?><?php if (!empty($m['entity_name'])): ?> '<span class="missing-entity missing-entity-<?= htmlspecialchars($m['entity_type']) ?>"><?= htmlspecialchars($m['entity_name']) ?></span>'<?php endif; ?>
                                                    </span>
                                                    <button type="button" class="missing-item-del" title="Bỏ dòng (chỉ để chụp ảnh, không ảnh hưởng dữ liệu)">&times;</button>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "THIẾT LẬP NCC" — bật/tắt "Xét hồ sơ NCC" (toàn hệ thống).
         Tắt 1 NCC -> ẩn khối "Hồ sơ nhà cung cấp" của NCC đó ngay trên
         trang này + bỏ qua khi tính "Hóa đơn, chứng từ còn thiếu".
         (nạp qua AJAX lúc mở, không server-render sẵn)
         ============================================================ -->
    <div class="pp-modal-overlay" id="supplier-checkprofile-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-sliders"></i> Thiết lập nhà cung cấp</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="scp-search">
                    <input type="text" id="scp-search-input" autocomplete="off" placeholder="Tìm nhà cung cấp theo tên...">
                </div>
                <table class="scp-table" id="scp-table">
                    <thead>
                        <tr>
                            <th>Tên nhà cung cấp</th>
                            <th style="width:140px;">Xét hồ sơ NCC</th>
                        </tr>
                    </thead>
                    <tbody id="scp-tbody">
                        <tr><td colspan="2" class="scp-loading">Đang tải...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "TẦN SUẤT" — tần suất lặp lại file hồ sơ toàn hệ thống
         (nạp qua AJAX lúc mở, không server-render sẵn)
         ============================================================ -->
    <div class="pp-modal-overlay" id="frequency-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-chart-column"></i> Tần suất lặp lại hồ sơ</h3>
                <div class="pp-modal-header-actions">
                    <select id="freq-filter-select" title="Lọc theo tần suất">
                        <option value="0">Tất cả</option>
                        <option value="2">&gt;2</option>
                        <option value="3">&gt;3</option>
                        <option value="5">&gt;5</option>
                    </select>
                    <button type="button" class="btn-download-freq" id="freq-download-zip" title="Tải xuống tất cả (ZIP), theo đúng danh sách đang hiển thị">
                        <i class="fa-solid fa-file-zipper"></i> Tải xuống
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body">
                <div class="freq-summary" id="freq-summary"></div>
                <div id="frequency-body">
                    <p class="freq-loading">Đang tải...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         (5) MODAL THAY THẾ THÀNH PHẦN (.tbn-replace)
         ============================================================ -->
    <div class="pp-modal-overlay" id="replace-material-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thay thế thành phần</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-add-product">
                    <form method="POST" id="replace-material-form"
                        action="?mod=product_profile&controllers=product_profile&action=ready_replace_composition_material&product_id=<?= $data['product_id'] ?>">
                        <p>Thay thế cho: <strong class="rm-current"></strong></p>
                        <input type="hidden" name="old_material_id" class="rm-old-id" value="">
                        <div class="wp-search-material material-search" data-mode="single">
                            <input type="text" class="material-search-input" placeholder="Tìm nguyên liệu mới..." autocomplete="off">
                            <div class="search-results"></div>
                        </div>
                        <div class="selected-materials"></div>
                        <button type="submit" class="btn-submit">THAY THẾ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         (6) MODAL CHECK DATABASE
         ============================================================ -->
    <div class="pp-modal-overlay" id="checkdb-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-database"></i> Kiểm tra Database</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="checkdb-toolbar">
                    <label>Bảng:</label>
                    <select class="checkdb-table-select">
                        <option value="products">products (sản phẩm)</option>
                        <option value="product_info_basic">product_info_basic (thông tin căn bản)</option>
                        <option value="pricing_policies">pricing_policies (giá)</option>
                        <option value="bill_of_materials">bill_of_materials (thành phần)</option>
                        <option value="files">files (file thành phần)</option>
                        <option value="material_invoices">material_invoices (hóa đơn NVL)</option>
                    </select>
                    <span class="checkdb-meta"></span>
                </div>
                <div class="checkdb-table-wrap"></div>
                <div class="checkdb-pagination"></div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // $ chắc chắn đã sẵn sàng tại DOMContentLoaded (jquery load defer)
            function openOverlay($m) {
                $m.addClass('is-open');
                $('body').css('overflow', 'hidden');
            }

            // Modal "Hóa đơn còn thiếu": lưu sẵn nội dung gốc lúc tải trang, mỗi lần MỞ modal thì nạp lại
            // nguyên vẹn — vì nút xóa dòng (.missing-item-del) chỉ để ẩn tạm lúc xem/chụp, không phải xóa
            // thật; mở lại modal luôn thấy đầy đủ, không giữ trạng thái đã xóa từ lần xem trước.
            var missingDocsOriginalHtml = null;
            var $missingDocsArea = $('#missing-docs-capture-area');
            if ($missingDocsArea.length) missingDocsOriginalHtml = $missingDocsArea.html();
            $(document).on('click', '.btn-missing-docs', function () {
                if (missingDocsOriginalHtml !== null) $missingDocsArea.html(missingDocsOriginalHtml);
            });

            // Modal "Thiết lập NCC": bật/tắt "Xét hồ sơ NCC" (toàn hệ thống). Nạp 1 lần lúc mở modal,
            // ô tìm lọc trực tiếp trên danh sách đã nạp (không gọi lại server).
            function ppEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
            var scpLoaded = false;
            var $scpTbody = $('#scp-tbody');
            function renderSupplierCheckProfile(list) {
                if (!list.length) { $scpTbody.html('<tr><td colspan="2" class="scp-empty">Chưa có nhà cung cấp nào.</td></tr>'); return; }
                var html = list.map(function (s) {
                    var checked = Number(s.check_profile) === 1 ? ' checked' : '';
                    return '<tr data-name="' + ppEsc(String(s.display_name || '').toLowerCase()) + '">'
                        + '<td>' + ppEsc(s.display_name) + '</td>'
                        + '<td><label class="scp-toggle"><input type="checkbox" class="scp-toggle-input" data-supplier-id="' + s.id + '"' + checked + '><span class="scp-toggle-track"></span></label></td>'
                        + '</tr>';
                }).join('');
                $scpTbody.html(html);
            }
            $(document).on('click', '.btn-supplier-setting', function () {
                if (scpLoaded) return;
                $.getJSON('?mod=product_profile&controllers=product_profile&action=ajax_list_suppliers_check_profile', function (res) {
                    if (!res || !res.success) { $scpTbody.html('<tr><td colspan="2" class="scp-empty">Không tải được.</td></tr>'); return; }
                    renderSupplierCheckProfile(res.data || []);
                    scpLoaded = true;
                }).fail(function () {
                    $scpTbody.html('<tr><td colspan="2" class="scp-empty">Lỗi kết nối.</td></tr>');
                });
            });
            $('#scp-search-input').on('input', function () {
                var kw = ($(this).val() || '').trim().toLowerCase();
                $scpTbody.find('tr[data-name]').each(function () {
                    var $tr = $(this);
                    $tr.toggle(kw === '' || String($tr.data('name')).indexOf(kw) !== -1);
                });
            });
            // Toggle 1 NCC -> ẩn/hiện NGAY khối "Hồ sơ nhà cung cấp" của NCC đó trên trang (không cần tải lại),
            // đồng thời lưu xuống DB. "Hóa đơn, chứng từ còn thiếu" sẽ tính đúng theo NCC bị tắt từ lần mở sau
            // (được server tính lại mỗi khi tải trang).
            $(document).on('change', '.scp-toggle-input', function () {
                var $chk = $(this);
                var supplierId = $chk.data('supplier-id');
                var checked = $chk.is(':checked');
                var $blocks = $('.wp-supplier-profile[data-entity-type="supplier"][data-entity-id="' + supplierId + '"]');
                $blocks.toggle(checked);
                $.post('?mod=product_profile&controllers=product_profile&action=ajax_toggle_supplier_check_profile',
                    { supplier_id: supplierId, value: checked ? '1' : '0' }, function (res) {
                        if (!res || !res.success) {
                            alert((res && res.message) || 'Lỗi cập nhật.');
                            $chk.prop('checked', !checked);
                            $blocks.toggle(!checked);
                        }
                    }, 'json').fail(function () {
                        alert('Lỗi kết nối.');
                        $chk.prop('checked', !checked);
                        $blocks.toggle(!checked);
                    });
            });

            // (5) Mở modal thêm / thay thế FILE thành phần
            $(document).on('click', '.js-open-cfile', function (e) {
                e.preventDefault();
                var $m = $('#composition-file-modal');
                $m.find('#cfile-form').attr('action', $(this).data('action'));
                $m.find('.cfile-subtitle').text($(this).data('subtitle') || '');
                $m.find('#cfile_label').val($(this).data('label') || '');
                $m.find('.cfile-submit').text($(this).data('submit') || 'LƯU');

                var old = $(this).data('old');
                if (old) {
                    $m.find('.wp-old-file').show();
                    $m.find('.cfile-oldname').text(old);
                } else {
                    $m.find('.wp-old-file').hide();
                    $m.find('.cfile-oldname').text('');
                }

                // reset ô chọn file
                $m.find('.file-name-display').val('');
                $m.find('input[type="file"]').val('');

                openOverlay($m);
            });

            // (5) Mở modal THAY THẾ THÀNH PHẦN
            $(document).on('click', '.js-open-replace-material', function (e) {
                e.preventDefault();
                var $m = $('#replace-material-modal');
                $m.find('.rm-old-id').val($(this).data('old-material-id'));
                $m.find('.rm-current').text($(this).data('current') || '');
                $m.find('.selected-materials').empty();
                $m.find('.material-search-input').val('');
                $m.find('.search-results').hide().empty();
                openOverlay($m);
            });

            // Checkbox tròn "Bộ hồ sơ chuẩn" -> cập nhật products.standard_document_set (không đòi hỏi đã có file, giống list.php)
            $(document).on('change', '.chk-standard-set', function () {
                var $chk = $(this);
                var pid = $chk.data('product-id');
                var val = $chk.is(':checked') ? '1' : '0';
                $.post('?mod=product_profile&controllers=product_profile&action=ajax_toggle_standard_set',
                    { product_id: pid, value: val }, function (res) {
                        if (!res || !res.success) {
                            alert((res && res.message) || 'Lỗi cập nhật.');
                            $chk.prop('checked', !$chk.is(':checked'));
                        }
                    }, 'json').fail(function () {
                        alert('Lỗi kết nối.');
                        $chk.prop('checked', !$chk.is(':checked'));
                    });
            });

            // Sửa ngày nhắc hẹn (cảnh báo hết hạn) của hóa đơn — bấm vào dòng "Nhắc: dd/mm/yyyy" để đổi ngày.
            $(document).on('click', '.invoice-remind', function () {
                var $tag = $(this);
                if ($tag.find('input').length) return; // đang sửa rồi
                var invoiceId = $tag.data('invoice-id');
                var cur = $tag.data('remind'); // yyyy-mm-dd
                var $inp = $('<input type="date">').val(cur);
                var orig = $tag.html();
                $tag.empty().append($inp);
                $inp.trigger('focus');

                function commit(save) {
                    var val = $inp.val();
                    if (!save || !val) { $tag.html(orig); return; }
                    $.post('?mod=product_profile&controllers=product_profile&action=ajax_update_invoice_remind',
                        { invoice_id: invoiceId, remind_at: val }, function (res) {
                            if (res && res.success) {
                                location.reload(); // đơn giản: tải lại để tính lại is-overdue + hiển thị đúng
                            } else {
                                alert((res && res.message) || 'Lỗi cập nhật.');
                                $tag.html(orig);
                            }
                        }, 'json').fail(function () {
                            alert('Lỗi kết nối.');
                            $tag.html(orig);
                        });
                }
                $inp.on('blur', function () { commit(true); });
                $inp.on('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); commit(true); }
                    else if (e.key === 'Escape') { commit(false); }
                });
            });

            // Modal "Hóa đơn còn thiếu" -> bỏ 1 dòng khỏi màn hình (chỉ để chụp ảnh, KHÔNG đụng dữ liệu thật)
            $(document).on('click', '.missing-item-del', function () {
                var $li = $(this).closest('li');
                var $ul = $li.closest('ul.missing-list');
                $li.remove();
                if (!$ul.children('li').length) {
                    $ul.closest('tr').remove();
                }
            });

            // "In" -> in khổ A4 (window.print, @media print chỉ hiện #missing-docs-capture-area — xem detail.css)
            $(document).on('click', '#missing-docs-print', function () {
                window.print();
            });

            // "Chụp" -> chụp nội dung modal thành ảnh, copy vào clipboard để Ctrl+V dán sang app khác.
            $(document).on('click', '#missing-docs-capture', function () {
                var $btn = $(this);
                var area = document.getElementById('missing-docs-capture-area');
                if (!area) return;
                if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.'); return; }
                if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
                var orig = $btn.html();
                $btn.html('Đang xử lý...').prop('disabled', true);
                window.html2canvas(area, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
                    canvas.toBlob(function (blob) {
                        navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                            alert('Đã copy ảnh vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                        }).catch(function () {
                            alert('Không copy được vào clipboard.');
                        }).then(function () {
                            $btn.html(orig).prop('disabled', false);
                        });
                    }, 'image/png');
                }).catch(function (err) {
                    console.error('Chụp modal error:', err);
                    alert('Lỗi tạo ảnh: ' + (err && err.message ? err.message : err));
                    $btn.html(orig).prop('disabled', false);
                });
            });

        });
    </script>
    <!-- Modal "Tần suất" — giới hạn theo sản phẩm đang xem (dùng chung JS với trang Danh sách sản phẩm) -->
    <script>window.PP_FREQ_PRODUCT_ID = <?= (int) $data['product_id'] ?>;</script>
    <script src="public/js/product_profile_frequency.js" defer></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
