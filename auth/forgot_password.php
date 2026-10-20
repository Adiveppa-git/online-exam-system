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

        if ($account && !empty($account['ambiguous'])) {
            $msg = "❌ " . ($account['error'] ?? "Multiple conflicting accounts found. Please contact administrator.");
        } elseif ($account && !empty($account['email'])) {
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
/* Responsive layout & center container */
.center-screen {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 20px 15px;
    box-sizing: border-box;
    background: #f4f6f9;
}

.login-box {
    width: 100%;
    max-width: 480px;
    background: #ffffff;
    padding: 35px 30px;
    border-radius: 8px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
    box-sizing: border-box;
}

.login-box input[type="email"] {
    width: 100%;
    height: 46px;
    padding: 11px 14px;
    margin-top: 6px;
    margin-bottom: 16px;
    font-size: 15px;
    font-family: inherit;
    color: #212529;
    background-color: #ffffff;
    border: 1px solid #ced4da;
    border-radius: 6px;
    box-sizing: border-box;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.login-box input[type="email"]:focus {
    outline: none !important;
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.login-box button[name="send_otp"] {
    width: 100%;
    height: 46px;
    background-color: #0d6efd;
    color: #ffffff;
    border: 1px solid #0d6efd;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 6px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.login-box button[name="send_otp"]:hover {
    background-color: #0b5ed7;
    border-color: #0a58ca;
}

.login-box button[name="send_otp"]:focus {
    outline: none !important;
    background-color: #0b5ed7;
    border-color: #0a58ca;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.35);
}

@media (max-width: 480px) {
    .login-box {
        padding: 26px 18px;
    }
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
