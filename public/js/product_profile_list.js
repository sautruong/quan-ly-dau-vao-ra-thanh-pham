$('#btn_choose').click(function () {
    
    $('#file_upload').click();
});

$('#file_upload').change(function () {
    let file = this.files[0];
    if (file) {
        $('#file_name').val(file.name);
    }
});