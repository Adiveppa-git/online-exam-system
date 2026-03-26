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

        // 🔐 CHANGE THIS AFTER TEST (new app password)
        $mail->Username   = 'mailproject112@gmail.com';
        $mail->Password   = 'sqnznhrsphzjllcf';

        // 🔥 FIX FOR RENDER (IMPORTANT)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // 🔥 EXTRA FIX (cloud compatibility)
        $mail->Timeout = 15;
        $mail->SMTPDebug = 0;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

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
        if ($mail->send()) {
            return true;
        } else {
            return false;
        }

    } catch (Exception $e) {
        // 🔥 TEMP DEBUG (REMOVE AFTER SUCCESS)
        error_log("Mailer Error: " . $mail->ErrorInfo);

        return false;
    }
}