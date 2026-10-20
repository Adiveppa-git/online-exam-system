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

/* ===== AUTH CHECK ===== */
if (!isset($_SESSION['reset_email'])) {
    if (!headers_sent()) {
        header("Location: forgot_password.php");
    }
    exit;
}

$email = $_SESSION['reset_email'];
$attemptKey = 'otp_attempts_' . md5(strtolower($email));
if (!isset($_SESSION[$attemptKey])) {
    $_SESSION[$attemptKey] = 0;
}
$msg = "";
$successNotice = "";

/* ===== DB CONNECTION CHECK ===== */
if (!isset($conn) || !$conn) {
    $msg = "Database service is temporarily unavailable. Please try again later.";
}

/* ===== RESEND / REQUEST NEW OTP ===== */
if (isset($_POST['resend_otp'])) {
    // CSRF verification
    if (!empty($_POST['csrf_token'])) {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $msg = "Invalid CSRF security token.";
        }
    }

    if (!$msg && (!isset($conn) || !$conn)) {
        $msg = "Database service is temporarily unavailable. Please try again later.";
    } elseif (!$msg) {
        $lastSendKey = 'last_otp_time_' . md5(strtolower($email));
        if (isset($_SESSION[$lastSendKey]) && (time() - (int)$_SESSION[$lastSendKey]) < OTP_REQUEST_COOLDOWN_SECONDS) {
            $wait = OTP_REQUEST_COOLDOWN_SECONDS - (time() - (int)$_SESSION[$lastSendKey]);
            $msg = "Please wait {$wait}s before requesting another verification code.";
        } else {
            $account = isset($_SESSION['reset_account']) ? $_SESSION['reset_account'] : findAccountForPasswordReset($conn, $email);
            if ($account && !empty($account['ambiguous'])) {
                $msg = "❌ " . ($account['error'] ?? "Account conflict detected. Please contact administrator.");
            } elseif ($account && !empty($account['email'])) {
                $newOtp = (string)random_int(100000, 999999);
                $newExpiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                if (saveAccountResetOtp($conn, $account, $newOtp, $newExpiry)) {
                    $roleLabel = ($account['role'] === 'admin') ? "Administrator Password Reset" : "Password Reset";
                    $body = buildOtpEmailHtml($roleLabel, $newOtp, 10);
                    $subject = "Password Reset OTP - Online Examination System";

                    if (sendMail($account['email'], $subject, $body)) {
                        $_SESSION[$lastSendKey] = time();
                        $_SESSION[$attemptKey] = 0; // reset attempt counter on new code
                        $_SESSION['mail_sent_mode'] = 'real';
                        $_SESSION['mail_sent_success'] = true;
                        $_SESSION['mail_flash_success'] = true;
                        $successNotice = "A new verification code has been sent to your registered email address.";
                    } else {
                        unset($_SESSION['mail_sent_mode']);
                        unset($_SESSION['mail_sent_success']);
                        unset($_SESSION['mail_flash_success']);
                        $err = getUserFacingMailError() ?: "Email service is temporarily unavailable. Please try again later.";
                        $msg = "❌ " . $err;
                    }
                } else {
                    $msg = "Database service is temporarily unavailable. Please try again later.";
                }
            } else {
                $msg = "Account details could not be found. Please restart from forgot password.";
            }
        }
    }
}

