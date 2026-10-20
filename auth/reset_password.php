<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/account_helper.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = "";

if (!isset($_SESSION['reset_email'])) {
    if (!headers_sent()) {
        header("Location: forgot_password.php");
    }
    exit;
}

$email = $_SESSION['reset_email'];
$attemptKey = 'otp_attempts_' . md5($email);
if (!isset($_SESSION[$attemptKey])) {
    $_SESSION[$attemptKey] = 0;
}

if (isset($_POST['reset'])) {

    // CSRF verification if submitted with token
    if (!empty($_POST['csrf_token'])) {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $msg = "Invalid CSRF security token.";
        }
    }

    $otp = trim($_POST['otp'] ?? '');
    $new = $_POST['password'] ?? '';

    if ($msg) {
        // CSRF validation error
    } elseif (!isset($conn) || !$conn) {
        $msg = "Database service is temporarily unavailable. Please try again later.";
    } elseif ((int)$_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
        $msg = "Too many failed verification attempts. Please request a new OTP.";
    } elseif (strlen($new) < 8 || strlen($new) > 15) {
        $msg = "Password must be 8 to 15 characters";
    } else {
        try {
            $account = isset($_SESSION['reset_account']) ? $_SESSION['reset_account'] : findAccountForPasswordReset($conn, $email);

            $res = null;
            if ($account) {
                $res = getAccountResetOtp($conn, $account);
            } else {
                $stmt = $conn->prepare("SELECT reset_otp, otp_expiry FROM users WHERE email=?");
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                }
            }

            if (!$res || empty($res['reset_otp'])) {
                $msg = "Verification code has expired. Please request a new one.";
            } elseif ($res['reset_otp'] !== $otp) {
                $_SESSION[$attemptKey] = (int)$_SESSION[$attemptKey] + 1;
                if ($_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
                    if ($account) {
                        clearAccountResetOtp($conn, $account);
                    } else {
                        $clearStmt = $conn->prepare("UPDATE users SET reset_otp=NULL, otp_expiry=NULL WHERE email=?");
                        if ($clearStmt) {
                            $clearStmt->bind_param("s", $email);
                            $clearStmt->execute();
                        }
                    }
                    $msg = "Too many failed verification attempts. Please request a new OTP.";
                } else {
                    $msg = "Invalid verification code.";
                }
            } elseif (strtotime($res['otp_expiry']) < time()) {
                $msg = "Verification code has expired. Please request a new one.";
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);

                $updated = false;
                if ($account) {
                    $updated = resetAccountPassword($conn, $account, $hash);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET password=?, reset_otp=NULL, otp_expiry=NULL WHERE email=?");
                    if ($stmt) {
                        $stmt->bind_param("ss", $hash, $email);
                        $updated = $stmt->execute();
                    }
                }

                if (!$updated) {
                    throw new Exception("Failed to execute password update statement");
                }

                unset($_SESSION[$attemptKey]);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_account']);
                unset($_SESSION['mail_sent_mode']);
                unset($_SESSION['mail_sent_success']);
                unset($_SESSION['mail_flash_success']);
                if (!headers_sent()) {
                    header("Location: login.php");
                }
                exit;
            }
        } catch (Throwable $e) {
            error_log("[SECURITY] reset_password error: " . $e->getMessage());
            $msg = "An error occurred while processing your request. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reset Password</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="center-screen">
<div class="login-box">
<h2>Reset Password</h2>

<p style="color:red;font-weight:bold"><?= htmlspecialchars($msg) ?></p>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<input type="text" name="otp" placeholder="Enter OTP" required>
<input type="password" name="password" placeholder="New Password" required>
<button name="reset">Reset Password</button>
</form>
</div>
</div>

</body>
</html>
