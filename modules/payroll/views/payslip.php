<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** Dữ liệu từ controller: $year, $month, $settings, $settings_meta, $employees, $position_allowances. */
$year  = (int) ($year ?? date('Y'));
$month = (int) ($month ?? date('n'));
$settings = isset($settings) && is_array($settings) ? $settings : [];
$settings_meta = isset($settings_meta) && is_array($settings_meta) ? $settings_meta : [];
$employees = isset($employees) && is_array($employees) ? $employees : [];
$position_allowances = isset($position_allowances) && is_array($position_allowances) ? $position_allowances : [];
$read_only = !empty($read_only);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng lương</title>
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
                    <h2 class="py-title" id="pyTitle">Bảng lương tháng <?php echo $month; ?> năm <?php echo $year; ?></h2>
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
                    <button type="button" class="py-btn py-btn-outline" id="pyBtnInfo"><i class="fa-solid fa-circle-info"></i> Thông tin</button>
                    <a class="py-btn py-btn-outline" id="pyTimesheetLink" href="?mod=payroll&amp;controllers=payroll&amp;action=timesheet&amp;year=<?php echo $year; ?>&amp;month=<?php echo $month; ?>">
                        <i class="fa-regular fa-calendar-check"></i> Bảng chấm công
                    </a>
                    <button type="button" class="py-btn py-btn-outline" id="pyBtnHistory"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử lương</button>
                    <button type="button" class="py-btn py-btn-outline" id="pyBtnSettings"><i class="fa-solid fa-gear"></i> Cài đặt<?php echo $read_only ? ' (chỉ xem)' : ''; ?></button>
                    <?php if (!$read_only): ?>
                    <button type="button" class="py-btn" id="pyBtnSave"><i class="fa-solid fa-floppy-disk"></i> Lưu</button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="pyHistoryBanner" class="py-history-banner" style="display:none;">
                Đang xem <b>lịch sử đã lưu</b> của tháng này (chỉ xem, không tính lại theo dữ liệu chấm công hiện tại).
                <button type="button" id="pyBtnLiveMode" class="py-link-btn">Quay về tính trực tiếp</button>
            </div>

            <div class="py-grid-wrap">
                <table class="py-payslip-table" id="pyPayslipTable">
                    <tbody id="pyPayslipBody">
                        <tr><td class="py-grid-loading">Đang tải...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Thông tin sản lượng & thành tích tháng -->
    <div class="py-modal-backdrop" id="pyInfoModal">
        <div class="py-modal">
            <div class="py-modal-header">Thông tin sản lượng &amp; thành tích tháng <span id="pyInfoMonthLabel"></span></div>
            <div class="py-modal-body">
                <table class="py-info-table">
                    <tr><td>Tổng công sản xuất</td><td id="pyInfoCongs">-</td></tr>
                    <tr><td>Hệ số công</td><td id="pyInfoCoeff">-</td></tr>
                    <tr>
                        <td>Sản lượng tháng này</td>
                        <td>
                            <button type="button" class="py-link-btn" id="pyInfoOutputBtn">-</button>
                            <button type="button" class="py-adjust-btn" id="pyInfoAdjustBtn" title="Điều chỉnh cộng/trừ sản lượng"><i class="fa-solid fa-sliders"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td>Sản lượng SP mới</td>
                        <td><button type="button" class="py-link-btn" id="pyInfoNewProductQty">-</button></td>
                    </tr>
                    <tr><td>SP/NVSX</td><td id="pyInfoI">-</td></tr>
                    <tr><td>Thành tích</td><td id="pyInfoU">-</td></tr>
                </table>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyInfoCloseBtn">Đóng</button>
            </div>
        </div>
    </div>

    <!-- Modal danh sách sản lượng theo sản phẩm -->
    <div class="py-modal-backdrop" id="pyProductListModal">
        <div class="py-modal">
            <div class="py-modal-header">Sản lượng theo sản phẩm — tháng <span id="pyProductListMonthLabel"></span></div>
            <div class="py-modal-body">
                <table class="py-info-table py-product-list-table">
                    <thead><tr><th>Sản phẩm</th><th>Sản lượng</th></tr></thead>
                    <tbody id="pyProductListBody">
                        <tr><td colspan="2" class="py-grid-loading">Đang tải...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyProductListCloseBtn">Đóng</button>
            </div>
        </div>
    </div>

    <!-- Modal giải thích cách tính Thưởng KPI sản lượng -->
    <div class="py-modal-backdrop" id="pyKpiExplainModal">
        <div class="py-modal">
            <div class="py-modal-header" id="pyKpiExplainTitle">Giải thích Thưởng KPI sản lượng</div>
            <div class="py-modal-body" id="pyKpiExplainBody"></div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyKpiExplainCloseBtn">Đóng</button>
            </div>
        </div>
    </div>

    <!-- Modal điều chỉnh sản lượng (cộng/trừ tạm do lệch nhập liệu sản xuất) -->
    <div class="py-modal-backdrop" id="pyAdjustModal">
        <div class="py-modal">
            <div class="py-modal-header">Điều chỉnh sản lượng tháng <span id="pyAdjustMonthLabel"></span></div>
            <div class="py-modal-body">
                <table class="py-info-table">
                    <tr><td>Sản lượng gốc (theo dữ liệu sản xuất)</td><td id="pyAdjustRaw">-</td></tr>
                    <tr><td>Tổng điều chỉnh</td><td id="pyAdjustSum">-</td></tr>
                    <tr><td><b>Sản lượng dùng tính lương</b></td><td id="pyAdjustFinal"><b>-</b></td></tr>
                </table>

                <ul class="py-adjust-list" id="pyAdjustList"></ul>

                <div class="py-adjust-add">
                    <input type="text" id="pyAdjustAmount" placeholder="Số lượng (+/-, vd -20)" inputmode="decimal">
                    <input type="text" id="pyAdjustNote" placeholder="Lý do điều chỉnh...">
                    <button type="button" class="py-btn" id="pyAdjustAddBtn">Thêm</button>
                </div>
                <p class="py-adjust-hint">Dùng để bù tạm khi phát hiện lệch số liệu nhập kho sản xuất — có thể sửa/xóa bất cứ lúc nào; số cuối cùng sẽ được dùng để tính lương ngay khi lưu.</p>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyAdjustCloseBtn">Đóng</button>
            </div>
        </div>
    </div>

    <!-- Modal Cài đặt -->
    <div class="py-modal-backdrop" id="pySettingsModal">
        <div class="py-modal py-modal-wide">
            <div class="py-modal-header">Cài đặt các số dùng cho tính lương</div>
            <div class="py-modal-body">
                <table class="py-settings-table" id="pySettingsTable">
                    <?php foreach ($settings as $key => $value):
                        $meta  = $settings_meta[$key] ?? ['label' => $key, 'type' => 'text', 'unit' => ''];
                        $type  = $meta['type'];
                        $disp  = $type === 'currency' ? number_format((float) $value, 0, ',', '.') : (string) $value;
                    ?>
                        <tr data-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" data-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
                            <td class="py-settings-label"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-settings-value-cell">
                                <input type="text" class="py-settings-input" value="<?php echo htmlspecialchars($disp, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($meta['unit'] !== ''): ?><span class="py-settings-unit"><?php echo htmlspecialchars($meta['unit'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="py-settings-section">
                    <h4>Phụ cấp chức vụ theo nhân viên</h4>
                    <div class="py-posallow-form">
                        <select id="pyPosAllowEmp">
                            <?php foreach ($employees as $e): ?>
                                <option value="<?php echo (int) $e['id']; ?>"><?php echo htmlspecialchars($e['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="pyPosAllowValue" placeholder="Số tiền phụ cấp" inputmode="decimal" value="0">
                        <button type="button" class="py-btn" id="pyPosAllowSaveBtn">Lưu</button>
                    </div>
                    <ul class="py-posallow-list" id="pyPosAllowList">
                        <?php if (empty($position_allowances)): ?>
                            <li class="py-posallow-empty">Chưa thiết lập phụ cấp chức vụ cho nhân viên nào.</li>
                        <?php else: foreach ($position_allowances as $pa): ?>
                            <li data-emp="<?php echo (int) $pa['employee_id']; ?>">
                                <?php echo htmlspecialchars($pa['full_name'], ENT_QUOTES, 'UTF-8'); ?>:
                                <b><?php echo number_format((float) $pa['position_allowance'], 0, ',', '.'); ?> đ</b>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pySettingsCloseBtn">Đóng</button>
                <button type="button" class="py-btn" id="pySettingsApplyBtn">Áp dụng</button>
            </div>
        </div>
    </div>

    <!-- Modal Lịch sử lương -->
    <div class="py-modal-backdrop" id="pyHistoryModal">
        <div class="py-modal">
            <div class="py-modal-header">Xem lịch sử lương</div>
            <div class="py-modal-body">
                <div class="py-nav-jump" style="justify-content:center;">
                    <select id="pyHistMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>>Tháng <?php echo $m; ?></option>
                        <?php endfor; ?>
                    </select>
                    <select id="pyHistYear">
                        <?php for ($y = $year - 3; $y <= $year + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>>Năm <?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="py-modal-footer">
                <button type="button" class="py-btn py-btn-outline" id="pyHistoryCloseBtn">Đóng</button>
                <button type="button" class="py-btn" id="pyHistoryViewBtn">Xem</button>
            </div>
        </div>
    </div>

    <script>
        window.PY_CONFIG = {
            baseUrl: '?mod=payroll&controllers=payroll&action=',
            year: <?php echo (int) $year; ?>,
            month: <?php echo (int) $month; ?>,
            readOnly: <?php echo $read_only ? 'true' : 'false'; ?>,
            newProductBonusRate: <?php echo json_encode((float) ($settings['payroll.new_product_bonus_rate'] ?? 500)); ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/payroll/payslip.js"></script>
</body>

</html>
