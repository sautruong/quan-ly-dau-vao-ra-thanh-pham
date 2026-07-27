<?php
/** Bước xác thực email đăng ký bằng OTP 6 ký tự (hiệu lực 60 giây). */
$email_masked = $email_masked ?? '';
$expires_in   = (int) ($expires_in ?? 60);
$otp_sent     = !isset($otp_sent) || $otp_sent; // mặc định coi như đã gửi
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xác thực email đăng ký - SAFEKING</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/style_register.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <div id="container">
            <div id="info-brand">
                <div id="logo">
                    <img src="public/images/logo/logo_vat_png.png" alt="" width="25px" height="auto">
                    <h4 style="font-weight: 600;">Safe King</h4>
                </div>
                <div id="title_form">
                    <h3>Xác thực email</h3>
                    <p>Chúng tôi đã gửi một mã OTP gồm <strong>6 ký tự</strong> đến email
                        <strong><?php echo htmlspecialchars($email_masked); ?></strong>.
                        Vui lòng nhập mã để hoàn tất đăng ký. Mã có hiệu lực trong <strong>60 giây</strong>.</p>
                    <?php if ($otp_sent): ?>
                        <p style="font-size:13px;color:#64748b;">Không thấy email? Hãy kiểm tra mục <strong>Spam / Quảng cáo</strong> rồi bấm “Gửi lại mã”.</p>
                    <?php else: ?>
                        <p style="font-size:13px;color:#dc2626;font-weight:600;">Gửi email thất bại. Vui lòng kiểm tra kết nối/cấu hình mail rồi bấm “Gửi lại mã”.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="otp-register">
                <div class="otp-boxes" id="otpBoxes">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                </div>

                <p class="otp-message" id="otpMessage"></p>

                <div id="otp-actions">
                    <span id="otp-countdown">Mã hết hạn sau <b><span id="otp-seconds"><?php echo $expires_in; ?></span>s</b></span>
                    <button type="button" id="btn-resend-otp" disabled>Gửi lại mã</button>
                </div>

                <a href="?mod=auth&controllers=index&action=register" class="link_login">Quay lại đăng ký</a>
            </div>
        </div>
    </div>
</body>

<script>
(function () {
    var EXPIRES_IN = <?php echo (int) $expires_in; ?>;

    var otpBoxes  = Array.prototype.slice.call(document.querySelectorAll('#otpBoxes .otp-box'));
    var msg       = document.getElementById('otpMessage');
    var secondsEl = document.getElementById('otp-seconds');
    var countdown = document.getElementById('otp-countdown');
    var resendBtn = document.getElementById('btn-resend-otp');

    var timer    = null;
    var verifying = false;

    function setMsg(text, isError) {
        msg.textContent = text || '';
        msg.classList.remove('ok', 'err');
        if (text) msg.classList.add(isError ? 'err' : 'ok');
    }

    function lockBoxes(locked) {
        otpBoxes.forEach(function (b) { b.disabled = locked; });
    }

    /* ---- Bộ đếm 60 giây ---- */
    function startCountdown(seconds) {
        if (timer) { clearInterval(timer); timer = null; }
        var remain = seconds;
        secondsEl.textContent = remain;
        countdown.style.display = '';
        resendBtn.disabled = true;
        lockBoxes(false);
        otpBoxes.forEach(function (b) { b.value = ''; });
        otpBoxes[0].focus();

        timer = setInterval(function () {
            remain--;
            secondsEl.textContent = remain > 0 ? remain : 0;
            if (remain <= 0) {
                clearInterval(timer);
                timer = null;
                countdown.style.display = 'none';
                resendBtn.disabled = false;
                lockBoxes(true);
                setMsg('Mã OTP đã hết hiệu lực. Vui lòng bấm "Gửi lại mã".', true);
            }
        }, 1000);
    }

    function collectCode() {
        return otpBoxes.map(function (b) { return b.value; }).join('').toUpperCase();
    }

    /* ---- 6 ô nhập: tự nhảy ô, dán mã, tự xác thực khi đủ 6 ---- */
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

    /* ---- Xác thực OTP ---- */
    function verifyOtp() {
        if (verifying) return;
        verifying = true;
        setMsg('Đang kiểm tra mã...', false);
        var fd = new FormData();
        fd.append('otp', collectCode());
        fetch('?mod=auth&controllers=index&action=verifyRegister', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            verifying = false;
            if (res && res.ok) {
                if (timer) { clearInterval(timer); timer = null; }
                setMsg('Xác thực thành công! Đang chuyển hướng...', false);
                lockBoxes(true);
                window.location.href = res.redirect || '?mod=auth&controllers=index&action=infomation';
            } else {
                setMsg((res && res.message) || 'Mã không đúng', true);
                if (res && res.expired) {
                    if (timer) { clearInterval(timer); timer = null; }
                    countdown.style.display = 'none';
                    lockBoxes(true);
                    resendBtn.disabled = false;
                } else {
                    otpBoxes.forEach(function (b) { b.value = ''; });
                    otpBoxes[0].focus();
                }
            }
        })
        .catch(function () { verifying = false; setMsg('Lỗi kết nối, vui lòng thử lại', true); });
    }

    /* ---- Gửi lại mã ---- */
    resendBtn.addEventListener('click', function () {
        resendBtn.disabled = true;
        setMsg('Đang gửi lại mã...', false);
        fetch('?mod=auth&controllers=index&action=resendRegisterOtp', {
            method: 'POST', body: new FormData(), credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                setMsg(res.message || 'Đã gửi lại mã', false);
                startCountdown(res.expires_in || EXPIRES_IN);
            } else {
                setMsg((res && res.message) || 'Không gửi lại được mã', true);
                if (res && res.expired) {
                    setTimeout(function () {
                        window.location.href = '?mod=auth&controllers=index&action=register';
                    }, 1500);
                } else {
                    resendBtn.disabled = false;
                }
            }
        })
        .catch(function () { resendBtn.disabled = false; setMsg('Lỗi kết nối, vui lòng thử lại', true); });
    });

    // Khởi động bộ đếm theo số giây còn lại do server cấp.
    startCountdown(EXPIRES_IN);
})();
</script>

</html>
