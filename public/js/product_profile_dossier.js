/* ============================================================
   product_profile_dossier.js
   Nút "Tạo bộ hồ sơ": cho user chọn 1 thư mục lưu, rồi ghi ra
   cây thư mục + tải các file từ hệ thống về:
     - Hồ sơ {product}            -> các file sản phẩm
     - Hồ sơ thành phần NVL {product}
         - {tên thành phần}
             - Hồ sơ nhà cung cấp -> file NCC
             - Hồ sơ nguyên liệu  -> file nguyên liệu
   Dùng File System Access API (Chrome/Edge). Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';

    // Bỏ ký tự không hợp lệ cho tên thư mục/file trên Windows
    function sanitize(name) {
        name = (name === null || name === undefined) ? '' : String(name);
        name = name.replace(/[\\/:*?"<>|]/g, '-').replace(/\s+/g, ' ').trim();
        name = name.replace(/[.\s]+$/, ''); // không kết thúc bằng '.' hoặc khoảng trắng
        return name || 'untitled';
    }

    // Đảm bảo tên không trùng trong cùng thư mục
    function uniqueName(used, name) {
        if (!used.has(name)) { used.add(name); return name; }
        var dot = name.lastIndexOf('.');
        var base = dot > 0 ? name.slice(0, dot) : name;
        var ext = dot > 0 ? name.slice(dot) : '';
        var i = 1, cand;
        do { cand = base + ' (' + i + ')' + ext; i++; } while (used.has(cand));
        used.add(cand);
        return cand;
    }

    async function getDir(parent, name) {
        return await parent.getDirectoryHandle(sanitize(name), { create: true });
    }

    // Tải & ghi danh sách file vào 1 thư mục, trả số file lỗi
    async function writeFiles(dir, files) {
        var used = new Set();
        var failed = 0;
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            try {
                var resp = await fetch(f.url);
                if (!resp.ok) { failed++; continue; }
                var blob = await resp.blob();
                var fname = uniqueName(used, sanitize(f.name));
                var fh = await dir.getFileHandle(fname, { create: true });
                var w = await fh.createWritable();
                await w.write(blob);
                await w.close();
            } catch (e) {
                failed++;
            }
        }
        return failed;
    }

    $(document).on('click', '.btn-create-dossier', async function () {
        var $btn = $(this);
        var productId = $btn.data('product-id');

        if (!window.showDirectoryPicker) {
            alert('Trình duyệt không hỗ trợ chọn thư mục để lưu. Vui lòng dùng Google Chrome hoặc Microsoft Edge.');
            return;
        }

        // 1) Lấy cây file từ server
        var manifest;
        try {
            manifest = await $.ajax({
                url: ACT + 'ajax_dossier_manifest',
                method: 'POST',
                data: { product_id: productId },
                dataType: 'json'
            });
        } catch (e) {
            alert('Không lấy được dữ liệu hồ sơ.');
            return;
        }
        if (!manifest || !manifest.success) {
            alert((manifest && manifest.message) || 'Không lấy được dữ liệu hồ sơ.');
            return;
        }

        // 2) Cho user chọn thư mục lưu
        var root;
        try {
            root = await window.showDirectoryPicker({ mode: 'readwrite' });
        } catch (e) {
            return; // user bấm huỷ
        }

        var origText = $btn.text();
        $btn.prop('disabled', true).text('Đang tạo...');
        var totalFailed = 0;

        try {
            var pname = manifest.product_name || 'San pham';

            // (a) Folder: Hồ sơ {product} -> file sản phẩm
            var prodDir = await getDir(root, 'Hồ sơ ' + pname);
            totalFailed += await writeFiles(prodDir, manifest.product_files || []);

            // (b) Folder: Hồ sơ thành phần NVL {product}
            var ingredients = manifest.ingredients || [];
            if (ingredients.length) {
                var nvlDir = await getDir(root, 'Hồ sơ thành phần NVL ' + pname);
                var usedIng = new Set();
                for (var i = 0; i < ingredients.length; i++) {
                    var ing = ingredients[i];
                    var ingName = uniqueName(usedIng, sanitize(ing.name || ('Thanh phan ' + (i + 1))));
                    var ingDir = await nvlDir.getDirectoryHandle(ingName, { create: true });

                    var supDir = await getDir(ingDir, 'Hồ sơ nhà cung cấp');
                    totalFailed += await writeFiles(supDir, ing.supplier_files || []);

                    var matDir = await getDir(ingDir, 'Hồ sơ nguyên liệu');
                    totalFailed += await writeFiles(matDir, ing.material_files || []);
                }
            }

            alert('Đã tạo bộ hồ sơ cho "' + pname + '"' + (totalFailed ? (' — ' + totalFailed + ' file không tải được.') : '.'));
        } catch (e) {
            alert('Có lỗi khi tạo bộ hồ sơ: ' + (e && e.message ? e.message : e));
        } finally {
            $btn.prop('disabled', false).text(origText);
        }
    });

});
