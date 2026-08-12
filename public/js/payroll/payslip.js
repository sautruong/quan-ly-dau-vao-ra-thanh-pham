(function () {
    'use strict';

    var CFG = window.PY_CONFIG || { baseUrl: '?mod=payroll&controllers=payroll&action=', year: 0, month: 0, readOnly: false };
    var state = {
        year: CFG.year,
        month: CFG.month,
        employees: [],
        summary: null,      // monthly_stats (live) hoặc payroll_monthly_summary (lịch sử) — dùng cho modal giải thích KPI
        overrides: {},     // employee_id -> { ot_hours, violation_count, advance_amount, other_bonus, other_bonus_note, bhxh_company, bhxh_employee }
        historyMode: false
    };

    /** i/u dùng chung: live trả 'i'/'u', lịch sử trả 'output_per_cong_i'/'achievement_value_u'. */
    function summaryI() { return state.summary ? Number(state.summary.i != null ? state.summary.i : state.summary.output_per_cong_i) : null; }
    function summaryU() { return state.summary ? Number(state.summary.u != null ? state.summary.u : state.summary.achievement_value_u) : null; }
    function summaryOutputQty() { return state.summary ? Number(state.summary.output_qty) : null; }
    function summaryNewProductQty() { return state.summary ? Number(state.summary.new_product_qty) : null; }
    function summaryCongCoeff() { return state.summary ? Number(state.summary.he_so_cong != null ? state.summary.he_so_cong : state.summary.cong_coefficient) : null; }

    function post(action, params) {
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams(params || {}).toString()
        }).then(function (r) { return r.json(); });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function money(v) {
        if (v === undefined || v === null || v === '') return '—';
        var n = Number(v);
        return n.toLocaleString('vi-VN', { maximumFractionDigits: 0 }) + ' đ';
    }

    function num(v, digits) {
        if (v === undefined || v === null || v === '') return '—';
        return Number(v).toFixed(digits == null ? 0 : digits).replace(/\.0+$/, digits ? '' : '');
    }

    function formatDateVN(isoDate) {
        var s = String(isoDate || '');
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '—';
    }

    /** Input tiền: hiển thị có dấu chấm ngăn cách nghìn, không có bước nhảy (type=text). */
    function moneyInputFormat(v) {
        return (Number(v) || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
    }
    function moneyInputParse(v) {
        var digits = String(v == null ? '' : v).replace(/[^\d]/g, '');
        return digits === '' ? 0 : parseInt(digits, 10);
    }

    function ov(empId, field, def) {
        var o = state.overrides[empId] || {};
        return o[field] !== undefined ? o[field] : def;
    }

    function setOv(empId, field, value) {
        if (!state.overrides[empId]) state.overrides[empId] = {};
        state.overrides[empId][field] = value;
    }

    /* ============================================================
     *  Định nghĩa các dòng của bảng lương (transposed)
     * ============================================================ */
    var ROWS = [
        { key: 'full_name', label: 'Tên nhân viên', kind: 'text', headerRow: true },
        { key: 'job_title', label: 'Chức vụ', kind: 'jobtitle' },
        { key: 'hire_date', label: 'Ngày vào làm', kind: 'hiredate_ro' },
        { key: 'months_worked_p', label: 'Số tháng làm việc', kind: 'num0' },
        { key: 'seniority_coefficient', label: 'Thâm niên (hệ số)', kind: 'num2' },
        { key: '_employment_form', label: 'Hình thức', kind: 'fixed', value: 'NV chính thức' },
        { key: '_bhxh_status', label: 'BHXH', kind: 'fixed', value: 'Đã tham gia' },
        { key: 'base_salary_a', label: 'Lương cơ bản', kind: 'money' },
        { key: 'workdays_in_month_b', label: 'Số ngày làm việc trong tháng', kind: 'num1' },
        { key: 'days_worked_c', label: 'Số ngày đi làm', kind: 'num1' },
        { key: 'holiday_days_d', label: 'Số ngày lễ/tết', kind: 'num1' },
        { key: 'annual_leave_f', label: 'Phép năm', kind: 'num1' },
        { key: 'off_days_g', label: 'Số ngày off', kind: 'num1' },
        { key: 'total_days_h', label: 'Tổng số ngày tính lương', kind: 'num1', boldOnly: true },
        { key: 'base_total_r', label: 'Tổng lương cơ bản', kind: 'money', bold: true, sep: true },

        { key: 'discipline_allow_j', label: 'Phụ cấp kỉ luật', kind: 'money', sep: true },
        { key: 'position_allow_k', label: 'Phụ cấp chức vụ', kind: 'money' },
        { key: 'total_allow_l', label: 'Tổng phụ cấp', kind: 'money', bold: true },

        { key: 'allow_boc_hang', label: 'Phụ cấp bốc hàng', kind: 'money', sep: true },
        { key: 'allow_don_dep', label: 'Phụ cấp dọn dẹp', kind: 'money' },
        { key: 'kpi_o', label: 'Thưởng KPI sản lượng', kind: 'kpi_explain' },
        { key: 'allow_hu_hong', label: 'Hư hỏng/SP lỗi', kind: 'money' },
        { key: 'violation_count_t', label: 'VPNQ (-50K/lần)', kind: 'edit_int', field: 'violation_count' },
        { key: 'kpi_total', label: 'Tổng thưởng KPI', kind: 'money', bold: true },

        { key: 'ot_hours', label: 'Số giờ tăng ca', kind: 'edit_num', field: 'ot_hours', sep: true },
        { key: 'ot_total', label: 'Tổng tiền tăng ca', kind: 'money' },

        { key: 'bhxh_company', label: 'Tiền DN nộp BHXH cho NLĐ', kind: 'money_formula', sep: true },
        { key: 'bhxh_employee', label: 'Tiền NLĐ nộp BHXH', kind: 'money_formula' },
        { key: 'total_with_bhxh', label: 'Tổng lương gồm BHXH', kind: 'money', boldOnly: true },
        { key: 'total_after_bhxh', label: 'Tổng lương sau BHXH', kind: 'money', boldOnly: true },
        { key: 'advance_amount', label: 'Tiền ứng tháng này', kind: 'edit_money', field: 'advance_amount' },
        { key: 'other_bonus', label: 'Thưởng khác', kind: 'edit_bonus', field: 'other_bonus' },
        { key: 'net_pay', label: 'Thực lãnh', kind: 'money', bold: true, highlight: true },
        { key: '_email', label: 'Gửi bảng lương', kind: 'email' }
    ];

    /* ============================================================
     *  Tải dữ liệu (live hoặc lịch sử)
     * ============================================================ */
    function loadLive() {
        state.historyMode = false;
        document.getElementById('pyHistoryBanner').style.display = 'none';
        renderTitle();
        var overridesPayload = {};
        Object.keys(state.overrides).forEach(function (eid) { overridesPayload[eid] = state.overrides[eid]; });
        return post('payslip_data', { year: state.year, month: state.month, overrides: JSON.stringify(overridesPayload) })
            .then(function (res) {
                if (!res || !res.success) return;
                state.employees = res.employees || [];
                state.summary = res.summary || null;
                render();
                showStaleWarning(res.stale);
            });
    }

    /**
     * Cảnh báo SỐ ĐÃ LƯU LỆCH SỐ HIỆN TẠI.
     * Trang này tính LIVE mỗi lần tải, còn MAIL phiếu lương đọc số đã đóng băng lúc bấm Lưu.
     * Hai bên lệch thì mail gửi đi sai mà trước đây không có dấu hiệu gì — đúng tình huống
     * tháng 7/2026: snapshot ghi 02-03/08, luật loại hàng "Mẫu" thêm ngày 05/08.
     */
    function showStaleWarning(stale) {
        var cu = document.getElementById('pyStaleWarn');
        if (cu) cu.remove();
        if (!stale || !stale.length) return;

        var box = document.createElement('div');
        box.id = 'pyStaleWarn';
        box.className = 'py-stale-warn';
        box.innerHTML =
            '<div class="py-stale-head"><i class="fa-solid fa-triangle-exclamation"></i> '
          + 'Số liệu đã thay đổi so với lần lưu gần nhất</div>'
          + '<ul class="py-stale-list">'
          + stale.map(function (d) {
                return '<li>' + d.label + ': đã lưu <b>' + money(d.saved) + '</b>'
                     + ' → hiện tại <b>' + money(d.live) + '</b></li>';
            }).join('')
          + '</ul>'
          + '<p class="py-stale-note">Mail phiếu lương gửi theo <b>số đã lưu</b>, nên hiện đang '
          + 'khác với bảng trên màn hình. Bấm <b>Lưu</b> để cập nhật rồi hãy gửi mail.</p>';

        /* Đặt ngay dưới banner lịch sử — cùng chỗ mà người dùng vốn đã quen nhìn để biết
           trang đang ở trạng thái gì. Không dùng .py-wrap: class đó không tồn tại trong view. */
        var moc = document.getElementById('pyHistoryBanner');
        if (moc && moc.parentNode) moc.parentNode.insertBefore(box, moc.nextSibling);
        else document.body.insertBefore(box, document.body.firstChild);
    }

    function loadHistory(y, m) {
        return post('load_history_month', { year: y, month: m }).then(function (res) {
            if (!res || !res.success || !res.summary) {
                alert('Chưa có lịch sử lương cho tháng ' + m + '/' + y + '.');
                return;
            }
            state.year = y; state.month = m;
            state.historyMode = true;
            state.summary = res.summary;
            state.employees = (res.employees || []).map(function (e) { return Object.assign({}, e, { supported: true }); });
            document.getElementById('pyHistoryBanner').style.display = 'block';
            document.getElementById('pyHistoryModal').classList.remove('show');
            renderTitle();
            render();
        });
    }

    function renderTitle() {
        document.getElementById('pyTitle').textContent = 'Bảng lương tháng ' + state.month + ' năm ' + state.year;
        var link = document.getElementById('pyTimesheetLink');
        if (link) link.href = '?mod=payroll&controllers=payroll&action=timesheet&year=' + state.year + '&month=' + state.month;
    }

    /* ============================================================
     *  Render bảng
     * ============================================================ */
    function render() {
        var tbody = document.getElementById('pyPayslipBody');
        tbody.innerHTML = '';
        if (!state.employees.length) {
            tbody.innerHTML = '<tr><td class="py-grid-loading">Chưa có nhân viên nào ở Trụ sở chính.</td></tr>';
            return;
        }
        ROWS.forEach(function (row) {
            var tr = document.createElement('tr');
            var cls = [];
            if (row.sep) cls.push('py-row-sep');
            if (row.bold) cls.push('py-row-bold');
            if (row.boldOnly) cls.push('py-row-bold-text');
            if (row.highlight) cls.push('py-row-highlight');
            if (row.headerRow) cls.push('py-row-header');
            tr.className = cls.join(' ');

            var th = document.createElement('td');
            th.className = 'py-row-label';
            th.textContent = row.label;
            if (row.kind === 'email' && !CFG.readOnly) {
                var bulkBtn = document.createElement('button');
                bulkBtn.type = 'button';
                bulkBtn.className = 'py-email-btn py-email-bulk-btn';
                bulkBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Gửi tất cả';
                bulkBtn.addEventListener('click', function () { sendAllPayslipEmails(bulkBtn); });
                th.appendChild(document.createElement('br'));
                th.appendChild(bulkBtn);
            }
            tr.appendChild(th);

            state.employees.forEach(function (emp) {
                tr.appendChild(renderCell(row, emp));
            });
            tr.appendChild(renderTotalCell(row));
            tbody.appendChild(tr);
        });
    }

    /** Cột "Tổng cộng" ngoài cùng bên phải — chỉ cộng các dòng tiền (money), các dòng khác để trống. */
    var MONEY_KINDS = ['money', 'kpi_explain', 'money_formula', 'edit_money', 'edit_bonus'];

    function renderTotalCell(row) {
        var td = document.createElement('td');
        td.className = 'py-emp-col py-total-col';
        if (row.headerRow) {
            td.textContent = 'TỔNG CỘNG';
            return td;
        }
        if (MONEY_KINDS.indexOf(row.kind) === -1) {
            td.textContent = '';
            return td;
        }
        var sum = 0;
        var any = false;
        state.employees.forEach(function (emp) {
            if (!emp.supported) return;
            var v = Number(emp[row.key]);
            if (!isNaN(v)) { sum += v; any = true; }
        });
        td.textContent = any ? money(sum) : '—';
        return td;
    }

    function renderCell(row, emp) {
        var td = document.createElement('td');
        td.className = 'py-emp-col';

        if (!emp.supported && row.key !== 'full_name' && row.key !== 'job_title') {
            td.textContent = '—';
            return td;
        }

        var readOnly = state.historyMode || CFG.readOnly;

        switch (row.kind) {
            case 'text':
                td.textContent = emp[row.key] || '';
                break;
            case 'jobtitle':
                if (!emp.supported) {
                    td.innerHTML = esc(emp.job_title || '(trống)') + '<br><span class="py-badge-warning">' + esc(emp.warning || 'Chưa cấu hình lương') + '</span>';
                    td.className += ' py-warning-col';
                } else {
                    td.textContent = emp.job_title || '';
                }
                break;
            case 'fixed':
                td.textContent = row.value;
                break;
            case 'num0': td.textContent = num(emp[row.key], 0); break;
            case 'num1': td.textContent = num(emp[row.key], 1); break;
            case 'num2': td.textContent = num(emp[row.key], 2); break;
            case 'money': td.textContent = money(emp[row.key]); break;

            case 'kpi_explain':
                td.classList.add('py-kpi-cell');
                var kpiWrap = document.createElement('div');
                kpiWrap.className = 'py-kpi-wrap';
                var kpiVal = document.createElement('span');
                kpiVal.textContent = money(emp[row.key]);
                var kpiBtn = document.createElement('button');
                kpiBtn.type = 'button';
                kpiBtn.className = 'py-kpi-explain-btn';
                kpiBtn.title = 'Xem giải thích cách tính';
                kpiBtn.innerHTML = '<i class="fa-solid fa-circle-question"></i>';
                kpiBtn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    openKpiExplain(emp);
                });
                kpiWrap.appendChild(kpiVal);
                kpiWrap.appendChild(kpiBtn);
                td.appendChild(kpiWrap);
                break;

            case 'hiredate_ro':
                td.className += ' py-fixed-cell';
                td.textContent = formatDateVN(emp.hire_date);
                break;

            case 'money_formula':
                td.textContent = money(emp[row.key]);
                td.classList.add('py-has-tooltip');
                td.setAttribute('data-tooltip', 'Lương đóng BHXH ' + money(emp.bhxh_base) + ' × tỷ lệ (xem ở Cài đặt) = ' + money(emp[row.key]));
                break;

            case 'edit_int':
            case 'edit_num':
            case 'edit_money':
            case 'edit_bonus':
                if (readOnly) {
                    if (row.kind === 'edit_money' || row.kind === 'edit_bonus') {
                        var t = money(emp[row.key]);
                        if (row.kind === 'edit_bonus' && emp.other_bonus_note) t += ' (' + emp.other_bonus_note + ')';
                        td.textContent = t;
                    } else {
                        td.textContent = num(emp[row.key], row.kind === 'edit_int' ? 0 : 1);
                    }
                } else {
                    td.className += ' py-input-cell';
                    if (row.kind === 'edit_bonus') {
                        td.classList.add('py-bonus-cell');
                        var curNote = ov(emp.employee_id, 'other_bonus_note', emp.other_bonus_note || '');

                        var amtInput = document.createElement('input');
                        amtInput.type = 'text';
                        amtInput.inputMode = 'decimal';
                        amtInput.value = moneyInputFormat(ov(emp.employee_id, row.field, emp[row.key] || 0));

                        var noteBtn = document.createElement('button');
                        noteBtn.type = 'button';
                        noteBtn.className = 'py-bonus-note-btn' + (curNote ? ' has-note' : '');
                        noteBtn.title = curNote ? ('Ghi chú: ' + curNote) : 'Thêm ghi chú';
                        noteBtn.innerHTML = '<i class="fa-solid fa-note-sticky"></i>';
                        noteBtn.style.display = (moneyInputParse(amtInput.value) > 0) ? '' : 'none';

                        var noteInput = document.createElement('input');
                        noteInput.type = 'text';
                        noteInput.className = 'py-input-note py-bonus-note-input';
                        noteInput.placeholder = 'Ghi chú...';
                        noteInput.value = curNote;
                        noteInput.style.display = 'none';

                        var commit = function () {
                            setOv(emp.employee_id, row.field, moneyInputParse(amtInput.value));
                            setOv(emp.employee_id, 'other_bonus_note', noteInput.value);
                            loadLive();
                        };
                        amtInput.addEventListener('change', function () {
                            var parsed = moneyInputParse(amtInput.value);
                            amtInput.value = moneyInputFormat(parsed);
                            noteBtn.style.display = parsed > 0 ? '' : 'none';
                            commit();
                        });
                        noteBtn.addEventListener('click', function (ev) {
                            ev.stopPropagation();
                            var show = noteInput.style.display === 'none';
                            noteInput.style.display = show ? 'block' : 'none';
                            if (show) noteInput.focus();
                        });
                        noteInput.addEventListener('change', commit);

                        var row1 = document.createElement('div');
                        row1.className = 'py-bonus-row1';
                        row1.appendChild(amtInput);
                        row1.appendChild(noteBtn);
                        td.appendChild(row1);
                        td.appendChild(noteInput);
                    } else if (row.kind === 'edit_money') {
                        var moneyInput = document.createElement('input');
                        moneyInput.type = 'text';
                        moneyInput.inputMode = 'decimal';
                        moneyInput.value = moneyInputFormat(ov(emp.employee_id, row.field, emp[row.key] || 0));
                        moneyInput.addEventListener('change', function () {
                            var parsed = moneyInputParse(moneyInput.value);
                            moneyInput.value = moneyInputFormat(parsed);
                            setOv(emp.employee_id, row.field, parsed);
                            loadLive();
                        });
                        td.appendChild(moneyInput);
                    } else {
                        var input = document.createElement('input');
                        input.type = 'number';
                        input.step = row.kind === 'edit_int' ? '1' : '0.5';
                        if (row.kind === 'edit_int') input.className = 'py-input-int';
                        var defVal = row.autoKey ? (emp[row.key] != null ? emp[row.key] : emp[row.autoKey]) : (emp[row.key] || 0);
                        input.value = ov(emp.employee_id, row.field, defVal);
                        if (row.autoKey) input.title = 'Tự động = ' + money(emp[row.autoKey]) + '. Sửa để ghi đè.';
                        input.addEventListener('change', function () {
                            setOv(emp.employee_id, row.field, input.value);
                            loadLive();
                        });
                        td.appendChild(input);
                    }
                }
                break;

            case 'email':
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'py-email-btn';
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Gửi';
                if (!emp.email) { btn.disabled = true; btn.title = 'Nhân viên chưa có email.'; }
                if (CFG.readOnly) { btn.disabled = true; btn.title = 'Chỉ xem — không thể gửi.'; }
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    post('send_payslip_email', { employee_id: emp.employee_id, year: state.year, month: state.month }).then(function (res) {
                        alert((res && res.message) || (res && res.success ? 'Đã gửi.' : 'Gửi thất bại.'));
                        btn.disabled = false;
                    });
                });
                td.appendChild(btn);
                break;

            default:
                td.textContent = '';
        }
        return td;
    }

    /** Gửi bảng lương lần lượt cho toàn bộ nhân viên hợp lệ (tuần tự, tránh dồn tải mail server). */
    function sendAllPayslipEmails(btn) {
        var targets = state.employees.filter(function (e) { return e.supported && e.email; });
        if (!targets.length) { alert('Không có nhân viên nào có email hợp lệ.'); return; }
        if (!confirm('Gửi bảng lương cho ' + targets.length + ' nhân viên?')) return;

        btn.disabled = true;
        var okCount = 0, failCount = 0;
        var chain = Promise.resolve();
        targets.forEach(function (emp) {
            chain = chain.then(function () {
                return post('send_payslip_email', { employee_id: emp.employee_id, year: state.year, month: state.month })
                    .then(function (res) { if (res && res.success) okCount++; else failCount++; });
            });
        });
        chain.then(function () {
            btn.disabled = false;
            alert('Đã gửi: ' + okCount + ' thành công' + (failCount ? ', ' + failCount + ' thất bại' : '') + '.');
        });
    }

    /* ============================================================
     *  Điều hướng tháng
     * ============================================================ */
    function goToMonth(y, m) {
        if (m < 1) { m = 12; y -= 1; }
        if (m > 12) { m = 1; y += 1; }
        state.year = y; state.month = m;
        state.overrides = {};
        document.getElementById('pyJumpMonth').value = String(m);
        var yearSel = document.getElementById('pyJumpYear');
        if (![].some.call(yearSel.options, function (o) { return Number(o.value) === y; })) {
            var opt = document.createElement('option');
            opt.value = String(y); opt.textContent = 'Năm ' + y;
            yearSel.appendChild(opt);
        }
        yearSel.value = String(y);
        loadLive();
    }

    document.getElementById('pyPrevMonth').addEventListener('click', function () { goToMonth(state.year, state.month - 1); });
    document.getElementById('pyNextMonth').addEventListener('click', function () { goToMonth(state.year, state.month + 1); });
    document.getElementById('pyTodayBtn').addEventListener('click', function () {
        var now = new Date();
        goToMonth(now.getFullYear(), now.getMonth() + 1);
    });
    document.getElementById('pyJumpGo').addEventListener('click', function () {
        var m = parseInt(document.getElementById('pyJumpMonth').value, 10);
        var y = parseInt(document.getElementById('pyJumpYear').value, 10);
        goToMonth(y, m);
    });

    /* ============================================================
     *  Thông tin sản lượng & thành tích tháng + danh sách theo sản phẩm
     * ============================================================ */
    function num2(v) { return v == null ? '-' : Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 2 }); }
    function num0(v) { return v == null ? '-' : Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 0 }); }

    function refreshInfoModal() {
        document.getElementById('pyInfoMonthLabel').textContent = state.month + '/' + state.year;
        return post('monthly_info', { year: state.year, month: state.month }).then(function (res) {
            if (!res || !res.success) return null;
            var s = res.stats;
            document.getElementById('pyInfoCongs').textContent = num2(s.total_congs);
            document.getElementById('pyInfoCoeff').textContent = num2(s.he_so_cong);
            document.getElementById('pyInfoOutputBtn').textContent = num2(s.output_qty);
            document.getElementById('pyInfoNewProductQty').textContent = num2(s.new_product_qty);
            document.getElementById('pyInfoI').textContent = num0(s.i);
            document.getElementById('pyInfoU').textContent = money(s.u);
            return s;
        });
    }

    document.getElementById('pyBtnInfo').addEventListener('click', function () {
        refreshInfoModal().then(function () {
            document.getElementById('pyInfoModal').classList.add('show');
        });
    });
    document.getElementById('pyInfoCloseBtn').addEventListener('click', function () {
        document.getElementById('pyInfoModal').classList.remove('show');
    });
    document.getElementById('pyInfoModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyInfoModal')) document.getElementById('pyInfoModal').classList.remove('show');
    });

    /* ============================================================
     *  Giải thích cách tính Thưởng KPI sản lượng
     * ============================================================ */
    function openKpiExplain(emp) {
        var a = emp.kpi_i != null ? Number(emp.kpi_i) : summaryI();          // SP/NVSX
        var b = emp.kpi_u != null ? Number(emp.kpi_u) : summaryU();          // Thành tích
        var coeff = Number(emp.seniority_coefficient);                       // hệ số thâm niên
        var c = Number(emp.days_worked_c);                                   // số ngày đi làm
        var d = Number(emp.workdays_in_month_b);                             // số ngày làm việc trong tháng
        var oBase = emp.kpi_o_base != null ? Number(emp.kpi_o_base) : (a != null && b != null ? Math.round(a * b * coeff) : null);
        var prorated = (oBase != null && d > 0) ? Math.round(oBase * c / d) : null;
        var newProductBonus = Number(emp.new_product_bonus || 0);
        var finalO = Number(emp.kpi_o);

        var outputQty = summaryOutputQty();
        var congCoeff = summaryCongCoeff();
        var p = Number(emp.months_worked_p);
        var y2 = Math.floor(p / 6);

        var lines = [];
        if (a == null || b == null || oBase == null) {
            lines.push('<p class="py-kpi-explain-note">Không đủ dữ liệu để hiển thị chi tiết (SP/NVSX, thành tích) ở chế độ lịch sử — chỉ hiển thị các bước còn lưu được.</p>');
        } else {
            var aTooltip = (outputQty != null && congCoeff != null)
                ? 'Tổng số sản phẩm / hệ số công = ' + num2(outputQty) + '/' + num2(congCoeff)
                : '';
            var coeffTooltip = 'Số tháng làm việc = ' + p + ' → floor(' + p + '/6) = ' + y2 + ' → 1 + ' + y2 + '×0.05 = ' + num2(coeff);
            lines.push(rowExplain('SP/NVSX', num2(a), { tooltip: aTooltip }));
            lines.push(rowExplain('Thành tích', money(b)));
            lines.push(rowExplain('Hệ số thâm niên', num2(coeff), { tooltip: coeffTooltip }));
            lines.push(rowExplain('KPI sản lượng', num2(a) + ' × ' + money(b) + ' × ' + num2(coeff) + ' = ' + money(oBase)));
        }
        lines.push(rowExplain('Số ngày đi làm', num2(c)));
        lines.push(rowExplain('Số ngày làm việc trong tháng', num2(d)));
        if (oBase != null && prorated != null) {
            lines.push(rowExplain('Quy đổi theo chuyên cần', money(oBase) + ' × ' + num2(c) + ' / ' + num2(d) + ' = ' + money(prorated)));
        }
        if (emp.job_title && emp.job_title.toLowerCase().indexOf('trưởng phòng') !== -1 && newProductBonus > 0) {
            var newProductQty = summaryNewProductQty();
            var rate = Number(CFG.newProductBonusRate || 500);
            var npTooltip = newProductQty != null ? (num2(newProductQty) + ' × ' + money(rate) + ' = ' + money(newProductBonus)) : '';
            lines.push(rowExplain('+ Thưởng sản phẩm mới (Trưởng phòng)', money(newProductBonus), { tooltip: npTooltip }));
        }
        lines.push(rowExplain('<b>Thưởng KPI sản lượng</b>', '<b>' + money(finalO) + '</b>', { highlight: true }));

        document.getElementById('pyKpiExplainTitle').textContent = 'Giải thích Thưởng KPI sản lượng — ' + emp.full_name;
        document.getElementById('pyKpiExplainBody').innerHTML = lines.join('');
        document.getElementById('pyKpiExplainModal').classList.add('show');
    }

    function rowExplain(label, value, opts) {
        opts = opts || {};
        var valueClass = 'py-kpi-explain-value';
        var tooltipAttr = '';
        if (opts.tooltip) {
            valueClass += ' py-has-tooltip';
            tooltipAttr = ' data-tooltip="' + opts.tooltip.replace(/"/g, '&quot;') + '"';
        }
        return '<div class="py-kpi-explain-row' + (opts.highlight ? ' highlight' : '') + '">'
            + '<span class="py-kpi-explain-label">' + label + '</span>'
            + '<span class="' + valueClass + '"' + tooltipAttr + '>' + value + '</span>'
            + '</div>';
    }

    document.getElementById('pyKpiExplainCloseBtn').addEventListener('click', function () {
        document.getElementById('pyKpiExplainModal').classList.remove('show');
    });
    document.getElementById('pyKpiExplainModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyKpiExplainModal')) document.getElementById('pyKpiExplainModal').classList.remove('show');
    });

    function openProductListModal(onlyNew) {
        document.querySelector('#pyProductListModal .py-modal-header').textContent = (onlyNew ? 'Sản phẩm mới — sản lượng tháng ' : 'Sản lượng theo sản phẩm — tháng ')
            + state.month + '/' + state.year;
        var body = document.getElementById('pyProductListBody');
        body.innerHTML = '<tr><td colspan="2" class="py-grid-loading">Đang tải...</td></tr>';
        document.getElementById('pyProductListModal').classList.add('show');
        post('output_by_product', { year: state.year, month: state.month, only_new: onlyNew ? 1 : 0 }).then(function (res) {
            var list = (res && res.success) ? (res.data || []) : [];
            if (!list.length) {
                body.innerHTML = '<tr><td colspan="2" class="py-grid-loading">' + (onlyNew ? 'Chưa có sản lượng sản phẩm mới trong tháng này.' : 'Chưa có sản lượng trong tháng này.') + '</td></tr>';
                return;
            }
            body.innerHTML = '';
            list.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + esc(row.product_name) + '</td><td>' + num2(row.qty) + '</td>';
                body.appendChild(tr);
            });
        });
    }

    document.getElementById('pyInfoOutputBtn').addEventListener('click', function () { openProductListModal(false); });
    document.getElementById('pyInfoNewProductQty').addEventListener('click', function () { openProductListModal(true); });
    document.getElementById('pyProductListCloseBtn').addEventListener('click', function () {
        document.getElementById('pyProductListModal').classList.remove('show');
    });
    document.getElementById('pyProductListModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyProductListModal')) document.getElementById('pyProductListModal').classList.remove('show');
    });

    /* ============================================================
     *  Điều chỉnh sản lượng tháng (cộng/trừ tạm)
     * ============================================================ */
    var adjustEditingId = 0;

    function resetAdjustForm() {
        adjustEditingId = 0;
        document.getElementById('pyAdjustAmount').value = '';
        document.getElementById('pyAdjustNote').value = '';
        document.getElementById('pyAdjustAddBtn').textContent = 'Thêm';
    }

    function renderAdjustList(list) {
        var ul = document.getElementById('pyAdjustList');
        ul.innerHTML = '';
        if (!list.length) {
            ul.innerHTML = '<li class="py-adjust-empty">Chưa có điều chỉnh nào.</li>';
            return;
        }
        list.forEach(function (a) {
            var amt = Number(a.amount);
            var sign = amt >= 0 ? '+' : '';
            var li = document.createElement('li');
            li.innerHTML = '<span class="py-adjust-amt' + (amt < 0 ? ' neg' : '') + '">' + sign + num2(amt) + '</span>'
                + '<span class="py-adjust-note">' + esc(a.note || '') + '</span>'
                + (CFG.readOnly ? '' :
                    '<button type="button" class="py-adjust-edit" title="Sửa"><i class="fa-solid fa-pen"></i></button>'
                    + '<button type="button" class="py-adjust-del" title="Xóa"><i class="fa-solid fa-trash"></i></button>');
            if (!CFG.readOnly) {
                li.querySelector('.py-adjust-edit').addEventListener('click', function () {
                    adjustEditingId = a.id;
                    document.getElementById('pyAdjustAmount').value = a.amount;
                    document.getElementById('pyAdjustNote').value = a.note || '';
                    document.getElementById('pyAdjustAddBtn').textContent = 'Cập nhật';
                });
                li.querySelector('.py-adjust-del').addEventListener('click', function () {
                    if (!confirm('Xóa điều chỉnh này?')) return;
                    post('output_adjustment_delete', { id: a.id }).then(function (res) {
                        renderAdjustList((res && res.data) || []);
                        if (adjustEditingId === a.id) resetAdjustForm();
                        refreshInfoModal();
                        loadLive();
                    });
                });
            }
            ul.appendChild(li);
        });
    }

    function refreshAdjustModal() {
        document.getElementById('pyAdjustMonthLabel').textContent = state.month + '/' + state.year;
        post('monthly_info', { year: state.year, month: state.month }).then(function (res) {
            if (!res || !res.success) return;
            var s = res.stats;
            document.getElementById('pyAdjustRaw').textContent = num2(s.output_qty_raw);
            document.getElementById('pyAdjustSum').textContent = (s.output_adjustment >= 0 ? '+' : '') + num2(s.output_adjustment);
            document.getElementById('pyAdjustFinal').textContent = num2(s.output_qty);
        });
        post('output_adjustments_list', { year: state.year, month: state.month }).then(function (res) {
            renderAdjustList((res && res.data) || []);
        });
    }

    document.getElementById('pyInfoAdjustBtn').addEventListener('click', function () {
        resetAdjustForm();
        refreshAdjustModal();
        document.getElementById('pyAdjustModal').classList.add('show');
    });
    document.getElementById('pyAdjustCloseBtn').addEventListener('click', function () {
        document.getElementById('pyAdjustModal').classList.remove('show');
    });
    document.getElementById('pyAdjustModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyAdjustModal')) document.getElementById('pyAdjustModal').classList.remove('show');
    });
    document.getElementById('pyAdjustAddBtn').addEventListener('click', function () {
        var amount = document.getElementById('pyAdjustAmount').value;
        var note = document.getElementById('pyAdjustNote').value;
        if (amount === '' || isNaN(Number(amount)) || Number(amount) === 0) {
            alert('Nhập số lượng cộng/trừ khác 0 (số âm để trừ).');
            return;
        }
        post('output_adjustment_save', { id: adjustEditingId, year: state.year, month: state.month, amount: amount, note: note }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không thể lưu.'); return; }
            resetAdjustForm();
            renderAdjustList(res.data || []);
            refreshAdjustModal();
            refreshInfoModal();
            loadLive();
        });
    });

    /* ============================================================
     *  Lưu tháng
     * ============================================================ */
    var pyBtnSaveEl = document.getElementById('pyBtnSave');
    if (pyBtnSaveEl) {
        pyBtnSaveEl.addEventListener('click', function () {
            post('check_already_saved', { year: state.year, month: state.month }).then(function (res) {
                var proceed = true;
                if (res && res.already_saved) {
                    proceed = confirm('Bảng lương tháng ' + state.month + '/' + state.year + ' đã được lưu trước đó. Ghi đè?');
                }
                if (!proceed) return;
                var overridesPayload = {};
                Object.keys(state.overrides).forEach(function (eid) { overridesPayload[eid] = state.overrides[eid]; });
                post('save_month', { year: state.year, month: state.month, overrides: JSON.stringify(overridesPayload) }).then(function (r) {
                    if (!r || !r.success) { alert('Lưu thất bại.'); return; }
                    var msg = 'Đã lưu ' + r.saved + ' nhân viên.';
                    if (r.skipped && r.skipped.length) {
                        msg += '\nBỏ qua (chưa cấu hình lương): ' + r.skipped.map(function (s) { return s.full_name; }).join(', ');
                    }
                    alert(msg);
                    loadLive();
                });
            });
        });
    }

    /* ============================================================
     *  Lịch sử lương
     * ============================================================ */
    document.getElementById('pyBtnHistory').addEventListener('click', function () {
        document.getElementById('pyHistoryModal').classList.add('show');
    });
    document.getElementById('pyHistoryCloseBtn').addEventListener('click', function () {
        document.getElementById('pyHistoryModal').classList.remove('show');
    });
    document.getElementById('pyHistoryModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyHistoryModal')) document.getElementById('pyHistoryModal').classList.remove('show');
    });
    document.getElementById('pyHistoryViewBtn').addEventListener('click', function () {
        var m = parseInt(document.getElementById('pyHistMonth').value, 10);
        var y = parseInt(document.getElementById('pyHistYear').value, 10);
        loadHistory(y, m);
    });
    document.getElementById('pyBtnLiveMode').addEventListener('click', function () {
        state.overrides = {};
        loadLive();
    });

    /* ============================================================
     *  Cài đặt
     * ============================================================ */
    document.getElementById('pyBtnSettings').addEventListener('click', function () {
        document.getElementById('pySettingsModal').classList.add('show');
    });
    document.getElementById('pySettingsCloseBtn').addEventListener('click', function () {
        document.getElementById('pySettingsModal').classList.remove('show');
    });
    document.getElementById('pySettingsModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pySettingsModal')) document.getElementById('pySettingsModal').classList.remove('show');
    });
    function saveSettingInput(input) {
        var tr = input.closest('tr');
        var key = tr.getAttribute('data-key');
        var type = tr.getAttribute('data-type');
        var raw = type === 'currency' ? input.value.replace(/[^\d]/g, '') : input.value.trim();
        return post('save_setting', { key: key, value: raw }).then(function (res) {
            if (res && res.success && type === 'currency') {
                input.value = Number(raw || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
            }
            return res;
        });
    }

    document.querySelectorAll('#pySettingsTable input.py-settings-input').forEach(function (input) {
        input.addEventListener('change', function () {
            saveSettingInput(input).then(function (res) {
                if (res && res.success) loadLive();
                else alert((res && res.message) || 'Không thể lưu cài đặt.');
            });
        });
    });

    document.getElementById('pySettingsApplyBtn').addEventListener('click', function (ev) {
        var btn = ev.currentTarget;
        btn.disabled = true;
        var inputs = document.querySelectorAll('#pySettingsTable input.py-settings-input');
        Promise.all([].map.call(inputs, saveSettingInput)).then(function (results) {
            btn.disabled = false;
            var failed = results.filter(function (r) { return !r || !r.success; });
            if (failed.length) {
                alert('Có ' + failed.length + ' cài đặt không lưu được.');
            } else {
                loadLive();
                alert('Đã áp dụng cài đặt vào bảng lương.');
            }
        });
    });

    /* ============================================================
     *  Phụ cấp chức vụ theo nhân viên (trong Cài đặt)
     * ============================================================ */
    function renderPosAllowList(list) {
        var ul = document.getElementById('pyPosAllowList');
        ul.innerHTML = '';
        if (!list || !list.length) {
            ul.innerHTML = '<li class="py-posallow-empty">Chưa thiết lập phụ cấp chức vụ cho nhân viên nào.</li>';
            return;
        }
        list.forEach(function (pa) {
            var li = document.createElement('li');
            li.setAttribute('data-emp', pa.employee_id);
            li.innerHTML = esc(pa.full_name) + ': <b>' + money(pa.position_allowance) + '</b>';
            ul.appendChild(li);
        });
    }

    document.getElementById('pyPosAllowSaveBtn').addEventListener('click', function () {
        var empId = document.getElementById('pyPosAllowEmp').value;
        var value = document.getElementById('pyPosAllowValue').value;
        post('save_position_allowance', { employee_id: empId, value: value }).then(function (res) {
            if (res && res.success) {
                renderPosAllowList(res.data);
                loadLive();
            } else {
                alert((res && res.message) || 'Không thể lưu.');
            }
        });
    });

    /* ============================================================
     *  "Chỉ xem" — khóa các điều khiển ghi/sửa tĩnh (render 1 lần từ PHP)
     * ============================================================ */
    function applyReadOnlyLockdown() {
        if (!CFG.readOnly) return;
        document.querySelectorAll('#pySettingsTable input.py-settings-input').forEach(function (el) { el.disabled = true; });
        var applyBtn = document.getElementById('pySettingsApplyBtn'); if (applyBtn) applyBtn.style.display = 'none';

        var posEmp = document.getElementById('pyPosAllowEmp'); if (posEmp) posEmp.disabled = true;
        var posVal = document.getElementById('pyPosAllowValue'); if (posVal) posVal.disabled = true;
        var posBtn = document.getElementById('pyPosAllowSaveBtn'); if (posBtn) posBtn.style.display = 'none';

        var adjAmt = document.getElementById('pyAdjustAmount'); if (adjAmt) adjAmt.disabled = true;
        var adjNote = document.getElementById('pyAdjustNote'); if (adjNote) adjNote.disabled = true;
        var adjBtn = document.getElementById('pyAdjustAddBtn'); if (adjBtn) adjBtn.style.display = 'none';
    }
    applyReadOnlyLockdown();

    /* ============================================================
     *  Khởi động
     * ============================================================ */
    loadLive();
})();
