<?php
/* =====================================================================
 *  THƯ VIỆN MÃ HÓA DỮ LIỆU CÁ NHÂN HÓA (AES-256-CBC, khóa chung)
 *  ---------------------------------------------------------------------
 *  Mục đích: mã hóa thuận-nghịch các trường dữ liệu CÁ NHÂN HÓA của mỗi
 *  user trước khi lưu DB (vd: task_groups.title, task_items.content,
 *  todo_lists.title, todo_items.content, chat_messages.body...), và
 *  giải mã khi đọc ra để hiển thị bình thường (tiếng Việt nguyên vẹn).
 *
 *  KHÔNG dùng cho dữ liệu DÙNG CHUNG của hệ thống (sản phẩm, NVL, đơn
 *  hàng, công thức...) — chỉ dữ liệu riêng của từng cá nhân.
 *
 *  Định dạng giá trị mã hóa: 'enc:v1:' . base64(IV(16 byte) . ciphertext)
 *  - Tiền tố 'enc:v1:' để (1) nhận diện đã mã hóa, (2) tương thích ngược:
 *    dữ liệu CŨ chưa mã hóa (không có tiền tố) vẫn đọc được như thường.
 *
 *  Lib này KHÔNG autoload — require_once tại điểm dùng (giống
 *  notifications.php / todos.php / chat.php).
 * ===================================================================== */

if (!function_exists('crypto_key')) {

    /** Lấy khóa 32 byte (AES-256) từ $config['crypto_key'] (config/crypto.php). */
    function crypto_key()
    {
        static $key = null;
        if ($key !== null) return $key;

        global $config;
        $raw = isset($config['crypto_key']) ? (string) $config['crypto_key'] : '';
        // Fallback: nạp trực tiếp file config nếu chưa có (phòng khi lib được
        // require ở ngữ cảnh chưa bootstrap đủ).
        if ($raw === '' && defined('CONFIGPATH') && is_file(CONFIGPATH . DIRECTORY_SEPARATOR . 'crypto.php')) {
            require CONFIGPATH . DIRECTORY_SEPARATOR . 'crypto.php';
            $raw = isset($config['crypto_key']) ? (string) $config['crypto_key'] : '';
        }
        if ($raw === '') {
            // Khóa dự phòng cuối cùng — vẫn hoạt động nhưng nên cấu hình thật.
            $raw = 'safeking-default-insecure-key-please-configure';
        }
        // Dẫn xuất 32 byte ổn định từ chuỗi khóa cấu hình.
        $key = hash('sha256', $raw, true);
        return $key;
    }

    /** Một giá trị có phải đã mã hóa (đúng định dạng enc:v1:) hay không. */
    function crypto_is_encrypted($value)
    {
        return is_string($value) && strncmp($value, 'enc:v1:', 7) === 0;
    }

    /**
     * Mã hóa 1 chuỗi. Trả về 'enc:v1:...'.
     * - null / chuỗi rỗng: trả nguyên (không mã hóa chuỗi rỗng cho gọn).
     * - đã mã hóa rồi: trả nguyên (idempotent, tránh mã hóa 2 lần).
     */
    function crypto_encrypt($plain)
    {
        if ($plain === null) return null;
        $plain = (string) $plain;
        if ($plain === '') return $plain;
        if (crypto_is_encrypted($plain)) return $plain;

        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            // Mã hóa lỗi: trả bản rõ để không mất dữ liệu (an toàn-fail-open ở mức lưu).
            return $plain;
        }
        return 'enc:v1:' . base64_encode($iv . $cipher);
    }

    /**
     * Giải mã 1 giá trị.
     * - Không có tiền tố enc:v1: -> trả nguyên (dữ liệu cũ/plaintext).
     * - Giải mã lỗi -> trả chuỗi rỗng (tránh lộ ciphertext ra giao diện).
     */
    function crypto_decrypt($value)
    {
        if ($value === null) return null;
        $value = (string) $value;
        if (!crypto_is_encrypted($value)) return $value;

        $blob = base64_decode(substr($value, 7), true);
        if ($blob === false || strlen($blob) <= 16) return '';
        $iv     = substr($blob, 0, 16);
        $cipher = substr($blob, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', crypto_key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}
