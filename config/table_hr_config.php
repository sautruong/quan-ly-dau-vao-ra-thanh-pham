<?php
$table_hr_config = [
    'employees' => [
        'labels' => [
            'id' => 'Mã',
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'gender' => 'Giới tính',
            'date_of_birth' => 'Ngày sinh',
            'phone_number' => 'Số điện thoại',
            'email' => 'email'
        ],
        'hidden' => [
            'form' => ['id'],
            'list' => []
        ]
    ],
    'employee_basic_info' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'ethnicity' => 'Dân tộc',
            'nationality' => 'Quốc gia',
            'place_of_birth' => 'Nơi sinh'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_work_info' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'hire_date' => 'Ngày vào làm',
            'employment_status' => 'Trạng thái làm việc',
            'region' => 'Miền',
            'branch' => 'Chi nhánh',
            'work_address' => 'Địa chỉ làm việc'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_identity' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'citizen_id' => 'Căn cước công dân',
            'id_issue_date' => 'Ngày cấp',
            'id_issue_place' => 'Nơi cấp',
            'permanent_address' => 'Địa chỉ thường trú',
            'temporary_address' => 'Địa chỉ tạm trú'

        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_bank' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'bank_account_number' => 'Số tài khoản ngân hàng',
            'bank_name' => 'Tên ngân hàng',
            'bank_branch' => 'Chi nhánh ngân hàng'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_education' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'education_level' => 'Trình độ',
            'education_type' => 'Hệ đào tạo',
            'university' => 'Trường đào tạo',
            'major' => 'Chuyên ngành',
            'graduation_year' => 'Năm tốt nghiệp'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_insurance' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'social_insurance_number' => 'Mã số BHXK',
            'social_insurance_status' => 'Trạng thái đóng BHXH',
            'social_insurance_start_date' => 'Thời điểm đóng BHXH',
            'social_insurance_salary' => 'Mức lương BHXH'

        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_contract' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'contract_type' => 'Loại hợp đồng',
            'contract_sign_date' => 'Ngày ký HĐ',
            'contract_start_date' => 'Ngày hiệu lực HĐ',
            'contract_end_date' => 'Ngày hết hạn HĐ'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_organization' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'division' => 'Khối',
            'department' => 'Phòng ban',
            'job_title' => 'Chức danh',
            'position' => 'Vị trí',
            'role' => 'Vai trò',
            'employee_type' => 'Loại nhân sự'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_management' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'manager_level_1' => 'Quản lý cấp 1',
            'manager_level_2' => 'Quản lý cấp 2',
            'manager_level_3' => 'Quản lý cấp 3'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],
    'employee_documents' => [
        'labels' => [
            'employee_code' => 'Mã nhân viên',
            'full_name' => 'Họ và tên',
            'file_path' => 'Đường dẫn file',
            'file_type' => 'Loại file',
            'upload_date' => 'Ngày tải lên'
        ],
        'hidden' => [
            'form' => ['employee_code', 'full_name'],
            'list' => []
        ]
    ],

];
