<?php
$action = $_GET['action'] ?? 'dashboard';
?>
<div id="hr-top-sidebar-right">
    <div id="title-dashboard">
        <div id="name-dashboard">
            <h1>Quản lý hồ sơ sản phẩm</h1>
        </div>
        <div id="container-add">
            <a href="?mod=product_profile&controllers=product_profile&action=add_product">
                <div class="ic-add">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="title-add">
                    <p>Thêm sản phẩm</p>
                </div>
            </a>
        </div>
    </div>
    <div id="container-main-tab">
        <ul class="main-tab">
            <li class="tab-item <?= $action == 'dashboard' ? 'tab-active' : '' ?>">
                <a href="?mod=product_profile&controllers=product_profile&action=dashboard">Tổng quan</a>
            </li>
            <li class="tab-item <?= $action == 'list' ? 'tab-active' : '' ?>">
                <a href="?mod=product_profile&controllers=product_profile&action=list">Danh sách sản phẩm</a>
            </li>
            <li class="tab-item <?= $action == 'food_legislation' ? 'tab-active' : '' ?>">
                <a href="?mod=product_profile&controllers=product_profile&action=food_legislation">Tư liệu luật thực phẩm</a>
            </li>
        </ul>
    </div>
</div>