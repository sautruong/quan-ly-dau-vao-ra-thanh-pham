<?php
// Cấu hình BÊN A + Giám đốc (sửa-tại-chỗ, lưu dùng dài lâu).
$cs = $data['contract_settings'] ?? [
    'company_name'    => 'CÔNG TY TNHH VUA AN TOÀN',
    'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
    'director_name'   => 'LÊ HỮU TRÍ',
];
/** Span sửa-tại-chỗ: hover hiện cây bút, sửa 1 lần lưu dài lâu (data-ckey). */
$c_edit = function ($key, $val, $bold = false) {
    $v = htmlspecialchars((string) $val);
    $b = $bold ? ' c-bold' : '';
    return '<span class="c-editable' . $b . '" data-ckey="' . $key . '">'
         . '<span class="c-ed-text">' . $v . '</span>'
         . '<i class="fa fa-pen c-pen" title="Sửa (lưu dùng dài lâu)"></i>'
         . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hợp đồng lao động nhân viên</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/contract.css'); ?>">
    <style>
        .c-editable { position: relative; display: inline-block; border-radius: 3px; padding: 0 2px; }
        .c-editable.c-bold { font-weight: bold; }
        .c-editable:hover { background: #fff7d6; }
        .c-editable.editing { background: #fff; outline: 1px dashed #2563eb; cursor: text; }
        .c-pen { display: none; margin-left: 5px; color: #2563eb; cursor: pointer; font-size: 12px; }
        .c-editable:hover .c-pen { display: inline; }
        .c-editable.editing .c-pen { display: none; }
        .toolbar .save-word { display: flex; justify-content: center; flex-direction: column; cursor: pointer; }
        @media print { .c-pen { display: none !important; } .c-editable:hover { background: transparent; } }
    </style>
</head>

<body>
    <div class="toolbar">
        <div class="print">
            <i class="fa fa-print"></i>
            <p>Print</p>
        </div>

        <div class="save-word">
            <i class="fa fa-file-word"></i>
            <p>Save word</p>
        </div>
    </div>

    <div class="contract">

        <!-- ===== TRANG 1 ===== -->
        <div class="page">

            <div style="display:flex; justify-content:space-between;">
                <div>
                    <?php echo $c_edit('company_name', $cs['company_name'], true); ?><br>
                    Số: <?php echo $data['contract_number']; ?>
                </div>

                <div class="center">
                    <b>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</b><br>
                    <b>Độc lập - Tự do - Hạnh phúc</b>
                </div>
            </div>

            <br>

            <h1 style="font-size: 24px;margin-bottom: 20px;">HỢP ĐỒNG LAO ĐỘNG</h1>

            <p class="italic">Căn cứ vào Luật lao động nước CHXHCN Việt Nam ban hành ngày 18/6/2012</p>
            <p class="italic text-justify">Căn cứ vào Luật dân sự nước CHXHCN Việt Nam ban hành ngày 24/11/2015, có hiệu lực thi hành từ ngày 01/01/2017</p>

            <p class="italic">Hôm nay, ngày <?php echo $data['sign_date']; ?> tại Công ty TNHH Vua An Toàn</p>
            <p>Chúng tôi gồm:</p>
            <p><b>BÊN A (NGƯỜI SỬ DỤNG LAO ĐỘNG): </b><?php echo $c_edit('company_name', $cs['company_name'], true); ?></p>
            <p>Địa chỉ: <?php echo $c_edit('company_address', $cs['company_address']); ?></p>
            <p>Đại diện: Ông <?php echo $c_edit('director_name', $cs['director_name'], true); ?> - Giám Đốc</p>

            <p><b>BÊN B (NGƯỜI LAO ĐỘNG):</b> <?php echo $data['employee_name']; ?></p>
            <p>Ngày tháng năm sinh: <?php echo format_value('date_of_birth', $data['date_of_birth']); ?></p>
            <p>Quốc tịch: <?php echo $data['nationality']; ?></p>
            <p>Địa chỉ thường trú: <?php echo $data['permanent_address']; ?></p>
            <p>Chỗ ở hiện tại: <?php echo $data['temporary_address']; ?></p>
            <p>Số CCCD: <?php echo $data['citizen_id']; ?></p>
            <p>Nơi cấp: <?php echo $data['place_of_issue']; ?></p>
            <p class="text-justify">Các bên thỏa thuận ký kết Hợp đồng làm việc và cam kết thực hiện đúng những điều khoản sau đây:</p>
            <p><b>Điều 1: Điều khoản chung</b></p>
            <p>1. Loại HĐLĐ có thời hạn và thời hạn là: <?php echo $data['contract_type']; ?></p>
            <p>2. Thời gian lao động từ ngày: <?php echo $data['sign_date']; ?> đến hết ngày <?php echo $data['contract_end_date']; ?></p>
            <p>3. Nơi làm việc: <b>CÔNG TY TNHH VUA AN TOÀN</b></p>
            <p>Địa chỉ làm việc: <?php echo $data['work_address']; ?></p>
            <p>4. Chức vụ: <?php echo $data['position']; ?></p>

        </div>


        <!-- ===== TRANG 2 ===== -->
        <div class="page">

            <p class="text-justify">5. Công việc phải làm: Chức danh chuyên môn và công việc phải làm được mô tả trong bản mô tả công việc, trách nhiệm công việc và/hoặc mục tiêu công việc được giao theo từng thời điểm, được công bố trong cơ sở dữ liệu của Công ty. Chức danh chuyên môn và công việc phải làm có thể thay đổi tùy nhu cầu kinh doanh và hoạt động của Công ty, sự thay đổi sẽ được Công ty thông báo cho người lao động và trở thành phụ lục của hợp đồng này. Sự thay đổi không bị xem như là vi phạm các điều khoản của hợp đồng.</p>
            <p><b>Điều 2: Chế độ làm việc</b></p>
            <p>1. Thời gian làm việc</p>
            <p class="text-justify">- Thời gian làm việc: 48 giờ/tuần (sáng từ 8h đến 12h, chiều từ 13h đến 17h)</p>
            <p class="text-justify">- Làm việc từ thứ 2 đến thứ 7 hàng tuần</p>
            <p class="text-justify">- Người lao động đồng ý làm thêm giờ khi được yêu cầu vào từng thời điểm phụ thuộc vào nhu cầu công việc của Công ty.</p>
            <p class="text-justify">- Được cấp phát những dụng cụ: Cần thiết theo yêu cầu công việc.</p>
            <p class="text-justify">- Điều kiện an toàn và vệ sinh lao động tại nơi làm việc theo quy định hiện hành của Nhà nước</p>
            <p class="text-justify">2. Thiết bị và công cụ làm việc sẽ được Công ty cấp phát tùy theo nhu cầu của công việc</p>
            <p class="text-justify">3. Điều kiện an toàn và vệ sinh lao động tại nơi làm việc theo quy định của pháp luật hiện hành.</p>
            <p><b>Điều 3: Quyền lợi và nghĩa vụ của người lao động</b></p>
            <p><b>1. Quyền của người lao động</b></p>
            <p>a. Tiền lương và phụ cấp:</p>
            <p class="text-justify">- Mức lương đóng bảo hiểm xã hội (BHXH): <?php echo number_format($data['insurance_salary']); ?> VNĐ/tháng</p>
            <p class="text-justify">- Mức lương trên đã bao gồm các khoản phụ cấp</p>
            <p class="text-justify">- Đối với người lao động đang trong thời gian thử việc được trả 85% trên các khoản lương.</p>
            <p class="text-justify">- Hình thức trả lương: Trả 01 lần vào ngày 10 hàng tháng, trả lương qua tài khoản hoặc tiền mặt. Được ứng lương vào ngày 25 hàng tháng (2.000.000 đồng áp dụng cho nhân viên chính thức).</p>
            <p>b. Các quyền lợi khác:</p>
            <p class="text-justify">- Tiền thưởng: Tùy thuộc vào kết quả kinh doanh của Công ty, quyết định của Ban Tổng Giám Đốc và theo quy chế thưởng của Công ty trong từng thời kỳ. Tiền thưởng được thanh toán khi nhân viên làm việc với Công ty đến thời điểm trả thưởng.</p>
        </div>
        <div class="page">

            <p class="text-justify">- Chế độ nâng lương: Việc xem xét lương hàng năm sẽ tuân theo quy chế lương của Công ty và kết quả đánh giá thực hiện công việc của cá nhân và Công ty hàng năm. Lương sẽ được xem xét tùy vào quyết định của Ban Tổng Giám Đốc. Quyết định nâng lương sẽ được xem xét tùy vào quyết định của Ban Tổng Giám Đốc. Quyết định nâng lương sẽ được xem là phụ lục Hợp đồng về việc điều chỉnh lương của Hợp Đồng này. </p>
            <p class="text-justify">- Chế độ nghỉ ngơi (nghỉ hàng tuần, phép năm, lễ Tết…): Theo quy định hiện hành </p>

            <p class="text-justify">- Bảo hiểm xã hội (BHXH), bảo hiểm y tế (BHYT) và Bảo hiểm thất nghiệp (BHTN): Mức đóng BHXH hằng tháng đối với người sử dụng lao động (NSDLĐ) và người lao động (NLĐ) được tính theo quy định hiện hành của Nhà nước.</p>
            <p class="text-justify">- Thuế thu nhập cá nhân liên quan đến tổng thu nhập hàng tháng. Nhân viên chịu trách nhiệm đóng các khoản thuế liên quan. Công ty sẽ thực hiện kê khai, khấu trừ các khoản tiền này để đóng cho cơ quan có thẩm quyền. Nhân viên có trách nhiệm kê khai giảm trừ gia cảnh nộp cho Phòng tổ chức Nhân sự của Công ty (nếu có)</p>
            <p class="text-justify">- Chế độ đào tạo: Trong thời gian đơn vị cử đi học người lao động phải hoàn thành khóa học đúng thời hạn, được hưởng nguyên lương và các quyền lợi khác như người đi làm việc, trừ tiền bồi dưỡng độc hại. </p>

            <p><b>2. Nghĩa vụ của người lao động</b></p>
            <p class="text-justify">- Thực hiện công việc với hiệu quả cao nhất theo sự phân công, điều hành của người có thẩm quyền.</p>
            <p class="text-justify">- Hoàn thành công việc được giao và sẵn sàng chấp nhận mọi sự điều động khi có yêu cầu.</p>
            <p class="text-justify">- Không làm việc đủ ngày công theo khoản 1 điều 2, sẽ tính lương theo mức đóng BHXH trên số ngày công thực tế.</p>
            <p class="text-justify">- Bồi thường vi phạm và vật chất theo quy chế, nội quy của Công ty và pháp luật Nhà nước quy định.</p>
            <p class="text-justify">- Tham dự đầy đủ, nhiệt tình các buổi huấn luyện, đào tạo, hội thảo do Bộ phận hoặc Công ty tổ chức.</p>
            <p class="text-justify">- Thực hiện đúng cam kết trong hợp đồng lao động và các thỏa thuận bằng văn bản khác với Công ty.</p>
            <p class="text-justify">- Tuyệt đối thực hiện cam kết bảo mật thông tin.</p>
            <p><b>Điều 4: Quyền và nghĩa vụ của người sử dụng lao động</b></p>
            <p><b>1. Quyền của người sử dụng lao động</b></p>
            <p class="text-justify">- Điều hành người lao động hoàn thành công việc theo Hợp đồng (bố trí, điều chuyển công việc cho người lao động theo đúng chức năng chuyên môn). </p>

        </div>

        <!-- ===== TRANG 3 ===== -->
        <div class="page">
            <p class="text-justify">- Có quyền tạm thời chuyển người lao động sang làm công việc khác, ngừng việc và áp dụng các biện pháp kỷ luật theo quy định của pháp luật hiện hành và theo nội quy Công ty trong thời gian hợp đồng còn giá trị.</p>
            <p class="text-justify">- Tạm hoãn, chấm dứt hợp đồng, kỷ luật người lao động theo đúng quy định của pháp luật và nội quy công ty. </p>
            <p class="text-justify">- Có quyền đòi bồi thường, khiếu nại với cơ quan liên đới để bảo vệ quyền lợi của mình nếu người lao động vi phạm pháp luật hay các điều khoản của hợp đồng này.</p>

            <p><b>2. Nghĩa vụ của người sử dụng lao động</b></p>
            <p class="text-justify">- Thực hiện đầy đủ những điều kiện cần thiết đã cam kết trong hợp đồng lao động để người lao động đạt hiệu quả công việc cao. Bảo đảm việc làm cho người lao động theo Hợp đồng đã ký.</p>
            <p class="text-justify">- Thanh toán đầy đủ, đúng thời hạn các chế độ và quyền lợi cho người lao động.</p>

            <p><b>3. Bí mật</b></p>
            <p class="text-justify">Trường hợp nghỉ việc tại Công ty, Bên B tuyệt đối không được thực hiện các công việc sau:</p>
            <p class="text-justify">- Mang theo các chi tiết về bí mật công việc, bí mật thương mại hay những thông tin mật khác có được trong quá trình làm việc cho Công ty.</p>
            <p class="text-justify">- Nhận và yêu cầu thực hiện công việc cho những người mà Bên B biết rằng họ là những khách hàng của Công ty trong suốt thời gian Bên B làm việc cho Công ty. </p>
            <p class="text-justify">- Theo Hợp đồng này, “Bí Mật Thông Tin” nghĩa là bất cứ thành phần nào hay toàn bộ thông tin mà trước đó Bên B hoặc Công ty chưa công khai ra ngoài hay một phần của thông tin trực tiếp hay gián tiếp có liên hệ đến Công ty.</p>
            <p class="text-justify">- Tất cả những bí mật thương mại, hay những thông tin khác về chiến lược, kế hoạch, công nghệ, kinh tế, tài chính, tiếp thị, tố tụng của công ty mà những đối thủ cạnh tranh hay các cá nhân, tổ chức khác có thể sử dụng để trục lợi hoặc làm phương hại đến uy tín hoạt động của Công ty.</p>
            <p class="text-justify">- Bên B không được tiết lộ cho bất cứ ai các bí mật thông tin liên quan đến Công ty, hay liên quan đến các chương trình hợp tác của Công ty với các cá nhân, tổ chức khác mà không có sự đồng ý bằng văn bản trước của Công ty.</p>
            <p class="text-justify">- Ngoài ra, bất kỳ thông tin nào liên quan đến công việc kinh doanh, những người đứng đầu hay khách hàng của Công ty đều được xem là bí mật, thì Bên B phải bảo mật trong suốt thời gian làm việc cho Công ty và ít nhất là năm (5) năm sau khi chấm dứt làm việc cho Công ty. Nếu vi phạm một trong các điều khoản trên, Bên B phải bồi thường cho Công ty số tiền là 500.000.000 VNĐ (Năm trăm triệu đồng chẵn).</p>
        </div>
        <!-- ===== TRANG 4 ===== -->
        <div class="page">




            <p><b>Điều 6: Thỏa thuận khác</b></p>
            <p class="text-justify">Trong quá trình thực hiện hợp đồng nếu một bên có nhu cầu thay đổi nội dung trong hợp đồng phải báo cho bên kia trước ít nhất 03 ngày và ký kết bản Phụ lục hợp đồng theo quy định của pháp luật. Trong thời gian tiến hành thỏa thuận hai bên vẫn tuân theo hợp đồng lao động đã ký kết.</p>
            <p class="text-justify">Người lao động đọc kỹ, hiểu rõ và cam kết thực hiện các điều khoản và quy định ghi tại Hợp đồng lao động.</p>
            <p><b>Điều 7: Điều khoản thi hành</b></p>
            <p class="text-justify">1. Hợp đồng Lao động này được làm tại Công ty TNHH Vua An Toàn, vào ngày <?php echo $data['sign_date']; ?></p>
            <p class="text-justify">2. Hợp đồng được lập thành hai (02) bản, có giá trị pháp lý ngang nhau và có hiệu lực từ ngày <?php echo $data['sign_date']; ?></p>

            <br><br>

            <div class="sign">
                <div class="center">
                    <b>Người Lao Động</b>
                    <p style="font-style: italic;">(Ký, ghi rõ họ tên)</p>
                </div>

                <div class="center">
                    <b>GIÁM ĐỐC</b>
                    <p style="font-style: italic;">(Ký, ghi rõ họ tên)</p>
                    <div style="margin-bottom: 80px;"></div>
                    <?php echo $c_edit('director_name', $cs['director_name'], true); ?>
                </div>
            </div>
        </div>

    </div>


    </div>


</body>
<script src="<?php echo asset_ver('public/js/contract.js'); ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    const employeeName = <?php echo json_encode($data['employee_name']); ?>;
</script>
</html>