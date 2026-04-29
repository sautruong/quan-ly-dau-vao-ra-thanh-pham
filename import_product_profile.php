<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function get_data_from_excel($name_file_excel, $table)
{
    $conn = new mysqli("localhost", "root", "", "nmsx_Vat");
    $conn->set_charset("utf8mb4");

    if ($conn->connect_error) die("Lỗi kết nối: " . $conn->connect_error);

    $filePath = "C:/Users/Admin/Desktop/" . $name_file_excel;
    if (!file_exists($filePath)) die("File Excel không tồn tại: $filePath");

    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true); // Trả kq là mảng từng dòng 1,2,3 mỗi dòng có key là cột A,B,C,D...
    // echo "<pre>";
    // print_r($rows);
    // echo "</pre>";
    // >> OK: có 1 cột trống phía sau

    foreach ($rows as $rIndex => &$row) {
        foreach ($row as $cIndex => $value) {
            $row[$cIndex] = $value ?? ''; // nếu null → ''
        }
    }
    // echo "<pre>";
    // print_r($rows);
    // echo "</pre>";

    // >> OK: loại bỏ được cột trống phí sâu
    // Thêm 'id' vào đầu mảng
    unset($row);

    $header = array_filter($rows[1], fn($v) => $v !== null && $v !== '');
    $header = array_values($header); // reset key

    //array_unshift($header, 'id');

    $str_header = '(' . implode(', ', $header) . ')';
    //KQ:(employee_id, ethnicity, nationality, place_of_birth)

    // Lấy header + loại bỏ cột trống
    $header = array_map('trim', $rows[1]);
    //array_unshift($header, 'id');

    // echo "<pre>";
    // print_r($str_header);
    // echo "</pre>";

    foreach ($rows as $index => $row) {
        if ($index == 1) continue; // bỏ dòng tiêu đề
        // $row = array_slice($row, 0, count($header) - 1);
        // // Thêm NULL cho id
        // array_unshift($row, 'NULL');
        // Tạo chuỗi row_index để xíu ghép vào chuỗi sql
        $row_index = '(' . implode(', ', array_map(
            function ($v) use ($conn) {
                if (strtoupper($v) === 'NULL') return 'NULL';
                if (is_numeric($v)) return $v;
                return "'" . mysqli_real_escape_string($conn, $v) . "'";
            },
            $row
        )) . ')';
        // $id = $row[1];


        //         $check = mysqli_query(
        //             $conn,
        //             "
        //     SELECT id FROM employees WHERE id = $id
        // "
        //         );
        //         if (mysqli_num_rows($check) == 0) {
        //             echo "❌ Không tồn tại id = $id<br>";
        //             continue;
        //         }
        //-----------------------------------------
        $sql = "INSERT INTO `$table` $str_header
                VALUES $row_index";
        //-----------------------------------------
        if (mysqli_query($conn, $sql)) {
            echo "Dữ liệu đã được chèn thành công!";
        } else {
            echo "Lỗi SQL: " . mysqli_error($conn);
        }
    }
    // echo $sql;
    // echo "<br>";
    // echo $table;
    // echo "<br>";
    // echo $str_header;
    // echo "<br>";
    // echo $row_index;

    $conn->close();
}
// gọi hàm
get_data_from_excel("product_prices.xlsx", "product_prices");
