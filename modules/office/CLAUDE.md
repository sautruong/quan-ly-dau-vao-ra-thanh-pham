# Module Office (Docs + Sheets)

Không gian soạn thảo văn bản (Docs) và bảng tính (Sheets) tự viết, dùng chung 1 module
`office` (không tách 2 module riêng). File này tóm tắt các quyết định kiến trúc, schema,
convention và bug đã tìm ra/sửa trong quá trình xây dựng — đọc trước khi sửa module này.

## 1. Kiến trúc tổng quan

- **1 controller** `officeController.php`, **1 model** `officeModel.php`, dùng chung
  **1 bảng dữ liệu** `ofc_documents` phân biệt Docs/Sheets bằng cột `type` ENUM('doc','sheet').
- URL: `?mod=office&controllers=office&action=<act>`. Action `docs`/`sheets` là danh sách
  (đăng ký vào `tbl_views`, nhóm menu `'VĂN PHÒNG'`); action `editor` là trang soạn thảo dùng
  chung cho cả 2 loại (KHÔNG đăng ký `tbl_views` — quyền xem/sửa kiểm tra riêng theo từng tài
  liệu qua `office_can_view`/`office_can_edit`, giống pattern `project_management`).
- Tự tạo bảng + tự đăng ký view (`office_ensure_tables()`, `office_ensure_view_registered()`,
  gọi ở `construct()` của controller) — không cần chạy SQL migration tay.
- Sidebar/menu: nhóm `'VĂN PHÒNG'` (icon `fa-briefcase`) phải khai báo ở **2 nơi** đồng bộ thủ
  công: `layout/sidebar-app.php::app_sidebar_group_icon()` và
  `modules/home/controllers/indexController.php::$icon_map`.

## 2. Schema DB (prefix `ofc_`)

| Bảng | Cột đáng chú ý | Ghi chú |
|---|---|---|
| `ofc_documents` | `type`, `title` (mã hoá), `content` (mã hoá), `owner_id`, `current_version`, `is_starred`, `last_opened_at` | 1 dòng = 1 tài liệu/bảng tính |
| `ofc_document_versions` | `document_id`, `version_no`, `content` (mã hoá), `note` | Snapshot lịch sử — tự động throttle ≥5 phút/lần lưu, hoặc lưu thủ công qua nút "Lưu phiên bản" |
| `ofc_shares` | `document_id`, `shared_with`, `permission` (view/comment/edit), `status`, `in_my_library` | Chia sẻ **cấp quyền NGAY** khi tạo (`status='accepted'` thẳng — KHÔNG có bước chấp nhận/từ chối) |
| `ofc_active_sessions` | `document_id`, `user_id`, `last_ping_at`, `is_typing`, `typing_at` | Presence (khoá mềm) + trạng thái "đang soạn" |

**title + content ĐỀU được mã hoá** bằng `libraries/crypto.php` (khoá chung toàn hệ thống,
giống `personal-data-encryption` pattern của file_management/tasks/todo/chat) — quyết định
này KHÁC với đề xuất ban đầu ("không mã hoá nội dung"), user yêu cầu đổi giữa chừng.

## 3. Quyết định thiết kế đã chốt (không tự ý đổi ngược lại)

- **Cộng tác = khoá mềm, KHÔNG phải OT/CRDT thật.** Lưu dựa trên so sánh `current_version`
  (giống `pm_canvas_save` của `project_management`) — version DB mới hơn version client gửi
  lên → trả `conflict`, KHÔNG tự merge, chỉ báo cho user tự tải lại.
- **KHÔNG có autosave.** `OfficeEditor.scheduleAutosave()` (office_common.js) chỉ đánh dấu
  `dirty=true` + sáng nút "Lưu" — phải bấm nút mới thật sự POST lên DB. Có cảnh báo
  `beforeunload` nếu rời trang khi còn `dirty`.
- **Chia sẻ = cấp quyền ngay**, không có trạng thái "pending" chờ chấp nhận (đã bỏ hẳn
  `office_share_respond`). Có `office_leave_share` (người nhận tự dọn dẹp), `office_toggle_library`
  (ghim thư viện), `office_share_to_chat` (gửi 1 bản xuất thẳng vào Chat).
