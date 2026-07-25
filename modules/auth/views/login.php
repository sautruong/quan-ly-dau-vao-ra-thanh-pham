<?php
// show_array($error);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập tài khoản hệ thống Safeking</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/style_login.css?v=<?php echo filemtime(__DIR__ . '/../../../public/css/style_login.css'); ?>">
    <!-- Responsive -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="public/css/all.css">
</head>
<body>
    <div id="wrapper">
        <div id="container">
            <div id="wp-brand">
                <img src="public/images/logo/logo_vat_png.png" alt="" width="40px" height="auto">
                <!-- <h4>NMSX_VAT</h4> -->
                <!-- <h5 style="font-weight: 300;">Enterprise Management System</h5> -->
            </div>
            <div id="wp-login">
                <div id="wp-title-login">
                    <h2>Đăng nhập</h2>
                    <p>Hệ thống quản lý nhà máy sản xuất <strong>Vua An Toàn</strong>. Nơi lưu trữ tài sản chiến
                        lượt, tối ưu vận hành và chuẩn hóa quy trình.</p>
                </div>
                <div id="wp-form-login">
                    <form action="?mod=auth&controllers=index&action=checkLogin" method="POST" id="form-login">
                        <input type="text" placeholder="Tên đăng nhập" name="username" value="<?php if (!empty($username)) echo $username; ?>">
                        <?php if (!empty($error['username'])) echo "<p class='error'> {$error['username']} </p>" ?>
                        
                        <div class="password-box">
                            <input type="password" placeholder="Mật khẩu" name="password" id="passowrd">
                            <span class="toggle-password" onclick="togglePassword()">
                                <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                            </span>
                        </div>
                        <?php if (!empty($error['password'])) echo "<p class='error'> {$error['password']} </p>" ?>
                        <div id="wp-check-remember-me">
                            <span class="remember-check">
                                <input type="checkbox" id="remember-me" name="remember-me">
                                <span class="remember-check-mark"><i class="fa-solid fa-check"></i></span>
                            </span>
                            <label for="remember-me"style="margin: 0;">Ghi nhớ tên đăng nhập mật khẩu</label>
                        </div>
                        <?php if (!empty($error['account'])) echo "<p class='error'> {$error['account']} </p>" ?>
                        <input type="submit" value="Đăng nhập" id="btn-login" name="btn_login">
                    </form>
                    <div id="wp-login-links">
                        <a href="?mod=auth&controllers=index&action=register" class="link_register">Đăng ký tài khoản</a>
                        <a href="#" class="link_forget" id="open-forget-password">Quên mật khẩu</a>
                    </div>
                    <button type="button" id="open-gmail-login" class="btn-gmail-login">
                        <i class="fa-brands fa-google"></i>
                        <span>Đăng nhập bằng tài khoản gmail</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Quên mật khẩu -->
    <div class="modal-backdrop" id="forgetModal">
        <div class="modal">
            <div class="modal-header">Quên mật khẩu</div>

            <!-- BƯỚC 1: nhập tên đăng nhập / email → lấy mã → nhập 6 ô mã -->
            <div class="fp-step" id="fp-step-1">
                <div class="modal-body">
                    <label for="fp-identifier">Tên đăng nhập hoặc Email:</label>
                    <div class="fp-identifier-row">
                        <input type="text" id="fp-identifier" name="identifier" autocomplete="off"
                            placeholder="Nhập tên đăng nhập hoặc email">
                        <button type="button" class="btn-confirm" id="btn-get-code">Lấy mã kích hoạt</button>
                    </div>

                    <div id="fp-otp-area" style="display:none;">
                        <p class="fp-otp-hint">Nhập mã 6 ký tự đã gửi tới email của bạn:</p>
                        <div class="otp-boxes" id="otpBoxes">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                        </div>
                    </div>
                </div>
                <p class="fp-message" id="fp-message-1"></p>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="btn-cancel-forget">Hủy</button>
                </div>
            </div>

            <!-- BƯỚC 2: nhập mật khẩu mới -->
            <div class="fp-step" id="fp-step-2" style="display:none;">
                <form id="forgetForm">
                    <div class="modal-body">
                        <label for="fp-password">Mật khẩu mới:</label>
                        <input type="password" id="fp-password" name="password" autocomplete="new-password">

                        <label for="fp-password-confirm">Nhập lại mật khẩu mới:</label>
                        <input type="password" id="fp-password-confirm" name="password_confirm" autocomplete="new-password">
                    </div>
                    <p class="fp-message" id="fp-message-2"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" id="btn-cancel-forget-2">Hủy</button>
                        <button type="submit" class="btn-confirm" id="btn-confirm-forget">Xác nhận</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Đăng nhập/đăng ký bằng tài khoản gmail (xác thực OTP qua email) -->
    <div class="modal-backdrop" id="gmailModal">
        <div class="modal">
            <div class="modal-header">Đăng nhập bằng tài khoản gmail</div>

            <!-- BƯỚC 1: nhập email -> gửi -> nhập 6 ô mã -->
            <div class="fp-step" id="gm-step-1">
                <div class="modal-body">
                    <label for="gm-email">Email của bạn:</label>
                    <div class="fp-identifier-row">
                        <input type="email" id="gm-email" name="email" autocomplete="off"
                            placeholder="Nhập email để nhận mã xác thực">
                        <button type="button" class="btn-confirm" id="gm-send">Gửi</button>
                    </div>

                    <div id="gm-otp-area" style="display:none;">
                        <p class="fp-otp-hint">Nhập mã 6 ký tự đã gửi tới email của bạn:</p>
                        <div class="otp-boxes" id="gmOtpBoxes">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                            <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                        </div>
                    </div>
                </div>
                <p class="fp-message" id="gm-message-1"></p>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="gm-cancel-1">Hủy</button>
                </div>
            </div>

            <!-- BƯỚC 2: đặt tên đăng nhập + mật khẩu -->
            <div class="fp-step" id="gm-step-2" style="display:none;">
                <form id="gmailForm">
                    <div class="modal-body">
                        <label for="gm-username">Tên đăng nhập:</label>
                        <input type="text" id="gm-username" name="username" autocomplete="off"
                            placeholder="6–32 ký tự: chữ, số, dấu _">

                        <label for="gm-password">Mật khẩu:</label>
                        <input type="password" id="gm-password" name="password" autocomplete="new-password"
                            placeholder="Tối thiểu 6 ký tự, có chữ và số">

                        <label for="gm-password-confirm">Nhập lại mật khẩu:</label>
                        <input type="password" id="gm-password-confirm" name="password_confirm" autocomplete="new-password"
                            placeholder="Nhập lại mật khẩu">
                    </div>
                    <p class="fp-message" id="gm-message-2"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" id="gm-cancel-2">Hủy</button>
                        <button type="submit" class="btn-confirm" id="gm-register">Đăng ký</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        /* Nút "Đăng nhập bằng tài khoản gmail" dưới các link đăng ký/quên mật khẩu. */
        .btn-gmail-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            margin-top: 14px;
            padding: 11px 14px;
            background: #fff;
            color: #3c4043;
            border: 1px solid #dadce0;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: box-shadow .15s, background .15s;
        }
        .btn-gmail-login:hover { background: #f7f8f8; box-shadow: 0 1px 3px rgba(60,64,67,.25); }
        .btn-gmail-login .fa-google { color: #ea4335; font-size: 16px; }
    </style>
</body>
<script src="public/js/app_register.js"></script>
<script>
(function () {
    var rememberCheckbox = document.getElementById('remember-me');
    var rememberWrap = rememberCheckbox ? rememberCheckbox.closest('.remember-check') : null;
    if (rememberCheckbox && rememberWrap) {
        rememberCheckbox.addEventListener('change', function () {
            if (!rememberCheckbox.checked) {
                rememberWrap.classList.remove('shake');
                void rememberWrap.offsetWidth;
                rememberWrap.classList.add('shake');
            }
        });
        rememberWrap.addEventListener('animationend', function () {
            rememberWrap.classList.remove('shake');
        });
    }
})();
</script>
<script>
(function () {
    var RESEND_SECONDS = 60;

    var modal      = document.getElementById('forgetModal');
    var openBtn    = document.getElementById('open-forget-password');
    var cancelBtn  = document.getElementById('btn-cancel-forget');
    var cancelBtn2 = document.getElementById('btn-cancel-forget-2');

    var step1      = document.getElementById('fp-step-1');
    var step2      = document.getElementById('fp-step-2');

    var identifierInput = document.getElementById('fp-identifier');
    var getCodeBtn      = document.getElementById('btn-get-code');
    var otpArea         = document.getElementById('fp-otp-area');
    var otpBoxes        = Array.prototype.slice.call(document.querySelectorAll('#otpBoxes .otp-box'));
    var msg1            = document.getElementById('fp-message-1');

    var form      = document.getElementById('forgetForm');
    var submitBtn = document.getElementById('btn-confirm-forget');
    var msg2      = document.getElementById('fp-message-2');

    var currentIdentifier = '';
    var verifiedCode      = '';
    var countdownTimer    = null;

    function setMsg(el, text, isError) {
        el.textContent = text || '';
        el.classList.remove('ok', 'err');
        if (text) el.classList.add(isError ? 'err' : 'ok');
    }

    function showStep(n) {
        step1.style.display = (n === 1) ? '' : 'none';
        step2.style.display = (n === 2) ? '' : 'none';
    }

    function resetModal() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        identifierInput.value = '';
        otpBoxes.forEach(function (b) { b.value = ''; });
        otpArea.style.display = 'none';
        getCodeBtn.disabled = false;
        getCodeBtn.textContent = 'Lấy mã kích hoạt';
        if (form) form.reset();
        setMsg(msg1, '');
        setMsg(msg2, '');
        currentIdentifier = '';
        verifiedCode = '';
        showStep(1);
    }

    function openModal(e) {
        if (e) e.preventDefault();
        resetModal();
        modal.classList.add('show');
        identifierInput.focus();
    }
    function closeModal() { modal.classList.remove('show'); }

    openBtn.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);
    cancelBtn2.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    /* ---- Bộ đếm 60 giây cho nút lấy/gửi lại mã ---- */
    function startCountdown() {
        var remain = RESEND_SECONDS;
        getCodeBtn.disabled = true;
        getCodeBtn.textContent = 'Gửi lại sau ' + remain + 's';
        countdownTimer = setInterval(function () {
            remain--;
            if (remain <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                getCodeBtn.disabled = false;
                getCodeBtn.textContent = 'Gửi lại mã';
            } else {
                getCodeBtn.textContent = 'Gửi lại sau ' + remain + 's';
            }
        }, 1000);
    }

    /* ---- B1: lấy mã kích hoạt ---- */
    getCodeBtn.addEventListener('click', function () {
        var identifier = identifierInput.value.trim();
        setMsg(msg1, '');
        if (identifier === '') {
            setMsg(msg1, 'Vui lòng nhập tên đăng nhập hoặc email', true);
            return;
        }
        getCodeBtn.disabled = true;
        var fd = new FormData();
        fd.append('identifier', identifier);
        fetch('?mod=auth&controllers=index&action=sendResetCode', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                currentIdentifier = identifier;
                otpArea.style.display = '';
                otpBoxes.forEach(function (b) { b.value = ''; });
                otpBoxes[0].focus();
                setMsg(msg1, res.message, false);
                startCountdown();
            } else {
                getCodeBtn.disabled = false;
                setMsg(msg1, (res && res.message) || 'Không gửi được mã', true);
            }
        })
        .catch(function () {
            getCodeBtn.disabled = false;
            setMsg(msg1, 'Lỗi kết nối, vui lòng thử lại', true);
        });
    });

    /* ---- 6 ô nhập mã: tự nhảy ô, dán mã, tự xác thực khi đủ 6 ---- */
    function collectCode() {
        return otpBoxes.map(function (b) { return b.value; }).join('').toUpperCase();
    }
    otpBoxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            box.value = box.value.toUpperCase();
            if (box.value && idx < otpBoxes.length - 1) otpBoxes[idx + 1].focus();
            if (collectCode().length === 6) verifyCode();
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && box.value === '' && idx > 0) otpBoxes[idx - 1].focus();
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
            for (var i = 0; i < otpBoxes.length; i++) {
                otpBoxes[i].value = text.charAt(i) || '';
            }
            (otpBoxes[Math.min(text.length, otpBoxes.length) - 1] || otpBoxes[0]).focus();
            if (collectCode().length === 6) verifyCode();
        });
    });

    /* ---- B2: xác thực mã 6 ký tự ---- */
    function verifyCode() {
        var code = collectCode();
        setMsg(msg1, 'Đang kiểm tra mã...', false);
        var fd = new FormData();
        fd.append('identifier', currentIdentifier);
        fd.append('activation_code', code);
        fetch('?mod=auth&controllers=index&action=verifyResetCode', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                verifiedCode = code;
                setMsg(msg1, '');
                showStep(2);
                document.getElementById('fp-password').focus();
            } else {
                setMsg(msg1, (res && res.message) || 'Mã không đúng', true);
                otpBoxes.forEach(function (b) { b.value = ''; });
                otpBoxes[0].focus();
            }
        })
        .catch(function () { setMsg(msg1, 'Lỗi kết nối, vui lòng thử lại', true); });
    }

    /* ---- B3: đổi mật khẩu mới ---- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        setMsg(msg2, '');
        submitBtn.disabled = true;
        var fd = new FormData(form);
        fd.append('identifier', currentIdentifier);
        fd.append('activation_code', verifiedCode);
        fetch('?mod=auth&controllers=index&action=resetPassword', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            submitBtn.disabled = false;
            if (res && res.ok) {
                setMsg(msg2, res.message, false);
                setTimeout(closeModal, 1800);
            } else {
                setMsg(msg2, (res && res.message) || 'Có lỗi xảy ra', true);
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            setMsg(msg2, 'Lỗi kết nối, vui lòng thử lại', true);
        });
    });
})();
</script>

<!-- JS: Đăng nhập/đăng ký bằng tài khoản gmail (xác thực OTP email) -->
<script>
(function () {
    var modal     = document.getElementById('gmailModal');
    if (!modal) return;
    var openBtn   = document.getElementById('open-gmail-login');
    var cancel1   = document.getElementById('gm-cancel-1');
    var cancel2   = document.getElementById('gm-cancel-2');

    var step1     = document.getElementById('gm-step-1');
    var step2     = document.getElementById('gm-step-2');

    var emailInput = document.getElementById('gm-email');
    var sendBtn    = document.getElementById('gm-send');
    var otpArea    = document.getElementById('gm-otp-area');
    var otpBoxes   = Array.prototype.slice.call(document.querySelectorAll('#gmOtpBoxes .otp-box'));
    var msg1       = document.getElementById('gm-message-1');

    var form       = document.getElementById('gmailForm');
    var regBtn     = document.getElementById('gm-register');
    var msg2       = document.getElementById('gm-message-2');

    var countdownTimer = null;

    function setMsg(el, text, isError) {
        el.textContent = text || '';
        el.classList.remove('ok', 'err');
        if (text) el.classList.add(isError ? 'err' : 'ok');
    }
    function showStep(n) {
        step1.style.display = (n === 1) ? '' : 'none';
        step2.style.display = (n === 2) ? '' : 'none';
    }
    function resetModal() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        emailInput.value = '';
        otpBoxes.forEach(function (b) { b.value = ''; });
        otpArea.style.display = 'none';
        sendBtn.disabled = false;
        sendBtn.textContent = 'Gửi';
        if (form) form.reset();
        setMsg(msg1, '');
        setMsg(msg2, '');
        showStep(1);
    }
    function openModal(e) {
        if (e) e.preventDefault();
        resetModal();
        modal.classList.add('show');
        emailInput.focus();
    }
    function closeModal() { modal.classList.remove('show'); }

    openBtn.addEventListener('click', openModal);
    cancel1.addEventListener('click', closeModal);
    cancel2.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    /* ---- Bộ đếm 60 giây cho nút gửi/gửi lại mã ---- */
    function startCountdown() {
        var remain = 60;
        sendBtn.disabled = true;
        sendBtn.textContent = 'Gửi lại sau ' + remain + 's';
        countdownTimer = setInterval(function () {
            remain--;
            if (remain <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                sendBtn.disabled = false;
                sendBtn.textContent = 'Gửi lại mã';
            } else {
                sendBtn.textContent = 'Gửi lại sau ' + remain + 's';
            }
        }, 1000);
    }

    /* ---- B1: gửi / gửi lại OTP ---- */
    var otpSentOnce = false;
    sendBtn.addEventListener('click', function () {
        setMsg(msg1, '');
        sendBtn.disabled = true;
        var action, fd = new FormData();
        if (!otpSentOnce) {
            var email = emailInput.value.trim();
            if (email === '') { setMsg(msg1, 'Vui lòng nhập email', true); sendBtn.disabled = false; return; }
            fd.append('email', email);
            action = 'gmailSendOtp';
        } else {
            action = 'gmailResendOtp';
        }
        fetch('?mod=auth&controllers=index&action=' + action, {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                otpSentOnce = true;
                emailInput.readOnly = true;
                otpArea.style.display = '';
                otpBoxes.forEach(function (b) { b.value = ''; });
                otpBoxes[0].focus();
                setMsg(msg1, res.message, false);
                startCountdown();
            } else {
                sendBtn.disabled = false;
                setMsg(msg1, (res && res.message) || 'Không gửi được mã', true);
            }
        })
        .catch(function () { sendBtn.disabled = false; setMsg(msg1, 'Lỗi kết nối, vui lòng thử lại', true); });
    });

    /* ---- 6 ô nhập mã: tự nhảy ô, dán mã, tự xác thực khi đủ 6 ---- */
    function collectCode() {
        return otpBoxes.map(function (b) { return b.value; }).join('').toUpperCase();
    }
    otpBoxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            box.value = box.value.toUpperCase();
            if (box.value && idx < otpBoxes.length - 1) otpBoxes[idx + 1].focus();
            if (collectCode().length === 6) verifyOtp();
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && box.value === '' && idx > 0) otpBoxes[idx - 1].focus();
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
            for (var i = 0; i < otpBoxes.length; i++) otpBoxes[i].value = text.charAt(i) || '';
            (otpBoxes[Math.min(text.length, otpBoxes.length) - 1] || otpBoxes[0]).focus();
            if (collectCode().length === 6) verifyOtp();
        });
    });

    /* ---- B1c: xác thực OTP -> sang bước 2 ---- */
    function verifyOtp() {
        var code = collectCode();
        setMsg(msg1, 'Đang kiểm tra mã...', false);
        var fd = new FormData();
        fd.append('otp', code);
        fetch('?mod=auth&controllers=index&action=gmailVerifyOtp', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                setMsg(msg1, '');
                showStep(2);
                document.getElementById('gm-username').focus();
            } else {
                setMsg(msg1, (res && res.message) || 'Mã không đúng', true);
                otpBoxes.forEach(function (b) { b.value = ''; });
                otpBoxes[0].focus();
            }
        })
        .catch(function () { setMsg(msg1, 'Lỗi kết nối, vui lòng thử lại', true); });
    }

    /* ---- B2: đặt tên đăng nhập + mật khẩu -> tạo tài khoản ---- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        setMsg(msg2, '');
        regBtn.disabled = true;
        var fd = new FormData(form);
        fetch('?mod=auth&controllers=index&action=gmailRegister', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            regBtn.disabled = false;
            if (res && res.ok) {
                setMsg(msg2, 'Đăng ký tài khoản thành công, chờ admin phê duyệt tài khoản.', false);
                setTimeout(function () {
                    window.location.href = res.redirect || '?mod=auth&controllers=index&action=infomation';
                }, 1800);
            } else {
                setMsg(msg2, (res && res.message) || 'Có lỗi xảy ra', true);
                // Hết phiên xác thực -> quay lại bước 1.
                if (res && res.expired) { setTimeout(function () { otpSentOnce = false; emailInput.readOnly = false; resetModal(); }, 1500); }
            }
        })
        .catch(function () { regBtn.disabled = false; setMsg(msg2, 'Lỗi kết nối, vui lòng thử lại', true); });
    });
})();
</script>

</html>