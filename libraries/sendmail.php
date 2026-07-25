<?php
//Import PHPMailer classes into the global namespace
//These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// PHPMailer được cài qua composer (phpmailer/phpmailer) — nạp bằng autoload.
require_once __DIR__ . '/../vendor/autoload.php';
function send_mail($sent_to_mail, $sent_to_fullname, $subject, $content, $option = array()) {
    global $config;
    $config_email = $config['email'];
    $mail = new PHPMailer(true);
    try {
        //Server settings - Cấu hình server từ gmail
        $mail->SMTPDebug = 0;                      //Enable verbose debug output
        $mail->isSMTP();                                            //Send using SMTP
        $mail->Host = $config_email['smtp_host'];                     //Set the SMTP server to send through
        $mail->SMTPAuth = true;                                   //Enable SMTP authentication
        $mail->Username = $config_email['smtp_user'];                     //SMTP username
        $mail->Password = $config_email['smtp_pass'];                               //SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;          //Enable implicit TLS encryption
        //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`
        $mail->Port = $config_email['smtp_port']; 
        $mail->CharSet = 'UTF-8';  //để có tiếng việt
        //Recipients - Thiết lập người nhận
        $mail->setFrom($config_email['smtp_user'], $config_email['smtp_fullname']); //Gửi từ đâu
        $mail->addAddress($sent_to_mail, $sent_to_fullname);     //người nhận
//    $mail->addAddress('ellen@example.com');               //gửi thêm người khác
        //Gửi gửi phản hồi lại qua đâu
        $mail->addReplyTo($config_email['smtp_user'], $config_email['smtp_fullname']);
//    $mail->addCC('cc@example.com'); //Thêm người nhận email này
//    $mail->addBCC('bcc@example.com');
//Attachments - đính kèm file
//    $mail->addAttachment($content);  
//    $mail -> Body = $content;       //Add attachments
//    $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name
        //Content - Nội dung
        $mail->isHTML(true);                                  //Set email format to HTML
        $mail->Subject = $subject; //Tiêu đề
        $mail->Body = "Thông tin được gửi từ <b>{$config_email['smtp_fullname']}</b>"
                . " <div>{$content}</div>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email không được gửi: ' . $mail->ErrorInfo);
        return false;
    }
}
