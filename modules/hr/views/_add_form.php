<?php
/* Form "Thêm nhân sự" dùng chung: trang add.php + modal trong list.php. */
require_once 'helper/hr_helper.php';
global $table_hr_config;
?>
<form method="POST" action="?mod=hr&controllers=hr&action=insert" class="hr-add-form">
    <?php foreach ($table_hr_config as $table => $config): ?>
        <div class="group-table">
            <h4 class="group-title"><?= get_table_title($table) ?></h4>
            <div class="form-grid">
                <?php
                $hiddenFields = $config['hidden']['form'] ?? [];
                foreach ($config['labels'] as $column => $label):
                    if (in_array($column, $hiddenFields)) continue;
                ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <?= render_input($table, $column, '') ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <input type="submit" value="Thêm nhân sự" id="btn-submit">
</form>