- **Tích hợp "Quản lý file" = xuất bản sao 1 chiều** (`office_export_to_fm` sinh file thật rồi
  gọi `fm_add_file`), KHÔNG link 2 chiều, không đụng schema `fm_*`.
- **Tải xuống**: Docs → `.doc` (thủ thuật HTML mở bằng Word, chưa có PHPWord nên không phải
  OOXML thật). Sheets → `.xlsx` THẬT bằng `phpoffice/phpspreadsheet` (đã có sẵn trong
  `composer.json`, nạp `vendor/autoload.php` on-demand). Nút "PDF" chỉ mở trang in
  (`editor.php?id=X&autoprint=1`) rồi gọi `window.print()` — dựa vào "Save as PDF" của trình
  duyệt, KHÔNG render PDF server-side thật (chưa có lib PDF nào cài).
- **Cố ý CHƯA làm**: tạo folder phân nhóm file trong danh sách Docs/Sheets (quy mô tương đương
  xây lại cây thư mục kiểu `file_management`, để dành 1 lượt riêng nếu cần); rich-text theo
  từng phần trong 1 ô Sheets (định dạng B/I/U hiện áp dụng cho CẢ Ô, không phải theo vùng bôi
  đen trong ô — muốn có cần đổi hẳn model lưu trữ ô sang "runs" có style riêng).

## 4. Gotcha quan trọng — đọc kỹ trước khi sửa

### 4.1. Công thức Sheets tồn tại SONG SONG ở 2 nơi — sửa 1 chỗ mà quên chỗ kia là bug
- **Client** `public/js/office/sheets_editor.js` (`computeCell` đệ quy + cache/visiting chống
  vòng lặp) — dùng để hiển thị realtime khi gõ.
- **Server** `modules/office/models/officeModel.php` (`office_sheet_compute_cell` +
  `office_sheet_eval_*`) — CHỈ dùng khi xuất file (CSV/XLSX), để giá trị xuất ra khớp giá trị
  đang hiển thị thay vì xuất nguyên văn chuỗi `=SUM(...)`.
- Đã hỗ trợ: `SUM/AVERAGE/COUNT/MIN/MAX/IF/SUMIF/COUNTIF/VLOOKUP/HLOOKUP` + số học `+-*/()`.
  IF/VLOOKUP/HLOOKUP xử lý riêng như "lệnh gọi hàm trần" (regex `^(IF|VLOOKUP|HLOOKUP)\(.*\)$`)
  vì có thể trả về CHUỖI (không nhúng được vào biểu thức số học như SUM/AVERAGE).
  `office_csv_guard()`/tương đương chặn CSV injection (giá trị bắt đầu `=,+,-,@`).
- **Thêm hàm mới ⇒ phải sửa CẢ 2 file, và thêm test cho cả 2** (xem mục 6).

### 4.2. BUG NGHIÊM TRỌNG đã xảy ra thật — mất dữ liệu ô khi lưu Sheets
`office_default_content('sheet')` từng trả `colWidths`/`rowHeights`/`cells` bằng PHP `[]`.
`json_encode([])` LUÔN ra JSON `"[]"` (mảng), không phải `"{}"` (object) — PHP không phân biệt
được mảng rỗng với object rỗng. Phía JS, `sheet.cells = loaded.cells || {}` dính bẫy "mảng rỗng
vẫn truthy" nên `sheet.cells` trở thành MẢNG THẬT. Gán `sheet.cells['A1'] = {...}` vẫn chạy
được trong bộ nhớ (mảng JS cho gán khoá chuỗi) nên người sửa vẫn thấy dữ liệu bình thường trên
máy mình — nhưng `JSON.stringify()` trên một MẢNG chỉ giữ phần tử đánh số, **âm thầm bỏ mất
mọi khoá chuỗi khi lưu**. Đã xác minh trực tiếp trên dữ liệu thật (document có `cells` lưu
thành `[]`, `colWidths` thành `[null,81,348]`).

**Đã sửa 2 lớp** — bất kỳ field JSON nào kiểu map/dictionary (key không phải số nguyên liên
tục) thêm sau này ĐỀU PHẢI theo đúng 2 lớp này:
1. PHP: khởi tạo bằng `new stdClass()` chứ không phải `[]`, để `json_encode` ra `{}` ngay từ đầu.
2. JS: dùng hàm `toObjMap(v)` (trong `sheets_editor.js`) khi đọc field đó từ JSON — KHÔNG bao
   giờ viết `x || {}` trực tiếp. Hàm này còn tự "chữa" dữ liệu cũ đã lỡ lưu sai dạng mảng.

