<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** Dữ liệu từ controller: $year, $month, $off_reasons, $read_only. */
$year  = (int) ($year ?? date('Y'));
$month = (int) ($month ?? date('n'));
$off_reasons = isset($off_reasons) && is_array($off_reasons) ? $off_reasons : [];
$read_only = !empty($read_only);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng chấm công</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/payroll/payroll.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content py-content">
            <div class="py-toolbar">
                <div class="py-nav">
                    <button type="button" class="py-nav-btn" id="pyPrevMonth" title="Tháng trước"><i class="fa-solid fa-chevron-left"></i></button>
                    <h2 class="py-title" id="pyTitle">Bảng chấm công tháng <?php echo $month; ?> năm <?php echo $year; ?></h2>
                    <?php if ($read_only): ?><span class="py-readonly-badge"><i class="fa-solid fa-eye"></i> Chỉ xem</span><?php endif; ?>
                    <button type="button" class="py-nav-btn" id="pyNextMonth" title="Tháng sau"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <div class="py-nav-jump">
                    <select id="pyJumpMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>>Tháng <?php echo $m; ?></option>
                        <?php endfor; ?>
                    </select>
                    <select id="pyJumpYear">
                        <?php for ($y = $year - 3; $y <= $year + 3; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>>Năm <?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="button" class="py-btn" id="pyJumpGo">Xem</button>
                    <button type="button" class="py-btn py-btn-outline" id="pyTodayBtn">Hôm nay</button>
                </div>
                <div class="py-links">
                    <a class="py-btn py-btn-outline" id="pyPayslipLink" href="?mod=payroll&amp;controllers=payroll&amp;action=payslip&amp;year=<?php echo $year; ?>&amp;month=<?php echo $month; ?>">
                        <i class="fa-solid fa-money-check-dollar"></i> Bảng lương
                    </a>
                    <?php if (!$read_only): ?>
                    <button type="button" class="py-btn py-btn-outline" id="pyBtnReasons"><i class="fa-solid fa-list"></i> Lý do off</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="py-stats" id="pyStats">
                <div class="py-stat">
                    <span class="py-stat-label">Ngày làm trong tháng</span>
                    <span class="py-stat-value" id="pyStatWorkdays">-</span>
                </div>
                <div class="py-stat">
                    <span class="py-stat-label">Tổng công sản xuất</span>
                    <span class="py-stat-value" id="pyStatCongs">-</span>
                </div>
                <div class="py-stat">
                    <span class="py-stat-label">Hệ số công</span>
                    <span class="py-stat-value" id="pyStatCoeff">-</span>
                </div>
            </div>

            <div class="py-legend">
                <span class="py-legend-item"><b>x</b> Đủ công</span>
                <span class="py-legend-item"><b>0.5x</b> Nửa công</span>
                <span class="py-legend-item"><b>off</b> Nghỉ</span>
                <span class="py-legend-item"><b>L</b> Lễ/Tết</span>
                <span class="py-legend-item"><b>-</b> Không tính công</span>
                <span class="py-legend-item py-legend-hint">Bấm vào tiêu đề 1 ngày để chấm Lễ/Tết cho cả nhóm ngày đó.</span>
            </div>

            <div class="py-grid-wrap">
                <table class="py-grid-table" id="pyGridTable">
                    <thead id="pyGridHead"></thead>
                    <tbody id="pyGridBody">
                        <tr><td class="py-grid-loading">Đang tải...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Popover chọn công cho 1 ô -->
    <div class="py-popover" id="pyCellPopover" style="display:none;">
        <button type="button" class="py-popover-x" id="pyPopoverClose" title="Đóng">&times;</button>
        <div class="py-popover-title" id="pyPopoverTitle"></div>
        <div class="py-popover-options">
            <label class="py-popover-radio">
                <input type="radio" name="pyMarkChoice" value="x">
                <span id="pyPopoverXLabel">Đủ công (x)</span>
            </label>
            <label class="py-popover-radio">
                <input type="radio" name="pyMarkChoice" value="half">
                <span>Nửa công (0.5x)</span>
            </label>
            <label class="py-popover-radio">
                <input type="radio" name="pyMarkChoice" value="off">
                <span>Off</span>
            </label>
            <div class="py-popover-reason-wrap" id="pyPopoverReasonWrap">
                <select class="py-popover-reason" id="pyPopoverReason">
                    <option value="">-- Lý do (tùy chọn) --</option>
                    <?php foreach ($off_reasons as $r): ?>
                        <option value="<?php echo (int) $r['id']; ?>"><?php echo htmlspecialchars($r['reason'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="py-popover-radio">
                <input type="radio" name="pyMarkChoice" value="dash">
                <span>Không tính công (-)</span>
            </label>
        </div>
        <button type="button" class="py-popover-ok" id="pyPopoverOk">OK</button>
    </div>

    <!-- Modal quản lý lý do off -->
    <div class="py-modal-backdrop" id="pyReasonModal">
        <div class="py-modal">
            <div class="py-modal-header">Danh sách lý do off</div>
            <div class="py-modal-body">
                <ul class="py-reason-list" id="pyReasonList"></ul>
                <div class="py-reason-add">
                    <input type="text" id="pyReasonInput" placeholder="Thêm lý do mới...">
                    <button type="button" class="py-btn" id="pyReasonAddBtn">Thêm</button>
                </div>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyReasonCloseBtn">Đóng</button>
            </div>
        </div>
    </div>

    <script>
        window.PY_CONFIG = {
            baseUrl: '?mod=payroll&controllers=payroll&action=',
            year: <?php echo (int) $year; ?>,
            month: <?php echo (int) $month; ?>,
            offReasons: <?php echo json_encode($off_reasons, JSON_UNESCAPED_UNICODE); ?>,
            readOnly: <?php echo !empty($read_only) ? 'true' : 'false'; ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/payroll/timesheet.js"></script>
</body>

</html>
