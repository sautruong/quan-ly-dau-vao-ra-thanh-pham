<?php
/* "Điểm nhắc": 3 loại nhắc độc lập (nhập SX / KHSX / bốc hàng).
 * Chỉ hiện cho admin hoặc user có quyền vào 1 trong 3 view đích — caller
 * phải tự bọc điều kiện `if ($__hd_remind_visible)` trước khi require file này. */
?>
<div class="app-remind" id="app-remind">
    <button type="button" class="app-remind-btn" id="app-remind-btn" aria-label="Điểm nhắc">
        <i class="fa-solid fa-thumbtack"></i>
    </button>
    <div class="app-remind-dropdown" id="app-remind-dropdown">
        <div class="app-remind-tabs" id="app-remind-tabs">
            <button type="button" class="app-remind-tab is-active" data-tab="pre_input">Nhập SX</button>
            <button type="button" class="app-remind-tab" data-tab="pre_plan">KHSX</button>
            <button type="button" class="app-remind-tab" data-tab="pickup">Bốc hàng</button>
        </div>

        <!-- Tab 1: nhắc trước nhập sản xuất -->
        <div class="app-remind-pane is-active" data-pane="pre_input">
            <div class="app-remind-add">
                <div class="app-remind-picker" data-picker="product">
                    <input type="text" class="app-remind-search" placeholder="Tìm sản phẩm…" autocomplete="off">
                    <div class="app-remind-suggest"></div>
                </div>
                <textarea class="app-remind-text" placeholder="Nội dung nhắc…" maxlength="500"></textarea>
                <button type="button" class="app-remind-save-btn">Lưu</button>
            </div>
            <div class="app-remind-list" data-list="pre_input"><div class="app-remind-empty">Đang tải…</div></div>
        </div>

        <!-- Tab 2: nhắc trước lập KHSX -->
        <div class="app-remind-pane" data-pane="pre_plan">
            <div class="app-remind-add">
                <div class="app-remind-picker" data-picker="product">
                    <input type="text" class="app-remind-search" placeholder="Tìm sản phẩm…" autocomplete="off">
                    <div class="app-remind-suggest"></div>
                </div>
                <textarea class="app-remind-text" placeholder="Nội dung nhắc…" maxlength="500"></textarea>
                <button type="button" class="app-remind-save-btn">Lưu</button>
            </div>
            <div class="app-remind-list" data-list="pre_plan"><div class="app-remind-empty">Đang tải…</div></div>
        </div>

        <!-- Tab 3: nhắc trước bốc hàng — theo chi nhánh / theo sản phẩm -->
        <div class="app-remind-pane" data-pane="pickup">
            <div class="app-remind-submode">
                <button type="button" class="app-remind-submode-btn is-active" data-mode="branch">Theo chi nhánh</button>
                <button type="button" class="app-remind-submode-btn" data-mode="product">Theo sản phẩm</button>
            </div>

            <div class="app-remind-add" data-mode-pane="branch">
                <label class="app-remind-all">
                    <span class="app-round-check">
                        <input type="checkbox" class="app-remind-all-check">
                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                    </span>
                    Áp dụng TẤT CẢ chi nhánh
                </label>
                <div class="app-remind-picker" data-picker="customer">
                    <input type="text" class="app-remind-search" placeholder="Tìm chi nhánh…" autocomplete="off">
                    <div class="app-remind-suggest"></div>
                </div>
                <div class="app-remind-chosen"></div>
                <textarea class="app-remind-text" placeholder="Nội dung nhắc…" maxlength="500"></textarea>
                <button type="button" class="app-remind-save-btn">Lưu</button>
            </div>
            <div class="app-remind-list" data-list="pickup_branch"><div class="app-remind-empty">Đang tải…</div></div>

            <div class="app-remind-add" data-mode-pane="product" style="display:none;">
                <label class="app-remind-all">
                    <span class="app-round-check">
                        <input type="checkbox" class="app-remind-all-check">
                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                    </span>
                    Áp dụng TẤT CẢ sản phẩm
                </label>
                <div class="app-remind-picker" data-picker="product">
                    <input type="text" class="app-remind-search" placeholder="Tìm sản phẩm…" autocomplete="off">
                    <div class="app-remind-suggest"></div>
                </div>
                <div class="app-remind-chosen"></div>
                <textarea class="app-remind-text" placeholder="Nội dung nhắc…" maxlength="500"></textarea>
                <button type="button" class="app-remind-save-btn">Lưu</button>
            </div>
            <div class="app-remind-list" data-list="pickup_product" style="display:none;"><div class="app-remind-empty">Đang tải…</div></div>
        </div>
    </div>
</div>
