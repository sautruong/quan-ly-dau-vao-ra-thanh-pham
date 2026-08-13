<?php

/*
 * ---------------------------------------------------------
 *  WEB PUSH — KHÓA VAPID (FILE MẪU, có lên git)
 * ---------------------------------------------------------
 *  Đây là bản mẫu KHÔNG chứa khóa thật, chỉ để biết cần những gì.
 *
 *  Cách dùng trên MÁY CHỦ:
 *    1. Chép file này thành config/push.php  (bản thật bị .gitignore chặn,
 *       nên `git pull` sẽ không bao giờ đè mất nó).
 *    2. Sinh cặp khóa: đăng nhập bằng tài khoản admin rồi mở
 *       /?mod=push&controllers=push&action=genkeys
 *       — trang đó in ra 2 chuỗi, dán vào bên dưới.
 *    3. Xong. Không cần restart gì cả.
 *
 *  Vì sao mỗi máy một cặp khóa: đăng ký push gắn chặt với tên miền, nên máy
 *  local và máy chủ vốn đã là hai tập thiết bị khác nhau — dùng khóa khác nhau
 *  hoàn toàn bình thường và an toàn hơn (khóa máy chủ không nằm trên máy code).
 *
 *  ĐỔI KHÓA = MẤT HẾT ĐĂNG KÝ CŨ. Sinh 1 lần rồi giữ nguyên mãi.
 */

// Phải là mailto:... hoặc https://... — dịch vụ push dùng để liên hệ khi có sự cố.
$config['push_vapid_subject'] = 'mailto:doi-dia-chi-nay@example.com';

// Chuỗi 87 ký tự (khóa công khai P-256, dạng base64url).
$config['push_vapid_public']  = '';

// Chuỗi 43 ký tự (khóa riêng). TUYỆT ĐỐI không đưa lên git, không nhúng vào JS.
$config['push_vapid_private'] = '';
