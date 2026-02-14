<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ===============================
   LOAD PHPMailer FILES
   =============================== */
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

/* ===============================
   SEND MAIL FUNCTION
   =============================== */
function sendMail($to, $subject, $body)
{
    $mail = new PHPMailer(true);

    try {
        /* ===== SMTP CONFIG ===== */
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        // ✅ YOUR REAL GMAIL
        $mail->Username   = 'mailproject112@gmail.com';

        // ✅ GMAIL APP PASSWORD (NOT NORMAL PASSWORD)
        $mail->Password   = 'sqnznhrsphzjllcf';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        /* ===== SENDER ===== */
        $mail->setFrom(
            'mailproject112@gmail.com',
            'Online Examination System'
        );

        /* ===== RECEIVER ===== */
        $mail->addAddress($to);

        /* ===== EMAIL CONTENT ===== */
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        /* ===== SEND ===== */
        $mail->send();
        return true;

    } catch (Exception $e) {
        // ❌ Log error silently (no UI break)
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}
