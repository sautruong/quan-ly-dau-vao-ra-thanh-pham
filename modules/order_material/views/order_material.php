<?php
/** Dữ liệu từ controller: $doc (tiêu đề phiếu + người ký), $orders (đơn đã lưu). */
$doc    = $doc    ?? ['company_name' => 'VUA AN TOÀN', 'company_address' => '', 'signer_orderer' => '', 'signer_warehouse' => '', 'signer_accountant' => ''];
$orders = $orders ?? [];
$om_today = 'Ngày ' . date('j') . ' tháng ' . date('m') . ' năm ' . date('Y');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt hàng NVL</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/order_material/order_material.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content om-content">
            <div class="om-grid">

                <!-- ============================================================ -->
                <!-- KHỐI 1: PHÂN TÍCH NGUYÊN VẬT LIỆU                            -->
                <!-- ============================================================ -->
                <section class="om-block om-block-analyze">
                    <div class="om-block-head">
                        <h2><i class="fa-solid fa-flask-vial"></i> Phân tích nguyên vật liệu</h2>
                    </div>

                    <!-- Tìm nhà cung cấp -->
                    <div class="om-supplier-search">
                        <div class="om-search-wrap">
                            <i class="fa-solid fa-magnifying-glass om-search-icon"></i>
                            <input type="text" id="om-supplier-input" class="om-search-input" autocomplete="off"
                                placeholder="Tìm nhà cung cấp..." spellcheck="false">
                            <button type="button" class="om-search-clear" id="om-search-clear" title="Xóa tìm kiếm"><i class="fa-solid fa-xmark"></i></button>
                            <ul class="om-search-dropdown" id="om-supplier-dropdown"></ul>
                        </div>
                        <button type="button" class="om-tool-btn" id="om-btn-min-setting" disabled
                            title="Thiết lập tồn tối thiểu để cảnh báo">
                            <i class="fa-solid fa-sliders"></i> Tồn tối thiểu
                        </button>
                        <button type="button" class="om-tool-btn" id="om-btn-supplier-info" disabled
                            title="Xem thông tin nhà cung cấp">
                            <i class="fa-solid fa-circle-info"></i> Thông tin NCC
                        </button>
                    </div>

                    <!-- Bảng NVL -->
                    <div class="om-mat-wrap">
                        <table class="om-mat-table">
                            <thead>
                                <tr>
                                    <th>Tên nguyên vật liệu</th>
                                    <th class="om-col-unit">Đơn vị</th>
                                    <th class="om-col-stock">Tồn</th>
                                    <th class="om-col-act">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="om-mat-tbody">
                                <tr class="om-mat-empty">
                                    <td colspan="4">Chọn một nhà cung cấp để xem danh sách nguyên vật liệu.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Phân trang bảng NVL -->
                    <div class="om-mat-pager" id="om-mat-pager" style="display:none;">
                        <div class="om-pager-size">
                            Hiển thị
                            <select id="om-mat-pagesize">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="15">15</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                            </select>
                            dòng
                        </div>
                        <div class="om-pager-nav">
                            <button type="button" class="om-pager-btn" id="om-mat-prev" title="Trang trước"><i class="fa-solid fa-chevron-left"></i></button>
                            <span class="om-pager-info" id="om-mat-pageinfo">1 / 1</span>
                            <button type="button" class="om-pager-btn" id="om-mat-next" title="Trang sau"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>

                    <!-- Xem xét đặt (FAQ) -->
                    <div class="om-faq" id="om-faq">
                        <div class="om-faq-head"><i class="fa-solid fa-lightbulb"></i> Xem xét đặt</div>
                        <div class="om-faq-body" id="om-faq-body">
                            <div class="om-faq-empty">Chọn nhà cung cấp để xem các nguyên vật liệu nên đặt.</div>
                        </div>
                    </div>

                    <!-- Thành phần tạm ẩn (theo NCC đang chọn — bấm "Mở lại" để hiện lại trong danh sách) -->
                    <div class="om-hidden" id="om-hidden">
                        <div class="om-hidden-head"><i class="fa-solid fa-eye-slash"></i> Thành phần tạm ẩn</div>
                        <div class="om-hidden-body" id="om-hidden-body">
                            <div class="om-faq-empty">Chọn nhà cung cấp để xem các thành phần đang tạm ẩn.</div>
                        </div>
                    </div>
                </section>

                <!-- Menu chọn thời gian nhắc lịch (dùng chung cho các NVL trong "Xem xét đặt") -->
                <ul class="om-cal-menu" id="om-cal-menu" style="display:none;"></ul>

                <!-- Thanh kéo chia đôi 2 khối (cố định bề ngang "Đơn đặt hàng") -->
                <div class="om-resizer rs-resizer" data-target="#om-order-block" data-storage-key="om_order_block_width"
                    data-min="360" data-max="900"></div>

                <!-- ============================================================ -->
                <!-- KHỐI 2: ĐƠN ĐẶT HÀNG                                         -->
                <!-- ============================================================ -->
                <section class="om-block om-block-order" id="om-order-block">
                    <div class="om-block-head">
                        <h2><i class="fa-solid fa-file-invoice"></i> Đơn đặt hàng</h2>
                        <div class="om-order-tools">
                            <button type="button" class="om-tool-btn" id="om-btn-saved-orders">
                                <i class="fa-solid fa-clock-rotate-left"></i> Đơn đã lưu
                            </button>
                            <button type="button" class="om-tool-btn" id="om-btn-sign-setting" title="Cài đặt chữ ký">
                                <i class="fa-solid fa-signature"></i> Chữ ký
                            </button>
                        </div>
                    </div>

                    <!-- Phiếu đặt hàng (vùng được chụp/share) -->
                    <div class="om-doc" id="om-doc-sheet">
                        <div class="om-doc-header">
                            <!-- Cụm logo + tên (canh mép trái) -->
                            <div class="om-doc-brand">
                                <div class="om-doc-logo-badge">
                                    <img class="om-doc-logo" src="public/images/logo/logo_vat_png.png" alt="logo">
                                </div>
                                <div class="om-doc-company">
                                    <div class="om-editable om-doc-name" data-key="order_material.company_name">
                                        <span class="om-etext"><?php echo htmlspecialchars($doc['company_name']); ?></span>
                                        <button type="button" class="om-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button>
                                    </div>
                                    <div class="om-editable om-doc-enname" data-key="order_material.company_enname">
                                        <span class="om-etext"><?php echo htmlspecialchars($doc['company_enname']); ?></span>
                                        <button type="button" class="om-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button>
                                    </div>
                                </div>
                            </div>

                            <!-- Cụm thông tin liên hệ (canh phải, ngang hàng cụm logo) -->
                            <div class="om-doc-contact">
                                <div class="om-editable om-doc-addr" data-key="order_material.company_address" data-multiline="1">
                                    <span class="om-etext"><?php echo htmlspecialchars($doc['company_address']); ?></span>
                                    <button type="button" class="om-edit-btn" title="Sửa địa chỉ"><i class="fa-solid fa-pen"></i></button>
                                </div>
                                <div class="om-editable om-doc-phone" data-key="order_material.company_phone">
                                    <i class="fa-solid fa-phone om-doc-ico"></i>
                                    <span class="om-etext"><?php echo htmlspecialchars($doc['company_phone']); ?></span>
                                    <button type="button" class="om-edit-btn" title="Sửa SĐT"><i class="fa-solid fa-pen"></i></button>
                                </div>
                                <div class="om-editable om-doc-email" data-key="order_material.company_email">
                                    <i class="fa-solid fa-envelope om-doc-ico"></i>
                                    <span class="om-etext"><?php echo htmlspecialchars($doc['company_email']); ?></span>
                                    <button type="button" class="om-edit-btn" title="Sửa email"><i class="fa-solid fa-pen"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Đường line ngang kiểu dây thừng -->
                        <div class="om-doc-rope"></div>

                        <div class="om-doc-title"><span>ĐƠN ĐẶT HÀNG</span></div>

                        <div class="om-doc-to">
                            Kính gửi: <strong id="om-doc-supplier">.................................</strong>
                        </div>
                        <div class="om-doc-intro">
                            Công ty chúng tôi có nhu cầu đặt hàng tại Quý công ty với danh sách các mặt hàng sau:
                        </div>

                        <table class="om-doc-table">
                            <thead>
                                <tr>
                                    <th class="om-dc-stt">STT</th>
                                    <th class="om-dc-name">TÊN HÀNG HÓA</th>
                                    <th class="om-dc-unit">ĐƠN VỊ</th>
                                    <th class="om-dc-qty">SỐ LƯỢNG</th>
                                    <th class="om-dc-act"></th>
                                </tr>
                            </thead>
                            <tbody id="om-doc-tbody">
                                <tr class="om-doc-empty">
                                    <td colspan="5">Bấm "+" ở danh sách nguyên vật liệu để thêm vào đơn.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="om-doc-thanks">
                            Rất mong nhận được hàng sớm từ Quý công ty. Chân thành cảm ơn!
                        </div>

                        <!-- Cụm chữ ký (render động theo cấu hình, kéo-thả sắp xếp) -->
                        <div class="om-doc-signs" id="om-doc-signs"></div>

                        <!-- Footer: cụm (dải navy + tam giác + ngày) nằm TRÊN khối A -->
                        <div class="om-doc-footer">
                            <div class="om-doc-bottom">
                                <div class="om-doc-footband">
                                    <span class="om-doc-foottri"></span>
                                </div>
                                <div class="om-doc-date"><?php echo htmlspecialchars($om_today); ?></div>
                            </div>
                        </div>
                        <!-- Khối A: line bottom xanh lá dày 15px, sát mép dưới hóa đơn -->
                        <div class="om-doc-bottombar"></div>
                    </div>

                    <!-- Hành động đơn -->
                    <div class="om-order-actions">
                        <button type="button" class="om-btn om-btn-primary" id="om-btn-save-order">
                            <i class="fa-solid fa-floppy-disk"></i> Lưu đơn
                        </button>
                        <button type="button" class="om-btn om-btn-share" id="om-btn-share-order">
                            <i class="fa-solid fa-share-nodes"></i> Chụp & chia sẻ
                        </button>
                        <button type="button" class="om-btn om-btn-ghost" id="om-btn-clear-order">
                            <i class="fa-solid fa-trash-can"></i> Xóa nội dung
                        </button>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Phân tích NVL                                          -->
    <!-- ============================================================ -->
    <div class="om-modal-mask" id="om-modal-analysis" style="display:none;">
        <div class="om-modal-box om-modal-lg">
            <span class="om-modal-close" data-close="om-modal-analysis">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-flask-vial"></i> <span id="om-an-name"></span></h3>

            <div class="om-an-summary" id="om-an-summary"></div>

            <div class="om-an-sub">Các sản phẩm dùng nguyên vật liệu này</div>
            <div class="om-an-products" id="om-an-products"></div>

            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-ghost" id="om-btn-open-report">
                    <i class="fa-solid fa-file-export"></i> Xuất báo cáo
                </button>
                <button type="button" class="om-btn om-btn-primary" id="om-btn-open-plan">
                    <i class="fa-solid fa-calculator"></i> Kế hoạch (tính định mức cần)
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Xuất báo cáo định mức dùng NVL (chụp & chia sẻ) -->
    <div class="om-modal-mask" id="om-modal-report" style="display:none;">
        <div class="om-modal-box om-modal-lg">
            <span class="om-modal-close" data-close="om-modal-report">&times;</span>

            <div class="om-report-sheet" id="om-report-sheet">
                <div class="om-report-header" id="om-report-header"></div>

                <div class="om-doc-rope"></div>
                <div class="om-doc-title"><span>BC ĐỊNH MỨC DÙNG NVL</span></div>

                <div class="om-report-meta">
                    <div><strong>Tên nguyên vật liệu:</strong> <span id="om-report-mat-name" class="om-report-matname"></span></div>
                    <div><strong>Nhà cung cấp:</strong> <span id="om-report-supplier"></span></div>
                </div>

                <div class="om-an-summary" id="om-report-summary"></div>
                <div class="om-an-sub">Các sản phẩm dùng nguyên vật liệu này</div>
                <div class="om-an-products" id="om-report-products"></div>

                <div class="om-doc-footer">
                    <div class="om-doc-bottom">
                        <div class="om-doc-footband">
                            <span class="om-doc-foottri"></span>
                        </div>
                        <div class="om-doc-date" id="om-report-date"></div>
                    </div>
                </div>
                <div class="om-doc-bottombar"></div>
            </div>

            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-share" id="om-btn-share-report">
                    <i class="fa-solid fa-share-nodes"></i> Chụp & chia sẻ
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Kế hoạch tính định mức -->
    <div class="om-modal-mask" id="om-modal-plan" style="display:none;">
        <div class="om-modal-box">
            <span class="om-modal-close" data-close="om-modal-plan">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-calculator"></i> Kế hoạch định mức</h3>
            <p class="om-modal-hint">Nhập số lượng thành phẩm muốn sản xuất — hệ thống tính tổng định mức
                <strong id="om-plan-mat-name"></strong> cần dùng (theo công thức).</p>
            <table class="om-plan-table">
                <thead>
                    <tr><th>Sản phẩm</th><th class="om-pl-norm">Định mức/SP</th><th class="om-pl-qty">SL sản xuất</th><th class="om-pl-need">Cần dùng</th></tr>
                </thead>
                <tbody id="om-plan-tbody"></tbody>
            </table>
            <div class="om-plan-total">
                Tổng định mức cần: <strong id="om-plan-total">0</strong> <span id="om-plan-unit"></span>
            </div>
        </div>
    </div>

    <!-- MODAL: Thiết lập tồn tối thiểu -->
    <div class="om-modal-mask" id="om-modal-min" style="display:none;">
        <div class="om-modal-box om-modal-lg">
            <span class="om-modal-close" data-close="om-modal-min">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-sliders"></i> Thiết lập tồn tối thiểu</h3>
            <p class="om-modal-hint">Cảnh báo "nên đặt" khi <em>tồn &lt; số lượng tối thiểu</em> và trong "thời gian dùng" có phát sinh sử dụng.</p>
            <div class="om-min-wrap">
                <table class="om-min-table">
                    <thead>
                        <tr>
                            <th>Nguyên vật liệu</th>
                            <th class="om-mn-min">Số lượng tối thiểu</th>
                            <th class="om-mn-lead">TG về dự kiến (ngày)</th>
                            <th class="om-mn-win">Thời gian dùng</th>
                        </tr>
                    </thead>
                    <tbody id="om-min-tbody"></tbody>
                </table>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-primary" id="om-btn-save-min">
                    <i class="fa-solid fa-floppy-disk"></i> Lưu thiết lập
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Thông tin nhà cung cấp -->
    <div class="om-modal-mask" id="om-modal-supplier" style="display:none;">
        <div class="om-modal-box">
            <span class="om-modal-close" data-close="om-modal-supplier">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-truck-field"></i> Thông tin nhà cung cấp</h3>
            <div class="om-sup-info" id="om-sup-info"></div>
        </div>
    </div>

    <!-- MODAL: Đơn đã lưu -->
    <div class="om-modal-mask" id="om-modal-orders" style="display:none;">
        <div class="om-modal-box om-modal-lg">
            <span class="om-modal-close" data-close="om-modal-orders">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-clock-rotate-left"></i> Đơn đặt hàng đã lưu</h3>
            <div class="om-orders-list" id="om-orders-list"></div>
        </div>
    </div>

    <!-- MODAL: Quy đổi đơn vị (chỉ cho đơn hàng, không đổi gốc) -->
    <div class="om-modal-mask" id="om-modal-convert" style="display:none;">
        <div class="om-modal-box om-modal-sm">
            <span class="om-modal-close" data-close="om-modal-convert">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-right-left"></i> Quy đổi đơn vị</h3>
            <p class="om-modal-hint">Quy đổi chỉ áp dụng cho đơn đặt hàng gửi NCC — KHÔNG thay đổi đơn vị gốc trong hệ thống.</p>
            <div class="om-convert-name" id="om-convert-name"></div>
            <div class="om-convert-fields">
                <div class="om-cv-field">
                    <label>Đơn vị</label>
                    <input type="text" id="om-convert-unit" autocomplete="off">
                </div>
                <div class="om-cv-field">
                    <label>Số lượng</label>
                    <input type="text" id="om-convert-qty" inputmode="decimal" autocomplete="off">
                </div>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-primary" id="om-convert-apply"><i class="fa-solid fa-check"></i> Áp dụng</button>
            </div>
        </div>
    </div>

    <!-- MODAL: Cài đặt chữ ký (chọn chức danh hiển thị + thêm/sửa/xóa) -->
    <div class="om-modal-mask" id="om-modal-sign" style="display:none;">
        <div class="om-modal-box">
            <span class="om-modal-close" data-close="om-modal-sign">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-signature"></i> Cài đặt chữ ký</h3>
            <p class="om-modal-hint">Tích chọn chức danh để hiển thị cụm chữ ký trên phiếu. Có thể đổi tên, xóa, hoặc thêm chức danh mới. Trên phiếu, kéo-thả các cụm chữ ký để đổi vị trí.</p>
            <ul class="om-sign-rolelist" id="om-sign-rolelist"></ul>
            <div class="om-sign-addrow">
                <input type="text" id="om-sign-newrole" autocomplete="off" placeholder="Thêm chức danh mới...">
                <button type="button" class="om-btn om-btn-primary" id="om-sign-addbtn"><i class="fa-solid fa-plus"></i> Thêm</button>
            </div>
        </div>
    </div>

    <!-- Template: dòng NVL -->
    <template id="om-mat-row-tpl">
        <tr class="om-mat-row">
            <td class="om-mat-name"><span class="om-mat-link"></span> <i class="fa-solid fa-triangle-exclamation om-warn" title="Nên đặt: tồn dưới mức tối thiểu"></i></td>
            <td class="om-col-unit om-mat-unit"></td>
            <td class="om-col-stock om-mat-stock"></td>
            <td class="om-col-act">
                <div class="om-mat-row-actions">
                    <button type="button" class="om-add-btn" title="Thêm vào đơn đặt hàng — nhận SL đặt gần nhất">+</button>
                    <button type="button" class="om-hide-btn" title="Tạm ẩn khỏi danh sách NCC này"><i class="fa-solid fa-eye-slash"></i></button>
                </div>
            </td>
        </tr>
    </template>

    <!-- Template: dòng phiếu đặt hàng -->
    <template id="om-doc-row-tpl">
        <tr class="om-doc-row">
            <td class="om-dc-stt"></td>
            <td class="om-dc-name"></td>
            <td class="om-dc-unit"></td>
            <td class="om-dc-qty">
                <div class="om-qty-cell">
                    <input type="text" class="om-qty-input" inputmode="decimal" value="0">
                    <button type="button" class="om-qty-convert" title="Quy đổi đơn vị (chỉ cho đơn hàng)"><i class="fa-solid fa-right-left"></i></button>
                </div>
            </td>
            <td class="om-dc-act"><button type="button" class="om-doc-del" title="Bỏ khỏi đơn">&times;</button></td>
        </tr>
    </template>

    <script>
        window.OM_CONFIG = {
            baseUrl: '?mod=order_material&controllers=order_material&action=',
            orders: <?php echo json_encode($orders, JSON_UNESCAPED_UNICODE); ?>,
            signRoles: <?php echo json_encode($doc['sign_roles'], JSON_UNESCAPED_UNICODE); ?>,
            signs: <?php echo json_encode($doc['signs'], JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="public/js/order_material/order_material.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/shared/resizable_split.js"></script>
</body>

</html>
