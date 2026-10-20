<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/send_mail.php";
require_once __DIR__ . "/account_helper.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = "";

/* ================= SEND OTP ================= */

if (isset($_POST['send_otp'])) {

    // CSRF verification if submitted with token from protected browser form
    if (!empty($_POST['csrf_token'])) {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $msg = "❌ Invalid CSRF security token.";
        }
    }

    $email = trim($_POST['email'] ?? '');

    /* VALIDATE EMAIL */
    if (!$msg && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Invalid email address";
    } elseif (!$msg && (!isset($conn) || !$conn)) {
        $msg = "❌ Database service is temporarily unavailable. Please try again later.";
    } elseif (!$msg) {

        /* DYNAMIC ACCOUNT LOOKUP (STUDENT OR ADMIN) */
        $account = findAccountForPasswordReset($conn, $email);

        if ($account && !empty($account['email'])) {
            $registeredEmail = $account['email'];
            $lastSendKey = 'last_otp_time_' . md5($registeredEmail);

            if (isset($_SESSION[$lastSendKey]) && (time() - (int)$_SESSION[$lastSendKey]) < OTP_REQUEST_COOLDOWN_SECONDS) {
                $msg = "❌ Please wait before requesting another verification code.";
            } else {
                /* GENERATE CRYPTOGRAPHICALLY SECURE OTP */
                $otp = (string)random_int(100000, 999999);
                $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                /* SAVE OTP TO SPECIFIC ACCOUNT RECORD */
                saveAccountResetOtp($conn, $account, $otp, $expiry);

                /* SEND MAIL TO REGISTERED RECIPIENT */
                $roleLabel = ($account['role'] === 'admin') ? "Administrator Password Reset" : "Password Reset";
                $body = buildOtpEmailHtml($roleLabel, $otp, 10);
                $subject = "Password Reset OTP - Online Examination System";

                // Explicitly send to the verified registered account email (never default to admin)
                if (sendMail($registeredEmail, $subject, $body)) {
                    $_SESSION['reset_email'] = $registeredEmail;
                    $_SESSION['reset_account'] = $account;
                    $_SESSION['mail_sent_success'] = true;
                    $_SESSION['mail_flash_success'] = true;
                    $_SESSION[$lastSendKey] = time();
                    $_SESSION['otp_attempts_' . md5(strtolower($registeredEmail))] = 0; // Reset verification attempt counter

                    if (!headers_sent()) {
                        header("Location: verify_reset_otp.php");
                    }
                    exit;
                } else {
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_account']);
                    unset($_SESSION['mail_sent_mode']);
                    unset($_SESSION['mail_sent_success']);
                    unset($_SESSION['mail_flash_success']);
                    $err = getUserFacingMailError() ?: "Email service is temporarily unavailable. Please try again later.";
                    $msg = "❌ " . $err;
                }
            }
        } else {
            // Safe response for unknown email address (OWASP User Enumeration Defense)
            $msg = "ℹ️ If that email address is registered, a password reset verification code has been sent.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Forgot Password</title>

<link rel="stylesheet" href="../assets/css/style.css">

<style>

/* CENTER LARGE SCREEN */

.login-box{
    width:500px;
}

</style>

</head>

<body>

<div class="center-screen">

<div class="login-box">

<h2>Forgot Password</h2>

<?php if($msg): ?>
<p style="color:<?= (strpos($msg, '❌') !== false) ? 'red' : ((strpos($msg, 'ℹ️') !== false) ? '#0d6efd' : 'green') ?>;font-weight:bold;text-align:center">
<?= htmlspecialchars($msg) ?>
</p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<input
type="email"
name="email"
placeholder="Enter registered email"
required
>

<button name="send_otp">
Send OTP
</button>

</form>

<p style="text-align:center;margin-top:15px">

<a href="login.php" style="color:#0d6efd">
Back to Login
</a>

</p>

</div>

</div>

</body>
</html>
