<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * invoice_ui helper — render ô "Hóa đơn" theo dòng cho bảng admin_factory.
 * Dùng chung cho sales_orders (sales_invoice) và purchase_order (purchase_invoice).
 *
 * Cần: libraries/warehouse_receipt_invoices.php (cho wri_list) — controller đã require.
 */

if (!function_exists('invoice_ui_is_image')) {
    function invoice_ui_is_image($url)
    {
        $ext = strtolower(pathinfo((string) $url, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}

if (!function_exists('render_invoice_thumb')) {
    /** Render 1 thumbnail hóa đơn (ảnh hoặc icon PDF) kèm nút xóa. */
    function render_invoice_thumb($inv)
    {
        $url = htmlspecialchars((string) ($inv['file_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $id  = (int) ($inv['id'] ?? 0);
        $inner = invoice_ui_is_image($inv['file_url'] ?? '')
            ? '<img src="' . $url . '" data-view="' . $url . '" alt="">'
            : '<i class="fa-solid fa-file-pdf iu-file-ico" data-view="' . $url . '"></i>';
        return '<div class="iu-thumb" data-id="' . $id . '">'
             . $inner
             . '<button type="button" class="iu-remove" title="Xóa hóa đơn">&times;</button>'
             . '</div>';
    }
}

if (!function_exists('render_invoice_cell')) {
    /**
     * Render <td class="invoice-cell"> hoàn chỉnh.
     * @param int    $wr_id    id dòng (warehouse_receipts.id hoặc sales_orders.id)
     * @param string $type     'purchase_invoice' | 'sales_invoice'
     * @param array  $invoices danh sách hóa đơn đã có (mỗi phần tử có id, file_url)
     * @param string $td_class class CSS bổ sung cho <td>
     */
    function render_invoice_cell($wr_id, $type, $invoices = [], $td_class = '', $store = '')
    {
        $wr_id = (int) $wr_id;
        $type  = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $store = htmlspecialchars((string) $store, ENT_QUOTES, 'UTF-8');
        $cls   = trim('invoice-cell ' . $td_class);

        $thumbs = '';
        foreach ((array) $invoices as $inv) {
            $thumbs .= render_invoice_thumb($inv);
        }
        $count = count((array) $invoices);

        // .ic-list ẩn mặc định cho gọn dòng; bấm nút '^' (.ic-toggle) mới hiện.
        // 2 cách lưu hóa đơn: (1) click ô để chọn rồi Ctrl+V dán ảnh; (2) bấm nút
        // .ic-upload để mở hộp chọn tệp. Click vùng .ic-drop chỉ để CHỌN ô (không xổ folder).
        // data-store (tuỳ chọn): 'supplier_payment' -> ảnh lưu ở supplier_payment_attachments.
        $store_attr = $store !== '' ? ' data-store="' . $store . '"' : '';
        $html  = '<td class="' . $cls . '" data-wr-id="' . $wr_id . '" data-inv-type="' . $type . '"' . $store_attr . '>';
        $html .= '<div class="ic-list">' . $thumbs . '</div>';
        $html .= '<div class="ic-drop"><i class="fa-solid fa-paperclip"></i>Copy hình ảnh rồi dán'
               . '<button type="button" class="ic-upload" title="Tải hóa đơn từ máy"><i class="fa-solid fa-upload"></i></button>'
               . '<button type="button" class="ic-toggle" title="Hiện/ẩn hóa đơn">'
               . '<span class="ic-count' . ($count > 0 ? ' has' : '') . '">' . $count . '</span><i class="fa-solid fa-chevron-up"></i></button>'
               . '<input type="file" multiple accept="image/*,.pdf" hidden></div>';
        $html .= '</td>';
        return $html;
    }
}
