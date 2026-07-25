<?php
/** Dữ liệu từ controller: $doc (mảng hoặc null), $id, $me. */
$doc = $doc ?? null;
$id  = (int) ($id ?? 0);
$me  = $me ?? [];
$of_boot = json_encode([
    'id'  => $id,
    'doc' => $doc,
    'me'  => ['id' => (int) ($me['id'] ?? 0), 'name' => (string) (($me['fullname'] ?? '') ?: ($me['username'] ?? ''))],
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
$is_sheet = $doc && $doc['type'] === 'sheet';
$can_edit = $doc && in_array($doc['my_permission'], ['owner', 'edit'], true);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $doc ? htmlspecialchars($doc['title']) : 'Không tìm thấy'; ?></title>
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

        <div class="content of-ed-page">
        <?php if (!$doc): ?>
            <div class="of-noaccess">
                <i class="fa-regular fa-folder-open"></i>
                <p>Không tìm thấy tài liệu hoặc bạn không có quyền xem.</p>
                <a href="?mod=office&controllers=office&action=docs" class="of-btn">Quay lại Docs</a>
            </div>
        <?php else: ?>
            <div class="of-ed-head">
                <a href="?mod=office&controllers=office&action=<?php echo $is_sheet ? 'sheets' : 'docs'; ?>" id="of-ed-back" class="of-ed-back" title="Quay lại">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <i class="fa-solid <?php echo $is_sheet ? 'fa-table-cells' : 'fa-file-lines'; ?> of-ed-icon"></i>
                <input type="text" id="of-ed-title" class="of-ed-title" value="<?php echo htmlspecialchars($doc['title']); ?>"
                       <?php echo $can_edit ? '' : 'disabled'; ?> maxlength="200">
                <span class="of-ed-savestate" id="of-ed-savestate"></span>
                <div class="of-ed-presence" id="of-ed-presence"></div>
                <div class="of-ed-actions">
                    <button type="button" class="of-ed-zoom" id="of-ed-zoom" title="Bấm để về 100%">100%</button>
                    <?php if ($can_edit): ?>
                    <button type="button" class="of-btn of-btn-save" id="of-ed-save" disabled><i class="fa-solid fa-floppy-disk"></i> Lưu</button>
                    <?php endif; ?>
                    <?php if ($doc['owner_id'] === (int) ($me['id'] ?? 0)): ?>
                    <button type="button" class="of-btn" id="of-ed-share"><i class="fa-solid fa-user-plus"></i> Chia sẻ</button>
                    <?php endif; ?>
                    <button type="button" class="of-btn" id="of-ed-export"><i class="fa-solid fa-folder-plus"></i> Đưa vào Quản lý file</button>
                    <button type="button" class="of-btn" id="of-ed-print"><i class="fa-solid fa-print"></i> In</button>
                </div>
            </div>

            <?php if ($is_sheet): ?>
                <div class="of-sheet-toolbar" id="of-sheet-toolbar"></div>
                <div class="of-sheet-subbar">
                    <label class="of-ed-gridcheck" title="Ẩn gridlines">
                        <span class="app-round-check">
                            <input type="checkbox" id="of-sheet-hide-gridlines">
                            <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                        </span>
                        Ẩn gridlines
                    </label>
                </div>
                <div class="of-formula-bar" id="of-formula-bar">
                    <span class="of-formula-ref" id="of-formula-ref">A1</span>
                    <span class="of-formula-fx"><i class="fa-solid fa-code"></i></span>
                    <div class="of-formula-input-wrap">
                        <textarea id="of-formula-input" rows="1" autocomplete="off" spellcheck="false"></textarea>
                        <div class="of-formula-resizer" id="of-formula-resizer" title="Kéo để mở rộng/thu nhỏ"><i class="fa-solid fa-grip-lines"></i></div>
                    </div>
                    <div class="of-formula-suggest" id="of-formula-suggest" style="display:none;"></div>
                </div>
                <div class="of-sheet-scroll" id="of-sheet-scroll">
                    <div class="of-sheet-wrap" id="of-sheet-wrap"></div>
                </div>
                <div class="of-sheet-charts" id="of-sheet-charts" style="display:none;"></div>
            <?php else: ?>
                <div class="of-doc-toolbar" id="of-doc-toolbar"></div>
                <div class="of-ruler" id="of-doc-ruler">
                    <div class="of-ruler-track" id="of-doc-ruler-track">
                        <div class="of-ruler-handle of-ruler-handle-left" id="of-doc-ruler-left" title="Thụt lề trái"></div>
                        <div class="of-ruler-handle of-ruler-handle-right" id="of-doc-ruler-right" title="Thụt lề phải"></div>
                    </div>
                </div>
                <div class="of-doc-scroll" id="of-doc-scroll">
                    <div class="of-doc-page-wrap" id="of-doc-editor"></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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

    <?php if ($doc): ?>
    <script type="application/json" id="of-boot"><?php echo $of_boot; ?></script>
    <script src="<?php echo office_asset('public/js/office/office_common.js'); ?>"></script>
    <?php if ($is_sheet): ?>
        <script src="<?php echo office_asset('public/js/office/sheets_editor.js'); ?>"></script>
    <?php else: ?>
        <script src="<?php echo office_asset('public/js/office/docs_editor.js'); ?>"></script>
    <?php endif; ?>
    <?php if (!empty($_GET['autoprint'])): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
    <?php endif; ?>
    <?php endif; ?>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
