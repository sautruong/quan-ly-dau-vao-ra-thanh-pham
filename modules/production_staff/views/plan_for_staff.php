<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kế hoạch sản xuất (NVSX)</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/production_staff/plan_for_staff.css">
</head>

<?php
$cols = isset($_GET['cols']) && (int)$_GET['cols'] === 2 ? 2 : 4;
?>

<body>
    <div id="wrapper" class="cols-<?php echo $cols; ?>">
        <div class="wp-header-content">
            <div class="header">
                <div class="wp-top-header">
                    <div class="logo">
                        <a href="">
                            <img src="public/images/logo/logo_vat_png.png" alt="">
                        </a>
                    </div>

                    <div class="title">
                        <h1>KẾ HOẠCH SẢN XUẤT</h1>
                    </div>
                    <div class="choose-display">
                        <select name="display-col" id="display-col">
                            <option value="2" <?php echo $cols === 2 ? 'selected' : ''; ?>>2 cột</option>
                            <option value="4" <?php echo $cols === 4 ? 'selected' : ''; ?>>4 cột</option>
                        </select>
                    </div>
                </div>


                <div class="date">
                    <p><?php echo date('d') . ' tháng ' . date('n') . ' ' . date('Y'); ?></p>
                </div>
            </div>
            <div class="content">
                <ul class="list-product">
                    <?php if (empty($plans)): ?>
                        <li style="grid-column:1/-1;text-align:center;padding:40px;color:#6b7280;font-style:italic;list-style:none;">
                            Chưa có kế hoạch sản xuất được gửi xuống.
                        </li>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                            <li class="product-item">
                                <div class="wp-product-num">
                                    <div class="name-product">
                                        <p><strong><?php echo htmlspecialchars($plan['product_name']); ?></strong></p>
                                    </div>
                                    <div class="num">
                                        <input type="text" value="<?php echo (int)$plan['quantity']; ?>" readonly>
                                    </div>
                                </div>
                                <div class="list-QC">
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_sample_<?php echo $plan['plan_id']; ?>" <?php echo (int)$plan['sample'] === 1 ? 'checked' : ''; ?> disabled>
                                        <label for="ck_sample_<?php echo $plan['plan_id']; ?>">lấy mẫu</label>
                                    </div>
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_test_<?php echo $plan['plan_id']; ?>" <?php echo (int)$plan['test'] === 1 ? 'checked' : ''; ?> disabled>
                                        <label for="ck_test_<?php echo $plan['plan_id']; ?>">Test</label>
                                    </div>
                                    <div class="wp-ck">
                                        <input type="checkbox" id="ck_stock_<?php echo $plan['plan_id']; ?>" <?php echo (int)$plan['check_inventory'] === 1 ? 'checked' : ''; ?> disabled>
                                        <label for="ck_stock_<?php echo $plan['plan_id']; ?>">Kiểm tồn</label>
                                    </div>
                                </div>

                                <div class="wp-info">
                                    <div class="title">
                                        <p>Thông tin:</p>
                                    </div>
                                    <ul class="list-info">
                                        <li class="info-item">Tồn kho đầu ngày:
                                            <span><?php echo $plan['stock_qty'] !== null ? (int)$plan['stock_qty'] : '—'; ?></span>
                                        </li>
                                        <li class="info-item">Bao bì trong:
                                            <span><?php echo htmlspecialchars($plan['inner_packaging'] ?: '—'); ?></span>
                                        </li>
                                        <li class="info-item">QC BBT:
                                            <span><?php echo htmlspecialchars($plan['inner_packaging_spec'] ?: '—'); ?></span>
                                        </li>
                                        <li class="info-item">Bao bì ngoài:
                                            <span><?php echo htmlspecialchars($plan['outer_packaging'] ?: '—'); ?></span>
                                        </li>
                                        <li class="info-item">QC BBN:
                                            <span><?php echo htmlspecialchars($plan['outer_packaging_spec'] ?: '—'); ?></span>
                                        </li>
                                    </ul>
                                </div>

                                <?php
                                $note_display = trim($plan['pre_note'] ?? '');
                                if ($note_display === '') $note_display = trim($plan['plan_note'] ?? '');
                                ?>
                                <?php if ($note_display !== ''): ?>
                                    <div class="note">
                                        <strong>Lưu ý:</strong>
                                        <input type="text" value="<?php echo htmlspecialchars($note_display); ?>" readonly>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>

                <?php if (!empty($tasks)): ?>
                    <div class="wp-other-work">
                        <div class="add-other-work">
                            <p>VIỆC KHÁC</p>
                        </div>
                        <ul class="list-other-work">
                            <?php foreach ($tasks as $i => $task): ?>
                                <li class="other-work-item">
                                    <div class="serial">
                                        <p><?php echo $i + 1; ?></p>
                                    </div>
                                    <div class="content-other-work">
                                        <input type="text" value="<?php echo htmlspecialchars($task['description']); ?>" readonly>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        (function() {
            var sel = document.getElementById('display-col');
            if (!sel) return;
            sel.addEventListener('change', function() {
                var url = new URL(window.location.href);
                url.searchParams.set('cols', sel.value);
                window.location.href = url.toString();
            });
        })();
    </script>
</body>

</html>