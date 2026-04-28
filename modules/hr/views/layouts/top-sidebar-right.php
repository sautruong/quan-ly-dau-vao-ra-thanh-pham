<?php
$action = $_GET['action'] ?? 'dashboard';
?>
            <div id="hr-top-sidebar-right">
                <div id="title-dashboard">
                    <div id="name-dashboard">
                        <h1>Quản lý nhân sự</h1>
                    </div>
                    <div id="container-add">
                        <a href="?mod=hr&controllers=hr&action=add">
                            <div class="ic-add">
                                <i class="fa-solid fa-plus"></i>
                            </div>
                            <div class="title-add">
                                <p>Thêm nhân sự</p>
                            </div>
                        </a>
                    </div>
                </div>
                <div id="container-main-tab">
                    <ul class="main-tab">
                        <li class="tab-item <?= $action == 'dashboard' ? 'tab-active' : '' ?>">
                            <a href="?mod=hr&controllers=hr&action=dashboard">Tổng quan</a>
                        </li>
                        <li class="tab-item <?= $action == 'list' ? 'tab-active' : '' ?>">
                            <a href="?mod=hr&controllers=hr&action=list">Danh sách nhân sự</a>
                        </li>
                        <li class="tab-item <?= $action == 'organization' ? 'tab-active' : '' ?>">
                            <a href="?mod=hr&controllers=hr&action=organization">Sơ đồ tổ chức</a>
                        </li>
                    </ul>

                </div>
            </div>