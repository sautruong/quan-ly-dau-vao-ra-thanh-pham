<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($project['name']) ? htmlspecialchars($project['name']) : 'Dự án'; ?> — Quản lý dự án</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/project_management/detail.css">
    <link rel="stylesheet" href="public/css/shared/user_card.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
        <?php if (empty($project)): ?>
            <div class="pm-noaccess">
                <i class="fa-solid fa-lock"></i>
                <p>Bạn không có quyền truy cập dự án này, hoặc dự án không tồn tại.</p>
                <a href="?mod=project_management&controllers=project&action=dashboard">← Về danh sách dự án</a>
            </div>
        <?php else: ?>
            <div class="pm-ws" id="pm-ws">
                <!-- ================= KHỐI CHAT (TRÁI) ================= -->
                <section class="pm-chat-pane" id="pm-chat-pane">
                    <header class="pm-chat-head">
                        <a class="pm-back" href="?mod=project_management&controllers=project&action=dashboard" title="Về danh sách"><i class="fa-solid fa-chevron-left"></i></a>
                        <div class="pm-head-titles">
                            <div class="pm-proj-name"><?php echo htmlspecialchars($project['name']); ?></div>
                            <div class="pm-session-name" id="pm-session-name" title="Đổi tên session (nhấp đúp)">—</div>
                        </div>
                        <div class="pm-head-actions">
                            <button type="button" class="pm-icon-btn pm-show-canvas-btn" id="pm-btn-show-canvas" title="Mở bản thiết kế" style="display:none"><i class="fa-solid fa-pen-ruler"></i></button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-new-session" title="Tạo session mới"><i class="fa-solid fa-plus"></i></button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-history" title="Lịch sử session"><i class="fa-solid fa-clock-rotate-left"></i></button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-star-filter" title="Lọc tin gắn sao"><i class="fa-regular fa-star"></i></button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-members" title="Thành viên"><i class="fa-solid fa-users"></i> <span id="pm-member-count">0</span></button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-search" title="Tìm tin nhắn"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </div>
                    </header>

                    <!-- Popup tìm kiếm tin nhắn -->
                    <div class="pm-pop pm-search-pop" id="pm-search-pop" style="display:none">
                        <div class="pm-search-bar">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="pm-search-input" placeholder="Tìm tin nhắn theo từ khóa...">
                            <button type="button" class="pm-pop-close" data-close-search><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <div class="pm-search-hint" id="pm-search-hint">Nhập từ khóa để tìm tin nhắn trong session này.</div>
                        <ul id="pm-search-results"></ul>
                    </div>

                    <!-- Thanh tin nhắn đã ghim (dưới chat-head) -->
                    <div class="pm-pinned-bar" id="pm-pinned-bar" style="display:none">
                        <i class="fa-solid fa-thumbtack"></i>
                        <div class="pm-pinned-list" id="pm-pinned-list"></div>
                    </div>

                    <!-- Dropdown lịch sử session -->
                    <div class="pm-pop pm-session-history" id="pm-session-history" style="display:none">
                        <div class="pm-pop-head">Lịch sử session</div>
                        <ul id="pm-session-list"></ul>
                    </div>

                    <!-- Panel thành viên -->
                    <div class="pm-pop pm-members-pop" id="pm-members-pop" style="display:none">
                        <div class="pm-pop-head">Thành viên dự án
                            <button type="button" class="pm-pop-close" data-close><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <ul id="pm-members-list"></ul>
                        <div class="pm-add-member" id="pm-add-member-wrap" style="display:none">
                            <div class="pm-add-member-head">Mời thành viên tham gia dự án</div>
                            <input type="text" id="pm-member-search" placeholder="Tìm tên...">
                            <ul id="pm-user-pick"></ul>
                        </div>
                        <div class="pm-members-foot">
                            <button type="button" class="pm-leave-btn" id="pm-btn-leave"><i class="fa-solid fa-right-from-bracket"></i> Rời dự án</button>
                        </div>
                    </div>

                    <div class="pm-messages" id="pm-messages">
                        <div class="pm-load-more" id="pm-load-more" style="display:none"><button type="button">Tải thêm tin cũ</button></div>
                        <div class="pm-msg-list" id="pm-msg-list"></div>
                    </div>

                    <!-- Khung trả lời đang chờ -->
                    <div class="pm-reply-bar" id="pm-reply-bar" style="display:none">
                        <div class="pm-reply-info"><b id="pm-reply-name"></b><span id="pm-reply-preview"></span></div>
                        <button type="button" id="pm-reply-cancel"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <!-- Hộp soạn tin -->
                    <div class="pm-composer">
                        <div class="pm-composer-grip" id="pm-composer-grip" title="Kéo để mở rộng ô nhập"></div>
                        <div class="pm-composer-tools">
                            <button type="button" class="pm-tool" id="pm-tool-emoji" title="Cảm xúc"><i class="fa-regular fa-face-smile"></i></button>
                            <button type="button" class="pm-tool" id="pm-tool-attach" title="Đính kèm ảnh/tệp"><i class="fa-solid fa-paperclip"></i></button>
                            <span class="pm-tool-sep"></span>
                            <button type="button" class="pm-tool" data-composer="checklist" title="Todo List"><i class="fa-solid fa-list-check"></i></button>
                            <button type="button" class="pm-tool" data-composer="vote" title="Check list (bình chọn / chọn giải pháp)"><i class="fa-solid fa-square-check"></i></button>
                            <button type="button" class="pm-tool" data-composer="table" title="Bảng (mô phỏng field)"><i class="fa-solid fa-table"></i></button>
                            <button type="button" class="pm-tool" data-composer="tree" title="Sơ đồ cây (module/view)"><i class="fa-solid fa-sitemap"></i></button>
                            <button type="button" class="pm-tool" data-composer="process" title="Quy trình các bước"><i class="fa-solid fa-diagram-project"></i></button>
                        </div>
                        <div class="pm-composer-main">
                            <textarea id="pm-input" rows="1" placeholder="Nhập tin nhắn..."></textarea>
                            <button type="button" class="pm-send" id="pm-send" title="Gửi"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                        <div class="pm-attach-preview" id="pm-attach-preview"></div>
                        <input type="file" id="pm-file" multiple style="display:none">
                    </div>
                </section>

                <!-- Thanh kéo chỉnh độ rộng -->
                <div class="pm-resizer" id="pm-resizer" title="Kéo để chỉnh độ rộng"></div>

                <!-- ================= KHỐI CANVAS (PHẢI) ================= -->
                <section class="pm-canvas-pane" id="pm-canvas-pane">
                    <header class="pm-canvas-head">
                        <div class="pm-canvas-tools">
                            <button type="button" class="pm-shape" data-shape="select" title="Chọn / di chuyển"><i class="fa-solid fa-arrow-pointer"></i></button>
                            <button type="button" class="pm-shape" data-shape="rect" title="Chữ nhật"><i class="fa-regular fa-square"></i></button>
                            <button type="button" class="pm-shape" data-shape="square" title="Hình vuông"><i class="fa-solid fa-square"></i></button>
                            <button type="button" class="pm-shape" data-shape="circle" title="Hình tròn"><i class="fa-regular fa-circle"></i></button>
                            <button type="button" class="pm-shape" data-shape="triangle" title="Tam giác"><i class="fa-solid fa-play" style="transform:rotate(-90deg)"></i></button>
                            <button type="button" class="pm-shape" data-shape="rrect" title="Chữ nhật bo góc (kéo núm bo góc)"><i class="fa-regular fa-square"></i><sup style="font-size:8px">◠</sup></button>
                            <button type="button" class="pm-shape" data-shape="line" title="Đường thẳng"><i class="fa-solid fa-minus"></i></button>
                            <button type="button" class="pm-shape" data-shape="arrow" title="Mũi tên 1 chiều"><i class="fa-solid fa-arrow-right-long"></i></button>
                            <button type="button" class="pm-shape" data-shape="arrow2" title="Mũi tên 2 chiều"><i class="fa-solid fa-arrows-left-right"></i></button>
                            <button type="button" class="pm-shape" data-shape="text" title="Văn bản"><i class="fa-solid fa-font"></i></button>
                            <span class="pm-tool-sep"></span>
                            <input type="color" id="pm-fill" value="#bbf7d0" title="Màu nền (RGB)">
                            <button type="button" class="pm-shape" id="pm-fill-none" title="Nền trong suốt (không màu)"><i class="fa-solid fa-droplet-slash"></i></button>
                            <input type="color" id="pm-stroke" value="#16a34a" title="Màu viền/chữ">
                            <button type="button" class="pm-shape" id="pm-canvas-delete" title="Xóa shape đang chọn"><i class="fa-solid fa-trash"></i></button>
                            <span class="pm-tool-sep"></span>
                            <button type="button" class="pm-shape" id="pm-canvas-link" title="Gắn tin nhắn cho hình đang chọn"><i class="fa-solid fa-comment-dots"></i></button>
                        </div>
                        <div class="pm-canvas-right">
                            <span class="pm-canvas-status" id="pm-canvas-status"></span>
                            <button type="button" class="pm-icon-btn" id="pm-canvas-export" title="Xuất bản thiết kế ra PNG"><i class="fa-solid fa-download"></i> PNG</button>
                            <button type="button" class="pm-icon-btn" id="pm-btn-canvas-off" title="Ẩn bản thiết kế (chỉ với bạn)"><i class="fa-solid fa-eye-slash"></i> Ẩn</button>
                        </div>
                    </header>
                    <!-- Thanh nhập văn bản (hiện ngay khi bấm công cụ Văn bản, sát dưới header) -->
                    <div class="pm-text-bar" id="pm-text-bar" style="display:none">
                        <textarea id="pm-text-input" rows="2" placeholder="Nhập nội dung văn bản... (Ctrl+Enter để chèn nhanh)"></textarea>
                        <div class="pm-text-bar-btns">
                            <button type="button" class="pm-btn-ghost" id="pm-text-cancel">Hủy</button>
                            <button type="button" class="pm-btn-primary" id="pm-text-ok"><i class="fa-solid fa-check"></i> Nhập</button>
                        </div>
                    </div>
                    <div class="pm-canvas-wrap" id="pm-canvas-wrap">
                        <svg id="pm-svg" class="pm-svg" xmlns="http://www.w3.org/2000/svg"></svg>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ========== Popup emoji ========== -->
    <div class="pm-emoji-pop" id="pm-emoji-pop" style="display:none"></div>

    <!-- ========== Modal builder (checklist/table/tree/process) ========== -->
    <div class="pm-modal" id="pm-builder-modal" style="display:none">
        <div class="pm-modal-box pm-builder-box">
            <div class="pm-modal-head">
                <h3 id="pm-builder-title">Trình soạn</h3>
                <button type="button" class="pm-modal-close" id="pm-builder-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="pm-modal-body" id="pm-builder-body"></div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn-ghost" id="pm-builder-cancel">Hủy</button>
                <button type="button" class="pm-btn-primary" id="pm-builder-send">Gửi</button>
            </div>
        </div>
    </div>

    <!-- ========== Modal nhắc hẹn ========== -->
    <div class="pm-modal" id="pm-reminder-modal" style="display:none">
        <div class="pm-modal-box" style="width:400px">
            <div class="pm-modal-head"><h3><i class="fa-regular fa-clock"></i> Đặt nhắc hẹn</h3><button type="button" class="pm-modal-close" id="pm-reminder-close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="pm-modal-body">
                <div class="pm-rem-quick" id="pm-rem-quick">
                    <button type="button" data-quick="10m"><i class="fa-solid fa-bolt"></i> 10 phút</button>
                    <button type="button" data-quick="1h"><i class="fa-regular fa-hourglass-half"></i> 1 tiếng</button>
                    <button type="button" data-quick="tomorrow"><i class="fa-regular fa-sun"></i> Ngày mai 8:00</button>
                    <button type="button" data-quick="custom"><i class="fa-regular fa-calendar"></i> Chọn thời gian</button>
                </div>
                <div id="pm-rem-custom" style="display:none">
                    <label>Thời điểm cụ thể</label>
                    <input type="datetime-local" id="pm-reminder-at">
                </div>
                <div class="pm-rem-when" id="pm-rem-when"></div>
                <label>Ghi chú (tùy chọn)</label>
                <input type="text" id="pm-reminder-note" placeholder="VD: nhắc kiểm tra lại...">
            </div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn-ghost" id="pm-reminder-cancel">Hủy</button>
                <button type="button" class="pm-btn-primary" id="pm-reminder-save"><i class="fa-regular fa-bell"></i> Đặt nhắc</button>
            </div>
        </div>
    </div>

    <!-- ========== Modal gắn sao kèm mô tả ========== -->
    <div class="pm-modal" id="pm-star-modal" style="display:none">
        <div class="pm-modal-box" style="width:400px">
            <div class="pm-modal-head"><h3><i class="fa-solid fa-star" style="color:#f59e0b"></i> Gắn sao kèm mô tả</h3><button type="button" class="pm-modal-close" id="pm-star-close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="pm-modal-body">
                <label>Mô tả (tùy chọn)</label>
                <textarea id="pm-star-note" rows="3" placeholder="VD: hình thiết kế cần sửa, đoạn yêu cầu quan trọng..."></textarea>
                <div class="pm-star-hint">Mô tả hiển thị khi lọc tin gắn sao — hữu ích cho tin hình ảnh hoặc đoạn văn bản dài.</div>
            </div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn-ghost" id="pm-star-cancel">Hủy</button>
                <button type="button" class="pm-btn-primary" id="pm-star-save"><i class="fa-solid fa-star"></i> Gắn sao</button>
            </div>
        </div>
    </div>

    <!-- ========== Modal chia sẻ lại (forward) ========== -->
    <div class="pm-modal" id="pm-forward-modal" style="display:none">
        <div class="pm-modal-box" style="width:400px">
            <div class="pm-modal-head"><h3>Chia sẻ tin</h3><button type="button" class="pm-modal-close" id="pm-forward-close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="pm-modal-tabs">
                <button type="button" class="pm-fw-tab is-active" data-fwtab="session"><i class="fa-solid fa-folder"></i> Sang session</button>
                <button type="button" class="pm-fw-tab" data-fwtab="chat"><i class="fa-solid fa-comments"></i> Qua chat hệ thống</button>
            </div>
            <div class="pm-modal-body">
                <div class="pm-fw-pane" id="pm-fw-pane-session">
                    <ul class="pm-forward-list" id="pm-forward-list"></ul>
                </div>
                <div class="pm-fw-pane" id="pm-fw-pane-chat" style="display:none">
                    <input type="text" class="pm-fw-search" id="pm-fw-chat-search" placeholder="Tìm người nhận...">
                    <ul class="pm-forward-list" id="pm-fw-chat-list"></ul>
                    <input type="text" class="pm-fw-note" id="pm-fw-chat-note" placeholder="Lời nhắn (tuỳ chọn)">
                </div>
            </div>
        </div>
    </div>

    <!-- ========== Modal rời dự án ========== -->
    <div class="pm-modal" id="pm-leave-modal" style="display:none">
        <div class="pm-modal-box" style="width:420px">
            <div class="pm-modal-head"><h3>Rời dự án</h3><button type="button" class="pm-modal-close" id="pm-leave-close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="pm-modal-body">
                <p id="pm-leave-msg">Bạn chắc chắn muốn rời dự án này?</p>
                <div id="pm-leave-newleader" style="display:none">
                    <label>Trao quyền trưởng dự án cho:</label>
                    <select id="pm-leave-leader-select"></select>
                </div>
                <label class="pm-leave-silent"><input type="checkbox" id="pm-leave-silent"> Rời trong im lặng (không thông báo cho thành viên)</label>
            </div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn-ghost" id="pm-leave-cancel">Hủy</button>
                <button type="button" class="pm-btn-danger" id="pm-leave-confirm"><i class="fa-solid fa-right-from-bracket"></i> Rời dự án</button>
            </div>
        </div>
    </div>

    <!-- Lightbox ảnh + canvas preview: phóng to/thu nhỏ (lăn chuột), xoay, tải xuống, chia sẻ qua chat -->
    <div class="pm-lightbox" id="pm-lightbox" style="display:none">
        <button type="button" class="pm-lightbox-close" id="pm-lightbox-close" title="Đóng"><i class="fa-solid fa-xmark"></i></button>
        <div class="pm-lightbox-stage" id="pm-lightbox-stage">
            <img id="pm-lightbox-img" src="" alt="" draggable="false">
        </div>
        <div class="pm-lightbox-toolbar" id="pm-lightbox-toolbar">
            <button type="button" data-lbt="out" title="Thu nhỏ"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <span class="pm-lightbox-zoom" id="pm-lightbox-zoom">100%</span>
            <button type="button" data-lbt="in" title="Phóng to"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" data-lbt="reset" title="Cỡ gốc"><i class="fa-solid fa-expand"></i></button>
            <span class="pm-lightbox-sep"></span>
            <button type="button" data-lbt="rot-left" title="Xoay trái"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" data-lbt="rot-right" title="Xoay phải"><i class="fa-solid fa-rotate-right"></i></button>
            <span class="pm-lightbox-sep"></span>
            <button type="button" data-lbt="download" title="Tải ảnh xuống"><i class="fa-solid fa-download"></i></button>
            <button type="button" data-lbt="share" title="Chia sẻ qua chat"><i class="fa-solid fa-share"></i></button>
        </div>
    </div>

    <!-- Modal phóng to bảng (xem dạng "ảnh" lớn) -->
    <div class="pm-zoom" id="pm-zoom" style="display:none"><div class="pm-zoom-box" id="pm-zoom-box"></div></div>

    <script>
        window.PM = {
            base: '?mod=project_management&controllers=project&action=',
            projectId: <?php echo (int) ($project['id'] ?? 0); ?>,
            projectName: <?php echo json_encode($project['name'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
            sessions: <?php echo json_encode($sessions ?? [], JSON_UNESCAPED_UNICODE); ?>,
            firstSession: <?php echo (int) ($first_session ?? 0); ?>,
            members: <?php echo json_encode($members ?? [], JSON_UNESCAPED_UNICODE); ?>,
            isLeader: <?php echo !empty($is_leader) ? 'true' : 'false'; ?>,
            allUsers: <?php echo json_encode($all_users ?? [], JSON_UNESCAPED_UNICODE); ?>,
            invited: <?php echo json_encode(array_map('intval', $invited ?? []), JSON_UNESCAPED_UNICODE); ?>,
            meId: <?php echo (int) ($me['id'] ?? 0); ?>,
            meName: <?php echo json_encode(($me['fullname'] ?? '') ?: ($me['username'] ?? ''), JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <?php if (!empty($project)): ?>
    <script src="public/js/shared/user_card.js"></script>
    <script>window.UserCard && UserCard.setApi('?mod=project_management&controllers=project&action=userCard');</script>
    <script src="public/js/project_management/composers.js"></script>
    <script src="public/js/project_management/canvas.js"></script>
    <script src="public/js/project_management/chat.js"></script>
    <script src="public/js/project_management/detail.js"></script>
    <?php endif; ?>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