### 4.3. Layout editor: `.of-ed-page` phải nạp sidebar/header + `app_shell.js`
`editor.php` dùng `#wrapper.has-sider` + `get_sidebar('app')` + `get_header('app')` giống mọi
view khác. **Đừng quên `<script src="public/js/shared/app_shell.js">`** ở cuối trang — thiếu
file này thì mọi nút trên app-header (chuông/lịch/todo/user) và menu con sidebar không phản
hồi click (đã bị bug này 1 lần). Layout cột bên trong `.of-ed-page` dùng
`display:flex;flex-direction:column;height:calc(100vh - var(--app-header-h))` đặt trên
`.of-ed-page` (không phải trên `.content` gốc) vì `#wrapper.has-sider > .content` trong
`app_shell.css` có nhiều rule `!important` đè lên.

### 4.4. In nhiều trang: `global.css` khoá `overflow:hidden` toàn app
`global.css` khoá `html,body,#wrapper` ở `overflow:hidden;height:100%` (khung app 1 màn hình).
Khi in, việc này cắt cụt nội dung ngoài 1 màn hình khiến xem trước khi in chỉ thấy "trang 1".
Đã gỡ riêng cho lúc in trong `@media print` của `office.css`
(`html,body,#wrapper{height:auto!important;overflow:visible!important}`) — chỉ áp dụng khi in
trang Office, không ảnh hưởng module khác. Mặc định trình duyệt KHÔNG in màu nền/viền trừ khi
có `print-color-adjust: exact` (đã thêm cho ô bảng Docs) — thiếu dòng này thì bảng tô màu trên
màn hình in ra sẽ trắng tinh.

### 4.5. Docs: bảng lồng trong contenteditable + mất Selection khi bấm toolbar
Bảng trong Docs là `<table contenteditable="true">` LỒNG trong `<div class="of-table-wrap"
contenteditable="false">` (kỹ thuật "editable island"). Bấm nút/select/color-picker trên
toolbar làm trình duyệt tự chuyển focus, MẤT Selection đang nằm trong bảng lồng → định dạng
không áp dụng đúng chỗ. Fix chung: toolbar bắt `mousedown` (chạy TRƯỚC khi focus rời trang) gọi
`saveCurrentRange()`, hành động thật sự (`click`/`change`/`input`) gọi `restoreSavedRange()`
trước khi `execCommand`. Áp dụng cho MỌI điều khiển toolbar, không riêng bảng.

### 4.6. Docs: nhiều trang A4 thật, không phải 1 khối cuộn dài
`#of-doc-editor` là container chứa nhiều `<div class="of-doc-page" contenteditable>` (mỗi
trang 1 vùng soạn thảo riêng, khổ A4 thật). Lưu trữ: nội dung DB là 1 chuỗi nối các trang bằng
mốc `<!--OFPAGE-->` (`serializePages`/`loadPages`). Ctrl+Enter tạo hẳn 1 trang DOM mới
(`insertNewPageAfter()`), không phải chèn marker trong cùng 1 trang. **Hệ quả**: mọi thao tác
toolbar phải target đúng `activePage` (theo dõi qua `focusin` trên container), KHÔNG phải 1
biến `editor` cố định. Khi xuất Word, mốc `<!--OFPAGE-->` đổi thành
`<div style="page-break-before:always">`.

Enter khi con trỏ ở Ô CUỐI CÙNG của bảng bị trình duyệt hiểu mặc định là "xuống dòng TRONG ô
đó" (nhìn giống bảng phình xuống) — đã chặn riêng: ô cuối cùng thì Enter tự đưa caret sang
đoạn văn ngay sau bảng (`placeCaretAfter()`) thay vì thêm dòng trong ô.

