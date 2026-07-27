<?php
/* =====================================================================
 *  CHAT WIDGET (góc phải dưới màn hình, kiểu Messenger)
 *  ---------------------------------------------------------------------
 *  Gọi bằng: get_template_part('chat-widget')  hoặc require trực tiếp.
 *  - Nút tròn nổi (launcher) + badge số tin chưa đọc.
 *  - Panel: danh bạ ↔ hội thoại ↔ khung chat (do JS điều khiển).
 *  Chỉ render khi đã đăng nhập. Self-contained (CSS/JS riêng).
 * ===================================================================== */

if (!empty($GLOBALS['__chat_widget_rendered'])) { return; }
$GLOBALS['__chat_widget_rendered'] = true;

$__chat_user = function_exists('permission_current_user') ? permission_current_user() : null;
if (!$__chat_user) { return; } // chưa đăng nhập → không hiển thị

// Cache-busting theo mtime file — tránh trình duyệt/khách giữ bản CSS/JS cũ sau khi cập nhật tính năng.
$__chat_css = __DIR__ . '/../public/css/shared/chat.css';
$__chat_js  = __DIR__ . '/../public/js/shared/chat.js';
$__chat_css_v = is_file($__chat_css) ? filemtime($__chat_css) : time();
$__chat_js_v  = is_file($__chat_js)  ? filemtime($__chat_js)  : time();
?>
<link rel="stylesheet" href="public/css/shared/chat.css?v=<?php echo $__chat_css_v; ?>">
<link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/user_card.css'); ?>">

