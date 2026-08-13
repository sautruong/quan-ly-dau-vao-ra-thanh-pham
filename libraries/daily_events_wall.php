<?php
/**
 * =====================================================================
 *  Thảo luận (daily_events "Tường") — thư viện dùng chung
 * =====================================================================
 *  Tường kiểu Facebook: user đăng bài (nội dung + ảnh/file), gắn thẻ user
 *  khác (đẩy chuông), thả emoji, bình luận + trả lời (lồng tối đa 1 cấp,
 *  thụt lề ở giao diện), hashtag (#tag) để tìm lại bài sau này.
 *
 *  CÁ NHÂN HÓA: mặc định 1 bài chỉ tác giả (user_id) mới thấy trên
 *  "Tường của tôi". User được gắn thẻ (de_wall_shares) CHỈ thấy bài đó
 *  trên tường mình sau khi tự bấm "Gắn vào tường" (pinned=1) — trước đó
 *  chỉ xem được qua link permalink trong chuông (dew_can_view_post bỏ
 *  qua điều kiện pinned). Admin KHÔNG mặc định thấy mọi bài (khác module
 *  daily_events cũ) — chỉ có quyền xóa (kiểm duyệt), xem dew_post_delete/
 *  dew_comment_delete.
 *
 *  Bảng: de_wall_posts, de_wall_attachments, de_wall_shares,
 *  de_wall_reactions, de_wall_comments, de_wall_hashtags.
 *  KHÔNG đụng tới de_items/de_comments/de_attachments/de_shares cũ của
 *  module daily_events cũ (xem libraries/daily_events.php) — dữ liệu cũ
 *  giữ nguyên trong DB, không còn hiển thị.
 *
 *  Prefix hàm: dew_*
 * =====================================================================
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('dew_ensure_tables')) {

    function dew_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS de_wall_posts (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            user_id     INT(11) NOT NULL,
            content     MEDIUMTEXT DEFAULT NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_wall_attachments (
            id            INT(11) NOT NULL AUTO_INCREMENT,
            owner_type    VARCHAR(10) NOT NULL,
            owner_id      INT(11) NOT NULL,
            file_url      VARCHAR(255) NOT NULL,
            original_name MEDIUMTEXT DEFAULT NULL,
            is_image      TINYINT(1) NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_owner (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_wall_shares (
            id              INT(11) NOT NULL AUTO_INCREMENT,
            post_id         INT(11) NOT NULL,
            tagged_user_id  INT(11) NOT NULL,
            tagged_by       INT(11) NOT NULL DEFAULT 0,
            pinned          TINYINT(1) NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            pinned_at       DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_tag (post_id, tagged_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_wall_reactions (
            id           INT(11) NOT NULL AUTO_INCREMENT,
            target_type  VARCHAR(10) NOT NULL,
            target_id    INT(11) NOT NULL,
            user_id      INT(11) NOT NULL,
            emoji        VARCHAR(16) NOT NULL,
            created_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_react (target_type, target_id, user_id),
            KEY idx_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_wall_comments (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            post_id     INT(11) NOT NULL,
            parent_id   INT(11) NOT NULL DEFAULT 0,
            user_id     INT(11) NOT NULL,
            content     MEDIUMTEXT DEFAULT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post (post_id),
            KEY idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_wall_hashtags (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            post_id     INT(11) NOT NULL,
            tag         VARCHAR(100) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_tag (tag),
            KEY idx_post (post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    define('DEW_PAGE_SIZE', 10);
    define('DEW_EDIT_WINDOW_SECONDS', 3600); // sửa được trong 60 phút kể từ lúc đăng
    // content mã hóa (crypto_encrypt) -> không SQL LIKE được, tìm theo nội dung phải giải mã
    // + lọc bằng PHP (xem dew_post_list) -> quét tối đa N bài gần nhất, không quét toàn bộ bảng.
    define('DEW_SEARCH_SCAN_LIMIT', 500);

    /* ============================================================
     *  Helpers chung
     * ============================================================ */
    function dew_enc($s) { return crypto_encrypt((string) $s); }
    function dew_dec($s) { return crypto_decrypt((string) $s); }

    function dew_user_brief($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return ['id' => 0, 'fullname' => 'Người dùng', 'avatar' => ''];
        $u = db_fetch_row("SELECT id, fullname, username, avatar FROM tbl_users WHERE id = $uid LIMIT 1");
        if (!$u) return ['id' => $uid, 'fullname' => 'Người dùng', 'avatar' => ''];
        $av = trim((string) ($u['avatar'] ?? ''));
        return [
            'id'       => $uid,
            'fullname' => (string) (($u['fullname'] ?? '') ?: $u['username']),
            'avatar'   => $av !== '' ? 'public/images/avatar/' . $av : '',
        ];
    }

    /** Trích #hashtag trong nội dung (Unicode, hỗ trợ tiếng Việt có dấu). */
    function dew_extract_hashtags($content)
    {
        $content = (string) $content;
        if ($content === '') return [];
        preg_match_all('/#([\p{L}\p{N}_]{2,100})/u', $content, $m);
        $tags = [];
        foreach ($m[1] as $t) {
            $tag = mb_strtolower($t, 'UTF-8');
            if ($tag !== '' && !in_array($tag, $tags, true)) $tags[] = $tag;
        }
        return $tags;
    }

    /** Gợi ý #hashtag đã dùng trước đây theo tiền tố đang gõ (bộ nhớ hashtag). */
    function dew_hashtag_suggest($prefix, $limit = 8)
    {
        dew_ensure_tables();
        $prefix = mb_strtolower(ltrim(trim((string) $prefix), '#'), 'UTF-8');
        $limit = max(1, (int) $limit);
        $where = '';
        if ($prefix !== '') {
            $where = "WHERE tag LIKE '" . escape_string($prefix) . "%'";
        }
        $rows = db_fetch_array(
            "SELECT tag, COUNT(*) AS cnt FROM de_wall_hashtags $where
             GROUP BY tag ORDER BY cnt DESC, tag ASC LIMIT $limit"
        ) ?: [];
        return array_map(function ($r) { return $r['tag']; }, $rows);
    }

    /* ============================================================
     *  Ảnh / file đính kèm (dùng chung post + comment)
     * ============================================================ */
    function dew_storage_dir()
    {
        $rel = 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'daily_events' . DIRECTORY_SEPARATOR . 'wall';
        $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
        if (!is_dir($abs)) @mkdir($abs, 0777, true);
        return $abs;
    }

    function dew_normalize_files($files)
    {
        $out = [];
        if (!is_array($files) || !isset($files['name'])) return $out;
        if (is_array($files['name'])) {
            $n = count($files['name']);
            for ($i = 0; $i < $n; $i++) {
                $out[] = [
                    'name'     => $files['name'][$i] ?? '',
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i] ?? 0,
                ];
            }
        } else {
            $out[] = $files;
        }
        return $out;
    }

    /** $owner_type: 'post' | 'comment'. Nhận ảnh lẫn file bất kỳ (chặn đuôi nguy hiểm). */
    function dew_save_attachments($owner_type, $owner_id, $files)
    {
        $owner_id = (int) $owner_id;
        $owner_type = in_array($owner_type, ['post', 'comment'], true) ? $owner_type : 'post';
        if ($owner_id <= 0) return ['ok' => false, 'saved' => [], 'errors' => ['Thiếu id.']];

        $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'htaccess'];
        $abs_dir = dew_storage_dir();
        $rel_web = 'public/uploads/daily_events/wall';

        $saved = [];
        $errors = [];
        foreach (dew_normalize_files($files) as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $errors[] = 'Tải lên thất bại: ' . ($f['name'] ?? ''); continue; }
            if ((int) $f['size'] > 25 * 1024 * 1024) { $errors[] = 'Vượt 25MB: ' . $f['name']; continue; }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $blocked, true)) { $errors[] = 'Định dạng không được phép: ' . $f['name']; continue; }

            $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
            $filename = 'w' . substr($owner_type, 0, 1) . $owner_id . '_' . time() . '_' . substr(md5($f['name'] . mt_rand()), 0, 8)
                      . ($safe_ext !== '' ? '.' . $safe_ext : '');
            $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;

            $moved = is_uploaded_file($f['tmp_name'])
                ? move_uploaded_file($f['tmp_name'], $abs_path)
                : @rename($f['tmp_name'], $abs_path);
            if (!$moved) { $errors[] = 'Không thể lưu tệp: ' . $f['name']; continue; }

            $is_image = @getimagesize($abs_path) !== false ? 1 : 0;
            $file_url = $rel_web . '/' . $filename;
            $id = (int) db_insert('de_wall_attachments', [
                'owner_type'    => $owner_type,
                'owner_id'      => $owner_id,
                'file_url'      => $file_url,
                'original_name' => dew_enc(mb_substr((string) $f['name'], 0, 250, 'UTF-8')),
                'is_image'      => $is_image,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            $saved[] = ['id' => $id, 'file_url' => $file_url, 'name' => (string) $f['name'], 'is_image' => $is_image === 1];
        }
        return ['ok' => empty($errors) || !empty($saved), 'saved' => $saved, 'errors' => $errors];
    }

    function dew_list_attachments($owner_type, $owner_id)
    {
        $owner_id = (int) $owner_id;
        $ot = escape_string($owner_type);
        $rows = db_fetch_array(
            "SELECT id, file_url, original_name, is_image FROM de_wall_attachments
             WHERE owner_type = '$ot' AND owner_id = $owner_id ORDER BY id ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'file_url' => $r['file_url'],
                'name'     => dew_dec($r['original_name'] ?? ''),
                'is_image' => (int) $r['is_image'] === 1,
            ];
        }
        return $out;
    }

    function dew_delete_attachments_for($owner_type, $owner_id)
    {
        $owner_id = (int) $owner_id;
        $ot = escape_string($owner_type);
        $rows = db_fetch_array("SELECT file_url FROM de_wall_attachments WHERE owner_type = '$ot' AND owner_id = $owner_id") ?: [];
        foreach ($rows as $row) {
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['file_url']);
            $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
            if (is_file($abs) && strpos($rel, 'wall') !== false) @unlink($abs);
        }
        db_delete('de_wall_attachments', "owner_type = '$ot' AND owner_id = $owner_id");
    }

    function dew_can_manage_attachment_owner($owner_type, $owner_id, $user_id)
    {
        $owner_id = (int) $owner_id;
        if ($owner_type === 'post') {
            $p = dew_post_row($owner_id);
            return $p && (int) $p['user_id'] === (int) $user_id;
        }
        if ($owner_type === 'comment') {
            $c = db_fetch_row("SELECT user_id FROM de_wall_comments WHERE id = $owner_id LIMIT 1");
            return $c && (int) $c['user_id'] === (int) $user_id;
        }
        return false;
    }

    function dew_attachment_delete($attachment_id, $user_id, $is_admin = false)
    {
        $id = (int) $attachment_id;
        $row = db_fetch_row("SELECT * FROM de_wall_attachments WHERE id = $id LIMIT 1");
        if (!$row) return false;
        if (!$is_admin && !dew_can_manage_attachment_owner($row['owner_type'], $row['owner_id'], $user_id)) return false;
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['file_url']);
        $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
        if (is_file($abs) && strpos($rel, 'wall') !== false) @unlink($abs);
        db_delete('de_wall_attachments', "id = $id");
        return true;
    }

    /* ============================================================
     *  Cảm xúc (post + comment) — toggle giống chat_react/pm_react.
     * ============================================================ */
    function dew_reactions_for($target_type, array $target_ids, $viewer_id = 0)
    {
        $ids = array_values(array_filter(array_map('intval', $target_ids), function ($v) { return $v > 0; }));
        if (!$ids) return [];
        $tt = escape_string($target_type);
        $in = implode(',', $ids);
        $me = (int) $viewer_id;
        $rows = db_fetch_array(
            "SELECT r.target_id, r.emoji, r.user_id, u.fullname, u.username
             FROM de_wall_reactions r LEFT JOIN tbl_users u ON u.id = r.user_id
             WHERE r.target_type = '$tt' AND r.target_id IN ($in)"
        ) ?: [];
        $tmp = [];
        foreach ($rows as $r) {
            $tid = (int) $r['target_id'];
            $em  = $r['emoji'];
            $uid = (int) $r['user_id'];
            if (!isset($tmp[$tid][$em])) $tmp[$tid][$em] = ['emoji' => $em, 'count' => 0, 'mine' => false, 'users' => []];
            $tmp[$tid][$em]['count']++;
            if ($uid === $me) $tmp[$tid][$em]['mine'] = true;
            $tmp[$tid][$em]['users'][] = $uid === $me ? 'Bạn' : (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? 'Người dùng'));
        }
        $out = [];
        foreach ($tmp as $tid => $emojis) $out[$tid] = array_values($emojis);
        return $out;
    }

    /** Bấm lại cùng emoji = gỡ; bấm emoji khác = đổi; chưa có = thêm mới. */
    function dew_react($target_type, $target_id, $user_id, $emoji)
    {
        dew_ensure_tables();
        $target_type = in_array($target_type, ['post', 'comment'], true) ? $target_type : 'post';
        $tid = (int) $target_id;
        $uid = (int) $user_id;
        $em  = trim((string) $emoji);
        if ($tid <= 0 || $uid <= 0 || $em === '') return [];
        if (mb_strlen($em, 'UTF-8') > 8) $em = mb_substr($em, 0, 8, 'UTF-8');

        $tt = escape_string($target_type);
        $cur = db_fetch_row("SELECT id, emoji FROM de_wall_reactions
                             WHERE target_type = '$tt' AND target_id = $tid AND user_id = $uid LIMIT 1");
        $is_new = false;
        if ($cur) {
            if ($cur['emoji'] === $em) {
                db_delete('de_wall_reactions', "id = " . (int) $cur['id']);
            } else {
                db_update('de_wall_reactions', ['emoji' => $em, 'created_at' => date('Y-m-d H:i:s')], "id = " . (int) $cur['id']);
            }
        } else {
            db_insert('de_wall_reactions', [
                'target_type' => $target_type, 'target_id' => $tid, 'user_id' => $uid,
                'emoji' => $em, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $is_new = true;
        }
        // Chỉ đẩy chuông khi vừa thả cảm xúc MỚI (không đẩy khi đổi/gỡ, tránh làm phiền).
        if ($is_new) dew_notify_reaction($target_type, $tid, $uid, $em);
        $map = dew_reactions_for($target_type, [$tid], $uid);
        return $map[$tid] ?? [];
    }

    /* ============================================================
     *  Bình luận + trả lời (lồng tối đa 1 cấp)
     * ============================================================ */
    function dew_comment_row($id)
    {
        $r = db_fetch_row("SELECT * FROM de_wall_comments WHERE id = " . (int) $id . " LIMIT 1");
        return $r ?: null;
    }

    /** Tác giả bài + mọi người đã bình luận (mọi cấp) + mọi người được gắn thẻ — dùng để
     *  "đẩy chuông hết" khi có bình luận mới/thích bài (không tính chính người gây ra). */
    function dew_post_participant_ids($post_id)
    {
        $post_id = (int) $post_id;
        $ids = [];
        $post = dew_post_row($post_id);
        if ($post) $ids[] = (int) $post['user_id'];
        foreach (db_fetch_array("SELECT DISTINCT user_id FROM de_wall_comments WHERE post_id = $post_id") ?: [] as $r) {
            $ids[] = (int) $r['user_id'];
        }
        foreach (db_fetch_array("SELECT DISTINCT tagged_user_id FROM de_wall_shares WHERE post_id = $post_id") ?: [] as $r) {
            $ids[] = (int) $r['tagged_user_id'];
        }
        return array_values(array_unique(array_filter($ids, function ($v) { return $v > 0; })));
    }

    /** Bình luận mới (gốc) -> đẩy chuông TẤT CẢ người liên quan bài; trả lời -> CHỈ đẩy cho
     *  chủ bình luận được trả lời (không đẩy cả luồng, tránh làm phiền người khác). */
    function dew_notify_comment($post_id, $parent_id, $content, $user_id)
    {
        if (!function_exists('notify_create')) return;
        $post_id = (int) $post_id;
        $user_id = (int) $user_id;
        $actor = dew_user_brief($user_id);
        $preview = mb_substr((string) $content, 0, 120, 'UTF-8');
        $link = '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . $post_id;

        if ($parent_id > 0) {
            $parent = db_fetch_row("SELECT user_id FROM de_wall_comments WHERE id = " . (int) $parent_id . " LIMIT 1");
            $rid = $parent ? (int) $parent['user_id'] : 0;
            if ($rid > 0 && $rid !== $user_id) {
                notify_create($rid, $actor['fullname'] . ' đã trả lời bình luận của bạn', $preview, $link, 'wall_reply', $user_id);
            }
            return;
        }
        foreach (dew_post_participant_ids($post_id) as $rid) {
            if ($rid === $user_id) continue;
            notify_create($rid, $actor['fullname'] . ' đã bình luận về một bài viết', $preview, $link, 'wall_comment', $user_id);
        }
    }

    /** Thích BÀI -> đẩy chuông tất cả người liên quan; thích BÌNH LUẬN -> chỉ đẩy cho
     *  đúng chủ bình luận đó (1-1, không lan ra cả luồng). Chỉ đẩy khi vừa thả mới
     *  (không đẩy khi đổi/gỡ cảm xúc, tránh làm phiền). */
    function dew_notify_reaction($target_type, $target_id, $user_id, $emoji)
    {
        if (!function_exists('notify_create')) return;
        $target_id = (int) $target_id;
        $user_id = (int) $user_id;
        $actor = dew_user_brief($user_id);

        if ($target_type === 'post') {
            $link = '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . $target_id;
            foreach (dew_post_participant_ids($target_id) as $rid) {
                if ($rid === $user_id) continue;
                notify_create($rid, $actor['fullname'] . ' đã thả cảm xúc ' . $emoji . ' vào bài viết', '', $link, 'wall_react', $user_id);
            }
            return;
        }
        $c = db_fetch_row("SELECT post_id, user_id FROM de_wall_comments WHERE id = $target_id LIMIT 1");
        if (!$c) return;
        $rid = (int) $c['user_id'];
        if ($rid === $user_id) return;
        $link = '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . (int) $c['post_id'];
        notify_create($rid, $actor['fullname'] . ' đã thả cảm xúc ' . $emoji . ' vào bình luận của bạn', '', $link, 'wall_react', $user_id);
    }

    function dew_comment_add($post_id, $content, $user_id, $parent_id = 0)
    {
        dew_ensure_tables();
        $post_id = (int) $post_id;
        $user_id = (int) $user_id;
        $parent_id = (int) $parent_id;
        $content = trim((string) $content);
        if (!dew_can_view_post($post_id, $user_id)) {
            return ['success' => false, 'message' => 'Bạn không có quyền xem bài viết này.'];
        }
        if ($content === '') return ['success' => false, 'message' => 'Vui lòng nhập nội dung.'];

        if ($parent_id > 0) {
            $parent = db_fetch_row("SELECT id, post_id, parent_id FROM de_wall_comments WHERE id = $parent_id LIMIT 1");
            if (!$parent || (int) $parent['post_id'] !== $post_id) {
                $parent_id = 0;
            } elseif ((int) $parent['parent_id'] > 0) {
                $parent_id = (int) $parent['parent_id']; // gộp về cấp cha gốc, giữ tối đa 1 cấp lồng
            }
        }

        $id = (int) db_insert('de_wall_comments', [
            'post_id'    => $post_id,
            'parent_id'  => $parent_id,
            'user_id'    => $user_id,
            'content'    => dew_enc($content),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0) return ['success' => false, 'message' => 'Không thể lưu bình luận.'];
        dew_notify_comment($post_id, $parent_id, $content, $user_id);
        return ['success' => true, 'id' => $id];
    }

    define('DEW_COMMENT_RECALL_SECONDS', 60); // chủ bình luận chỉ "thu hồi" được trong 1 phút đầu

    /** Chủ bình luận (không phải admin) chỉ xóa được chính bình luận của mình, trong 1 phút đầu. */
    function dew_comment_can_recall($row, $user_id)
    {
        if (!$row || (int) $row['user_id'] !== (int) $user_id) return false;
        return (time() - strtotime($row['created_at'])) <= DEW_COMMENT_RECALL_SECONDS;
    }

    function dew_format_comment($r, $viewer_id, $is_admin = false)
    {
        $cid = (int) $r['id'];
        $canRecall = dew_comment_can_recall($r, $viewer_id);
        $secondsLeft = $canRecall ? max(0, DEW_COMMENT_RECALL_SECONDS - (time() - strtotime($r['created_at']))) : 0;
        return [
            'id'         => $cid,
            'post_id'    => (int) $r['post_id'],
            'parent_id'  => (int) $r['parent_id'],
            'content'    => dew_dec($r['content'] ?? ''),
            'user'       => dew_user_brief($r['user_id']),
            'created_at' => $r['created_at'],
            'attachments'=> dew_list_attachments('comment', $cid),
            'reactions'  => [],
            'replies'    => [],
            // Chỉ chính chủ mới xóa được (thu hồi trong 1 phút) — không có ngoại lệ cho admin.
            'can_delete' => $canRecall,
            'recall_seconds_left' => $secondsLeft,
        ];
    }

    /** Toàn bộ bình luận của 1 bài, gộp thành cây 1 cấp: gốc -> replies[]. */
    function dew_post_comments($post_id, $viewer_id, $is_admin = false)
    {
        $post_id = (int) $post_id;
        $rows = db_fetch_array("SELECT * FROM de_wall_comments WHERE post_id = $post_id ORDER BY created_at ASC") ?: [];
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $reax = dew_reactions_for('comment', $ids, $viewer_id);

        $byId = [];
        $roots = [];
        foreach ($rows as $r) {
            $c = dew_format_comment($r, $viewer_id, $is_admin);
            $c['reactions'] = $reax[$c['id']] ?? [];
            $byId[$c['id']] = $c;
            if ($c['parent_id'] === 0) $roots[] = $c['id'];
        }
        foreach ($byId as $cid => $c) {
            if ($c['parent_id'] > 0 && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['replies'][] = $c;
            }
        }
        $out = [];
        foreach ($roots as $rid) $out[] = $byId[$rid];
        return $out;
    }

    /** Chỉ chính chủ bình luận mới xóa được, trong 1 phút đầu — không có ngoại lệ cho admin
     *  (khác bài viết: xem dew_post_delete, admin vẫn kiểm duyệt được bài đăng). */
    function dew_comment_delete($comment_id, $user_id)
    {
        $comment_id = (int) $comment_id;
        $row = dew_comment_row($comment_id);
        if (!$row) return ['success' => false, 'message' => 'Không tìm thấy bình luận.'];
        if ((int) $row['user_id'] !== (int) $user_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền xóa bình luận này.'];
        }
        if (!dew_comment_can_recall($row, $user_id)) {
            return ['success' => false, 'message' => 'Đã quá 1 phút, không thể thu hồi bình luận này.'];
        }
        // cascade replies con (nếu comment_id là bình luận gốc)
        $children = db_fetch_array("SELECT id FROM de_wall_comments WHERE parent_id = $comment_id") ?: [];
        foreach ($children as $ch) {
            dew_delete_attachments_for('comment', (int) $ch['id']);
            db_delete('de_wall_reactions', "target_type = 'comment' AND target_id = " . (int) $ch['id']);
        }
        db_delete('de_wall_comments', "parent_id = $comment_id");
        dew_delete_attachments_for('comment', $comment_id);
        db_delete('de_wall_reactions', "target_type = 'comment' AND target_id = $comment_id");
        db_delete('de_wall_comments', "id = $comment_id");
        return ['success' => true];
    }

    /* ============================================================
     *  Bài đăng + gắn thẻ (chia sẻ) + hiển thị (Tường)
     * ============================================================ */
    function dew_post_row($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        return db_fetch_row("SELECT * FROM de_wall_posts WHERE id = $id LIMIT 1");
    }

    /** Tác giả, hoặc bất kỳ ai được gắn thẻ (kể cả chưa ghim) — dùng cho permalink từ chuông. */
    function dew_can_view_post($post_id, $viewer_id)
    {
        $post = dew_post_row($post_id);
        if (!$post) return false;
        $viewer_id = (int) $viewer_id;
        if ((int) $post['user_id'] === $viewer_id) return true;
        return (bool) db_fetch_row(
            "SELECT id FROM de_wall_shares WHERE post_id = " . (int) $post_id . " AND tagged_user_id = $viewer_id LIMIT 1"
        );
    }

    function dew_post_add($user_id, $content, array $tagged_uids = [], $has_attachments = false)
    {
        dew_ensure_tables();
        $user_id = (int) $user_id;
        $content = trim((string) $content);
        if ($user_id <= 0) return ['success' => false, 'message' => 'Thiếu người đăng.'];
        if ($content === '' && !$has_attachments) {
            return ['success' => false, 'message' => 'Vui lòng nhập nội dung hoặc đính kèm ảnh/file.'];
        }

        $id = (int) db_insert('de_wall_posts', [
            'user_id'    => $user_id,
            'content'    => dew_enc($content),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0) return ['success' => false, 'message' => 'Không thể đăng bài.'];

        foreach (dew_extract_hashtags($content) as $tag) {
            db_insert('de_wall_hashtags', ['post_id' => $id, 'tag' => $tag]);
        }

        $author = dew_user_brief($user_id);
        $tagged_uids = array_values(array_unique(array_filter(array_map('intval', $tagged_uids), function ($v) use ($user_id) {
            return $v > 0 && $v !== $user_id;
        })));
        foreach ($tagged_uids as $tuid) {
            db_insert('de_wall_shares', [
                'post_id'        => $id,
                'tagged_user_id' => $tuid,
                'tagged_by'      => $user_id,
                'pinned'         => 0,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('notify_create')) {
                $preview = mb_substr($content, 0, 120, 'UTF-8');
                notify_create(
                    $tuid,
                    $author['fullname'] . ' đã gắn thẻ bạn trong một bài viết',
                    $preview,
                    '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . $id,
                    'wall_tag',
                    $user_id
                );
            }
        }
        return ['success' => true, 'id' => $id];
    }

    /** Chủ bài viết còn trong cửa sổ 60 phút đầu (kể từ lúc đăng) mới được sửa nội dung. */
    function dew_post_can_edit($post_row, $viewer_id)
    {
        if (!$post_row) return false;
        if ((int) $post_row['user_id'] !== (int) $viewer_id) return false;
        return (time() - strtotime($post_row['created_at'])) <= DEW_EDIT_WINDOW_SECONDS;
    }

    /** Sửa nội dung bài viết (chỉ chủ bài, trong 60 phút đầu) — trích lại hashtag theo nội dung mới. */
    /** $tagged_uids = null -> không đụng danh sách gắn thẻ; mảng -> đồng bộ lại (thêm mới
     *  đẩy chuông, gỡ bớt xóa share, giữ nguyên trạng thái pinned của người vẫn còn được gắn). */
    function dew_post_update($post_id, $content, $user_id, $tagged_uids = null)
    {
        dew_ensure_tables();
        $post_id = (int) $post_id;
        $row = dew_post_row($post_id);
        if (!$row) return ['success' => false, 'message' => 'Không tìm thấy bài viết.'];
        if (!dew_post_can_edit($row, $user_id)) {
            return ['success' => false, 'message' => 'Đã quá 60 phút, không thể sửa bài viết này.'];
        }
        $content = trim((string) $content);
        $has_attachments = !empty(dew_list_attachments('post', $post_id));
        if ($content === '' && !$has_attachments) {
            return ['success' => false, 'message' => 'Vui lòng nhập nội dung hoặc đính kèm ảnh/file.'];
        }
        db_update('de_wall_posts', [
            'content'    => dew_enc($content),
            'updated_at' => date('Y-m-d H:i:s'),
        ], "id = $post_id");

        db_delete('de_wall_hashtags', "post_id = $post_id");
        foreach (dew_extract_hashtags($content) as $tag) {
            db_insert('de_wall_hashtags', ['post_id' => $post_id, 'tag' => $tag]);
        }

        if (is_array($tagged_uids)) {
            $new_uids = array_values(array_unique(array_filter(array_map('intval', $tagged_uids), function ($v) use ($user_id) {
                return $v > 0 && $v !== $user_id;
            })));
            $cur_rows = db_fetch_array("SELECT tagged_user_id FROM de_wall_shares WHERE post_id = $post_id") ?: [];
            $cur_uids = array_map(function ($r) { return (int) $r['tagged_user_id']; }, $cur_rows);

            $to_add = array_diff($new_uids, $cur_uids);
            $to_remove = array_diff($cur_uids, $new_uids);

            foreach ($to_remove as $rid) {
                db_delete('de_wall_shares', "post_id = $post_id AND tagged_user_id = " . (int) $rid);
            }
            if ($to_add) {
                $author = dew_user_brief($user_id);
                $preview = mb_substr($content, 0, 120, 'UTF-8');
                foreach ($to_add as $tuid) {
                    db_insert('de_wall_shares', [
                        'post_id'        => $post_id,
                        'tagged_user_id' => $tuid,
                        'tagged_by'      => $user_id,
                        'pinned'         => 0,
                        'created_at'     => date('Y-m-d H:i:s'),
                    ]);
                    if (function_exists('notify_create')) {
                        notify_create(
                            $tuid,
                            $author['fullname'] . ' đã gắn thẻ bạn trong một bài viết',
                            $preview,
                            '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . $post_id,
                            'wall_tag',
                            $user_id
                        );
                    }
                }
            }
        }
        return ['success' => true];
    }

    /** CHIA SẺ LẠI một bài ĐÃ ĐĂNG cho user khác (anh Sáu chốt 13/8/2026).
     *  Khác dew_post_update(): KHÔNG bị bó trong cửa sổ sửa 60 phút và chỉ THÊM người,
     *  không gỡ ai — bài cũ tới đâu cũng chia sẻ lại được. Người nhận được đẩy chuông,
     *  mở link permalink vào xem + bình luận ngay (chưa cần bấm "Gắn vào tường").
     *  Quyền: ai đang xem được bài thì chia sẻ tiếp được (tác giả, người đã được gắn
     *  thẻ, hoặc admin) — giống chuyển tiếp tin nhắn của chat. */
    function dew_post_share_add($post_id, array $user_ids, $actor_id, $is_admin = false)
    {
        dew_ensure_tables();
        $post_id = (int) $post_id;
        $actor_id = (int) $actor_id;
        $row = dew_post_row($post_id);
        if (!$row) return ['success' => false, 'message' => 'Không tìm thấy bài viết.'];
        if (!$is_admin && !dew_can_view_post($post_id, $actor_id)) {
            return ['success' => false, 'message' => 'Bạn không có quyền chia sẻ bài viết này.'];
        }

        $author_id = (int) $row['user_id'];
        $uids = array_values(array_unique(array_filter(array_map('intval', $user_ids), function ($v) use ($author_id) {
            return $v > 0 && $v !== $author_id; // tác giả luôn thấy bài của mình, khỏi gắn thẻ lại
        })));
        if (!$uids) return ['success' => false, 'message' => 'Chưa chọn người nhận.'];

        $cur_rows = db_fetch_array("SELECT tagged_user_id FROM de_wall_shares WHERE post_id = $post_id") ?: [];
        $cur_uids = array_map(function ($r) { return (int) $r['tagged_user_id']; }, $cur_rows);

        $actor   = dew_user_brief($actor_id);
        $preview = mb_substr(dew_dec($row['content'] ?? ''), 0, 120, 'UTF-8');
        $added = 0;
        foreach ($uids as $tuid) {
            if (in_array($tuid, $cur_uids, true)) continue; // đã được chia sẻ trước đó
            db_insert('de_wall_shares', [
                'post_id'        => $post_id,
                'tagged_user_id' => $tuid,
                'tagged_by'      => $actor_id,
                'pinned'         => 0,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            $added++;
            if (function_exists('notify_create')) {
                notify_create(
                    $tuid,
                    $actor['fullname'] . ' đã chia sẻ một bài viết với bạn',
                    $preview,
                    '?mod=daily_events&controllers=daily_events&action=daily_events&post=' . $post_id,
                    'wall_tag',
                    $actor_id
                );
            }
        }
        return [
            'success' => true,
            'added'   => $added,
            'message' => $added ? ('Đã chia sẻ cho ' . $added . ' người.') : 'Những người này đã được chia sẻ bài viết rồi.',
        ];
    }

    /** CHIA SẺ 1 ẢNH/TỆP ĐÍNH KÈM CỦA TƯỜNG QUA CHAT (anh Sáu chốt 13/8/2026).
     *  Sao chép file sang kho của chat (public/uploads/chat) rồi gửi như một tin nhắn
     *  bình thường — KHÔNG dùng lại đường dẫn cũ, để bài viết bị xóa (kèm file) thì
     *  tin nhắn đã gửi vẫn còn ảnh. */
    function dew_attachment_share_to_chat($attachment_id, array $user_ids, array $conversation_ids, $actor_id, $is_admin = false)
    {
        dew_ensure_tables();
        $id = (int) $attachment_id;
        $actor_id = (int) $actor_id;
        if ($actor_id <= 0) return ['success' => false, 'message' => 'Chưa đăng nhập.'];

        $att = db_fetch_row("SELECT * FROM de_wall_attachments WHERE id = $id LIMIT 1");
        if (!$att) return ['success' => false, 'message' => 'Không tìm thấy tệp.'];

        // Quyền xem tệp = quyền xem bài chứa nó.
        $owner_type = (string) $att['owner_type'];
        $post_id = $owner_type === 'comment'
            ? (int) (dew_comment_row((int) $att['owner_id'])['post_id'] ?? 0)
            : (int) $att['owner_id'];
        if (!$is_admin && !dew_can_view_post($post_id, $actor_id)) {
            return ['success' => false, 'message' => 'Bạn không có quyền chia sẻ tệp này.'];
        }

        require_once __DIR__ . '/chat.php';
        chat_ensure_tables();

        // Gom hội thoại đích: hội thoại có sẵn (phải là thành viên) + chat 1-1 với từng user.
        $targets = [];
        foreach ($conversation_ids as $cid) {
            $cid = (int) $cid;
            if ($cid > 0 && chat_is_participant($cid, $actor_id)) $targets[$cid] = true;
        }
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || $uid === $actor_id) continue;
            $cid = chat_get_or_create_direct($actor_id, $uid);
            if ($cid > 0) $targets[$cid] = true;
        }
        if (!$targets) return ['success' => false, 'message' => 'Chưa chọn nơi nhận.'];

        // Chép file sang kho chat.
        $rel_src = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $att['file_url']);
        $abs_src = APPPATH . DIRECTORY_SEPARATOR . $rel_src;
        if (!is_file($abs_src)) return ['success' => false, 'message' => 'Tệp gốc không còn trên máy chủ.'];

        $chat_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
        if (!is_dir($chat_dir)) @mkdir($chat_dir, 0775, true);
        $ext = strtolower(pathinfo($abs_src, PATHINFO_EXTENSION));
        $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
        $filename = 'c' . time() . '_' . substr(md5($att['file_url'] . uniqid('', true)), 0, 10)
                  . ($safe_ext !== '' ? '.' . $safe_ext : '');
        if (!@copy($abs_src, $chat_dir . DIRECTORY_SEPARATOR . $filename)) {
            return ['success' => false, 'message' => 'Không sao chép được tệp.'];
        }

        $is_image = (int) $att['is_image'] === 1;
        $orig = dew_dec($att['original_name'] ?? '');
        if ($orig === '') $orig = basename($rel_src);
        $size = (int) @filesize($abs_src);

        $count = 0;
        foreach (array_keys($targets) as $cid) {
            $mid = chat_insert_message($cid, $actor_id, '', $is_image ? 'image' : 'file');
            db_insert('chat_attachments', [
                'message_id'    => $mid,
                'file_name'     => $filename,
                'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
                'mime'          => '',
                'size'          => $size,
                'is_image'      => $is_image ? 1 : 0,
            ]);
            chat_mark_read($cid, $actor_id, $mid);
            $count++;
        }
        return ['success' => true, 'count' => $count, 'message' => 'Đã gửi tới ' . $count . ' cuộc trò chuyện.'];
    }

    /** Chỉ user đã được gắn thẻ ở bài này mới gọi được — bật/tắt ghim vào tường mình. */
    function dew_pin_toggle($post_id, $user_id)
    {
        dew_ensure_tables();
        $post_id = (int) $post_id;
        $user_id = (int) $user_id;
        $share = db_fetch_row("SELECT id, pinned FROM de_wall_shares WHERE post_id = $post_id AND tagged_user_id = $user_id LIMIT 1");
        if (!$share) return ['success' => false, 'message' => 'Bạn không được gắn thẻ ở bài này.'];
        $new = (int) $share['pinned'] === 1 ? 0 : 1;
        db_update('de_wall_shares', [
            'pinned'    => $new,
            'pinned_at' => $new === 1 ? date('Y-m-d H:i:s') : null,
        ], "id = " . (int) $share['id']);
        return ['success' => true, 'pinned' => $new === 1];
    }

    function dew_format_post($r, $viewer_id, $is_admin = false)
    {
        $pid = (int) $r['id'];
        $tags = db_fetch_array(
            "SELECT s.tagged_user_id, s.pinned, u.fullname, u.username, u.avatar
             FROM de_wall_shares s LEFT JOIN tbl_users u ON u.id = s.tagged_user_id
             WHERE s.post_id = $pid ORDER BY s.created_at ASC"
        ) ?: [];
        $tag_list = [];
        $my_tag = null;
        foreach ($tags as $t) {
            $tuid = (int) $t['tagged_user_id'];
            $pinned = (int) $t['pinned'] === 1;
            $av = trim((string) ($t['avatar'] ?? ''));
            $tag_list[] = [
                'user_id'  => $tuid,
                'fullname' => (string) (($t['fullname'] ?? '') ?: ($t['username'] ?? '')),
                'avatar'   => $av !== '' ? 'public/images/avatar/' . $av : '',
                'pinned'   => $pinned,
            ];
            if ($tuid === (int) $viewer_id) $my_tag = ['pinned' => $pinned];
        }
        $comments = dew_post_comments($pid, $viewer_id, $is_admin);
        $reax = dew_reactions_for('post', [$pid], $viewer_id);
        $comment_count = count($comments);
        foreach ($comments as $c) $comment_count += count($c['replies']);

        $can_edit = dew_post_can_edit($r, $viewer_id);
        $edit_seconds_left = $can_edit ? max(0, DEW_EDIT_WINDOW_SECONDS - (time() - strtotime($r['created_at']))) : 0;

        return [
            'id'             => $pid,
            'user'           => dew_user_brief($r['user_id']),
            'is_mine'        => (int) $r['user_id'] === (int) $viewer_id,
            'content'        => dew_dec($r['content'] ?? ''),
            'created_at'     => $r['created_at'],
            'attachments'    => dew_list_attachments('post', $pid),
            'tags'           => $tag_list,
            'my_tag'         => $my_tag,
            'reactions'      => $reax[$pid] ?? [],
            'comments'       => $comments,
            'comment_count'  => $comment_count,
            'can_delete'     => $is_admin || (int) $r['user_id'] === (int) $viewer_id,
            'can_edit'       => $can_edit,
            'edit_seconds_left' => $edit_seconds_left,
        ];
    }

    function dew_feed_where($viewer_id)
    {
        $viewer_id = (int) $viewer_id;
        return "(p.user_id = $viewer_id OR EXISTS (
                    SELECT 1 FROM de_wall_shares s WHERE s.post_id = p.id AND s.tagged_user_id = $viewer_id AND s.pinned = 1
                ))";
    }

    /** Feed "Tường của tôi" (tự đăng + được gắn thẻ đã ghim), phân trang + lọc theo #hashtag. */
    function dew_post_list($viewer_id, $page = 1, $hashtag = '', $is_admin = false, $keyword = '')
    {
        dew_ensure_tables();
        $viewer_id = (int) $viewer_id;
        $page = max(1, (int) $page);
        $per_page = DEW_PAGE_SIZE;
        $offset = ($page - 1) * $per_page;

        $where = dew_feed_where($viewer_id);
        $join = '';
        $tag = trim((string) $hashtag);
        if ($tag !== '') {
            $tag = mb_strtolower(ltrim($tag, '#'), 'UTF-8');
            $join = "INNER JOIN de_wall_hashtags h ON h.post_id = p.id AND h.tag = '" . escape_string($tag) . "'";
        }
        // Tìm theo NỘI DUNG bài đăng — kết hợp được với lọc #hashtag ở trên (cả 2 cùng lúc),
        // hoặc đứng riêng (tìm trên toàn bộ bài, không giới hạn hashtag). de_wall_posts.content
        // đã MÃ HÓA (crypto_encrypt) nên KHÔNG dùng SQL LIKE được — phải giải mã rồi lọc bằng
        // PHP (giống chat_search_messages, xem memory personal-data-encryption "Gotcha tìm kiếm").
        $kw = trim((string) $keyword);

        if ($kw === '') {
            $total_row = db_fetch_row("SELECT COUNT(DISTINCT p.id) AS n FROM de_wall_posts p $join WHERE $where");
            $total = (int) ($total_row['n'] ?? 0);

            $rows = db_fetch_array(
                "SELECT DISTINCT p.* FROM de_wall_posts p $join WHERE $where
                 ORDER BY p.created_at DESC LIMIT $per_page OFFSET $offset"
            ) ?: [];
        } else {
            // Quét tối đa DEW_SEARCH_SCAN_LIMIT bài gần nhất trong phạm vi where/join, giải mã
            // từng bài rồi lọc theo keyword — bài quá cũ ngoài phạm vi quét sẽ không ra kết quả.
            $candidates = db_fetch_array(
                "SELECT DISTINCT p.* FROM de_wall_posts p $join WHERE $where
                 ORDER BY p.created_at DESC LIMIT " . DEW_SEARCH_SCAN_LIMIT
            ) ?: [];
            $matched = [];
            foreach ($candidates as $r) {
                if (mb_stripos(dew_dec($r['content'] ?? ''), $kw) !== false) $matched[] = $r;
            }
            $total = count($matched);
            $rows = array_slice($matched, $offset, $per_page);
        }

        $out = [];
        foreach ($rows as $r) $out[] = dew_format_post($r, $viewer_id, $is_admin);
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** 1 bài theo permalink (chuông) — bỏ qua điều kiện "pinned". */
    function dew_post_get_single($post_id, $viewer_id, $is_admin = false)
    {
        $post_id = (int) $post_id;
        if (!$is_admin && !dew_can_view_post($post_id, $viewer_id)) return null;
        $row = dew_post_row($post_id);
        if (!$row) return null;
        $post = dew_format_post($row, $viewer_id, $is_admin);
        $post['is_tagged_not_pinned'] = $post['my_tag'] !== null && !$post['my_tag']['pinned'];
        return $post;
    }

    /** Snapshot nhiều bài cùng lúc (poll realtime cảm xúc/bình luận) — chỉ trả bài viewer còn xem được. */
    function dew_posts_snapshot(array $post_ids, $viewer_id, $is_admin = false)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), function ($v) { return $v > 0; })));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $rows = db_fetch_array("SELECT * FROM de_wall_posts WHERE id IN ($in)") ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!$is_admin && !dew_can_view_post((int) $r['id'], $viewer_id)) continue;
            $out[] = dew_format_post($r, $viewer_id, $is_admin);
        }
        return $out;
    }

    function dew_post_delete($post_id, $user_id, $is_admin = false)
    {
        $post_id = (int) $post_id;
        $row = dew_post_row($post_id);
        if (!$row) return ['success' => false, 'message' => 'Không tìm thấy bài viết.'];
        if (!$is_admin && (int) $row['user_id'] !== (int) $user_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền xóa bài viết này.'];
        }
        dew_delete_attachments_for('post', $post_id);
        foreach (db_fetch_array("SELECT id FROM de_wall_comments WHERE post_id = $post_id") ?: [] as $c) {
            dew_delete_attachments_for('comment', (int) $c['id']);
            db_delete('de_wall_reactions', "target_type = 'comment' AND target_id = " . (int) $c['id']);
        }
        db_delete('de_wall_comments', "post_id = $post_id");
        db_delete('de_wall_reactions', "target_type = 'post' AND target_id = $post_id");
        db_delete('de_wall_shares', "post_id = $post_id");
        db_delete('de_wall_hashtags', "post_id = $post_id");
        db_delete('de_wall_posts', "id = $post_id");
        return ['success' => true];
    }
}
