-- =====================================================================
--  PHÂN QUYỀN THEO VIEW (admin tick view nào → user thấy/đi được view đó)
--  - tbl_users.role : 'admin' bỏ qua mọi kiểm tra; 'user' bị giới hạn.
--  - tbl_views       : danh mục các view (trang) gán được, gom theo group_label.
--  - tbl_user_views  : bản ghi gán user <-> view.
--  Chạy 1 lần trên DB NMSX_VAT (MariaDB/XAMPP).
-- =====================================================================

-- 1) Thêm cột role cho tài khoản ------------------------------------------------
ALTER TABLE tbl_users
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER status;

-- 2) Danh mục view -------------------------------------------------------------
DROP TABLE IF EXISTS tbl_user_views;
DROP TABLE IF EXISTS tbl_views;

CREATE TABLE tbl_views (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    module      VARCHAR(50)  NOT NULL,
    controller  VARCHAR(50)  NOT NULL,
    action      VARCHAR(80)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    group_label VARCHAR(100) NOT NULL,
    sort        INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_view (module, controller, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Bản ghi gán user <-> view -------------------------------------------------
CREATE TABLE tbl_user_views (
    user_id    INT NOT NULL,
    view_id    INT NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, view_id),
    KEY idx_view (view_id),
    CONSTRAINT fk_uv_user FOREIGN KEY (user_id) REFERENCES tbl_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uv_view FOREIGN KEY (view_id) REFERENCES tbl_views(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Seed danh mục view (auth & home là trang công khai, không nằm trong danh mục)
INSERT INTO tbl_views (module, controller, action, label, group_label, sort) VALUES
-- NHẬP KHO -----------------------------------------------------------
('inventory_management','inventory_management','dashboard',            'Nhập thành phẩm sản xuất',      'NHẬP KHO', 10),
('inventory_management','inventory_management','investment_products',  'Nhập giá vốn sản xuất',         'NHẬP KHO', 11),
('inventory_management','inventory_management','other_receipt',        'Nhập thành phẩm (khác)',        'NHẬP KHO', 13),
('inventory_management','inventory_management','sales_return_receipt', 'Nhập hàng bán trả lại',         'NHẬP KHO', 14),
('inventory_management','inventory_management','sales_issue',          'Xuất bán hàng (ghi nhận)',      'NHẬP KHO', 15),
-- NHẬP NGUYÊN VẬT LIỆU (hiển thị "Nhập kho hàng hóa" qua permission_group_display_label) ----
('inventory_receiving','inventory_receiving','row_material_receiving',       'Nhập mua hàng hóa',  'NHẬP NGUYÊN VẬT LIỆU', 20),
('inventory_receiving','inventory_receiving','other_row_material_receiving', 'Nhập nguyên vật liệu (khác)','NHẬP NGUYÊN VẬT LIỆU', 21),
-- XUẤT KHO -----------------------------------------------------------
('warehouse_outbound','warehouse_outbound','sales_delivery_note',      'Xuất kho bán hàng', 'XUẤT KHO', 30),
('warehouse_outbound','warehouse_outbound','other_warehouse_outbound', 'Xuất kho khác',     'XUẤT KHO', 31),
-- SẢN XUẤT -----------------------------------------------------------
('production_staff','production_staff','production_plan', 'Kế hoạch sản xuất',       'SẢN XUẤT', 40),
('production_staff','production_staff','plan_for_staff',  'Kế hoạch cho nhân viên',  'SẢN XUẤT', 41),
-- THU CHI ------------------------------------------------------------
('cash_transactions','cash_transactions','cash_transactions', 'Nhập thu chi', 'THU CHI', 50),
-- TỒN KHO --------------------------------------------------------------
('report','report','finished_goods_inventory', 'Tồn kho thành phẩm',       'TỒN KHO', 60),
('report','report','material_inventory',        'Tồn kho nguyên vật liệu',  'TỒN KHO', 61),
('report','report','stock_at_point',            'Tồn kho tại thời điểm',    'TỒN KHO', 62),
-- BÁN HÀNG - NHÀ MÁY -------------------------------------------------
('sell_factory','sell_factory','order_factory', 'Đặt hàng nhà máy', 'BÁN HÀNG - NHÀ MÁY', 70),
-- KHO SẢN PHẨM -------------------------------------------------------
('inventory_product','inventory_product','inventory_product', 'Kho sản phẩm', 'KHO SẢN PHẨM', 80),
-- NHÂN SỰ ------------------------------------------------------------
('hr','hr','dashboard',         'Tổng quan nhân sự',       'NHÂN SỰ', 90),
('hr','hr','list',              'Danh sách nhân sự',       'NHÂN SỰ', 91),
('hr','hr','organization',      'Sơ đồ tổ chức',           'NHÂN SỰ', 92),
('hr','hr','create_contract',   'Tạo hợp đồng',            'NHÂN SỰ', 93),
('hr','hr','process_list_hr',   'Xử lý hồ sơ nhân sự',     'NHÂN SỰ', 94),
('hr','hr','add',               'Thêm nhân sự',            'NHÂN SỰ', 95),
('hr','hr','edit',              'Sửa nhân sự',             'NHÂN SỰ', 96),
-- HỒ SƠ SẢN PHẨM -----------------------------------------------------
('product_profile','product_profile','dashboard',                'Tổng quan hồ sơ',         'HỒ SƠ SẢN PHẨM', 100),
('product_profile','product_profile','list',                     'Danh sách hồ sơ sản phẩm','HỒ SƠ SẢN PHẨM', 101),
('product_profile','product_profile','company_profile',          'Hồ sơ công ty',           'HỒ SƠ SẢN PHẨM', 102),
('product_profile','product_profile','standard_dossier',         'Hồ sơ tiêu chuẩn',        'HỒ SƠ SẢN PHẨM', 103),
('product_profile','product_profile','add_product',              'Thêm sản phẩm',           'HỒ SƠ SẢN PHẨM', 104),
('product_profile','product_profile','edit_product',             'Sửa sản phẩm',            'HỒ SƠ SẢN PHẨM', 105),
('product_profile','product_profile','product_detail',           'Chi tiết sản phẩm',       'HỒ SƠ SẢN PHẨM', 106),
('product_profile','product_profile','add_file',                 'Thêm tệp hồ sơ',          'HỒ SƠ SẢN PHẨM', 107),
('product_profile','product_profile','update_file',              'Cập nhật tệp hồ sơ',      'HỒ SƠ SẢN PHẨM', 108),
('product_profile','product_profile','add_file_supplier',        'Thêm tệp nhà cung cấp',   'HỒ SƠ SẢN PHẨM', 109),
('product_profile','product_profile','add_file_material',        'Thêm tệp nguyên liệu',    'HỒ SƠ SẢN PHẨM', 110),
('product_profile','product_profile','replace_composition_file', 'Thay tệp thành phần',     'HỒ SƠ SẢN PHẨM', 111),
-- ADMIN - NHÀ MÁY ----------------------------------------------------
('admin_factory','admin','manage_product_list',                  'Quản lý sản phẩm',          'ADMIN - NHÀ MÁY', 120),
('admin_factory','admin','manage_material_list',                 'Quản lý nguyên vật liệu',   'ADMIN - NHÀ MÁY', 121),
('admin_factory','admin','manage_supplier_list',                 'Quản lý nhà cung cấp',      'ADMIN - NHÀ MÁY', 122),
('admin_factory','admin','manage_customer_list',                 'Quản lý khách hàng',        'ADMIN - NHÀ MÁY', 123),
('admin_factory','admin','manage_user_list',                     'Quản lý người dùng',        'ADMIN - NHÀ MÁY', 124),
('admin_factory','admin','purchase_order',                       'Đơn mua hàng',              'ADMIN - NHÀ MÁY', 125),
('admin_factory','admin','sales_orders',                         'Đơn bán hàng',              'ADMIN - NHÀ MÁY', 126),
('admin_factory','admin','finished_product_production_data',      'DL: Thành phẩm sản xuất',   'ADMIN - NHÀ MÁY', 127),
('admin_factory','admin','purchased_finished_product_data',      'DL: Thành phẩm mua',        'ADMIN - NHÀ MÁY', 128),
('admin_factory','admin','raw_material_purchase_data',           'DL: Mua nguyên vật liệu',   'ADMIN - NHÀ MÁY', 129),
('admin_factory','admin','raw_material_production_issue_data',   'DL: Xuất NVL sản xuất',     'ADMIN - NHÀ MÁY', 130),
('admin_factory','admin','sale_inventory_issue_data',            'DL: Xuất kho bán hàng',     'ADMIN - NHÀ MÁY', 131),
('admin_factory','admin','accounting_data',                      'DL: Kế toán',               'ADMIN - NHÀ MÁY', 132),
('admin_factory','admin','cash_transactions_data',               'DL: Thu chi',               'ADMIN - NHÀ MÁY', 133),
-- BIỂU MẪU QL VSATTP -------------------------------------------------
('vsattp','vsattp','material_receiving',    'Phiếu tiếp nhận nguyên liệu đầu vào',    'BIỂU MẪU QL VSATTP', 140),
('vsattp','vsattp','production_log',        'Sổ sản xuất theo lô/mẻ',                 'BIỂU MẪU QL VSATTP', 141),
('vsattp','vsattp','process_control',       'Phiếu kiểm soát quá trình',              'BIỂU MẪU QL VSATTP', 142),
('vsattp','vsattp','finished_goods_ledger', 'Sổ nhập – xuất kho thành phẩm',          'BIỂU MẪU QL VSATTP', 143),
('vsattp','vsattp','sanitation_log',        'Sổ vệ sinh nhà xưởng – thiết bị',        'BIỂU MẪU QL VSATTP', 144),
('vsattp','vsattp','health_training_log',   'Sổ theo dõi sức khỏe & tập huấn',        'BIỂU MẪU QL VSATTP', 145),
('vsattp','vsattp','traceability',          'Hồ sơ truy xuất nguồn gốc',              'BIỂU MẪU QL VSATTP', 146);

-- 5) Đặt 1 tài khoản admin (đổi username nếu cần) -----------------------------
UPDATE tbl_users SET role = 'admin' WHERE username = 'truongngocsau';
