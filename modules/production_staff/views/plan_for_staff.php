<?php
// Thông tin tiêu đề phiếu Share KHSX (dùng chung app_settings 'pf_share.*' với production_formula).
$ps_share = function_exists('ps_get_share_settings')
    ? ps_get_share_settings()
    : ['company_name' => 'VUA AN TOÀN', 'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kế hoạch sản xuất (NVSX)</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/production_staff/plan_for_staff.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<?php
$cols = isset($_GET['cols']) && (int)$_GET['cols'] === 2 ? 2 : 4;
// User "chỉ xem" view này: ẩn/khoá mọi control sửa (backend cũng đã chặn ở các action ghi).
$pf_ro = function_exists('permission_is_view_only')
    && permission_is_view_only('production_staff', 'production_staff', 'plan_for_staff');
// Ngày của kế hoạch hằng ngày (lấy theo card dài ngày đã công bố) — controller truyền xuống.
$pf_plan_ts = (isset($plan_date) && strtotime((string) $plan_date)) ? strtotime((string) $plan_date) : time();
// Hình công thức: admin thấy nút quản lý; user thường chỉ thấy nút xem với SP đã duyệt.
$pf_is_admin      = !empty($pf_is_admin);
$formula_approved = (isset($formula_approved) && is_array($formula_approved)) ? $formula_approved : [];
?>

<body>
    <div id="wrapper" class="cols-<?php echo $cols; ?><?php echo $pf_ro ? ' pf-ro' : ''; ?>">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="wp-header-content">
            <div class="header" style="display:none" aria-hidden="true">
                <div class="wp-top-header">
                    <div class="logo">
                        <a href="?mod=home">
                            <img src="public/images/logo/logo_vat_png.png" alt="">
                        </a>
                    </div>

                    <div class="title">
                        <h1>KẾ HOẠCH SẢN XUẤT</h1>
                    </div>
                    <div class="header-actions">
                        <button type="button" class="btn-reset" id="btn-reset">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </button>
                        <?php /* Nút "In BC" + "Share KHSX" đã gỡ (không còn cần) — 2026-11-09. */ ?>
                        <?php if (permission_can_check_db('production_staff', 'production_staff', 'plan_for_staff')): ?>
                        <button type="button" class="btn-check-db"
                            data-tables="production_plans,products,pre_production_notes,additional_tasks,finished_goods_inventory,product_info_basic,material_information,finished_product_production_data">
                            <i class="fa-solid fa-database"></i> Check Database
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="content">
                <div class="wp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="pf-search-product" placeholder="Tìm sản phẩm để thêm..." autocomplete="off">
                    <ul class="search-dropdown" id="pf-search-dropdown"></ul>
                </div>
                <div class="date">
                    <p><?php echo date('d', $pf_plan_ts) . ' tháng ' . date('n', $pf_plan_ts) . ' ' . date('Y', $pf_plan_ts); ?></p>
                </div>
                <ul class="list-product" id="list-product">
                    <?php if (empty($plans)): ?>
                        <li class="list-product-empty" style="grid-column:1/-1;text-align:center;padding:40px;color:#6b7280;font-style:italic;list-style:none;">
                            Chưa có kế hoạch sản xuất được gửi xuống.
                        </li>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                            <li class="product-item pf-acc" data-product-id="<?php echo (int)$plan['product_id']; ?>">
                                <div class="wp-product-num pf-acc-head">
                                    <?php $pf_img = trim((string) ($plan['image_url'] ?? '')); ?>
                                    <div class="pf-prod-ava"><?php if ($pf_img !== ''): ?><img src="public/images/<?php echo htmlspecialchars($pf_img, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-box pf-ava-ph"></i><?php endif; ?></div>
                                    <div class="name-product">
                                        <p><strong class="pf-name-text" contenteditable="false" spellcheck="false" data-product-id="<?php echo (int)$plan['product_id']; ?>"><?php echo htmlspecialchars($plan['product_name']); ?></strong><button type="button" class="pf-name-edit-btn" title="Sửa tên"><i class="fa-solid fa-pen"></i></button></p>
                                    </div>
                                    <div class="num">
                                        <input type="text" class="pf-qty-input" data-plan-id="<?php echo (int)$plan['plan_id']; ?>" value="<?php echo (int)$plan['quantity']; ?>">
                                    </div>
                                    <span class="pf-acc-caret" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
                                </div>
                                <div class="pf-acc-body">
                                <?php $pf_pid = (int) $plan['product_id']; ?>
                                <div class="list-QC">
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_sample_<?php echo $plan['plan_id']; ?>" data-plan-id="<?php echo (int)$plan['plan_id']; ?>" data-field="sample" <?php echo (int)$plan['sample'] === 1 ? 'checked' : ''; ?>>
                                        <label for="ck_sample_<?php echo $plan['plan_id']; ?>">Lấy mẫu</label>
                                    </div>
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_test_<?php echo $plan['plan_id']; ?>" data-plan-id="<?php echo (int)$plan['plan_id']; ?>" data-field="test" <?php echo (int)$plan['test'] === 1 ? 'checked' : ''; ?>>
                                        <label for="ck_test_<?php echo $plan['plan_id']; ?>">Test</label>
                                    </div>
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_stock_<?php echo $plan['plan_id']; ?>" data-plan-id="<?php echo (int)$plan['plan_id']; ?>" data-field="check_inventory" <?php echo (int)$plan['check_inventory'] === 1 ? 'checked' : ''; ?>>
                                        <label for="ck_stock_<?php echo $plan['plan_id']; ?>">Kiểm tồn</label>
                                    </div>
                                </div>

                                <div class="wp-info">
                                    <div class="title">
                                        <p>Thông tin:</p>
                                    </div>
                                    <ul class="list-info">
                                        <li class="info-item">Tồn kho đầu ngày:
                                            <span><?php echo $plan['stock_qty'] !== null ? (int)$plan['stock_qty'] : '—'; ?></span>
                                        </li>
                                        <li class="info-item">Bao bì trong:
                                            <div class="pkg-picker" data-field="inner_packaging_id" data-product-id="<?php echo (int)$plan['product_id']; ?>" data-id="<?php echo (int)($plan['inner_packaging_id'] ?? 0); ?>">
                                                <input type="text" class="pkg-search" value="<?php echo htmlspecialchars($plan['inner_packaging'] ?: ''); ?>" placeholder="Tìm nguyên liệu..." autocomplete="off">
                                                <div class="search-results"></div>
                                            </div>
                                        </li>
                                        <li class="info-item">QC BBT:
                                            <input type="text" class="info-field" data-field="inner_packaging_spec" data-product-id="<?php echo (int)$plan['product_id']; ?>" value="<?php echo htmlspecialchars($plan['inner_packaging_spec'] ?: ''); ?>" placeholder="Nhập quy cách...">
                                        </li>
                                        <li class="info-item">Bao bì ngoài:
                                            <div class="pkg-picker" data-field="outer_packaging_id" data-product-id="<?php echo (int)$plan['product_id']; ?>" data-id="<?php echo (int)($plan['outer_packaging_id'] ?? 0); ?>">
                                                <input type="text" class="pkg-search" value="<?php echo htmlspecialchars($plan['outer_packaging'] ?: ''); ?>" placeholder="Tìm nguyên liệu..." autocomplete="off">
                                                <div class="search-results"></div>
                                            </div>
                                        </li>
                                        <li class="info-item">QC BBN:
                                            <input type="text" class="info-field" data-field="outer_packaging_spec" data-product-id="<?php echo (int)$plan['product_id']; ?>" value="<?php echo htmlspecialchars($plan['outer_packaging_spec'] ?: ''); ?>" placeholder="Nhập quy cách...">
                                        </li>
                                    </ul>
                                </div>

                                <?php
                                $note_display = trim($plan['pre_note'] ?? '');
                                if ($note_display === '') $note_display = trim($plan['plan_note'] ?? '');
                                ?>
                                <?php if ($note_display !== ''): ?>
                                    <div class="note">
                                        <strong>Lưu ý:</strong>
                                        <input type="text" class="pf-note-input" data-product-id="<?php echo (int)$plan['product_id']; ?>" value="<?php echo htmlspecialchars($note_display); ?>" readonly>
                                    </div>
                                <?php endif; ?>
                                <div class="pf-card-actions">
                                    <button type="button" class="pf-mc-btn" data-product-id="<?php echo $pf_pid; ?>">Đối chiếu</button>
                                    <?php if ($pf_is_admin): ?>
                                        <button type="button" class="pf-formula-btn pf-formula-admin" data-product-id="<?php echo $pf_pid; ?>">Công thức</button>
                                        <button type="button" class="pf-del-product" data-plan-id="<?php echo (int) $plan['plan_id']; ?>">Xoá</button>
                                    <?php elseif (in_array($pf_pid, $formula_approved, true)): ?>
                                        <button type="button" class="pf-formula-btn pf-formula-view" data-product-id="<?php echo $pf_pid; ?>">Xem công thức</button>
                                    <?php endif; ?>
                                </div>
                                </div><!-- .pf-acc-body -->
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>

                <div class="wp-other-work">
                    <div class="add-other-work">
                        <p>VIỆC KHÁC</p>
                        <button type="button" class="btn-add-other-work" id="btn-add-other-work">
                            <i class="fa-solid fa-plus"></i> Thêm
                        </button>
                    </div>
                    <div class="other-work-form" id="other-work-form" style="display:none;">
                        <input type="text" id="other-work-input" placeholder="Nhập việc khác rồi nhấn Enter...">
                    </div>
                    <ul class="list-other-work" id="list-other-work">
                        <?php foreach ($tasks as $i => $task): ?>
                            <li class="other-work-item" data-task-id="<?php echo (int)$task['id']; ?>">
                                <div class="serial">
                                    <p><?php echo $i + 1; ?></p>
                                </div>
                                <div class="content-other-work">
                                    <input type="text" value="<?php echo htmlspecialchars($task['description']); ?>" readonly>
                                </div>
                                <button type="button" class="task-delete" title="Gỡ việc này">&times;</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Share KHSX -->
    <div class="khsx-backdrop" id="khsx-backdrop">
        <div class="khsx-modal" role="dialog" aria-modal="true">
            <div class="khsx-bar">
                <button type="button" class="khsx-share" id="khsx-share-btn"><i class="fa-solid fa-copy"></i> Share</button>
                <button type="button" class="khsx-close" id="khsx-close-btn" aria-label="Đóng">&times;</button>
            </div>
            <div class="khsx-sheet" id="khsx-sheet">
                <div class="pf-share-deco pf-share-deco-top"></div>
                <div class="pf-share-header">
                    <div class="pf-share-group-a">
                        <div class="pf-share-logo-wrap">
                            <img src="public/images/logo/logo_vat_png.png" alt="logo" class="pf-share-logo">
                        </div>
                        <div class="pf-share-titles">
                            <h1 class="pf-share-editable" data-share-key="company_name"><span class="pf-share-etext"><?php echo htmlspecialchars($ps_share['company_name'], ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="pf-share-edit" title="Sửa nội dung"><i class="fa-solid fa-pen"></i></button></h1>
                            <h4 class="pf-share-editable" data-share-key="company_address" data-multiline="1"><span class="pf-share-etext"><?php echo htmlspecialchars($ps_share['company_address'], ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="pf-share-edit" title="Sửa nội dung"><i class="fa-solid fa-pen"></i></button></h4>
                        </div>
                    </div>
                    <div class="pf-share-doctitle-wrap">
                        <div class="pf-share-doctitle">KẾ HOẠCH SẢN XUẤT</div>
                    </div>
                </div>
                <div class="pf-share-rope"></div>
                <div class="khsx-share-list" id="khsx-share-list"></div>
                <div class="khsx-share-tasks" id="khsx-share-tasks"></div>
                <div class="pf-share-deco pf-share-deco-bottom">
                    <div class="khsx-doc-bottom">
                        <div class="khsx-doc-footband">
                            <span class="khsx-doc-foottri"></span>
                        </div>
                        <div class="khsx-share-date"><?php echo htmlspecialchars(function_exists('ps_vn_date') ? ps_vn_date(date('Y-m-d', $pf_plan_ts)) : date('d/m/Y', $pf_plan_ts), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal In Báo cáo sản xuất (BC) — biểu mẫu A4 để nhân viên ghi tay sản lượng cuối ngày -->
    <div class="print-modal" id="bc-print-modal" style="display:none;">
        <div class="print-modal-overlay" id="bc-print-modal-overlay"></div>
        <div class="print-modal-content">
            <div class="print-modal-toolbar no-print">
                <button type="button" id="bc-print-do" class="btn-do-print">In</button>
                <button type="button" id="bc-print-close" class="btn-close-print">Đóng</button>
            </div>
            <div class="bc-print-sheet" id="bc-print-sheet">
                <h2 class="bc-title">BÁO CÁO SẢN XUẤT</h2>
                <p class="bc-date">Ngày <?php echo date('d', $pf_plan_ts); ?> tháng <?php echo date('n', $pf_plan_ts); ?> năm <?php echo date('Y', $pf_plan_ts); ?></p>

                <ul class="bc-product-list">
                    <?php if (empty($plans)): ?>
                        <li class="bc-product-row bc-empty">Chưa có kế hoạch sản xuất được gửi xuống.</li>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                            <li class="bc-product-row">
                                <div class="bc-product-top">
                                    <span class="bc-product-name"><?php echo htmlspecialchars($plan['product_name']); ?>:</span>
                                    <span class="bc-qty-box"></span>
                                </div>
                                <div class="bc-date-block">
                                    <span class="bc-date-block-label">Date:</span>
                                    <span class="bc-radio-opt"><span class="bc-bullet"></span>Hôm nay</span>
                                    <span class="bc-radio-opt"><span class="bc-bullet"></span>Hôm qua</span>
                                    <span class="bc-radio-opt"><span class="bc-bullet"></span>Khác:<span class="bc-fill-line"></span></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>

                <div class="bc-other-work">
                    <p class="bc-ow-title">Việc khác</p>
                    <ol class="bc-ow-list">
                        <?php if (!empty($tasks)): ?>
                            <?php foreach ($tasks as $task): ?>
                                <li><?php echo htmlspecialchars($task['description']); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li></li>
                            <li></li>
                            <li></li>
                        <?php endif; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pf_is_admin): ?>
    <!-- Footer "Lưu & Gửi kế hoạch": mờ sẵn, sáng khi có thay đổi; gửi = đẩy chuông NV -->
    <div class="pf-savebar" id="pf-savebar">
        <button type="button" class="pf-save-btn" id="pf-save-send" disabled>
            <i class="fa-solid fa-paper-plane"></i> Lưu &amp; Gửi kế hoạch
        </button>
    </div>
    <?php endif; ?>

    <!-- Modal CÔNG THỨC (admin quản lý): xem ảnh + nạp ảnh + duyệt cho NV xem -->
    <div class="pf-fx-backdrop" id="pf-fx-backdrop" aria-hidden="true">
        <div class="pf-fx-modal" role="dialog" aria-modal="true">
            <div class="pf-fx-bar">
                <span class="pf-fx-title" id="pf-fx-title">Công thức</span>
                <button type="button" class="pf-fx-close" id="pf-fx-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pf-fx-body">
                <div class="pf-fx-imgwrap" id="pf-fx-imgwrap">
                    <div class="pf-fx-empty" id="pf-fx-empty">Chưa có hình công thức.</div>
                    <img id="pf-fx-img" src="" alt="Công thức" style="display:none;">
                </div>
                <div class="pf-fx-note" id="pf-fx-note"></div>
                <div class="pf-fx-admin" id="pf-fx-admin" style="display:none;">
                    <input type="file" id="pf-fx-file" accept="image/*" hidden>
                    <button type="button" class="pf-fx-act pf-fx-upload" id="pf-fx-upload"><i class="fa-solid fa-upload"></i> Nạp ảnh</button>
                    <button type="button" class="pf-fx-act pf-fx-approve" id="pf-fx-approve"><i class="fa-solid fa-user-check"></i> Duyệt cho NV xem</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Xem ảnh công thức FULL VIEWPORT: pinch-zoom 2 ngón + kéo pan -->
    <div class="pf-zoom" id="pf-zoom" aria-hidden="true">
        <button type="button" class="pf-zoom-close" id="pf-zoom-close" aria-label="Đóng">&times;</button>
        <img id="pf-zoom-img" src="" alt="Công thức" draggable="false">
    </div>

    <!-- Modal ĐỐI CHIẾU NGUYÊN VẬT LIỆU -->
    <div class="pf-mc-backdrop" id="pf-mc-backdrop" aria-hidden="true">
        <div class="pf-mc-modal" role="dialog" aria-modal="true">
            <div class="pf-mc-bar">
                <span class="pf-mc-mtitle" id="pf-mc-mtitle">Đối chiếu nguyên vật liệu</span>
                <button type="button" class="pf-mc-close" id="pf-mc-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pf-mc-content" id="pf-mc-content"></div>
        </div>
    </div>

    <script>window.PF_READONLY = <?php echo $pf_ro ? 'true' : 'false'; ?>;</script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/production_staff/plan_for_staff.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>