<div id="chat-widget" class="chat-widget" data-me-id="<?php echo (int) ($__chat_user['id'] ?? 0); ?>">
    <!-- Nút nổi -->
    <button type="button" class="chat-launcher" id="chat-launcher" aria-label="Tin nhắn">
        <i class="fa-solid fa-comment-dots"></i>
        <span class="chat-launcher-badge" id="chat-launcher-badge" style="display:none;">0</span>
    </button>

    <!-- Bảng chat -->
    <div class="chat-panel" id="chat-panel" aria-hidden="true">

        <!-- A. DANH BẠ / HỘI THOẠI -->
        <div class="chat-home" id="chat-home">
            <div class="chat-head">
                <div class="chat-head-title"><i class="fa-solid fa-comments"></i> Tin nhắn</div>
                <div class="chat-head-actions">
                    <button type="button" class="chat-icon-btn" id="chat-settings" title="Cài đặt">
                        <i class="fa-solid fa-gear"></i>
                    </button>
                    <button type="button" class="chat-icon-btn" id="chat-new-group" title="Tạo nhóm">
                        <i class="fa-solid fa-user-group"></i>
                    </button>
                    <button type="button" class="chat-icon-btn" id="chat-close" title="Đóng">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="chat-tabs">
                <button type="button" class="chat-tab is-active" data-tab="recent">Gần đây</button>
                <button type="button" class="chat-tab" data-tab="contacts">Danh bạ</button>
            </div>

            <div class="chat-search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="chat-home-search" placeholder="Tìm người hoặc nhóm…" autocomplete="off">
            </div>

            <div class="chat-list" id="chat-recent-list">
                <div class="chat-empty">Đang tải…</div>
            </div>
            <div class="chat-list" id="chat-contacts-list" style="display:none;">
                <div class="chat-empty">Đang tải…</div>
            </div>
        </div>

        <!-- B. KHUNG CHAT -->
        <div class="chat-room" id="chat-room" style="display:none;">
            <div class="chat-head chat-room-head">
                <button type="button" class="chat-icon-btn" id="chat-back" title="Quay lại">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <!-- Avatar: hover hiện máy ảnh (đổi ảnh nhóm) -->
                <span class="chat-room-avatar" id="chat-room-avatar" title="Đổi ảnh đại diện">
                    <span class="chat-room-avatar-cam"><i class="fa-solid fa-camera"></i></span>
                </span>
                <input type="file" id="chat-room-avatar-input" accept="image/*" hidden>
                <div class="chat-room-meta">
                    <!-- Tên: hover hiện cây bút (đổi tên nhóm) -->
                    <div class="chat-room-name-wrap">
                        <span class="chat-room-name" id="chat-room-name"></span>
                        <button type="button" class="chat-room-name-edit" id="chat-room-name-edit" title="Đổi tên nhóm">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    </div>
                    <div class="chat-room-sub" id="chat-room-sub" title="Xem thành viên"></div>
                </div>
                <div class="chat-head-actions">
                    <button type="button" class="chat-icon-btn" id="chat-room-add-member" title="Thêm thành viên">
                        <i class="fa-solid fa-user-plus"></i>
                    </button>
                    <button type="button" class="chat-icon-btn" id="chat-room-search-btn" title="Tìm tin nhắn">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                    <button type="button" class="chat-icon-btn" id="chat-room-close" title="Đóng">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Thanh tìm tin nhắn trong hội thoại -->
            <div class="chat-room-search" id="chat-room-search" style="display:none;">
                <input type="text" id="chat-room-search-input" placeholder="Tìm trong hội thoại…" autocomplete="off">
                <button type="button" class="chat-icon-btn" id="chat-room-search-clear" title="Đóng tìm kiếm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="chat-messages" id="chat-messages">
                <div class="chat-empty">Chọn để bắt đầu trò chuyện.</div>
            </div>

            <!-- Lớp phủ: danh sách thành viên / thêm thành viên -->
            <div class="chat-room-overlay" id="chat-room-overlay" style="display:none;">
                <div class="chat-overlay-head">
                    <button type="button" class="chat-icon-btn" id="chat-overlay-back" title="Đóng">
                        <i class="fa-solid fa-arrow-left"></i>
                    </button>
                    <span class="chat-overlay-title" id="chat-overlay-title">Thành viên</span>
                </div>
                <div class="chat-overlay-body" id="chat-overlay-body"></div>
                <div class="chat-overlay-foot" id="chat-overlay-foot" style="display:none;">
                    <button type="button" class="chat-send-btn chat-overlay-submit" id="chat-overlay-submit">Thêm</button>
                </div>
            </div>

            <!-- Khu vực soạn tin -->
            <div class="chat-compose">
                <!-- Thanh "đang trả lời" 1 tin (kiểu Zalo) -->
                <div class="chat-reply-bar" id="chat-reply-bar" style="display:none;">
                    <i class="fa-solid fa-reply chat-reply-bar-icon"></i>
                    <div class="chat-reply-bar-info">
                        <span class="chat-reply-bar-name" id="chat-reply-bar-name"></span>
                        <span class="chat-reply-bar-text" id="chat-reply-bar-text"></span>
                    </div>
                    <button type="button" class="chat-reply-bar-cancel" id="chat-reply-bar-cancel" title="Hủy trả lời">&times;</button>
                </div>

                <div class="chat-attach-preview" id="chat-attach-preview" style="display:none;"></div>

                <!-- Bộ chọn emoji -->
                <div class="chat-emoji-pop" id="chat-emoji-pop" style="display:none;"></div>
                <!-- Bộ định dạng chữ (đậm/nghiêng/gạch chân/màu) cho đoạn text đang bôi đen -->
                <div class="chat-format-pop" id="chat-format-pop" style="display:none;">
                    <button type="button" class="chat-fmt-btn" data-fmt="b" title="Đậm"><b>B</b></button>
                    <button type="button" class="chat-fmt-btn" data-fmt="i" title="Nghiêng"><i>I</i></button>
                    <button type="button" class="chat-fmt-btn" data-fmt="u" title="Gạch chân"><u>U</u></button>
                    <span class="chat-fmt-sep"></span>
                    <button type="button" class="chat-fmt-btn chat-fmt-color" data-fmt="color" data-color="red" title="Đỏ" style="color:#ef4444;">A</button>
                    <button type="button" class="chat-fmt-btn chat-fmt-color" data-fmt="color" data-color="yellow" title="Vàng đậm" style="color:#b45309;">A</button>
                    <button type="button" class="chat-fmt-btn chat-fmt-color" data-fmt="color" data-color="blue" title="Xanh dương" style="color:#2563eb;">A</button>
                    <button type="button" class="chat-fmt-btn chat-fmt-color" data-fmt="color" data-color="green" title="Xanh lá" style="color:#16a34a;">A</button>
                </div>

                <div class="chat-compose-row">
                    <!-- Emoji: luôn hiển thị, canh trái (kể cả trên mobile). -->
                    <button type="button" class="chat-icon-btn chat-emoji-btn-el" id="chat-emoji-btn" title="Emoji">
                        <i class="fa-regular fa-face-smile"></i>
                    </button>
                    <!-- Nhóm công cụ phụ (ảnh/tệp/định dạng): desktop hiện thẳng hàng;
                         mobile gom vào nút "..." (chat-more-btn) mở dạng popover. -->
                    <div class="chat-compose-tools" id="chat-compose-tools">
                        <label class="chat-icon-btn" title="Gửi ảnh">
                            <i class="fa-solid fa-image"></i>
                            <input type="file" id="chat-input-image" accept="image/*" multiple hidden>
                        </label>
                        <label class="chat-icon-btn" title="Gửi tệp">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" id="chat-input-file" multiple hidden>
                        </label>
                        <button type="button" class="chat-icon-btn" id="chat-format-btn" title="Định dạng chữ (bôi đen đoạn text trước)">
                            <i class="fa-solid fa-font"></i>
                        </button>
                    </div>
                    <!-- Nút "..." chỉ hiện trên mobile để mở nhóm công cụ phụ, canh phải. -->
                    <button type="button" class="chat-icon-btn chat-more-btn" id="chat-more-btn" title="Thêm">
                        <i class="fa-solid fa-ellipsis"></i>
                    </button>
                    <div id="chat-input-text" class="chat-input-editable" contenteditable="true" role="textbox"
                         data-placeholder="Nhập tin nhắn.."></div>
                    <button type="button" class="chat-send-btn" id="chat-send-btn" title="Gửi">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- C. TẠO NHÓM -->
        <div class="chat-group-create" id="chat-group-create" style="display:none;">
            <div class="chat-head">
                <button type="button" class="chat-icon-btn" id="chat-group-back" title="Quay lại">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="chat-head-title">Tạo nhóm</div>
                <div class="chat-head-actions">
                    <button type="button" class="chat-icon-btn" id="chat-group-close" title="Đóng">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="chat-group-body">
                <input type="text" id="chat-group-name" class="chat-group-name" placeholder="Tên nhóm…" autocomplete="off">
                <div class="chat-search-bar">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="chat-group-search" placeholder="Tìm thành viên…" autocomplete="off">
                </div>
                <div class="chat-list" id="chat-group-members"></div>
            </div>
            <div class="chat-group-foot">
                <span id="chat-group-count">Đã chọn 0</span>
                <button type="button" class="chat-send-btn chat-group-submit" id="chat-group-submit">Tạo nhóm</button>
            </div>
        </div>
    </div>

    <!-- Menu ngữ cảnh cho 1 hội thoại trong danh sách ("...") -->
    <div class="chat-ctx-menu" id="chat-ctx-menu" style="display:none;"></div>

    <!-- Modal nhắc hẹn cho 1 tin nhắn -->
    <div class="chat-modal" id="chat-reminder-modal">
        <div class="chat-modal-overlay" data-reminder-close></div>
        <div class="chat-modal-box">
            <div class="chat-modal-head">
                <h3>Nhắc hẹn tin nhắn</h3>
                <button type="button" class="chat-icon-btn" data-reminder-close>&times;</button>
            </div>
            <div class="chat-modal-body">
                <label class="chat-modal-label">Nhập nội dung</label>
                <textarea id="chat-reminder-note" class="chat-modal-input chat-modal-textarea" rows="3"
                          placeholder="Nội dung nhắc hẹn…"></textarea>
                <label class="chat-modal-label">Chọn thời gian</label>
                <input type="datetime-local" id="chat-reminder-when" class="chat-modal-input">
                <div class="chat-reminder-quick">
                    <button type="button" data-quick="600">10 phút</button>
                    <button type="button" data-quick="3600">1 giờ</button>
                    <button type="button" data-quick="86400">Ngày mai</button>
                </div>
                <div class="chat-modal-actions">
                    <button type="button" class="chat-modal-cancel" data-reminder-close>Hủy</button>
                    <button type="button" class="chat-send-btn chat-modal-submit" id="chat-reminder-submit">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal CÀI ĐẶT chat -->
    <div class="chat-modal" id="chat-settings-modal">
        <div class="chat-modal-overlay" data-settings-close></div>
        <div class="chat-modal-box">
            <div class="chat-modal-head">
                <h3><i class="fa-solid fa-gear"></i> Cài đặt trò chuyện</h3>
                <button type="button" class="chat-icon-btn" data-settings-close>&times;</button>
            </div>
            <div class="chat-modal-body chat-settings-body">

                <!-- Ẩn bóng chat: gắn bóng chat lên cạnh chuông (góc phải header) -->
                <div class="chat-set-row">
                    <div class="chat-set-info">
                        <div class="chat-set-name">Ẩn bóng chat</div>
                        <div class="chat-set-desc">Khi bật, bóng chat ở góc phải màn hình sẽ ẩn và gắn lên cạnh
                            chuông thông báo. Tin nhắn mới không tự bung khung chat — chỉ hiện số tin chưa đọc trên
                            biểu tượng. Bấm vào biểu tượng để đưa bóng chat trở lại góc phải như mặc định.</div>
                    </div>
                    <label class="chat-switch">
                        <input type="checkbox" id="set-hide-bubble"><span class="chat-switch-slider"></span>
                    </label>
                </div>

                <!-- Hiển thị trạng thái hoạt động -->
                <div class="chat-set-row">
                    <div class="chat-set-info">
                        <div class="chat-set-name">Hiển thị trạng thái hoạt động</div>
                        <div class="chat-set-desc">Khi tắt, người khác không thấy bạn online (nút xanh) và không
                            thấy “đã xem” của bạn — chỉ trưởng nhóm biết.</div>
                    </div>
                    <label class="chat-switch">
                        <input type="checkbox" id="set-show-online"><span class="chat-switch-slider"></span>
                    </label>
                </div>

                <!-- Tắt thông báo toàn hộp chat -->
                <div class="chat-set-block">
                    <div class="chat-set-name">Tắt thông báo tất cả tin nhắn</div>
                    <div class="chat-set-desc">Khi bật, tin mới sẽ không tự bung khung chat — chỉ hiện số tin chưa đọc trên nút chat.</div>
                    <div class="chat-set-current" id="set-mute-current"></div>
                    <div class="chat-set-options" id="set-mute-options">
                        <button type="button" class="chat-set-opt" data-mute="1h">Trong 1 giờ</button>
                        <button type="button" class="chat-set-opt" data-mute="4h">Trong 4 giờ</button>
                        <button type="button" class="chat-set-opt" data-mute="forever">Đến khi được mở lại</button>
                        <button type="button" class="chat-set-opt chat-set-opt-on" data-mute="off">Bật lại thông báo</button>
                    </div>
                </div>

                <!-- Chế độ nhận tin -->
                <div class="chat-set-block">
                    <div class="chat-set-name">Chế độ nhận tin mới</div>
                    <div class="chat-set-desc">Có thể chọn cả hai cách báo tin mới.</div>
                    <div class="chat-set-row">
                        <div class="chat-set-info">
                            <div class="chat-set-sub">Chỉ hiển thị trong hệ thống</div>
                            <div class="chat-set-desc">Tự mở/đẩy hội thoại lên trong khung chat khi có tin mới.</div>
                        </div>
                        <label class="chat-switch">
                            <input type="checkbox" id="set-notify-system"><span class="chat-switch-slider"></span>
                        </label>
                    </div>
                    <div class="chat-set-row">
                        <div class="chat-set-info">
                            <div class="chat-set-sub">Thông báo nổi (chat toast)</div>
                            <div class="chat-set-desc">Hiện một hộp thông báo nổi ở góc màn hình khi có tin mới.</div>
                        </div>
                        <label class="chat-switch">
                            <input type="checkbox" id="set-notify-toast"><span class="chat-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="chat-modal-actions">
                    <button type="button" class="chat-modal-cancel" data-settings-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal RỜI NHÓM (chọn trưởng nhóm mới nếu cần + rời trong im lặng) -->
    <div class="chat-modal" id="chat-leave-modal">
        <div class="chat-modal-overlay" data-leave-close></div>
        <div class="chat-modal-box">
            <div class="chat-modal-head">
                <h3><i class="fa-solid fa-right-from-bracket"></i> Rời nhóm</h3>
                <button type="button" class="chat-icon-btn" data-leave-close>&times;</button>
            </div>
            <div class="chat-modal-body">
                <!-- Chỉ hiện khi tôi là trưởng nhóm duy nhất -->
                <div id="chat-leave-admin" style="display:none;">
                    <label class="chat-modal-label">Bạn là trưởng nhóm — chọn người kế nhiệm</label>
                    <select id="chat-leave-successor" class="chat-modal-input"></select>
                </div>

                <div class="chat-set-row" style="margin-top:12px;">
                    <div class="chat-set-info">
                        <div class="chat-set-sub">Rời nhóm trong im lặng</div>
                        <div class="chat-set-desc">Khi bật, chỉ trưởng nhóm biết bạn đã rời — các thành viên khác không thấy thông báo.</div>
                    </div>
                    <label class="chat-switch">
                        <input type="checkbox" id="chat-leave-silent"><span class="chat-switch-slider"></span>
                    </label>
                </div>

                <div class="chat-modal-actions">
                    <button type="button" class="chat-modal-cancel" data-leave-close>Hủy</button>
                    <button type="button" class="chat-send-btn chat-modal-submit" id="chat-leave-submit">Rời nhóm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal CHIA SẺ LẠI tin nhắn (3 tab: Gần đây / Nhóm / Danh bạ) -->
    <div class="chat-modal" id="chat-share-modal">
        <div class="chat-modal-overlay" data-share-close></div>
        <div class="chat-modal-box">
            <div class="chat-modal-head">
                <h3><i class="fa-solid fa-share"></i> Chia sẻ tin nhắn</h3>
                <button type="button" class="chat-icon-btn" data-share-close>&times;</button>
            </div>
            <div class="chat-modal-body">
                <div class="chat-share-tabs">
                    <button type="button" class="chat-share-tab is-active" data-share-tab="recent">Gần đây</button>
                    <button type="button" class="chat-share-tab" data-share-tab="groups">Nhóm</button>
                    <button type="button" class="chat-share-tab" data-share-tab="contacts">Danh bạ</button>
                </div>
                <div class="chat-search-bar">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="chat-share-search" placeholder="Tìm…" autocomplete="off">
                </div>
                <div class="chat-share-list" id="chat-share-list">
                    <div class="chat-empty">Đang tải…</div>
                </div>
                <div class="chat-modal-actions chat-share-foot">
                    <span class="chat-share-count" id="chat-share-count">Đã chọn 0</span>
                    <button type="button" class="chat-modal-cancel" data-share-close>Hủy</button>
                    <button type="button" class="chat-send-btn chat-modal-submit" id="chat-share-submit">Chia sẻ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal chọn cách dán khi clipboard có cả ảnh và chữ (vd copy vùng chọn trong Excel) -->
    <div class="chat-modal" id="chat-paste-modal">
        <div class="chat-modal-overlay" data-paste-close></div>
        <div class="chat-modal-box">
            <div class="chat-modal-head">
                <h3><i class="fa-solid fa-paste"></i> Dán nội dung</h3>
                <button type="button" class="chat-icon-btn" data-paste-close>&times;</button>
            </div>
            <div class="chat-modal-body">
                <p class="chat-paste-hint">Nội dung vừa dán có cả hình ảnh lẫn chữ — chọn cách gửi:</p>
                <div class="chat-modal-actions">
                    <button type="button" class="chat-modal-cancel" id="chat-paste-as-text">Gửi dạng chữ</button>
                    <button type="button" class="chat-send-btn chat-modal-submit" id="chat-paste-as-image">Gửi dạng hình</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast nhắc hẹn tới hạn -->
    <div class="chat-toast-wrap" id="chat-toast-wrap"></div>
</div>

<script src="<?php echo asset_ver('public/js/shared/user_card.js'); ?>"></script>
<script>window.UserCard && UserCard.setApi('?mod=chat&controllers=chat&action=userCard');</script>
<script src="public/js/shared/chat.js?v=<?php echo $__chat_js_v; ?>"></script>
<?php
// Poller "Gửi báo cáo tự động" — nạp toàn cục khi đã đăng nhập (đi kèm chat-widget trên mọi trang app).
$__arp = __DIR__ . '/../public/js/shared/auto_report_poller.js';
$__arp_v = is_file($__arp) ? filemtime($__arp) : time();
?>
<script src="public/js/shared/auto_report_poller.js?v=<?php echo $__arp_v; ?>"></script>
