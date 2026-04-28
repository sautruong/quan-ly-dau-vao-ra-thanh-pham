<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/style_home.css">
</head>
<style>
    #wrapper{
        display: flex !important;
        /* align-items: center !important; */
        justify-content: center !important;
    }
    /* list dạng grid */
    .list-card {
        display: grid;

        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        align-items: start;

        padding: 20px;
    }

    /* card */
    .card-item {
        list-style: none;
        display: grid;
        border-radius: 12px;
        border: 1px solid gainsboro;
        padding: 20px;
        align-items: start;

    }

    .card-item a {
        display: flex;
        flex-direction: column;
        grid-template-columns: 500px 1fr;
        /* ảnh cố định */
        align-items: center;
        gap: 15px;

        text-decoration: none;
        height: fit-content;

    }

    /* ảnh giữ nguyên 500px */
    .card-item img {
        width: 500px;
        max-width: 100%;
        height: auto;
        display: block;
    }

    /* title */
    .title-card {
        min-width: 0;
        /* 🔥 cực kỳ quan trọng trong grid */
    }

    .title-card p {
        margin: 0;
        font-size: 18px;
        color: gray;
        transition: 0.2s;
    }

    /* hover */
    .card-item:hover .title-card p {
        color: green;
    }

    /* hover ảnh nhẹ */
    .card-item:hover img {
        transform: scale(1.02);
        transition: 0.3s;
    }
</style>

<body>
    <div id="wrapper">
        <ul class="list-card">

            <li class="card-item">
                <a href="?mod=product_profile&controllers=product_profile&action=list">
                    <div class="img">
                        <img src="public/images/thumnail/thum_product_profile.JPG" alt="">
                    </div>
                    <div class="title-card">
                        <p>Hồ sơ sản phẩm</p>
                    </div>
                </a>
            </li>
            <li class="card-item">
                <a href="?mod=production_staff&controllers=production_staff&action=production_plan">
                    <div class="img">
                        <img src="public/images/thumnail/lap-ke-hoach-san-xuat.JPG" alt="">
                    </div>
                    <div class="title-card">
                        <p>Lập kế hoạch sản xuất</p>
                    </div>
                </a>
            </li>
            <li class="card-item">
                <a href="?mod=inventory_management&controllers=inventory_management&action=dashboard">
                    <div class="img">
                        <img src="public/images/thumnail/lap-ke-hoach-san-xuat.JPG" alt="">
                    </div>
                    <div class="title-card">
                        <p>QUẢN LÝ TỒN KHO SẢN PHẨM</p>
                    </div>
                </a>
            </li>

        </ul>
    </div>
</body>


</html>