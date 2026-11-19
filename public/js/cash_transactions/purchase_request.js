/**
 * View "Đề nghị mua đồ" (cash_transactions/purchase_request) — mobile-first.
 *   Tab "Lập đề nghị": ô tìm/chọn hạng mục → card (SL + đơn vị + ghi chú) → Hủy / Lưu nháp / Gửi đề nghị
 *   Tab "Đơn của tôi": quản lý đơn đã lập
 *   Tab "Duyệt" (admin): chọn nhiều đề nghị → gom → sao chép ảnh → xác nhận "Đã đặt mua"
 * Cần pr_common.js (window.PR) và html2canvas.
 */
(function () {
    'use strict';
    if (!window.PR || !window.PR_BOOT) return;

    var BOOT = window.PR_BOOT;
    var esc = PR.esc;
    PR.setCatalog(BOOT.catalog);

    var $ = function (id) { return document.getElementById(id); };
    var els = {
        panes:   { new: $('pr-pane-new'), mine: $('pr-pane-mine'), review: $('pr-pane-review') },
        footers: { new: $('pr-footer-new'), review: $('pr-footer-review') },
        cards: $('pr-cards'), empty: $('pr-cards-empty'), noteWrap: $('pr-note-wrap'), note: $('pr-note'),
        urgent: $('pr-urgent'), search: $('pr-search'), suggest: $('pr-suggest'), editing: $('pr-editing'),
        mineList: $('pr-mine-list'), reviewList: $('pr-review-list'),
        minePager: $('pr-mine-pager'), reviewPager: $('pr-review-pager'),
        batchBtn: $('pr-batch'), batchN: $('pr-batch-n'), selAll: $('pr-selall'), selAllWrap: $('pr-selall-wrap'),
        btnSaveDraft: $('pr-save-draft'), btnSubmit: $('pr-submit'), btnCancel: $('pr-cancel')
    };

    var state = {
        tab: 'new',
        draft: { id: 0, cards: [] },        // đơn đang soạn (id > 0 = đang sửa đơn nháp/bị từ chối)
        mine: [], minePage: 1,
        review: { mode: 'submitted', list: [], selected: {}, page: 1, orders: {} }   // page: chỉ dùng cho mode 'done'
    };
    var seq = 0;

    /* ============================================================
       TIỆN ÍCH
       ============================================================ */
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
    }
    function fmtQty(n) {
        n = parseFloat(n); if (isNaN(n)) return '';
        return String(Math.round(n * 100) / 100).replace('.', ',');
    }
    function parseQty(s) {
        var v = parseFloat(String(s == null ? '' : s).replace(',', '.'));
        return isNaN(v) ? 0 : v;
    }
    function dmy(d) {
        var t = new Date(String(d || '').replace(' ', 'T'));
        if (isNaN(t)) return '';
        return ('0' + t.getDate()).slice(-2) + '/' + ('0' + (t.getMonth() + 1)).slice(-2) + '/' + t.getFullYear();
    }
    /** Đường dẫn tương đối của ảnh (theo <base> của trang) → URL tuyệt đối. */
    function absUrl(u) {
        try { return new URL(u, document.baseURI).href; } catch (e) { return u; }
    }
    /** "NGUYỄN thành lễ" → "Nguyễn Thành Lễ": chỉ in hoa chữ cái đầu mỗi từ (tên người lập phiếu). */
    function titleCase(s) {
        return String(s || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('vi')
            .replace(/(^|\s)(\S)/g, function (m, sp, ch) { return sp + ch.toLocaleUpperCase('vi'); });
    }
    function thumbHtml(url, cls) {
        return '<span class="' + (cls || 'pr-thumb') + '"' + (url ? ' data-zoom="' + esc(url) + '"' : '') + '>' +
            (url ? '<img src="' + esc(url) + '" alt="" loading="lazy">' : '<i class="fa-regular fa-image"></i>') + '</span>';
    }
    /** Hình trên card "Lập đề nghị": hạng mục trong danh sách → bấm để xem/đổi/gỡ hình; "Đề xuất khác" không có hình. */
    function cardThumb(c) {
        if (!c.item_id) return thumbHtml('', 'pr-thumb');
        return '<span class="pr-thumb pr-thumb-edit" data-photo="1" role="button" tabindex="0" title="' + (c.image_url ? 'Xem / đổi hình' : 'Thêm hình') + '">' +
            (c.image_url ? '<img src="' + esc(c.image_url) + '" alt="" loading="lazy">' : '<i class="fa-regular fa-image"></i>') +
            '<b class="pr-thumb-badge"><i class="fa-solid fa-camera"></i></b></span>';
    }
    function catOrderIndex() {
        var m = {}; PR.catalog.forEach(function (c, i) { m[+c.id] = i; }); return m;
    }

    /* ============================================================
       TAB
       ============================================================ */
    function setBadge(id, n) {
        var b = $(id); if (!b) return;
        b.textContent = n > 99 ? '99+' : n;
        b.hidden = !(n > 0);
    }
    function applyCounts(c) {
        if (!c) return;
        setBadge('pr-badge-mine', c.mine_open || 0);
        setBadge('pr-badge-review', c.review || 0);
    }

    function setTab(tab, silent) {
        if (!els.panes[tab]) tab = 'new';
        state.tab = tab;
        Object.keys(els.panes).forEach(function (k) { if (els.panes[k]) els.panes[k].hidden = (k !== tab); });
        document.querySelectorAll('.pr-tab').forEach(function (b) {
            var on = b.getAttribute('data-tab') === tab;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (els.footers.new) els.footers.new.hidden = !(tab === 'new' && BOOT.canEdit);
        if (els.footers.review) els.footers.review.hidden = !(tab === 'review' && state.review.mode === 'submitted');
        document.body.classList.toggle('pr-has-footer',
            (tab === 'new' && BOOT.canEdit) || (tab === 'review' && state.review.mode === 'submitted'));
        if (!silent) {
            try { history.replaceState(null, '', location.pathname + '?tab=' + tab); } catch (e) {}
        }
        if (tab === 'mine') loadMine(1);          // vào lại tab → về trang 1 (đơn mới nhất)
        if (tab === 'review') loadReview(1);
    }
    document.querySelector('.pr-tabs').addEventListener('click', function (e) {
        var b = e.target.closest('.pr-tab');
        if (b) setTab(b.getAttribute('data-tab'));
    });

    /* ============================================================
       LẬP ĐỀ NGHỊ — card
       ============================================================ */
    function cardHtml(c) {
        return '<article class="pr-card" data-key="' + c.key + '">' +
            '<div class="pr-card-top">' + cardThumb(c) +
                '<div class="pr-card-title"><strong>' + esc(c.name) + '</strong>' +
                    '<small>' + esc(c.category_name || 'Đề xuất khác') + (c.item_id ? '' : ' · ngoài danh sách') + '</small></div>' +
                '<button type="button" class="pr-card-del" aria-label="Bỏ mục này" title="Bỏ mục này">&times;</button>' +
            '</div>' +
            '<div class="pr-card-qty">' +
                '<label>Số lượng<input type="text" inputmode="decimal" class="pr-input pr-qty" autocomplete="off" placeholder="0" value="' + esc(c.qty) + '"></label>' +
                '<label>Đơn vị<input type="text" list="pr-units" class="pr-input pr-unit" autocomplete="off" maxlength="40" placeholder="bịch, cái…" value="' + esc(c.unit) + '"></label>' +
            '</div>' +
            '<input type="text" class="pr-input pr-line-note" maxlength="255" autocomplete="off" placeholder="Ghi chú (nhãn hiệu, quy cách…)" value="' + esc(c.note) + '">' +
            '<div class="pr-card-info" data-info>' + infoHtml(c.info) + '</div>' +
        '</article>';
    }

    function infoHtml(info) {
        if (!info) return '<span class="pr-muted"><i class="fa-solid fa-spinner fa-spin"></i> Đang tra lần mua trước…</span>';
        var h = '';
        if (info.history && info.history.length) {
            h += '<div class="pr-hist"><span class="pr-hist-label">Trước đó</span> ' + info.history.map(function (x) {
                return '<button type="button" class="pr-chip" data-qty="' + esc(x.qty_text) + '" data-unit="' + esc(x.unit) + '" title="Bấm để điền số lượng này">' +
                    esc(x.date) + ': ' + esc(x.qty_text) + ' ' + esc(x.unit) + '</button>';
            }).join('<span class="pr-hist-sep">|</span>') + '</div>';
        } else {
            h += '<div class="pr-hist pr-muted">Chưa từng đặt mua hạng mục này.</div>';
        }
        (info.pending || []).forEach(function (p) {
            h += '<div class="pr-warn"><i class="fa-solid fa-triangle-exclamation"></i> Đang có đề nghị chờ duyệt: ' +
                esc(p.who) + ' (' + esc(p.date) + ') — ' + esc(p.qty_text) + ' ' + esc(p.unit) + '</div>';
        });
        return h;
    }

    function cardByKey(k) {
        for (var i = 0; i < state.draft.cards.length; i++) if (state.draft.cards[i].key === k) return state.draft.cards[i];
        return null;
    }
    function cardEl(key) { return els.cards.querySelector('[data-key="' + key + '"]'); }

    function syncCompose() {
        var n = state.draft.cards.length;
        els.empty.hidden = n > 0;
        els.noteWrap.hidden = n === 0;
        var dis = n === 0 || !BOOT.canEdit;
        els.btnSaveDraft.disabled = dis;
        els.btnSubmit.disabled = dis;
    }

    function addCard(c) {
        c.key = 'c' + (++seq);
        c.qty = c.qty || ''; c.unit = c.unit || ''; c.note = c.note || '';
        state.draft.cards.push(c);
        els.cards.insertAdjacentHTML('beforeend', cardHtml(c));
        syncCompose();
        if (c.item_id && !c.info) {
            PR.get('pr_item_info', { id: c.item_id, exclude: state.draft.id || 0 }).then(function (res) {
                if (!res.ok) return;
                c.info = { history: res.history || [], pending: res.pending || [] };
                var el = cardEl(c.key);
                if (el) {
                    el.querySelector('[data-info]').innerHTML = infoHtml(c.info);
                    // Gợi ý đơn vị theo lần mua gần nhất (chỉ khi người dùng chưa gõ).
                    if (!c.unit && c.info.history.length) {
                        c.unit = c.info.history[0].unit;
                        el.querySelector('.pr-unit').value = c.unit;
                    }
                }
            });
        }
        return c;
    }

    function addItem(itemId) {
        var it = PR.findItem(itemId);
        if (!it) return;
        var dup = state.draft.cards.filter(function (c) { return +c.item_id === +it.id; })[0];
        if (dup) {
            PR.toast('"' + it.name + '" đã có trong đề nghị.', 'err');
            var el = cardEl(dup.key); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var c = addCard({ item_id: +it.id, name: it.name, category_id: +it.category_id, category_name: it.category_name, image_url: it.image_url || '' });
        var el2 = cardEl(c.key);
        if (el2) { el2.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); el2.querySelector('.pr-qty').focus({ preventScroll: true }); }
    }

    els.cards.addEventListener('input', function (e) {
        var el = e.target.closest('.pr-card'); if (!el) return;
        var c = cardByKey(el.getAttribute('data-key')); if (!c) return;
        if (e.target.classList.contains('pr-qty')) {
            var cleaned = e.target.value.replace(/[^\d.,]/g, '');
            if (cleaned !== e.target.value) e.target.value = cleaned;
            c.qty = cleaned;
        } else if (e.target.classList.contains('pr-unit')) c.unit = e.target.value;
        else if (e.target.classList.contains('pr-line-note')) c.note = e.target.value;
        e.target.classList.remove('is-bad');
    });
    els.cards.addEventListener('click', function (e) {
        var el = e.target.closest('.pr-card'); if (!el) return;
        var c = cardByKey(el.getAttribute('data-key')); if (!c) return;
        if (e.target.closest('.pr-card-del')) {
            state.draft.cards = state.draft.cards.filter(function (x) { return x !== c; });
            el.remove(); syncCompose(); return;
        }
        if (e.target.closest('[data-photo]')) { openPhotoModal(c); return; }
        var z = e.target.closest('[data-zoom]');
        if (z) { PR.zoomImage(z.getAttribute('data-zoom')); return; }
        var chip = e.target.closest('.pr-chip');
        if (chip) {
            c.qty = chip.getAttribute('data-qty'); c.unit = chip.getAttribute('data-unit');
            el.querySelector('.pr-qty').value = c.qty; el.querySelector('.pr-unit').value = c.unit;
            el.querySelector('.pr-qty').classList.remove('is-bad'); el.querySelector('.pr-unit').classList.remove('is-bad');
        }
    });

    els.cards.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-photo]')) {
            e.preventDefault(); e.target.click();
        }
    });

    /**
     * Bấm hình trên card → modal XEM hình hạng mục: gỡ hình · chọn tệp / chụp hình khác thay vào · Hủy / Lưu.
     * Hình là của HẠNG MỤC trong danh sách đề nghị (dùng chung mọi người) nên Lưu = ghi vào danh sách.
     */
    function openPhotoModal(c) {
        if (!c.item_id) return;
        if (!BOOT.canEdit) { if (c.image_url) PR.zoomImage(c.image_url); return; }
        var zone = null;
        var m = PR.modal({ title: 'Hình hạng mục', cls: 'pr-modal-photo', onClose: function () { if (zone) zone.destroy(); } });
        m.body.innerHTML =
            '<div class="pr-photo-name"><strong>' + esc(c.name) + '</strong><small>' + esc(c.category_name || '') + '</small></div>' +
            '<div data-zone></div>';
        zone = PR.imageZone(m.body.querySelector('[data-zone]'), { url: c.image_url || '', big: true, removeBtn: true });
        m.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="cancel">Hủy</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="save"><i class="fa-solid fa-floppy-disk"></i> Lưu</button>';

        var busy = false;
        m.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]'); if (!b || busy) return;
            if (b.getAttribute('data-r') === 'cancel') { m.close(); return; }
            if (!zone.changed()) { m.close(); return; }          // không đổi gì → đóng, không gọi máy chủ
            var fd = new FormData();
            fd.append('id', c.item_id);
            zone.appendTo(fd);
            busy = true; b.disabled = true; var old = b.innerHTML;
            b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang lưu…';
            PR.api('pr_item_image', fd).then(function (res) {
                busy = false; b.disabled = false; b.innerHTML = old;
                if (!res.ok) { PR.toast(res.message || 'Không lưu được hình.', 'err'); return; }
                PR.setCatalog(res.catalog);
                var url = (res.item && res.item.image_url) || '';
                // Mọi card đang soạn của cùng hạng mục đều đổi theo.
                state.draft.cards.forEach(function (x) {
                    if (+x.item_id !== +c.item_id) return;
                    x.image_url = url;
                    var el = cardEl(x.key);
                    var th = el && el.querySelector('.pr-card-top > .pr-thumb');
                    if (th) th.outerHTML = cardThumb(x);
                });
                m.close();
                PR.toast(url ? 'Đã lưu hình hạng mục.' : 'Đã gỡ hình hạng mục.');
            });
        });
    }

    /* ---------- Ô tìm kiếm (gợi ý ngay dưới ô) ---------- */
    var sugList = [];
    function renderSuggest() {
        var q = norm(els.search.value.trim());
        if (!q) { els.suggest.hidden = true; return; }
        sugList = [];
        PR.catalog.forEach(function (c) {
            (c.items || []).forEach(function (it) {
                if (norm(it.name).indexOf(q) !== -1 || norm(c.name).indexOf(q) !== -1) sugList.push(it);
            });
        });
        var shown = sugList.slice(0, 8);
        var added = {}; state.draft.cards.forEach(function (c) { if (c.item_id) added[c.item_id] = 1; });
        var h = shown.map(function (it) {
            return '<li data-item="' + it.id + '"' + (added[it.id] ? ' class="is-added"' : '') + '>' +
                thumbHtml(it.image_url, 'pr-sug-thumb') +
                '<span class="pr-sug-name">' + esc(it.name) + '<small>' + esc(it.category_name) + '</small></span>' +
                (added[it.id] ? '<i class="fa-solid fa-check"></i>' : '') + '</li>';
        }).join('');
        if (!shown.length) {
            h = '<li class="pr-sug-none" data-new="1"><i class="fa-solid fa-plus"></i> Chưa có "' + esc(els.search.value.trim()) +
                '" trong danh sách — <b>thêm vào danh sách đề nghị</b></li>';
        }
        els.suggest.innerHTML = h;
        els.suggest.hidden = false;
    }
    els.search.addEventListener('input', renderSuggest);
    els.search.addEventListener('focus', renderSuggest);
    els.search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { els.suggest.hidden = true; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var first = els.suggest.querySelector('li:not(.is-added)');
            if (first) first.click();
        }
    });
    els.suggest.addEventListener('click', function (e) {
        var li = e.target.closest('li'); if (!li) return;
        if (li.getAttribute('data-new')) {
            var name = els.search.value.trim();
            els.search.value = ''; els.suggest.hidden = true;
            addNewItem(name);
            return;
        }
        addItem(li.getAttribute('data-item'));
        els.search.value = ''; els.suggest.hidden = true;
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pr-pick-search')) els.suggest.hidden = true;
    });

    /**
     * "+ Thêm mục mua": thêm THẲNG vào danh sách đề nghị (mục chi + tên + ảnh) rồi đưa món đó vào đề nghị đang soạn.
     * (Trước đây là "Đề xuất khác ngoài danh sách" — nay mọi món mới đều vào danh sách dùng chung.)
     * afterSaved(item) mặc định = thêm card; bộ chọn truyền hàm riêng để chỉ tick chọn món mới.
     */
    function addNewItem(prefillName, afterSaved) {
        if (!BOOT.canEdit) { PR.toast('Bạn chỉ có quyền xem — không thể thêm mục mua.', 'err'); return null; }
        return PR.itemModal({
            title: 'Thêm mục mua',
            name: prefillName || '',
            manageCats: BOOT.canManageCats,
            onSaved: afterSaved || function (it) { addItem(it.id); }
        });
    }
    $('pr-add-item').addEventListener('click', function () { addNewItem(''); });

    /* ---------- Bộ chọn từ danh sách (bottom-sheet, chọn nhiều) ---------- */
    $('pr-open-list').addEventListener('click', function () {
        var picked = {};
        var m = PR.modal({ title: 'Chọn từ danh sách đề nghị', cls: 'pr-modal-sheet' });
        m.body.innerHTML =
            '<div class="pr-pk-tools">' +
                '<div class="pr-ql-search pr-pk-search"><i class="fa-solid fa-magnifying-glass"></i>' +
                '<input type="text" class="pr-input" id="pr-pk-q" placeholder="Lọc nhanh…" autocomplete="off"></div>' +
                (BOOT.canEdit ? '<button type="button" class="pr-btn pr-btn-outline pr-pk-add" id="pr-pk-add" title="Thêm nhanh một mục mua vào danh sách"><i class="fa-solid fa-plus"></i> Thêm</button>' : '') +
            '</div>' +
            '<div class="pr-pk-list" id="pr-pk-list"></div>';
        m.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="close">Đóng</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="ok" disabled>Thêm vào đề nghị (0)</button>';
        var $l = m.body.querySelector('#pr-pk-list'), $q = m.body.querySelector('#pr-pk-q');
        var added = {}; state.draft.cards.forEach(function (c) { if (c.item_id) added[c.item_id] = 1; });

        function refreshFoot() {
            var n = Object.keys(picked).length, b = m.foot.querySelector('[data-r="ok"]');
            b.disabled = n === 0; b.textContent = 'Thêm vào đề nghị (' + n + ')';
        }
        function render() {
            var q = norm($q.value.trim()), h = '';
            PR.catalog.forEach(function (c) {
                var its = (c.items || []).filter(function (it) { return !q || norm(it.name).indexOf(q) !== -1; });
                if (!its.length) return;
                h += '<div class="pr-pk-cat"><div class="pr-pk-cat-h">' + esc(c.name) + '</div>';
                its.forEach(function (it) {
                    var on = picked[it.id] || added[it.id];
                    h += '<div class="pr-pk-item' + (on ? ' is-on' : '') + (added[it.id] ? ' is-added' : '') + '" data-item="' + it.id + '">' +
                        thumbHtml(it.image_url, 'pr-pk-thumb') +
                        '<span class="pr-pk-name">' + esc(it.name) + (added[it.id] ? '<small>Đã có trong đề nghị</small>' : '') + '</span>' +
                        '<span class="pr-pk-check"><i class="fa-solid fa-check"></i></span>' +
                        // Thùng rác (cạnh phải ô tick): gỡ món khỏi danh sách. Món đang nằm trong đề nghị này thì không gỡ được.
                        ((BOOT.canEdit && !added[it.id])
                            ? '<button type="button" class="pr-icon-btn pr-icon-danger pr-pk-del" aria-label="Gỡ khỏi danh sách" title="Gỡ khỏi danh sách đề nghị"><i class="fa-solid fa-trash"></i></button>'
                            : '') +
                        '</div>';
                });
                h += '</div>';
            });
            $l.innerHTML = h || '<div class="pr-ql-empty">Không có hạng mục nào' + (q ? ' khớp.' : '.') +
                (BOOT.canEdit ? ' Bấm <b>+ Thêm</b> để thêm mục mua.' : '') + '</div>';
        }
        render(); refreshFoot();
        $q.addEventListener('input', render);

        // + Thêm: thêm nhanh 1 mục mua vào danh sách ngay tại đây; xong thì tự tick chọn món vừa thêm.
        var $add = m.body.querySelector('#pr-pk-add');
        if ($add) $add.addEventListener('click', function () {
            addNewItem($q.value.trim(), function (it) {
                picked[it.id] = 1;
                $q.value = '';
                render(); refreshFoot();
                var row = $l.querySelector('[data-item="' + it.id + '"]');
                if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        });

        $l.addEventListener('click', function (e) {
            var del = e.target.closest('.pr-pk-del');
            if (del) {
                e.stopPropagation();
                var drow = del.closest('.pr-pk-item'), did = drow.getAttribute('data-item');
                var it = PR.findItem(did); if (!it) return;
                PR.confirm('Gỡ "' + it.name + '" khỏi danh sách đề nghị? Các đề nghị đang chờ vẫn giữ nguyên.',
                    { okText: 'Gỡ', danger: true, title: 'Gỡ khỏi danh sách' }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_item_delete', { id: did }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không gỡ được.', 'err'); return; }
                        PR.setCatalog(res.catalog);
                        delete picked[did];
                        render(); refreshFoot();
                        PR.toast('Đã gỡ "' + it.name + '" khỏi danh sách.');
                    });
                });
                return;
            }
            var row = e.target.closest('.pr-pk-item');
            if (!row || row.classList.contains('is-added')) return;
            var id = row.getAttribute('data-item');
            if (picked[id]) delete picked[id]; else picked[id] = 1;
            row.classList.toggle('is-on', !!picked[id]);
            refreshFoot();
        });
        m.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]'); if (!b) return;
            if (b.getAttribute('data-r') === 'ok') {
                Object.keys(picked).forEach(function (id) { addItem(id); });
            }
            m.close();
        });
    });

    /* ---------- Soạn / Hủy / Lưu nháp / Gửi ---------- */
    function resetCompose() {
        state.draft = { id: 0, cards: [] };
        els.cards.innerHTML = '';
        els.note.value = '';
        els.urgent.checked = false;
        els.editing.hidden = true; els.editing.innerHTML = '';
        syncCompose();
    }

    function editRequest(r) {
        resetCompose();
        state.draft.id = r.id;
        els.note.value = r.note || '';
        els.urgent.checked = !!r.is_urgent;
        r.lines.forEach(function (l) {
            // Hạng mục đã bị gỡ khỏi danh sách → giữ lại như dòng "Đề xuất khác" để không mất ý định của người lập.
            var live = l.item_id ? PR.findItem(l.item_id) : null;
            addCard({
                item_id: live ? l.item_id : 0, name: l.item_name,
                category_id: live ? l.category_id : (l.category_id || 0), category_name: l.category_name,
                image_url: l.image_url || '', qty: fmtQty(l.qty), unit: l.unit, note: l.note
            });
        });
        els.editing.innerHTML = '<i class="fa-solid fa-pen-to-square"></i><div><b>Đang sửa đơn ' + esc(r.code) + '</b>' +
            (r.status === 'rejected' && r.reject_reason ? '<span class="pr-editing-why">Bị từ chối: ' + esc(r.reject_reason) + '</span>' : '') +
            '</div><button type="button" class="pr-link-btn" id="pr-editing-off">Bỏ sửa</button>';
        els.editing.hidden = false;
        $('pr-editing-off').addEventListener('click', function () { resetCompose(); });
        setTab('new');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    els.btnCancel.addEventListener('click', function () {
        if (!state.draft.cards.length && !state.draft.id) { resetCompose(); return; }
        PR.confirm('Bỏ đề nghị đang soạn? Những gì đã nhập sẽ mất (đơn nháp đã lưu trước đó vẫn còn).',
            { okText: 'Bỏ đề nghị', cancelText: 'Tiếp tục soạn', danger: true, title: 'Hủy soạn' }).then(function (yes) {
            if (yes) resetCompose();
        });
    });

    function payload() {
        return {
            id: state.draft.id || 0, is_urgent: els.urgent.checked ? 1 : 0, note: els.note.value.trim(),
            lines: state.draft.cards.map(function (c) {
                return { item_id: c.item_id || 0, name: c.name, category_id: c.category_id || 0,
                         qty: parseQty(c.qty), unit: c.unit.trim(), note: c.note.trim() };
            })
        };
    }

    function validateForSubmit() {
        var bad = null;
        state.draft.cards.forEach(function (c) {
            var el = cardEl(c.key);
            var qOk = parseQty(c.qty) > 0, uOk = c.unit.trim() !== '';
            el.querySelector('.pr-qty').classList.toggle('is-bad', !qOk);
            el.querySelector('.pr-unit').classList.toggle('is-bad', !uOk);
            if ((!qOk || !uOk) && !bad) bad = el;
        });
        if (bad) {
            PR.toast('Nhập đủ số lượng và đơn vị cho từng mục.', 'err');
            bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    function save(submit) {
        if (!state.draft.cards.length) return;
        if (submit && !validateForSubmit()) return;
        var go = function () {
            var btn = submit ? els.btnSubmit : els.btnSaveDraft;
            var old = btn.innerHTML;
            els.btnSaveDraft.disabled = els.btnSubmit.disabled = true;
            btn.textContent = 'Đang lưu…';
            PR.api('pr_request_save', { payload: JSON.stringify(payload()), submit: submit ? 1 : 0 }).then(function (res) {
                btn.innerHTML = old; syncCompose();
                if (!res.ok) { PR.toast(res.message || 'Không lưu được đề nghị.', 'err'); return; }
                applyCounts(res.counts);
                PR.toast(submit ? 'Đã gửi đề nghị ' + res.request.code + ' cho người duyệt.' : 'Đã lưu nháp ' + res.request.code + '.');
                resetCompose();
                setTab('mine');
            });
        };
        if (submit) {
            PR.confirm('Gửi đề nghị gồm ' + state.draft.cards.length + ' mục cho người duyệt?',
                { okText: 'Gửi đề nghị', cancelText: 'Chưa gửi', title: 'Gửi đề nghị' }).then(function (yes) { if (yes) go(); });
        } else go();
    }
    els.btnSaveDraft.addEventListener('click', function () { save(false); });
    els.btnSubmit.addEventListener('click', function () { save(true); });

    /* ============================================================
       THẺ ĐƠN (dùng cho "Đơn của tôi" và "Duyệt")
       ============================================================ */
    function requestCard(r, o) {
        o = o || {};
        var lines = r.lines || [];
        var LIMIT = 4;
        var li = lines.map(function (l, i) {
            return '<li class="' + (l.dropped ? 'is-dropped ' : '') + (i >= LIMIT ? 'pr-more' : '') + '">' +
                thumbHtml(l.image_url, 'pr-li-thumb') +
                '<span class="pr-li-name">' + esc(l.item_name) +
                    (l.note ? '<small>' + esc(l.note) + '</small>' : '') + '</span>' +
                '<b class="pr-li-qty">' + esc(l.qty_text) + ' ' + esc(l.unit) + '</b></li>';
        }).join('');
        var more = lines.length > LIMIT
            ? '<button type="button" class="pr-link-btn pr-toggle-more" data-more="' + (lines.length - LIMIT) + '">+ ' + (lines.length - LIMIT) + ' mục nữa</button>' : '';

        var acts = '';
        if (o.review) {
            if (r.status === 'submitted') acts = '<button type="button" class="pr-btn pr-btn-sm pr-btn-danger-o" data-act="reject">Từ chối</button>';
        } else if (r.status === 'draft') {
            acts = '<button type="button" class="pr-btn pr-btn-sm pr-btn-outline" data-act="edit"><i class="fa-solid fa-pen"></i> Sửa</button>' +
                   '<button type="button" class="pr-btn pr-btn-sm pr-btn-danger-o" data-act="delete">Xóa nháp</button>';
        } else if (r.status === 'rejected') {
            acts = '<button type="button" class="pr-btn pr-btn-sm pr-btn-primary" data-act="edit"><i class="fa-solid fa-pen"></i> Sửa &amp; gửi lại</button>' +
                   '<button type="button" class="pr-btn pr-btn-sm pr-btn-danger-o" data-act="delete">Xóa</button>';
        } else if (r.status === 'submitted') {
            acts = '<button type="button" class="pr-btn pr-btn-sm pr-btn-danger-o" data-act="cancel">Hủy đề nghị</button>';
        } else if (r.status === 'cancelled') {
            acts = '<button type="button" class="pr-btn pr-btn-sm pr-btn-danger-o" data-act="delete">Xóa</button>';
        }

        var sel = (o.review && o.selectable)
            ? '<label class="pr-req-sel"><input type="checkbox" data-sel' + (state.review.selected[r.id] ? ' checked' : '') + '></label>' : '';
        var meta = (o.review ? esc(r.user_name) + ' · ' : '') + esc(r.date_text) + ' · ' + lines.length + ' mục';
        var foot = '';
        if (r.status === 'ordered') foot = '<div class="pr-req-ok"><i class="fa-solid fa-circle-check"></i> Đã đặt mua ' + esc(dmy(r.decided_at)) + (r.decided_by ? ' · ' + esc(r.decided_by) : '') + '</div>';
        if (r.status === 'rejected') foot = '<div class="pr-req-bad"><i class="fa-solid fa-circle-xmark"></i> Từ chối: ' + esc(r.reject_reason) + (o.review && r.decided_by ? ' <small>(' + esc(r.decided_by) + ')</small>' : '') + '</div>';

        return '<article class="pr-req st-' + r.status + (sel && state.review.selected[r.id] ? ' is-picked' : '') + '" data-id="' + r.id + '">' +
            '<header class="pr-req-h">' + sel +
                '<div class="pr-req-title"><strong>' + esc(r.code) + '</strong>' +
                    '<span class="pr-status pr-st-' + r.status + '">' + esc(r.status_label) + '</span>' +
                    (r.is_urgent ? '<span class="pr-urgent-tag"><i class="fa-solid fa-bolt"></i> GẤP</span>' : '') + '</div>' +
                '<div class="pr-req-meta">' + meta + '</div>' +
            '</header>' +
            '<ul class="pr-req-lines">' + li + '</ul>' + more +
            (r.note ? '<div class="pr-req-note"><i class="fa-regular fa-comment"></i> ' + esc(r.note) + '</div>' : '') +
            foot +
            (acts ? '<footer class="pr-req-actions">' + acts + '</footer>' : '') +
        '</article>';
    }

    function wireRequestList(container, getList, o) {
        container.addEventListener('click', function (e) {
            var card = e.target.closest('.pr-req'); if (!card) return;
            var id = +card.getAttribute('data-id');
            var r = getList().filter(function (x) { return +x.id === id; })[0]; if (!r) return;

            var more = e.target.closest('.pr-toggle-more');
            if (more) {
                var open = card.classList.toggle('is-open');
                more.textContent = open ? 'Thu gọn' : '+ ' + more.getAttribute('data-more') + ' mục nữa';
                return;
            }
            var z = e.target.closest('[data-zoom]');
            if (z) { PR.zoomImage(z.getAttribute('data-zoom')); return; }
            var b = e.target.closest('[data-act]'); if (!b) return;
            var act = b.getAttribute('data-act');

            if (act === 'edit') { editRequest(r); return; }
            if (act === 'delete') {
                PR.confirm('Xóa đơn ' + r.code + '?', { okText: 'Xóa', danger: true }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_request_delete', { id: id }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không xóa được.', 'err'); return; }
                        applyCounts(res.counts); loadMine(); PR.toast('Đã xóa đơn.');
                    });
                });
            } else if (act === 'cancel') {
                PR.confirm('Hủy đề nghị ' + r.code + '? Người duyệt sẽ không còn thấy đơn này.',
                    { okText: 'Hủy đề nghị', cancelText: 'Giữ lại', danger: true }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_request_cancel', { id: id }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không hủy được.', 'err'); return; }
                        applyCounts(res.counts); loadMine(); PR.toast('Đã hủy đề nghị.');
                    });
                });
            } else if (act === 'reject') {
                PR.ask('Từ chối ' + r.code, { multiline: true, placeholder: 'Lý do (bắt buộc) — người đề nghị sẽ nhận được', okText: 'Từ chối' }).then(function (reason) {
                    if (reason === null) return;
                    PR.api('pr_request_reject', { id: id, reason: reason }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không từ chối được.', 'err'); return; }
                        applyCounts(res.counts); delete state.review.selected[id]; loadReview(); PR.toast('Đã từ chối và báo cho người đề nghị.');
                    });
                });
            }
        });
    }

    /* ============================================================
       ĐƠN CỦA TÔI
       ============================================================ */
    /* ---------- Phân trang (dùng chung "Đơn của tôi" + "Đã xử lý (30 ngày)") ---------- */
    /**
     * p = {page, pages, total, per_page} do máy chủ trả. Gọn cho điện thoại: « ‹ [tối đa 3 số quanh trang hiện tại] › »
     * (« » chỉ hiện khi > 3 trang) — tối đa 7 nút 40px, vừa khung 375px. Dưới nút có dòng "Đơn 11–20 / 87".
     */
    function pagerHtml(p) {
        if (!p || !(p.pages > 1)) return '';
        var cur = p.page, last = p.pages;
        var from = (cur - 1) * p.per_page + 1, to = Math.min(p.total, cur * p.per_page);
        var start = Math.max(1, Math.min(cur - 1, last - 2)), end = Math.min(last, start + 2);
        function btn(page, label, o) {
            o = o || {};
            return '<button type="button" class="pr-pg-btn' + (o.on ? ' is-on' : '') + '" data-page="' + page + '"' +
                (o.off ? ' disabled' : '') + (o.on ? ' aria-current="page"' : '') + (o.aria ? ' aria-label="' + o.aria + '"' : '') + '>' + label + '</button>';
        }
        var h = '<nav class="pr-pager" aria-label="Phân trang">';
        if (last > 3) h += btn(1, '<i class="fa-solid fa-angles-left"></i>', { off: cur <= 1, aria: 'Trang đầu' });
        h += btn(cur - 1, '<i class="fa-solid fa-chevron-left"></i>', { off: cur <= 1, aria: 'Trang trước' });
        for (var i = start; i <= end; i++) h += btn(i, i, { on: i === cur });
        h += btn(cur + 1, '<i class="fa-solid fa-chevron-right"></i>', { off: cur >= last, aria: 'Trang sau' });
        if (last > 3) h += btn(last, '<i class="fa-solid fa-angles-right"></i>', { off: cur >= last, aria: 'Trang cuối' });
        return h + '</nav><div class="pr-pg-info">Đơn ' + from + '–' + to + ' / ' + p.total + '</div>';
    }
    /** Bấm nút trang trong container → go(số trang) rồi cuộn lên đầu danh sách. */
    function wirePager(container, go) {
        if (!container) return;
        container.addEventListener('click', function (e) {
            var b = e.target.closest('.pr-pg-btn');
            if (!b || b.disabled) return;
            var n = parseInt(b.getAttribute('data-page'), 10);
            if (n >= 1) { go(n); window.scrollTo({ top: 0, behavior: 'smooth' }); }
        });
    }

    /** page: số trang cần tải; bỏ trống = giữ trang hiện tại (sau khi xóa/hủy đơn — máy chủ tự lùi nếu trang đó không còn). */
    function loadMine(page) {
        if (page) state.minePage = page;
        els.minePager.innerHTML = '';
        els.mineList.innerHTML = '<div class="pr-loading"><i class="fa-solid fa-spinner fa-spin"></i> Đang tải…</div>';
        PR.get('pr_my_requests', { page: state.minePage }).then(function (res) {
            if (!res.ok) { els.mineList.innerHTML = '<div class="pr-empty"><p>' + esc(res.message || 'Không tải được.') + '</p></div>'; return; }
            state.mine = res.requests || [];
            state.minePage = res.page || 1;
            applyCounts(res.counts);
            els.mineList.innerHTML = state.mine.length
                ? state.mine.map(function (r) { return requestCard(r); }).join('')
                : '<div class="pr-empty"><i class="fa-regular fa-folder-open"></i><p>Bạn chưa lập đề nghị nào.<br>Qua tab <b>Lập đề nghị</b> để bắt đầu.</p></div>';
            els.minePager.innerHTML = pagerHtml(res);
        });
    }
    wireRequestList(els.mineList, function () { return state.mine; });
    wirePager(els.minePager, function (n) { loadMine(n); });

    /* ============================================================
       DUYỆT (admin)
       ============================================================ */
    function updateBatchBtn() {
        if (!els.batchBtn) return;
        var n = Object.keys(state.review.selected).length;
        els.batchN.textContent = n;
        els.batchBtn.disabled = n === 0;
        if (els.selAll) {
            var pending = state.review.list.filter(function (r) { return r.status === 'submitted'; });
            els.selAll.checked = pending.length > 0 && n === pending.length;
        }
    }

    /** page: chỉ có nghĩa ở mode 'done' (Đã xử lý) — "Chờ duyệt" là hàng đợi làm việc nên không phân trang. */
    function loadReview(page) {
        if (!els.reviewList) return;
        var mode = state.review.mode;
        if (page) state.review.page = page;
        els.reviewPager.innerHTML = '';
        els.reviewList.innerHTML = '<div class="pr-loading"><i class="fa-solid fa-spinner fa-spin"></i> Đang tải…</div>';
        PR.get('pr_review_list', mode === 'done' ? { mode: mode, page: state.review.page } : { mode: mode }).then(function (res) {
            if (!res.ok) { els.reviewList.innerHTML = '<div class="pr-empty"><p>' + esc(res.message || 'Không tải được.') + '</p></div>'; return; }
            applyCounts(res.counts);
            if (mode === 'done') state.review.page = res.page || 1;
            var sub = mode === 'submitted';
            // "Đã xử lý": mỗi PHIẾU GOM = 1 thẻ (các đề nghị đã gom cùng một lần đặt mua); đề nghị bị từ chối = từng thẻ riêng.
            var entries = sub ? [] : (res.entries || []);
            state.review.list = sub ? (res.requests || [])
                : entries.filter(function (e) { return e.kind === 'request'; }).map(function (e) { return e.request; });
            state.review.orders = {};
            entries.forEach(function (e) { if (e.kind === 'order') state.review.orders[e.order.id] = e.order; });
            // Bỏ chọn những đơn không còn chờ duyệt (đã bị hủy/xử lý bởi người khác).
            var alive = {}; state.review.list.forEach(function (r) { if (r.status === 'submitted') alive[r.id] = 1; });
            Object.keys(state.review.selected).forEach(function (id) { if (!alive[id]) delete state.review.selected[id]; });

            els.selAllWrap.hidden = !(sub && state.review.list.length);
            var html = sub
                ? state.review.list.map(function (r) { return requestCard(r, { review: true, selectable: true }); }).join('')
                : entries.map(function (e) {
                    return e.kind === 'order' ? orderCard(e.order) : requestCard(e.request, { review: true, selectable: false });
                }).join('');
            els.reviewList.innerHTML = html ||
                '<div class="pr-empty"><i class="fa-regular fa-circle-check"></i><p>' +
                    (sub ? 'Không có đề nghị nào đang chờ duyệt.' : 'Chưa có đề nghị nào được xử lý trong 30 ngày qua.') + '</p></div>';
            els.reviewPager.innerHTML = sub ? '' : pagerHtml(res);
            updateBatchBtn();
        });
    }
    if (els.reviewList) {
        wireRequestList(els.reviewList, function () { return state.review.list; });
        // Thẻ PHIẾU GOM (tab "Đã xử lý"): bấm vào thẻ / nút "Xem lại phiếu" → mở phiếu để sao chép ảnh / tải PDF.
        els.reviewList.addEventListener('click', function (e) {
            var card = e.target.closest('.pr-ord'); if (!card) return;
            var o = state.review.orders[card.getAttribute('data-order')]; if (!o) return;
            var more = e.target.closest('.pr-toggle-more');
            if (more) {
                var open = card.classList.toggle('is-open');
                more.textContent = open ? 'Thu gọn' : '+ ' + more.getAttribute('data-more') + ' mục nữa';
                return;
            }
            var z = e.target.closest('[data-zoom]');
            if (z) { PR.zoomImage(z.getAttribute('data-zoom')); return; }
            openOrderView(o);
        });
        wirePager(els.reviewPager, function (n) { loadReview(n); });
        els.reviewList.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-sel]'); if (!cb) return;
            var card = cb.closest('.pr-req'), id = card.getAttribute('data-id');
            if (cb.checked) state.review.selected[id] = 1; else delete state.review.selected[id];
            card.classList.toggle('is-picked', cb.checked);
            updateBatchBtn();
        });
        $('pr-review-seg').addEventListener('click', function (e) {
            var b = e.target.closest('.pr-seg-btn'); if (!b) return;
            state.review.mode = b.getAttribute('data-mode');
            document.querySelectorAll('.pr-seg-btn').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            els.footers.review.hidden = state.review.mode !== 'submitted';
            document.body.classList.toggle('pr-has-footer', state.review.mode === 'submitted');
            loadReview(1);
        });
        els.selAll.addEventListener('change', function () {
            state.review.selected = {};
            if (els.selAll.checked) state.review.list.forEach(function (r) { if (r.status === 'submitted') state.review.selected[r.id] = 1; });
            els.reviewList.querySelectorAll('.pr-req').forEach(function (card) {
                var on = !!state.review.selected[card.getAttribute('data-id')];
                var cb = card.querySelector('[data-sel]'); if (cb) cb.checked = on;
                card.classList.toggle('is-picked', on);
            });
            updateBatchBtn();
        });
        els.batchBtn.addEventListener('click', openBatch);
    }

    /* ============================================================
       GOM ĐỀ NGHỊ → SAO CHÉP ẢNH → XÁC NHẬN ĐẶT MUA
       ============================================================ */
    function openBatch() {
        var reqs = state.review.list.filter(function (r) { return state.review.selected[r.id]; });
        if (!reqs.length) return;

        // Dòng phẳng, mỗi dòng giữ line_id để server cập nhật đúng SL / cờ gỡ.
        var rows = [];
        reqs.forEach(function (r) {
            r.lines.forEach(function (l) {
                rows.push({ line_id: l.id, req_id: r.id, code: r.code, who: r.user_name, urgent: r.is_urgent,
                    item_id: l.item_id, item_name: l.item_name, category_id: l.category_id, category_name: l.category_name,
                    unit: l.unit, qty: l.qty, qty_text: l.qty_text, note: l.note, image_url: l.image_url, dropped: false });
            });
        });

        var m = PR.modal({ title: 'Gom danh sách đặt mua', cls: 'pr-modal-lg pr-modal-batch' });
        m.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="back">Quay lại</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="copy"><i class="fa-regular fa-copy"></i> Sao chép ảnh</button>';

        function keyOf(r) { return (r.item_id > 0 ? 'i' + r.item_id : 'n' + r.item_name.toLowerCase()); }

        function renderBatch() {
            var order = catOrderIndex(), cats = {}, catKeys = [];
            rows.forEach(function (r) {
                var ck = r.category_id > 0 ? 'c' + r.category_id : 'x';
                if (!cats[ck]) { cats[ck] = { name: r.category_id > 0 ? r.category_name : 'Đề xuất khác', order: order[r.category_id] != null ? order[r.category_id] : 1e9, items: {}, itemKeys: [] }; catKeys.push(ck); }
                var ik = keyOf(r);
                if (!cats[ck].items[ik]) { cats[ck].items[ik] = []; cats[ck].itemKeys.push(ik); }
                cats[ck].items[ik].push(r);
            });
            catKeys.sort(function (a, b) { return cats[a].order - cats[b].order; });

            var live = rows.filter(function (r) { return !r.dropped; }).length;
            var h = '<div class="pr-batch-sum">' + reqs.length + ' đề nghị · ' + rows.length + ' dòng' +
                (live !== rows.length ? ' · <b>' + (rows.length - live) + ' dòng đã gỡ</b>' : '') + '</div>';

            catKeys.forEach(function (ck) {
                var c = cats[ck];
                h += '<section class="pr-batch-cat"><h4>' + esc(c.name) + '</h4>';
                c.itemKeys.forEach(function (ik) {
                    var ls = c.items[ik], first = ls[0];
                    // Tổng theo đơn vị (chỉ dòng chưa gỡ).
                    var tot = {};
                    ls.forEach(function (r) { if (!r.dropped) { var u = r.unit.toLowerCase(); tot[u] = tot[u] || { qty: 0, unit: r.unit }; tot[u].qty += parseQty(r.qty); } });
                    var totTxt = Object.keys(tot).map(function (u) { return fmtQty(tot[u].qty) + ' ' + tot[u].unit; }).join(' + ');
                    var dupN = ls.length;
                    h += '<div class="pr-batch-item"><div class="pr-batch-item-h">' + thumbHtml(first.image_url, 'pr-li-thumb') +
                        '<strong>' + esc(first.item_name) + '</strong>' +
                        (dupN > 1 ? '<span class="pr-dup">Trùng ' + dupN + ' đề nghị</span>' : '') +
                        (totTxt ? '<span class="pr-total">Tổng ' + esc(totTxt) + '</span>' : '<span class="pr-total pr-muted">đã gỡ</span>') + '</div>';
                    ls.forEach(function (r) {
                        h += '<div class="pr-batch-line' + (r.dropped ? ' is-dropped' : '') + '" data-line="' + r.line_id + '">' +
                            '<span class="pr-bl-who">' + esc(r.who) + '<small>' + esc(r.code) + (r.urgent ? ' · GẤP' : '') + '</small></span>' +
                            '<input type="text" inputmode="decimal" class="pr-input pr-bl-qty" value="' + esc(fmtQty(r.qty)) + '"' + (r.dropped ? ' disabled' : '') + '>' +
                            '<span class="pr-bl-unit">' + esc(r.unit) + '</span>' +
                            (r.dropped
                                ? '<button type="button" class="pr-icon-btn" data-act="undo" title="Hoàn lại dòng này"><i class="fa-solid fa-rotate-left"></i></button>'
                                : '<button type="button" class="pr-icon-btn pr-icon-danger" data-act="drop" title="Gỡ dòng này khỏi đợt mua"><i class="fa-solid fa-xmark"></i></button>') +
                            (r.note ? '<small class="pr-bl-note">' + esc(r.note) + '</small>' : '') +
                            '</div>';
                    });
                    h += '</div>';
                });
                h += '</section>';
            });
            m.body.innerHTML = h;
        }
        renderBatch();

        function rowOf(el) {
            var id = +el.closest('.pr-batch-line').getAttribute('data-line');
            return rows.filter(function (r) { return r.line_id === id; })[0];
        }
        m.body.addEventListener('input', function (e) {
            if (!e.target.classList.contains('pr-bl-qty')) return;
            var cleaned = e.target.value.replace(/[^\d.,]/g, '');
            if (cleaned !== e.target.value) e.target.value = cleaned;
            var r = rowOf(e.target); if (r) { r.qty = parseQty(cleaned); }
        });
        // Cập nhật dòng "Tổng" khi rời ô SL (vẽ lại cả danh sách giữa lúc đang gõ sẽ mất con trỏ).
        m.body.addEventListener('change', function (e) { if (e.target.classList.contains('pr-bl-qty')) renderBatch(); });
        m.body.addEventListener('click', function (e) {
            var z = e.target.closest('[data-zoom]');
            if (z) { PR.zoomImage(z.getAttribute('data-zoom')); return; }
            var b = e.target.closest('[data-act]'); if (!b) return;
            var r = rowOf(b); if (!r) return;
            r.dropped = b.getAttribute('data-act') === 'drop';
            renderBatch();
        });

        m.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]'); if (!b) return;
            if (b.getAttribute('data-r') === 'back') { m.close(); return; }

            // Mỗi đơn phải còn ≥1 dòng chưa gỡ, mọi SL > 0.
            for (var i = 0; i < reqs.length; i++) {
                var alive = rows.filter(function (r) { return r.req_id === reqs[i].id && !r.dropped; }).length;
                if (!alive) { PR.toast('Đơn ' + reqs[i].code + ' đã bị gỡ hết mục — hoàn lại một dòng hoặc bỏ chọn đơn đó.', 'err'); return; }
            }
            if (rows.some(function (r) { return !r.dropped && !(parseQty(r.qty) > 0); })) {
                PR.toast('Có dòng số lượng bằng 0 — nhập lại hoặc gỡ dòng đó.', 'err'); return;
            }
            openPreview(reqs, rows, m);
        });
    }

    /* ---------- Gộp dòng (đúng như libraries/purchase_request.php::pr_merge_lines) ---------- */
    function mergeRows(rows) {
        var acc = {}, keys = [];
        rows.forEach(function (r) {
            if (r.dropped) return;
            var k = (r.item_id > 0 ? 'i' + r.item_id : 'n' + r.item_name.toLowerCase()) + '|' + r.unit.toLowerCase();
            if (!acc[k]) { acc[k] = { category_id: r.category_id, category_name: r.category_name, item_id: r.item_id, item_name: r.item_name, unit: r.unit, qty: 0, notes: [], image_url: r.image_url, urgent: false }; keys.push(k); }
            acc[k].qty += parseQty(r.qty);
            if (r.note && acc[k].notes.indexOf(r.note) === -1) acc[k].notes.push(r.note);
            if (r.urgent) acc[k].urgent = true;
        });
        var order = catOrderIndex();
        var out = keys.map(function (k) { return acc[k]; });
        out.sort(function (a, b) {
            var ca = order[a.category_id] != null ? order[a.category_id] : 1e9, cb = order[b.category_id] != null ? order[b.category_id] : 1e9;
            if (ca !== cb) return ca - cb;
            var ia = a.item_id > 0 ? a.item_id : 1e12, ib = b.item_id > 0 ? b.item_id : 1e12;
            return ia !== ib ? ia - ib : a.item_name.localeCompare(b.item_name, 'vi');
        });
        return out;
    }

    /**
     * HTML tờ phiếu gửi người đi mua — BỐ CỤC THEO "PHIẾU XUẤT KHO" của hệ thống (sales_issue.js::buildPrintHtml):
     * khối thông tin đơn vị → tiêu đề in hoa → "Ngày … tháng … năm …" → bảng viền đen → chữ ký "Người lập phiếu".
     * Nội dung: STT · hình thumbnail · mua gì · đơn vị tính · số lượng, nhóm theo MỤC CHI (mỗi mục chi 1 dòng tiêu đề nhóm).
     * Chủ chốt 19/11: không cần giống phiếu giấy mẫu, không ghi chú/mã; sau đó chủ yêu cầu thêm hình thumbnail
     * của món (đủ lớn để nhìn rõ) vào cả ảnh chụp lẫn PDF — món chưa có hình thì để trống ô hình.
     */
    function sheetHtml(merged, opts) {
        opts = opts || {};
        var P = BOOT.print || {};
        var groups = [], idx = {};
        merged.forEach(function (m) {
            var k = m.category_id > 0 ? 'c' + m.category_id : 'x';
            if (idx[k] == null) { idx[k] = groups.length; groups.push({ name: m.category_id > 0 ? m.category_name : 'Đề xuất khác', items: [] }); }
            groups[idx[k]].items.push(m);
        });
        // Ngày trên phiếu: mặc định hôm nay; xem lại phiếu gom cũ thì dùng ngày đặt mua + người đã lập phiếu đó.
        var when = opts.date ? new Date(String(opts.date).replace(' ', 'T')) : new Date();
        if (isNaN(when)) when = new Date();
        var dd = ('0' + when.getDate()).slice(-2), mm = ('0' + (when.getMonth() + 1)).slice(-2);
        var signer = opts.signer != null ? opts.signer : ((BOOT.me && BOOT.me.name) || '');

        var n = 0, rowsHtml = '';
        groups.forEach(function (g) {
            rowsHtml += '<tr class="pr-sheet-grp"><td colspan="5">' + esc(g.name) + '</td></tr>';
            g.items.forEach(function (m) {
                // Hình thumbnail của món (chủ chốt 19/11: ảnh chụp/PDF phải thấy hình món đồ để người đi mua nhận ra).
                // Dùng ảnh NỀN `contain` chứ KHÔNG dùng <img object-fit>: html2canvas 1.4.1 không hiểu object-fit nên kéo giãn ảnh
                // dọc cho vừa ô vuông (bị bóp). background-size: contain thì có hỗ trợ → ảnh được phóng ĐỀU ngang dọc, cả món nằm
                // gọn trong khung vuông ở bản xem trước, ảnh chụp lẫn PDF.
                // URL phải TUYỆT ĐỐI: html2canvas chụp trong iframe nhân bản không có thẻ <base> của trang → đường dẫn tương đối
                // ("public/uploads/…") bị hiểu theo /cash_transactions/ và ra 404 → ô hình trống trên ảnh chụp/PDF.
                var thumb = m.image_url ? '<span class="pr-sheet-thumb" style="background-image:url(\'' + esc(absUrl(m.image_url)) + '\')"></span>' : '';
                // Ghi chú người đề nghị nhập cho món (nhãn hiệu, quy cách…): mergeRows gộp ở m.notes[], phiếu gom đã lưu ở m.note.
                var note = String(m.note || (m.notes && m.notes.join('; ')) || '').trim();
                rowsHtml += '<tr><td class="c">' + (++n) + '</td>' +
                    '<td class="img">' + thumb + '</td>' +
                    '<td>' + esc(m.item_name) +
                        (note ? '<div class="pr-sheet-note"><span>Ghi chú:</span> ' + esc(note) + '</div>' : '') + '</td>' +
                    '<td class="c">' + esc(m.unit) + '</td>' +
                    '<td class="num">' + esc(fmtQty(m.qty)) + '</td></tr>';
            });
        });

        // Ghi chú CHUNG của từng đề nghị (vd "Cần trước thứ Sáu"), kèm tên người đề nghị vì phiếu có thể gom nhiều người.
        var gn = (opts.notes || []).filter(function (x) { return x && String(x.text || '').trim() !== ''; });
        var gnHtml = gn.length
            ? '<div class="pr-sheet-gnotes"><div class="pr-sheet-gnotes-h">Ghi chú:</div><ul>' + gn.map(function (x) {
                return '<li>' + (x.who ? '<b>' + esc(x.who) + ':</b> ' : '') + esc(x.text) + '</li>';
            }).join('') + '</ul></div>'
            : '';

        return '<div class="pr-sheet">' +
            '<table class="pr-sheet-info">' +
                '<tr><td class="lab">Đơn vị đề nghị:</td><td>' + esc(P.company_name || '') + '</td></tr>' +
                '<tr><td class="lab">Địa chỉ:</td><td>' + esc(P.company_address || '') + '</td></tr>' +
            '</table>' +
            '<h2 class="pr-sheet-title">PHIẾU ĐỀ NGHỊ MUA ĐỒ</h2>' +
            '<div class="pr-sheet-date">Ngày ' + dd + ' tháng ' + mm + ' năm ' + when.getFullYear() + '</div>' +
            '<table class="pr-sheet-table"><thead><tr>' +
                '<th style="width:8%">STT</th><th style="width:17%">Hình</th><th>Tên hàng cần mua</th><th style="width:15%">Đơn vị tính</th><th style="width:14%">Số lượng</th>' +
            '</tr></thead><tbody>' + rowsHtml + '</tbody></table>' +
            gnHtml +
            '<div class="pr-sheet-sign"><div class="signer">' +
                '<div class="role">Người lập phiếu</div><div class="sub">(Ký tên)</div>' +
                '<div class="name">' + esc(titleCase(signer)) + '</div>' +
            '</div></div></div>';
    }

    /* ---------- Tờ phiếu: bản xem trước + Sao chép ảnh / Tải PDF ---------- */

    /** Ghi chú CHUNG của các đề nghị → in lên phiếu kèm tên người đề nghị. items: [{note, user_name}] */
    function generalNotes(items) {
        return (items || []).filter(function (r) { return r && String(r.note || '').trim() !== ''; })
            .map(function (r) { return { who: r.user_name || '', text: String(r.note).trim() }; });
    }

    /**
     * Chạy 1 thao tác xuất tờ phiếu: act = 'pdf' | 'copy'. Tự khóa/mở các nút ở chân modal và báo kết quả bằng toast.
     * Trả Promise<'clipboard'|'download'|'pdf'|null> (null = lỗi, đã báo).
     */
    function exportSheet(act, merged, opts, foot, btn) {
        var all = Array.prototype.slice.call(foot.querySelectorAll('.pr-btn')), old = btn.innerHTML;
        all.forEach(function (x) { x.disabled = true; });
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (act === 'pdf' ? 'Đang tạo PDF…' : 'Đang tạo ảnh…');
        return (act === 'pdf' ? downloadSheetPdf(merged, opts) : copySheetImage(merged, opts)).then(function (how) {
            all.forEach(function (x) { x.disabled = false; }); btn.innerHTML = old;
            if (how === 'nolib') { PR.toast('Chưa tải được thư viện tạo PDF (cần mạng) — thử lại hoặc dùng "Sao chép ảnh".', 'err'); return null; }
            if (how === 'fail') { PR.toast(act === 'pdf' ? 'Không tạo được PDF — thử lại.' : 'Không tạo được ảnh — thử lại.', 'err'); return null; }
            PR.toast(how === 'clipboard'
                ? 'Đã sao chép ảnh. Mở Zalo/Messenger và dán (Ctrl+V) để gửi người đi mua.'
                : how === 'pdf'
                    ? 'Đã tải file PDF về máy. Gửi file này cho người đi mua.'
                    : 'Trình duyệt không cho sao chép ảnh — đã tải file PNG về máy.');
            return how;
        });
    }

    /** Bản xem trước TRƯỚC khi đặt mua (từ modal gom): xuất xong thì hỏi "Xác nhận gửi đặt mua". */
    function openPreview(reqs, rows, batchModal) {
        var merged = mergeRows(rows);
        var opts = { notes: generalNotes(reqs) };
        var pv = PR.modal({ title: 'Xem trước ảnh danh sách', cls: 'pr-modal-lg pr-modal-preview' });
        pv.body.innerHTML = '<div class="pr-preview-wrap">' + sheetHtml(merged, opts) + '</div>';
        pv.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="back">Sửa lại</button>' +
            '<button type="button" class="pr-btn pr-btn-outline" data-r="pdf"><i class="fa-regular fa-file-pdf"></i> Tải PDF</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="copy"><i class="fa-regular fa-copy"></i> Sao chép ảnh</button>';

        var busy = false;
        pv.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]'); if (!b) return;
            var act = b.getAttribute('data-r');
            if (act === 'back') { pv.close(); return; }
            if (busy) return;
            busy = true;
            exportSheet(act, merged, opts, pv.foot, b).then(function (how) {
                busy = false;
                if (!how) return;
                // Bước chốt: chỉ khi admin xác nhận đã gửi, các đề nghị mới đổi sang "Đã đặt mua".
                PR.confirm((how === 'pdf' ? 'File PDF' : 'Ảnh danh sách') + ' đã sẵn sàng. Xác nhận GỬI ĐẶT MUA ' + reqs.length + ' đề nghị này? Các đề nghị sẽ chuyển sang "Đã đặt mua" và người đề nghị được báo.',
                    { title: 'Xác nhận gửi đặt mua', okText: 'Xác nhận đã gửi', cancelText: 'Chưa gửi' }).then(function (yes) {
                    if (!yes) return;
                    confirmOrder(reqs, rows, pv, batchModal);
                });
            });
        });
    }

    function confirmOrder(reqs, rows, pv, batchModal) {
        PR.api('pr_order_confirm', {
            request_ids: JSON.stringify(reqs.map(function (r) { return r.id; })),
            adjust: JSON.stringify(rows.map(function (r) { return { line_id: r.line_id, qty: parseQty(r.qty), dropped: r.dropped ? 1 : 0 }; }))
        }).then(function (res) {
            if (!res.ok) { PR.toast(res.message || 'Không xác nhận được.', 'err'); return; }
            applyCounts(res.counts);
            pv.close(); batchModal.close();
            state.review.selected = {};
            loadReview();
            PR.toast('Đã chuyển ' + reqs.length + ' đề nghị sang "Đã đặt mua".');
        });
    }

    /**
     * Thẻ PHIẾU GOM ở tab "Đã xử lý (30 ngày)": các đề nghị đã gom cùng một lần đặt mua hiện thành MỘT thẻ
     * (danh sách đã gộp lúc xác nhận + các đề nghị trong phiếu). Bấm vào thẻ / nút "Xem lại phiếu" → mở phiếu để sao chép ảnh / tải PDF.
     */
    function orderCard(o) {
        var lines = o.lines || [], LIMIT = 4;
        var li = lines.map(function (l, i) {
            return '<li class="' + (i >= LIMIT ? 'pr-more' : '') + '">' +
                thumbHtml(l.image_url, 'pr-li-thumb') +
                '<span class="pr-li-name">' + esc(l.item_name) + (l.note ? '<small>' + esc(l.note) + '</small>' : '') + '</span>' +
                '<b class="pr-li-qty">' + esc(l.qty_text) + ' ' + esc(l.unit) + '</b></li>';
        }).join('');
        var more = lines.length > LIMIT
            ? '<button type="button" class="pr-link-btn pr-toggle-more" data-more="' + (lines.length - LIMIT) + '">+ ' + (lines.length - LIMIT) + ' mục nữa</button>' : '';
        var chips = (o.requests || []).map(function (r) {
            return '<span class="pr-ord-chip"><b>' + esc(r.code) + '</b> · ' + esc(r.user_name) + '</span>';
        }).join('');
        return '<article class="pr-req pr-ord st-ordered" data-order="' + o.id + '">' +
            '<header class="pr-req-h">' +
                '<div class="pr-req-title"><strong><i class="fa-solid fa-layer-group"></i> Phiếu gom #' + o.id + '</strong>' +
                    '<span class="pr-status pr-st-ordered">Đã đặt mua</span></div>' +
                '<div class="pr-req-meta">' + esc(o.date_text) + ' ' + esc(o.time_text) + ' · ' + esc(o.by_name) + ' · ' +
                    o.request_count + ' đề nghị · ' + o.line_count + ' mục</div>' +
            '</header>' +
            '<ul class="pr-req-lines">' + li + '</ul>' + more +
            (chips ? '<div class="pr-ord-reqs"><span class="pr-ord-reqs-h">Gồm:</span>' + chips + '</div>' : '') +
            '<footer class="pr-req-actions"><button type="button" class="pr-btn pr-btn-sm pr-btn-primary" data-act="view-order">' +
                '<i class="fa-regular fa-eye"></i> Xem lại phiếu</button></footer>' +
        '</article>';
    }

    /**
     * Xem lại PHIẾU GOM đã đặt mua: dựng lại đúng tờ phiếu từ danh sách đã đóng băng lúc gom (ngày đặt mua + người lập phiếu +
     * ghi chú), có Sao chép ảnh / Tải PDF. KHÔNG có bước "Xác nhận đặt mua" vì phiếu đã đặt mua rồi.
     */
    function openOrderView(o) {
        var opts = { date: o.created_at, signer: o.by_name, notes: generalNotes(o.requests), name: 'phieu-gom-' + o.id };
        var pv = PR.modal({ title: 'Phiếu gom #' + o.id, cls: 'pr-modal-lg pr-modal-preview' });
        pv.body.innerHTML =
            '<div class="pr-ord-info"><i class="fa-solid fa-circle-check"></i> Đã đặt mua ' + esc(o.date_text) + ' ' + esc(o.time_text) +
                ' · ' + esc(o.by_name) + ' · ' + o.request_count + ' đề nghị</div>' +
            '<div class="pr-preview-wrap">' + sheetHtml(o.lines || [], opts) + '</div>';
        pv.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="close">Đóng</button>' +
            '<button type="button" class="pr-btn pr-btn-outline" data-r="pdf"><i class="fa-regular fa-file-pdf"></i> Tải PDF</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="copy"><i class="fa-regular fa-copy"></i> Sao chép ảnh</button>';
        var busy = false;
        pv.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]'); if (!b) return;
            var act = b.getAttribute('data-r');
            if (act === 'close') { pv.close(); return; }
            if (busy) return;
            busy = true;
            exportSheet(act, o.lines || [], opts, pv.foot, b).then(function () { busy = false; });
        });
    }

    /**
     * Dựng tờ phiếu ở khổ CỐ ĐỊNH (ngoài màn hình) rồi chụp — ảnh luôn cùng kích thước dù xem trên máy nào.
     * Trả Promise<{canvas, breaks}>: breaks = vị trí (px của canvas) cuối mỗi dòng bảng, để cắt trang PDF không xẻ đôi dòng.
     */
    function renderSheetCanvas(merged, scale, opts) {
        if (typeof html2canvas === 'undefined') return Promise.reject(new Error('html2canvas'));
        var host = document.createElement('div');
        host.className = 'pr-sheet-capture';
        host.innerHTML = sheetHtml(merged, opts);
        document.body.appendChild(host);
        var sheet = host.firstElementChild;

        // Nạp trước ảnh thumbnail (ảnh NỀN của .pr-sheet-thumb) cho tới khi xong (kể cả lỗi) để không bị ô trống trên ảnh chụp.
        var urls = Array.prototype.map.call(host.querySelectorAll('.pr-sheet-thumb'), function (el) {
            var m = /url\(["']?(.*?)["']?\)/.exec(el.style.backgroundImage || '');
            return m ? m[1] : '';
        }).filter(Boolean);
        return Promise.all(urls.map(function (u) {
            return new Promise(function (r) { var im = new Image(); im.onload = im.onerror = r; im.src = u; });
        })).then(function () {
            scale = scale || 2;
            var top = sheet.getBoundingClientRect().top;
            var breaks = Array.prototype.slice.call(sheet.querySelectorAll('tr, .pr-sheet-gnotes-h, .pr-sheet-gnotes li')).map(function (tr) {
                return Math.round((tr.getBoundingClientRect().bottom - top) * scale);
            });
            return html2canvas(sheet, { scale: scale, backgroundColor: '#ffffff', useCORS: true })
                .then(function (canvas) { return { canvas: canvas, breaks: breaks }; });
        }).then(function (r) { host.remove(); return r; }, function (err) { host.remove(); throw err; });
    }

    function fileStamp(d) {
        var t = d ? new Date(String(d).replace(' ', 'T')) : new Date();
        if (isNaN(t)) t = new Date();
        return t.getFullYear() + ('0' + (t.getMonth() + 1)).slice(-2) + ('0' + t.getDate()).slice(-2);
    }

    /**
     * Sao chép ảnh phiếu vào clipboard (ClipboardItem với Promise<Blob> để giữ "user gesture" trong lúc html2canvas chạy — Safari bắt buộc).
     * Trình duyệt không cho (HTTP / thiếu API) → tải file PNG. Trả Promise<'clipboard'|'download'|'fail'>.
     */
    function copySheetImage(merged, opts) {
        var blobP = renderSheetCanvas(merged, 2, opts).then(function (r) {
            return new Promise(function (res, rej) { r.canvas.toBlob(function (b) { b ? res(b) : rej(new Error('blob')); }, 'image/png'); });
        });

        function download() {
            return blobP.then(function (blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = ((opts && opts.name) || 'de-nghi-mua-do') + '-' + fileStamp(opts && opts.date) + '.png';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
                return 'download';
            }).catch(function () { return 'fail'; });
        }

        if (navigator.clipboard && window.ClipboardItem) {
            try {
                return navigator.clipboard.write([new ClipboardItem({ 'image/png': blobP })])
                    .then(function () { return 'clipboard'; })
                    .catch(function () { return download(); });
            } catch (e) { return download(); }
        }
        return download();
    }

    /**
     * Tải phiếu dạng PDF (A4 dọc, lề 10mm). Phiếu được chụp ở scale 3 rồi đặt vào trang như ảnh — nhờ vậy giữ đúng chữ
     * tiếng Việt, bảng và hình thumbnail y như bản xem trước. Phiếu dài hơn 1 trang thì cắt TẠI RANH GIỚI DÒNG.
     * Trả Promise<'pdf'|'nolib'|'fail'>.
     */
    function downloadSheetPdf(merged, opts) {
        if (!(window.jspdf && window.jspdf.jsPDF)) return Promise.resolve('nolib');
        return renderSheetCanvas(merged, 3, opts).then(function (r) {
            var canvas = r.canvas, W = canvas.width, H = canvas.height;
            var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
            var mg = 10, usableW = 210 - mg * 2, usableH = 297 - mg * 2;
            var pxPerMm = W / usableW, sliceH = Math.floor(usableH * pxPerMm);

            var y = 0, first = true;
            while (y < H) {
                var end = Math.min(H, y + sliceH);
                if (end < H) {
                    var cut = 0;                                  // dòng bảng cuối cùng còn vừa trang này
                    r.breaks.forEach(function (bk) { if (bk > y + 40 && bk <= end && bk > cut) cut = bk; });
                    if (cut) end = cut;
                }
                var h = end - y;
                var part = document.createElement('canvas');
                part.width = W; part.height = h;
                var ctx = part.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, h);
                ctx.drawImage(canvas, 0, y, W, h, 0, 0, W, h);
                if (!first) doc.addPage();
                // JPEG 0.92: phiếu có ảnh chụp món — PNG làm file phình ~4 lần mà nhìn không khác.
                doc.addImage(part.toDataURL('image/jpeg', 0.92), 'JPEG', mg, mg, usableW, h / pxPerMm, undefined, 'FAST');
                first = false;
                y = end;
            }
            doc.save(((opts && opts.name) || 'de-nghi-mua-do') + '-' + fileStamp(opts && opts.date) + '.pdf');
            return 'pdf';
        }).catch(function () { return 'fail'; });
    }

    /* ============================================================
       KHỞI TẠO
       ============================================================ */
    applyCounts(BOOT.counts);
    syncCompose();
    var t0 = BOOT.startTab || (BOOT.isApprover && (BOOT.counts.review || 0) > 0 ? 'review' : 'new');
    setTab(t0, true);
})();
