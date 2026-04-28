<?php
global $config;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>index hr</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/detail.css">


    <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>

    <!--Định nghĩa thư viện js-->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>

    <script src="public/js/menu_sidebar_left.js" defer></script>
    <!--app-->
    <script src="public/js/product_profile_detail.js" defer></script>

</head>

<body>
    <div id="wrapper">
        <!-- Require Sidebar-left -->
        <?php require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            <!-- Require Sidebar-right -->
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
               <?php if(!empty($data['info'][0]['product_name'])): ?>
                <h1> <?= mb_strtoupper($data['info'][0]['product_name']) ?></h1>
                <?php endif;?>
                <div class="wp-content">
                    <div class="wp-info-product">
                        <div class="wp-title">
                            <div class="title">
                                <p>Thông tin sản phẩm</p>
                            </div>
                            <a href="?mod=product_profile&controllers=product_profile&action=edit_product&id=<?= $data['product_id'] ?>" class="edit">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                        </div>
                        
                        <div class="container-info-product">
                            <div class="wp-info-product-basic">
                                <div class="product-thum">
                                    <img src="<?= $data['info'][0]['image_url'] ?>" alt="">
                                </div>
                                <div class="info-product-basic">
                                    <p>Đơn vị: <?= $data['info'][0]['unit'] ?></p>

                                    <p>Bao bì trong: <?= $data['info'][0]['inner_packaging'] ?></p>
                                    <p>Bao bì ngoài: <?= $data['info'][0]['outer_packaging'] ?></p>
                                    <p>Quy cách bao bì trong: <?= $data['info'][0]['inner_packaging_spec'] ?></p>
                                    <p>Quy cách bao bì ngoài: <?= $data['info'][0]['outer_packaging_spec'] ?></p>
                                </div>
                            </div>
                            <div class="line"></div>
                            <div class="wp-price-product" data-product-id="<?= $data['product_id'] ?>">
                                <div class="sale-price-policy">
                                    <div class="title-price">
                                        <H3>CHÍNH SÁCH GIÁ</H3>
                                    </div>
                                    <div class="tbl-price">
                                        <table class="price-table">
                                            <!-- 15 cột ẩn để định kích thước đều -->
                                            <colgroup>
                                                <col span="15" style="width:6.666%">
                                            </colgroup>

                                            <!-- HEADER: 3 ô x colspan=5 -->
                                            <thead>
                                                <tr>
                                                    <th colspan="5">Giá vốn</th>
                                                    <th colspan="5">Giá xuất Passion Link</th>
                                                    <th colspan="5">Giá bán lẻ</th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <!-- DÒNG GIÁ: 3 ô x colspan=5 -->
                                                <tr class="row-price">
                                                    <td colspan="5">
                                                        <span class="capital-price"><?= !empty($data['pricing']['cost_price']) ? number_format($data['pricing']['cost_price'], 0, ',', ',') . ' đ' : '0 đ' ?></span>
                                                    </td>
                                                    <td colspan="5">
                                                        <input type="text" value="<?= !empty($data['pricing']['system_price']) ? number_format($data['pricing']['system_price'], 0, ',', ',') . ' đ' : '0 đ' ?>" class="wholesale-price">
                                                    </td>
                                                    <td colspan="5">
                                                        <input type="text" value="<?= !empty($data['pricing']['retail_price']) ? number_format($data['pricing']['retail_price'], 0, ',', ',') . ' đ' : '0 đ' ?>" class="retail-price">
                                                    </td>
                                                </tr>

                                                <!-- DÒNG FLOW: 5 ô x colspan=3 -->
                                                <!-- dot1 | step-1-2 | dot2 | step-2-3 | dot3 -->
                                                <tr class="flow-row">
                                                    <td colspan="3" class="td-dot">
                                                        <i class="fa-regular fa-circle-dot"></i>
                                                    </td>
                                                    <td colspan="3" class="td-step">
                                                        <span class="step-label">x</span>
                                                        <span class="step-1-2"><?= !empty($data['pricing']['cost_price']) && $data['pricing']['cost_price'] > 0 ? number_format($data['pricing']['system_price'] / $data['pricing']['cost_price'], 2) : '0.00' ?></span>
                                                    </td>
                                                    <td colspan="3" class="td-dot">
                                                        <i class="fa-regular fa-circle-dot"></i>
                                                    </td>
                                                    <td colspan="3" class="td-step">
                                                        <span class="step-label">x</span>
                                                        <span class="step-2-3"><?= !empty($data['pricing']['system_price']) && $data['pricing']['system_price'] > 0 ? number_format($data['pricing']['retail_price'] / $data['pricing']['system_price'], 2) : '0.00' ?></span>
                                                    </td>
                                                    <td colspan="3" class="td-dot">
                                                        <i class="fa-regular fa-circle-dot"></i>
                                                    </td>
                                                </tr>

                                                <!-- DÒNG STEP 1→3: 3 ô x colspan=5 -->
                                                <!-- dot1 | step-1-3 | dot2 -->
                                                <tr class="row-total-step">
                                                    <td colspan="5" class="td-dot">
                                                        <i class="fa-regular fa-circle-dot"></i>
                                                    </td>
                                                    <td colspan="5" class="td-step">
                                                        <span class="step-label">x</span>
                                                        <span class="step-1-3"><?= !empty($data['pricing']['cost_price']) && $data['pricing']['cost_price'] > 0 ? number_format($data['pricing']['retail_price'] / $data['pricing']['cost_price'], 2) : '0.00' ?></span>
                                                    </td>
                                                    <td colspan="5" class="td-dot">
                                                        <i class="fa-regular fa-circle-dot"></i>
                                                    </td>
                                                </tr>

                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                      
                    </div>
                    <div class="wp-ingredient">
                        <div class="wp-title">
                            <div class="title">
                                <p>Thành phần cấu tạo</p>
                            </div>
                            <a href="?mod=product_profile&controllers=product_profile&action=edit_product&id=<?= $data['product_id'] ?>" class="edit">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                        </div>
                        <div class="container-ingredient">
                            <ul class="list-ingredient">
                                <?php if (!empty($data['composition'])): ?>
                                    <?php foreach ($data['composition'] as $item): ?>
                                        <li class="ingredient-item">
                                            <div class="name-ingredient">
                                                <p><strong><?= $item['material_name'] ?></strong> - <?= $item['supplier_name']  ?> </p>
                                            </div>
                                            <div class="container-content-ingredient">
                                                <div class="wp-button">
                                                    <a href="" class="tbn-replace">
                                                        Thay thế
                                                    </a>
                                                    <a href="" class="btn-delete">
                                                        Xóa thành phần
                                                    </a>
                                                </div>
                                                <div class="wp-supplier-profile">

                                                    <div class="wp-title">

                                                        <div class="level-1">
                                                            <p> - Hồ sơ nhà cung cấp </p>
                                                        </div>
                                                        <a href="?mod=product_profile&controllers=product_profile&action=add_file_supplier&id=<?= $item['mi_supplier_id'] ?>&product_id=<?= $data['product_id'] ?>" class="btn-add-file">
                                                            + Thêm file
                                                        </a>
                                                    </div>
                                                    <ul class="list-file">
                                                        <?php if (!empty($item['files']['supplier'])): ?>
                                                            <?php foreach ($item['files']['supplier'] as $item_file_supplier): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file">
                                                                        <a href="<?= $config['base_url'].$item_file_supplier['file_path'] ?>" target="_blank">
                                                                            <?= $item_file_supplier['file_name'] ?>
                                                                        </a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>" class="download-file" title="Tải file">
                                                                            <i class="fa-solid fa-download"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=replace_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>" class="replace-file" title="Thay thế file">
                                                                             <i class="fa-solid fa-rotate"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_composition_file&file_id=<?= $item_file_supplier['id'] ?>&product_id=<?= $data['product_id'] ?>" class="delete-file" title="Xóa file" onclick="return confirm('Bạn có chắc chắn muốn xóa file \'<?= htmlspecialchars($item_file_supplier['file_name'], ENT_QUOTES) ?>\' không?');">
                                                                             <i class="fa-regular fa-trash-can"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </ul>

                                                </div>
                                                <div class="wp-row-materials">
                                                    <div class="wp-title">
                                                        <div class="level-1">
                                                            <p> - Hồ sơ nguyên liệu</p>
                                                        </div>
                                                        <a href="?mod=product_profile&controllers=product_profile&action=add_file_material&id=<?= $item['material_info_id'] ?>&product_id=<?= $data['product_id'] ?>" class="btn-add-file">
                                                            + Thêm file
                                                        </a>
                                                    </div>
                                                    <ul class="list-file">
                                                        <?php if (!empty($item['files']['material'])): ?>
                                                            <?php foreach ($item['files']['material'] as $item_file_material): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file">
                                                                        <a href="<?= $config['base_url'] . $item_file_material['file_path'] ?>" target="_blank">
                                                                            <?= $item_file_material['file_name'] ?>
                                                                        </a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>" class="download-file" title="Tải file">
                                                                            <i class="fa-solid fa-download"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=replace_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>" class="replace-file" title="Thay thế file">
                                                                            <i class="fa-solid fa-rotate"></i>
                                                                        </a>
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_composition_file&file_id=<?= $item_file_material['id'] ?>&product_id=<?= $data['product_id'] ?>" class="delete-file" title="Xóa file" onclick="return confirm('Bạn có chắc chắn muốn xóa file \'<?= htmlspecialchars($item_file_material['file_name'], ENT_QUOTES) ?>\' không?');">
                                                                            <i class="fa-regular fa-trash-can"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

            </div>


        </div>


</body>

</html>