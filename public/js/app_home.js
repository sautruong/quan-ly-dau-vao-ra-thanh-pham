const toggle = document.getElementById("menu-toggle");
const menu = document.getElementById("mobile-menu");
const closeBtn = document.getElementById("menu-close");

toggle.onclick = function(){
    menu.classList.toggle("active");
}

closeBtn.onclick = function(){
    menu.classList.remove("active");
}
