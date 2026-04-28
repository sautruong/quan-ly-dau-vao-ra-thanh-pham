$('.filter').on('change', function () {
    //Lấy value của select-branch và value của select-info-hr
    let branch = $('.select-branch').val();
    let table = $('.select-info-hr').val();
    //alert(table);
    //Gửi qua ajax filter
    var data = { branch: branch, table: table }; //(1)CT JSON: bên trái là key, bên phải là value
    //console.log(data); //xuất dữ liệu trên trình duyệt
    $.ajax({
        url: '?mod=hr&controllers=hr&action=process_list_hr',
        method: 'POST',
        data: data, //Đã xử lý ở (1)
        dataType: 'html',
        success: function (res) {
            //                alert(data);
            //                console.log(data);
            //Xử lý dữ liệu trả về
            $("#container-table").html(res);
            //$("#total").html("<strong>" + data + "</strong>");
        },
        error: function (xhr, ajaxOptions, thrownError) {
            alert(xhr.status);
            alert(thrownError);
        }
    });
});