/* ===== VERIFY OTP ===== */
if (isset($_POST['verify'])) {

    // CSRF verification if submitted with token from protected browser form
    if (!empty($_POST['csrf_token'])) {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $msg = "Invalid CSRF security token.";
        }
    }

    $otp     = trim($_POST['otp'] ?? '');
    $newPass = $_POST['password'] ?? '';

    if ($msg) {
        // CSRF validation error already captured
    } elseif (!isset($conn) || !$conn) {
        $msg = "Database service is temporarily unavailable. Please try again later.";
    } elseif ((int)$_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
        $msg = "Too many failed verification attempts. Please request a new OTP.";
    } elseif (strlen($newPass) < 8 || strlen($newPass) > 15) {
        $msg = "Password must be 8 to 15 characters";
    } else {
        try {
            $account = isset($_SESSION['reset_account']) ? $_SESSION['reset_account'] : findAccountForPasswordReset($conn, $email);

            $res = null;
            if ($account) {
                $res = getAccountResetOtp($conn, $account);
            } else {
                $stmt = $conn->prepare("
                    SELECT reset_otp, otp_expiry
                    FROM users
                    WHERE email=?
                ");
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement");
                }
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
            }

            if (!$res || empty($res['reset_otp'])) {
                $msg = "Verification code has expired. Please request a new one.";
            } elseif ($res['reset_otp'] !== $otp) {
                $_SESSION[$attemptKey] = (int)$_SESSION[$attemptKey] + 1;
                if ($_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
                    // Invalidate current OTP upon reaching attempt limit (lockout)
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

                $hashed = password_hash($newPass, PASSWORD_DEFAULT);

                $updated = false;
                if ($account) {
                    $updated = resetAccountPassword($conn, $account, $hashed);
                } else {
                    $update = $conn->prepare("
                        UPDATE users
                        SET password=?, reset_otp=NULL, otp_expiry=NULL
                        WHERE email=?
                    ");
                    if (!$update) {
                        throw new Exception("Failed to prepare update statement");
                    }
                    $update->bind_param("ss", $hashed, $email);
                    $updated = $update->execute();
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
            error_log("[SECURITY] verify_reset_otp error: " . $e->getMessage());
            $msg = "An error occurred while processing your request. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify OTP & Reset Password</title>

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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

/* Card styling */
.login-box {
    width: 100%;
    max-width: 480px;
    background: #ffffff;
    padding: 35px 30px;
    border-radius: 8px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
    box-sizing: border-box;
}

.login-box h2 {
    margin: 0 0 20px 0;
    text-align: center;
    font-size: 24px;
    color: #212529;
}

/* Unified input styles: height, padding, border, radius, font size, spacing */
.login-box input[type="text"],
.login-box input[type="password"] {
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

/* Accessible focus outline: subtle blue ring, eliminates thick black borders */
.login-box input[type="text"]:focus,
.login-box input[type="password"]:focus,
.password-box input:focus {
    outline: none !important;
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

/* Password wrapper & precise icon vertical alignment */
.password-box {
    position: relative;
    width: 100%;
    margin-top: 6px;
    margin-bottom: 16px;
}

.password-box input {
    width: 100%;
    height: 46px;
    padding: 11px 45px 11px 14px; /* reserve space for eye toggle */
    margin: 0;
    box-sizing: border-box;
}

.password-box .toggle-eye {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #6c757d;
    font-size: 16px;
    user-select: none;
    z-index: 2;
    transition: color 0.15s ease;
}

.password-box .toggle-eye:hover {
    color: #0d6efd;
}

/* Password Strength Indicator */
.strength {
    font-size: 13px;
    margin-top: -8px;
    margin-bottom: 14px;
    font-weight: 500;
}
.strength.weak { color: #dc3545; }
.strength.medium { color: #fd7e14; }
.strength.strong { color: #198754; }

/* Full-width Reset Password button matching login page */
.login-box button[name="verify"],
.btn-submit {
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

.login-box button[name="verify"]:hover,
.btn-submit:hover {
    background-color: #0b5ed7;
    border-color: #0a58ca;
}

.login-box button[name="verify"]:focus,
.btn-submit:focus {
    outline: none !important;
    background-color: #0b5ed7;
    border-color: #0a58ca;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.35);
}

/* Consistent navigation links */
.auth-nav-links {
    text-align: center;
    margin-top: 22px;
    font-size: 14px;
    color: #6c757d;
}

.auth-nav-links a,
.auth-nav-links .link-button {
    color: #0d6efd;
    text-decoration: underline;
    font-weight: 500;
    font-size: 14px;
    font-family: inherit;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    display: inline;
    transition: color 0.15s ease;
}

.auth-nav-links a:hover,
.auth-nav-links .link-button:hover {
    color: #0a58ca;
}

.auth-nav-links a:focus,
.auth-nav-links .link-button:focus {
    outline: 2px solid rgba(13, 110, 253, 0.4);
    outline-offset: 2px;
}

.auth-nav-links .divider {
    margin: 0 8px;
    color: #adb5bd;
}

/* Mobile responsiveness */
@media (max-width: 480px) {
    .login-box {
        padding: 26px 18px;
    }
    .login-box h2 {
        font-size: 21px;
    }
}
</style>
</head>
<body>

<div class="center-screen">
<div class="login-box">

<h2>Verify OTP & Reset Password</h2>

<?php if ($msg): ?>
<p style="color:red;font-weight:bold;text-align:center;font-size:14px;margin-bottom:12px">
    <?= htmlspecialchars($msg) ?>
</p>
<?php endif; ?>

<?php if ($successNotice): ?>
<p style="font-size:13px;color:green;font-weight:bold;text-align:center;margin-bottom:12px">
    <i class="fa-solid fa-paper-plane"></i> <?= htmlspecialchars($successNotice) ?>
</p>
<?php elseif (!empty($_SESSION['mail_sent_success']) && isset($_SESSION['mail_sent_mode']) && $_SESSION['mail_sent_mode'] === 'real'): ?>
<p style="font-size:13px;color:green;text-align:center;margin-bottom:12px">
    <i class="fa-solid fa-paper-plane"></i> OTP sent successfully to your email address.
</p>
<?php elseif (!empty($_SESSION['mail_sent_success']) && isset($_SESSION['mail_sent_mode']) && $_SESSION['mail_sent_mode'] === 'log'): ?>
<p style="font-size:12px;color:#666;text-align:center;margin-bottom:12px">
    <i class="fa-solid fa-bug"></i> <strong>Dev Mode Notice:</strong> OTP has been logged to <code>logs/mail.log</code>
</p>
<?php endif; ?>

<form method="post" id="verifyForm">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<input type="text"
       name="otp"
       id="otp"
       placeholder="Enter 6-digit OTP"
       maxlength="6"
       pattern="[0-9]{6}"
       inputmode="numeric"
       autocomplete="one-time-code"
       required>

<!-- PASSWORD FIELD WITH PROPERLY ALIGNED FONT AWESOME ICON -->
<div class="password-box">
    <input type="password"
           id="password"
           name="password"
           placeholder="New Password (8-15 chars)"
           onkeyup="checkStrength()"
           required>

    <i class="fa-solid fa-eye-slash toggle-eye"
       id="toggleEye"
       onclick="togglePassword('password', this)"
       title="Toggle password visibility">
    </i>
</div>

<div id="strengthMsg" class="strength"></div>

<button type="submit" name="verify" id="btnVerify">
    Reset Password
</button>
</form>

<div class="auth-nav-links">
    <form method="post" style="display:inline;margin:0;padding:0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" name="resend_otp" class="link-button" id="btnResendOtp">Request new OTP</button>
    </form>
    <span class="divider">&bull;</span>
    <a href="login.php" class="nav-link">Back to Login</a>
</div>

</div>
</div>

<script>
/* UNIVERSAL TOGGLE SCRIPT */
function togglePassword(id, icon) {
    const input = document.getElementById(id);
    if (!input) return;

    if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    } else {
        input.type = "password";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }
}

/* PASSWORD STRENGTH */
function checkStrength() {
    const pass = document.getElementById("password").value;
    const msg  = document.getElementById("strengthMsg");

    if (pass.length === 0) {
        msg.textContent = "";
        msg.className = "strength";
    } else if (pass.length < 8) {
        msg.textContent = "Weak (min 8 characters)";
        msg.className = "strength weak";
    } else if (pass.length <= 10) {
        msg.textContent = "Medium";
        msg.className = "strength medium";
    } else if (pass.length <= 15) {
        msg.textContent = "Strong";
        msg.className = "strength strong";
    } else {
        msg.textContent = "Max 15 characters only";
        msg.className = "strength weak";
    }
}
</script>

</body>
</html>
