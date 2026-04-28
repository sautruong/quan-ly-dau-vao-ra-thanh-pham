<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = "C:/Users/Admin/Desktop/employee_basic_info.xlsx";

if (!file_exists($file)) {
    die("File không tồn tại!");
}

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

echo "<pre>";
print_r($rows);
echo "</pre>";