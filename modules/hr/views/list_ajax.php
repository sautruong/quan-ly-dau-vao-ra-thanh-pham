 <!-- <div id="container-table"> -->
 <table class="hr-table">
     <thead>
         <tr>
             <!-- <th class="index">STT</th> -->
             <?php foreach ($data['list_column'] as $key => $item_col): ?>
                 <?php if ($key != 'id') {
                    ?>
                     <th class="col-<?= $key ?>">
                         <?= htmlspecialchars($item_col) ?>
                     </th>
                 <?php } ?>
             <?php endforeach; ?>

         </tr>
     </thead>
     <tbody>   
         <?php foreach ($data['list_hr'] as $item_hr): ?>
             <tr>
                 <!-- <td class="index"><?= $stt++ ?></td> -->
                 <?php foreach ($data['list_column'] as $key => $label): ?>
                     <?php if ($key != 'id'): ?>
                         <?php if ($key == 'operation'): ?>
                             <td class="operation">
                                 <div class="operation-inner">
                                     <a href="<?= $item_hr['url_edit'] ?>">
                                         <i class="fa-solid fa-pencil"></i>
                                     </a>
                                     <a href="<?= $item_hr['url_delete'] ?>" onclick="return confirm('Xóa?')">
                                         <i class="fa-solid fa-trash-can"></i>
                                     </a>
                                     <a href="<?= $item_hr['url_create_contract'] ?>" class="tooltip" target="_blank">
                                         <i class="fa-regular fa-id-badge"></i>
                                         <span class="tooltip-text">Tạo hợp đồng lao động</span>
                                     </a>
                                 </div>
                             </td>
                         <?php else: ?>
                             <td class="col-<?= $key ?>">
                                 <?= htmlspecialchars(format_value($key, $item_hr[$key] ?? '')) ?>
                             </td>
                         <?php endif; ?>
                     <?php endif; ?>
                 <?php endforeach; ?>
             </tr>
         <?php endforeach; ?>
     </tbody>
 </table>
 <!-- </div> -->