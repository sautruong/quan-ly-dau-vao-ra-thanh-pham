<?php

/**
 * coffee_date_label — Tem date cà phê
 *
 * Tem nhỏ 60x20mm chỉ ghi tên dòng sản phẩm + NSX/HSD, không lưu trữ dữ liệu
 * (tạo ra để in rồi thôi). Tên dòng sản phẩm do người dùng gõ tự do mỗi lần
 * tạo tem (không tham chiếu bảng products) nên model chỉ còn việc đăng ký view.
 *
 * Prefix hàm: cdl_*.
 */

/* ============================================================
 *  Đăng ký view (tự chạy, không cần migration tay)
 * ============================================================ */

function cdl_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('coffee_date_label','coffee_date_label','coffee_date_label',
                      'Tem date cà phê','SẢN XUẤT', 49)");
}
