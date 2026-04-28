<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>index hr</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/list.css">


    <!--js của menu sidebarleft-->
    <!-- <script src="public/js/menu_sidebar_left.js" defer></script> -->

</head>

<body>
    <div id="wrapper">
        <!-- Require Sidebar-left -->
        <?php //require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            
                <!-- Require Sidebar-right -->
                <?php require "layouts/top-sidebar-right.php"; ?>
                <div class="main-content">
                    <div class="wp-search">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="search-product" class="search-input" placeholder="Tìm kiếm sản phẩm...">
                        </div>
                    </div>
                    <!-- HIỂN THỊ DANH SÁCH SẢN PHẨM -->
                    <!-- Vòng lặp ngoài lấy danh mục -->
                    <div class="container-category">
                        <?php foreach ($data as $key => $category): ?>
                            <div class="wp-title-category">
                                <div class="title-categogy">
                                    <h3><?= $key ?></h3>
                                </div>
                                <?php $firstKey = array_key_first($data); ?>
                                <?php if ($key === $firstKey): ?>
                                    <!-- GHI CHÚ: "Sản phẩm có công bố dinh dưỡng"-->
                                    <div class="container-note-nutrition-fact">
                                        <div class="nutrition-facts">
                                            <i class="fa-solid fa-circle-check"></i>
                                        </div>
                                        <div class="content-note">
                                            <p>Sản phẩm có công bố dinh dưỡng</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <!-- Vòng lặp trong lấy sản phẩm -->
                            <div id="wrapper-product">
                                <?php foreach ($category as $productId => $product_item): ?>

                                    <div class="container-product" data-product-name="<?= mb_strtolower($product_item['product_name'], 'UTF-8') ?>">
                                        <div class="wp-title-product">
                                            <div class="img_product">
                                                <img src="<?= $product_item['image_url'] ?>" alt="">
                                            </div>
                                            <div class="wp-title-button">
                                                <div class="container-title-product">
                                                    <div class="title-product">
                                                        <a href="?mod=product_profile&controllers=product_profile&action=product_detail&id=<?= $productId ?> ">
                                                            <?= $product_item['product_name'] ?>
                                                        </a>

                                                    </div>
                                                    <?php if ($product_item['has_nutrition_fact'] == 1): ?>
                                                        <div class="nutrition-facts">
                                                            <i class="fa-solid fa-circle-check"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="container-button">
                                                    <div class="wp-add-file">
                                                        <a href="?mod=product_profile&controllers=product_profile&action=add_file&id=<?= $productId ?>" class="btn-add-file" id="open_add_file">
                                                            Thêm file
                                                        </a>
                                                    </div>
                                                    <div class="wp-delete-product">
                                                        <a href="?mod=product_profile&controllers=product_profile&action=delete_product&id=<?= $productId ?>" class="btn-delete-product"
                                                            onclick="return confirm('Bạn có thực sự muốn xóa sản phẩm này?')">
                                                            Xóa sản phẩm
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="content-product">

                                            <div class="wp-list-file">
                                                <ul class="main-file">
                                                    <!-- Chạy vòng lặp laod list_file ra -->
                                                    <?php foreach ($product_item['list_file'] as $file_item): ?>
                                                        <li class="file-item">
                                                            <a href="<?= $file_item['file_path'] ?>" target="_blank">
                                                                <p class="title-file"><?= $file_item['file_name'] ?></p>
                                                            </a>
                                                            <div class="wp-icon-processing-file">
                                                                <a href="?mod=product_profile&controllers=product_profile&action=download_file&id_file=<?= $file_item['file_id'] ?>">
                                                                    <i class="fa-solid fa-download"></i>
                                                                </a>
                                                                <a href="?mod=product_profile&controllers=product_profile&action=update_file&id_product=<?= $productId ?>&id_file=<?= $file_item['file_id'] ?>">
                                                                    <i class="fa-solid fa-file-arrow-up"></i>
                                                                </a>
                                                                <a href="?mod=product_profile&controllers=product_profile&action=delete_file&id_file=<?= $file_item['file_id'] ?>"
                                                                    onclick="return confirm('Bạn có thật sự muốn xóa file này?')">
                                                                    <i class="fa-solid fa-trash-can"></i>
                                                                </a>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
          
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var input = document.getElementById('search-product');
                var products = document.querySelectorAll('.container-product');
                var categories = document.querySelectorAll('.container-category > .wp-title-category');
                var productWrappers = document.querySelectorAll('#wrapper-product');

                input.addEventListener('input', function() {
                    var keyword = this.value.toLowerCase().trim();

                    // Lọc từng sản phẩm
                    products.forEach(function(card) {
                        var name = card.getAttribute('data-product-name');
                        card.style.display = name.indexOf(keyword) !== -1 ? '' : 'none';
                    });

                    // Ẩn danh mục nếu tất cả sản phẩm bên trong đều bị ẩn
                    productWrappers.forEach(function(wrapper, index) {
                        var visibleCards = wrapper.querySelectorAll('.container-product:not([style*="display: none"])');
                        var hasVisible = visibleCards.length > 0;
                        wrapper.style.display = hasVisible ? '' : 'none';
                        if (categories[index]) {
                            categories[index].style.display = hasVisible ? '' : 'none';
                        }
                    });
                });
            });
        </script>
</body>

</html>