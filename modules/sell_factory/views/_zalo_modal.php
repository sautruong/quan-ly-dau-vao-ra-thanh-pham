<?php
/**
 * Modal "Share Zalo" — DÙNG CHUNG order_factory + order_history (cùng JS order-factory.js).
 * Hai tab:
 *   - Hình ảnh : hoá đơn #zalo-capture để chụp (cũng là tấm ảnh "Gửi qua chat" dùng).
 *   - Dạng chữ : mỗi SP một dòng "Tên: SL đơn vị" trong #zalo-text (sửa/chèn thêm được) rồi
 *                "Sao chép nội dung" để dán vào khung chat Zalo.
 * Nội dung hai tab do order-factory.js đổ vào mỗi lần mở (#btn-share-zalo).
 */
?>
<!-- ============== MODAL : SHARE ZALO ============== -->
<div class="modal-mask" id="modal-zalo" style="display:none;">
    <div class="modal-box">
        <span class="modal-close" data-close="modal-zalo">&times;</span>

        <div class="of-setting-tabs zalo-tabs" id="zalo-tabs">
            <button type="button" class="of-setting-tab active" data-ztab="image">Hình ảnh</button>
            <button type="button" class="of-setting-tab" data-ztab="text">Dạng chữ</button>
        </div>

        <!-- Tab HÌNH ẢNH. Khi chụp từ nơi khác (Gửi qua chat) mà tab này đang ẩn thì JS phải
             tạm hiện ra, xem captureInvoiceCanvas(). -->
        <div class="zalo-pane" id="zalo-pane-image">
            <div id="zalo-capture" class="zalo-capture">
                <div class="zalo-customer"><strong>Tên khách hàng:</strong> <span id="zalo-customer-name"></span></div>
                <div class="zalo-title">ĐẶT HÀNG NHÀ MÁY</div>
                <table class="zalo-table">
                    <thead>
                        <tr>
                            <th>Tên sản phẩm</th>
                            <th>Đơn vị</th>
                            <th>Số lượng</th>
                            <th>Khối lượng (kg)</th>
                        </tr>
                    </thead>
                    <tbody id="zalo-body"></tbody>
                </table>
                <div class="zalo-weight-total">
                    Tổng khối lượng: <span id="zalo-weight-total-value">0</span> kg
                </div>
            </div>
            <div class="modal-zalo-footer">
                <button class="btn-capture-zalo" id="btn-capture-zalo">Chụp hóa đơn</button>
            </div>
        </div>

        <!-- Tab DẠNG CHỮ -->
        <div class="zalo-pane" id="zalo-pane-text" style="display:none;">
            <textarea class="zalo-text" id="zalo-text" rows="8" spellcheck="false"
                      aria-label="Nội dung đơn hàng dạng chữ"></textarea>
            <p class="zalo-text-hint">Có thể gõ thêm lời nhắn ở đầu hoặc cuối. <b>Alt + Enter</b> để xuống dòng.</p>
            <div class="modal-zalo-footer">
                <button type="button" class="btn-capture-zalo" id="btn-copy-zalo-text">Sao chép nội dung</button>
            </div>
        </div>
    </div>
</div>
