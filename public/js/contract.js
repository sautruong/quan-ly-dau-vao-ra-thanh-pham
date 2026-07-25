/* Hợp đồng lao động — Print + Save word + sửa-tại-chỗ BÊN A / Giám đốc. */
(function () {
    'use strict';

    // ---- Print (A4 tự phân trang qua @media print + .page) ----
    var $print = document.querySelector('.print');
    if ($print) $print.addEventListener('click', function () { window.print(); });

    // ---- Save word: xuất .contract ra file .doc (Word mở được) ----
    var $saveWord = document.querySelector('.save-word');
    if ($saveWord) $saveWord.addEventListener('click', saveAsWord);

    function safeFileName(name) {
        return String(name || 'hop-dong')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/đ/g, 'd').replace(/Đ/g, 'D')
            .replace(/[^a-zA-Z0-9]/g, '-').replace(/-+/g, '-')
            .toLowerCase();
    }

    function saveAsWord() {
        var contract = document.querySelector('.contract');
        if (!contract) return;
        // Clone để gỡ cây bút + box-shadow trước khi xuất.
        var clone = contract.cloneNode(true);
        clone.querySelectorAll('.c-pen').forEach(function (el) { el.remove(); });
        clone.querySelectorAll('.page').forEach(function (p) {
            p.style.boxShadow = 'none';
            p.style.pageBreakAfter = 'always';
            p.style.margin = '0 auto';
        });

        var header = '<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">' +
            '<head><meta charset="utf-8">' +
            '<style>' +
            '@page { size: A4; margin: 20mm; }' +
            'body { font-family: "Times New Roman", serif; font-size: 13pt; line-height: 1.5; }' +
            '.page { page-break-after: always; }' +
            '.center { text-align: center; } .text-justify { text-align: justify; }' +
            '.italic { font-style: italic; } h1 { text-align:center; font-size: 16pt; }' +
            '.sign { display: flex; justify-content: space-between; }' +
            '</style></head><body>';
        var footer = '</body></html>';
        var html = header + clone.innerHTML + footer;

        var blob = new Blob(['﻿', html], { type: 'application/msword' });
        var name = (typeof employeeName !== 'undefined') ? employeeName : '';
        var today = new Date().toISOString().slice(0, 10);
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'hop-dong-' + safeFileName(name) + '-' + today + '.doc';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    // ---- Sửa-tại-chỗ BÊN A + Giám đốc (lưu dùng dài lâu) ----
    var saveUrl = location.pathname + '?mod=hr&controllers=hr&action=save_contract_setting';

    function enterEdit($wrap) {
        if (!$wrap || $wrap.classList.contains('editing')) return;
        var $txt = $wrap.querySelector('.c-ed-text');
        $wrap.classList.add('editing');
        $txt.dataset.orig = $txt.textContent;
        $txt.setAttribute('contenteditable', 'true');
        $txt.focus();
        var range = document.createRange();
        range.selectNodeContents($txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function syncKey(key, val) {
        document.querySelectorAll('.c-editable[data-ckey="' + key + '"] .c-ed-text').forEach(function ($t) {
            $t.textContent = val;
        });
    }

    function commitEdit($wrap) {
        if (!$wrap || !$wrap.classList.contains('editing')) return;
        var $txt = $wrap.querySelector('.c-ed-text');
        $wrap.classList.remove('editing');
        $txt.removeAttribute('contenteditable');
        var key = $wrap.getAttribute('data-ckey');
        var val = ($txt.textContent || '').replace(/\s+/g, ' ').trim();
        $txt.textContent = val;
        if (key && val !== ($txt.dataset.orig || '')) {
            syncKey(key, val);
            var body = new URLSearchParams();
            body.append('key', key);
            body.append('value', val);
            fetch(saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .catch(function () { console.warn('Lưu cấu hình hợp đồng thất bại.'); });
        }
    }

    document.addEventListener('click', function (e) {
        var $wrap = e.target.closest('.c-editable');
        if ($wrap && !$wrap.classList.contains('editing')) { e.preventDefault(); enterEdit($wrap); }
    });
    document.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('c-ed-text')) {
            commitEdit(e.target.closest('.c-editable'));
        }
    }, true);
    document.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('c-ed-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            var $wrap = e.target.closest('.c-editable');
            e.target.textContent = e.target.dataset.orig || '';
            $wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });
})();
