(function () {
    'use strict';

    var CFG = window.PY_CONFIG || { baseUrl: '?mod=payroll&controllers=payroll&action=', year: 0, month: 0, offReasons: [], readOnly: false };
    var state = {
        year: CFG.year,
        month: CFG.month,
        days: [],
        employees: [],
        offReasons: CFG.offReasons || [],
        popoverTarget: null // { employeeId, date }
    };

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

    function markLabel(mark, isSunday) {
        switch (mark) {
            case 'x': return isSunday ? 'OT' : 'x';
            case 'half': return '0.5x';
            case 'off': return 'off';
            case 'holiday': return 'L';
            case 'dash': return '-';
            default: return '';
        }
    }

    /* ============================================================
     *  Load + render lưới
     * ============================================================ */
    function loadGrid() {
        document.getElementById('pyGridBody').innerHTML = '<tr><td class="py-grid-loading">Đang tải...</td></tr>';
        return post('timesheet_grid', { year: state.year, month: state.month }).then(function (res) {
            if (!res || !res.success) return;
            state.days = res.days || [];
            state.employees = res.employees || [];
            renderTitle();
            renderHead();
            renderBody();
            renderStats(res.stats || {});
        });
    }

    function renderTitle() {
        document.getElementById('pyTitle').textContent = 'Bảng chấm công tháng ' + state.month + ' năm ' + state.year;
        var link = document.getElementById('pyPayslipLink');
        if (link) link.href = '?mod=payroll&controllers=payroll&action=payslip&year=' + state.year + '&month=' + state.month;
    }

    function renderHead() {
        var thead = document.getElementById('pyGridHead');
        var tr = document.createElement('tr');
        tr.innerHTML = '<th class="py-col-name">Tên nhân viên</th><th class="py-col-title">Chức vụ</th>';
        state.days.forEach(function (d) {
            var th = document.createElement('th');
            th.className = 'py-day-col py-day-head' + (d.is_sunday ? ' is-sunday' : '') + (d.is_today ? ' is-today' : '') + (CFG.readOnly ? ' py-readonly' : '');
            th.dataset.date = d.date;
            th.innerHTML = '<span class="py-wd">' + esc(d.weekday) + '</span>' + d.day;
            if (!CFG.readOnly) {
                th.title = 'Bấm để chấm Lễ/Tết cho cả nhóm ngày ' + d.day;
                th.addEventListener('click', function () { onHolidayColumnClick(d); });
            }
            tr.appendChild(th);
        });
        thead.innerHTML = '';
        thead.appendChild(tr);
    }

    function renderBody() {
        var tbody = document.getElementById('pyGridBody');
        tbody.innerHTML = '';
        if (!state.employees.length) {
            tbody.innerHTML = '<tr><td class="py-grid-loading">Chưa có nhân viên nào ở Trụ sở chính.</td></tr>';
            return;
        }
        state.employees.forEach(function (emp) {
            var tr = document.createElement('tr');
            var titleCell = emp.supported
                ? esc(emp.job_title)
                : esc(emp.job_title || '(trống)') + ' <span class="py-badge-warning">Chưa cấu hình lương</span>';
            tr.innerHTML = '<td class="py-col-name">' + esc(emp.full_name) + '</td><td class="py-col-title">' + titleCell + '</td>';
            state.days.forEach(function (d) {
                var cellData = (emp.marks && emp.marks[d.date]) || { mark: '', reason_id: null, reason: '' };
                var mark = cellData.mark;
                var td = document.createElement('td');
                var isFutureBlank = mark === '' && d.is_future;
                var isSundayOt = mark === 'x' && d.is_sunday;
                td.className = 'py-cell' + (mark ? ' py-mark-' + mark : '') + (isSundayOt ? ' py-mark-sunday-ot' : '')
                    + (isFutureBlank ? ' py-cell-future' : '')
                    + (d.is_sunday ? ' is-sunday' : '') + (d.is_today ? ' is-today' : '') + (CFG.readOnly ? ' py-readonly' : '');
                td.textContent = markLabel(mark, d.is_sunday);
                if (mark === 'off' && cellData.reason) {
                    td.classList.add('py-has-tooltip');
                    td.setAttribute('data-tooltip', cellData.reason);
                }
                if (isSundayOt) {
                    td.classList.add('py-has-tooltip');
                    td.setAttribute('data-tooltip', 'Tăng ca chủ nhật');
                }
                // Luôn cho phép bấm (kể cả ngày tương lai) để chấm off trước / chủ nhật tăng ca — trừ khi "chỉ xem".
                if (!CFG.readOnly) {
                    td.addEventListener('click', function (ev) {
                        openCellPopover(ev, emp, d, cellData);
                    });
                }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    function renderStats(stats) {
        document.getElementById('pyStatWorkdays').textContent = round1(stats.b);
        document.getElementById('pyStatCongs').textContent = round1(stats.total_congs);
        document.getElementById('pyStatCoeff').textContent = stats.he_so_cong != null ? Number(stats.he_so_cong).toFixed(3) : '-';
    }

    function round1(v) {
        if (v == null) return '-';
        var n = Number(v);
        return (Math.round(n * 10) / 10).toString();
    }

    /* ============================================================
     *  Popover chấm công 1 ô
     * ============================================================ */
    function openCellPopover(ev, emp, day, cellData) {
        var pop = document.getElementById('pyCellPopover');
        state.popoverTarget = { employeeId: emp.id, date: day.date };
        document.getElementById('pyPopoverTitle').textContent = emp.full_name + ' — ngày ' + day.day + '/' + state.month
            + (day.is_sunday ? ' (Chủ nhật)' : '');
        document.getElementById('pyPopoverXLabel').textContent = day.is_sunday ? 'Đi làm (x) — tính là tăng ca CN' : 'Đủ công (x)';

        document.querySelectorAll('input[name="pyMarkChoice"]').forEach(function (r) {
            r.checked = (r.value === cellData.mark);
        });
        var reasonSel = document.getElementById('pyPopoverReason');
        reasonSel.value = cellData.reason_id ? String(cellData.reason_id) : '';
        toggleReasonWrap();

        var rect = ev.target.getBoundingClientRect();
        pop.style.display = 'flex';
        var popW = 260;
        var left = rect.left;
        if (left + popW > window.innerWidth - 10) left = window.innerWidth - popW - 10;
        if (left < 10) left = 10;
        var top = rect.bottom + 6;
        if (top + 300 > window.innerHeight) top = rect.top - 306;
        if (top < 10) top = 10;
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
        ev.stopPropagation();
    }

    function toggleReasonWrap() {
        var checked = document.querySelector('input[name="pyMarkChoice"]:checked');
        var wrap = document.getElementById('pyPopoverReasonWrap');
        wrap.classList.toggle('show', !!checked && checked.value === 'off');
    }

    function closeCellPopover() {
        document.getElementById('pyCellPopover').style.display = 'none';
        state.popoverTarget = null;
    }

    document.addEventListener('click', function (ev) {
        var pop = document.getElementById('pyCellPopover');
        if (pop.style.display !== 'none' && !pop.contains(ev.target)) closeCellPopover();
    });

    document.getElementById('pyPopoverClose').addEventListener('click', closeCellPopover);

    document.querySelectorAll('input[name="pyMarkChoice"]').forEach(function (r) {
        r.addEventListener('change', toggleReasonWrap);
    });

    document.getElementById('pyPopoverOk').addEventListener('click', function () {
        if (!state.popoverTarget) return;
        var checked = document.querySelector('input[name="pyMarkChoice"]:checked');
        if (!checked) { alert('Vui lòng chọn 1 lựa chọn.'); return; }
        var mark = checked.value;
        var reasonId = mark === 'off' ? (document.getElementById('pyPopoverReason').value || '') : '';
        var target = state.popoverTarget;
        post('set_timesheet_mark', {
            employee_id: target.employeeId,
            date: target.date,
            mark: mark,
            reason_id: reasonId
        }).then(function (res) {
            closeCellPopover();
            if (res && res.success) loadGrid();
            else alert((res && res.message) || 'Không thể lưu.');
        });
    });

    /* ============================================================
     *  Chấm Lễ/Tết cả cột
     * ============================================================ */
    function onHolidayColumnClick(day) {
        var ok = confirm('Chấm "Lễ/Tết" cho TOÀN BỘ nhân viên vào ngày ' + day.day + '/' + state.month + '/' + state.year + '?');
        if (!ok) return;
        post('set_holiday_column', { year: state.year, month: state.month, date: day.date }).then(function (res) {
            if (res && res.success) loadGrid();
            else alert((res && res.message) || 'Không thể chấm Lễ/Tết.');
        });
    }

    /* ============================================================
     *  Điều hướng tháng
     * ============================================================ */
    function goToMonth(y, m) {
        if (m < 1) { m = 12; y -= 1; }
        if (m > 12) { m = 1; y += 1; }
        state.year = y; state.month = m;
        document.getElementById('pyJumpMonth').value = String(m);
        var yearSel = document.getElementById('pyJumpYear');
        if (![].some.call(yearSel.options, function (o) { return Number(o.value) === y; })) {
            var opt = document.createElement('option');
            opt.value = String(y); opt.textContent = 'Năm ' + y;
            yearSel.appendChild(opt);
        }
        yearSel.value = String(y);
        loadGrid();
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
     *  Modal Lý do off (CRUD)
     * ============================================================ */
    function renderReasonList() {
        var ul = document.getElementById('pyReasonList');
        ul.innerHTML = '';
        state.offReasons.forEach(function (r) {
            var li = document.createElement('li');
            li.innerHTML = '<input type="text" value="' + esc(r.reason) + '" data-id="' + r.id + '">'
                + '<button type="button" class="py-reason-del" title="Xóa"><i class="fa-solid fa-trash"></i></button>';
            var input = li.querySelector('input');
            input.addEventListener('change', function () {
                post('off_reason_save', { id: r.id, reason: input.value, sort_order: r.sort_order || 0 }).then(function (res) {
                    if (res && res.success) { state.offReasons = res.data; refreshReasonDropdown(); }
                });
            });
            li.querySelector('.py-reason-del').addEventListener('click', function () {
                if (!confirm('Xóa lý do "' + r.reason + '"?')) return;
                post('off_reason_delete', { id: r.id }).then(function (res) {
                    if (res && res.success) { state.offReasons = res.data; renderReasonList(); refreshReasonDropdown(); }
                });
            });
            ul.appendChild(li);
        });
    }

    function refreshReasonDropdown() {
        var sel = document.getElementById('pyPopoverReason');
        var cur = sel.value;
        sel.innerHTML = '<option value="">-- Lý do (tùy chọn) --</option>';
        state.offReasons.forEach(function (r) {
            var opt = document.createElement('option');
            opt.value = String(r.id);
            opt.textContent = r.reason;
            sel.appendChild(opt);
        });
        sel.value = cur;
    }

    var pyBtnReasonsEl = document.getElementById('pyBtnReasons');
    if (pyBtnReasonsEl) {
        pyBtnReasonsEl.addEventListener('click', function () {
            renderReasonList();
            document.getElementById('pyReasonModal').classList.add('show');
        });
    }
    document.getElementById('pyReasonCloseBtn').addEventListener('click', function () {
        document.getElementById('pyReasonModal').classList.remove('show');
    });
    document.getElementById('pyReasonModal').addEventListener('click', function (ev) {
        if (ev.target === document.getElementById('pyReasonModal')) {
            document.getElementById('pyReasonModal').classList.remove('show');
        }
    });
    document.getElementById('pyReasonAddBtn').addEventListener('click', function () {
        var input = document.getElementById('pyReasonInput');
        var reason = input.value.trim();
        if (!reason) return;
        post('off_reason_save', { id: 0, reason: reason, sort_order: state.offReasons.length }).then(function (res) {
            if (res && res.success) {
                state.offReasons = res.data;
                input.value = '';
                renderReasonList();
                refreshReasonDropdown();
            }
        });
    });

    /* ============================================================
     *  Khởi động
     * ============================================================ */
    loadGrid();
})();