### 4.7. Sheets: cuộn ngang cần `min-width: 0` trên flex item
`.of-sheet-wrap` là flex item trong `.of-ed-page` (flex-direction:column) — mặc định flex item
có `min-width:auto` nên tự phình rộng theo bảng thay vì cho `overflow:auto` cuộn ngang hoạt
động. Chỉ cần `min-width: 0`. (Đây cũng từng là nguyên nhân khiến kéo giãn 1 cột trông như "ép
các cột khác co lại" — do cả bảng bị ép vừa khung, không phải do logic resize sai.)

### 4.8. Sheets: double-click/đang sửa ô bị `render()` xé mất
Mousedown trên `.of-sheet-cell` LUÔN gọi `render()` (dựng lại toàn bộ HTML bảng). Nếu không
loại trừ trường hợp click ngay trong ô ĐANG sửa (`editingRef`), mỗi lần bôi đen/click lại
trong ô đang gõ sẽ bị `render()` xé mất DOM đang edit → mất caret + nội dung gõ dở. Luôn kiểm
tra `if (editingRef === td.dataset.ref) return;` sớm trong các handler mousedown/mousemove.

### 4.9. Sheets: hộp công thức dùng `formulaBarTargetRef`, không đọc `sel` lúc commit
`<td>` không tự nhận focus, nên click sang ô khác trong lúc hộp công thức (`#of-formula-input`)
đang có focus sẽ đổi `sel` (phục vụ chèn tham chiếu khi gõ công thức) nhưng KHÔNG blur input.
Nếu đọc `sel` trực tiếp lúc commit sẽ ghi nhầm nội dung đang gõ vào ô vừa click thay vì ô đang
thực sự sửa — phải chụp `formulaBarTargetRef` lúc đồng bộ (`syncFormulaBar`), dùng lại đúng
biến đó lúc commit.

### 4.10. Cache-busting bắt buộc khi sửa CSS/JS Office
Module này sửa liên tục — `office.css` và toàn bộ JS trong `public/js/office/` được nạp qua
helper `office_asset($relPath)` (gắn `?v=filemtime(...)`) trong `editor.php`/`docs_list.php`/
`sheets_list.php`. Nếu thêm file JS/CSS mới cho module, PHẢI nạp qua `office_asset()`, không
dùng đường dẫn tĩnh trực tiếp — nếu không, sửa xong dễ bị hiểu nhầm "chưa có tác dụng" do
trình duyệt cache bản cũ.

## 5. Bản đồ file

```
modules/office/
  controllers/officeController.php   — dispatch action, AJAX JSON endpoints
  models/officeModel.php             — CRUD, mã hoá, chia sẻ, engine công thức (server), export/download
  views/docs_list.php, sheets_list.php — danh sách dạng thẻ (Của tôi/Được chia sẻ/Đã chia sẻ)
  views/editor.php                   — trang soạn thảo dùng chung Docs+Sheets

public/js/office/
  office_common.js   — OfficeEditor: api/toast/modal, lưu thủ công, chia sẻ, lịch sử, presence, in
  list.js            — danh sách: tạo mới, đổi tên inline, chia sẻ trên thẻ, thư viện, tải xuống
  docs_editor.js      — nhiều trang A4, bảng (chèn/resize/màu/xoá), ảnh, toolbar, ngắt trang
  sheets_editor.js    — lưới, công thức, định dạng, resize dòng/cột, biểu đồ SVG, hộp công thức

public/css/office/office.css — toàn bộ style Office (kể cả @media print)
```

## 6. Kiểm thử

- **Test model-level** (không qua HTTP, chạy PHP CLI trực tiếp với DB thật) là cách chính đã
  dùng xuyên suốt — bootstrap thủ công các hằng số `APPPATH`/`CONFIGPATH`/... rồi
  `require MODULESPATH . '/office/models/officeModel.php'` và gọi hàm trực tiếp. Bao phủ:
  tạo/lưu/conflict-version/chia sẻ/đổi quyền/presence/lịch sử/export/download/công thức nâng
  cao/định dạng mặc định object-vs-array. Script mẫu nằm trong scratchpad của phiên làm việc
  (không commit vào repo) — khi cần kiểm thử lại, viết script tương tự bootstrap app rồi gọi
  thẳng các hàm `office_*`.
- Sau mỗi thay đổi: `php -l` cho các file PHP đã sửa, `node --check` cho các file JS, đếm
  `{`/`}` khớp nhau cho CSS, và smoke-test route qua `php -S` + `curl` (mong đợi 302 sạch, log
  không có PHP error, vì view/AJAX đều yêu cầu đăng nhập).
- Thêm hàm công thức mới ⇒ viết test cho CẢ engine JS lẫn PHP (xem mục 4.1).
