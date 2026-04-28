document.querySelectorAll(".password-box").forEach(box => {
    let input = box.querySelector("input");
    let icon = box.querySelector("i");
    let toggle = box.querySelector(".toggle-password");

    // Ẩn icon ban đầu
    toggle.style.display = "none";
    // Khi nhập password thì hiện icon
    input.addEventListener("input", function () {
        if (input.value.length > 0) {
            toggle.style.display = "block";
        } else {
            toggle.style.display = "none";
        }
    });
    // Toggle show / hide password
    toggle.onclick = function () {

        if (input.type === "password") {
            input.type = "text";
            icon.classList.replace("fa-eye-slash", "fa-eye");
        } else {
            input.type = "password";
            icon.classList.replace("fa-eye", "fa-eye-slash");
        }

    }

});