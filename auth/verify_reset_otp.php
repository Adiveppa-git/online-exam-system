<?php
session_start();
require_once "send_mail.php";

/* ===== AUTH CHECK ===== */
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];
$attemptKey = 'otp_attempts_' . md5($email);
if (!isset($_SESSION[$attemptKey])) {
    $_SESSION[$attemptKey] = 0;
}
$msg = "";

/* ===== VERIFY OTP ===== */
if (isset($_POST['verify'])) {

    $otp     = trim($_POST['otp']);
    $newPass = $_POST['password'];

    if ((int)$_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
        $msg = "Too many failed verification attempts. Please request a new OTP.";
    } elseif (strlen($newPass) < 8 || strlen($newPass) > 15) {
        $msg = "Password must be 8 to 15 characters";
    } else {

        $stmt = $conn->prepare("
            SELECT reset_otp, otp_expiry 
            FROM users 
            WHERE email=?
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if (!$res || empty($res['reset_otp'])) {
            $msg = "Verification code has expired. Please request a new one.";
        } elseif ($res['reset_otp'] !== $otp) {
            $_SESSION[$attemptKey] = (int)$_SESSION[$attemptKey] + 1;
            if ($_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS) {
                // Invalidate current OTP upon reaching attempt limit
                $clearStmt = $conn->prepare("UPDATE users SET reset_otp=NULL, otp_expiry=NULL WHERE email=?");
                $clearStmt->bind_param("s", $email);
                $clearStmt->execute();
                $msg = "Too many failed verification attempts. Please request a new OTP.";
            } else {
                $msg = "Invalid verification code.";
            }
        } elseif (strtotime($res['otp_expiry']) < time()) {
            $msg = "Verification code has expired. Please request a new one.";
        } else {

            $hashed = password_hash($newPass, PASSWORD_DEFAULT);

            $update = $conn->prepare("
                UPDATE users 
                SET password=?, reset_otp=NULL, otp_expiry=NULL 
                WHERE email=?
            ");
            $update->bind_param("ss", $hashed, $email);
            $update->execute();

            unset($_SESSION[$attemptKey]);
            unset($_SESSION['reset_email']);
            header("Location: login.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Verify OTP</title>

<link rel="stylesheet" href="../assets/css/style.css">

<!-- FONT AWESOME -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

.strength{
    font-size:14px;
    margin-top:6px;
    font-weight:bold;
}

.weak{color:red;}
.medium{color:orange;}
strong{color:green;}

</style>

</head>

<body>

<div class="center-screen">

<div class="login-box">

<h2>Verify OTP & Reset Password</h2>

<?php if ($msg): ?>
<p style="color:red;font-weight:bold"><?= $msg ?></p>
<?php endif; ?>

<?php if (isset($_SESSION['mail_sent_mode']) && $_SESSION['mail_sent_mode'] === 'real'): ?>
<p style="font-size:13px;color:green;text-align:center;margin-bottom:12px">
    <i class="fa-solid fa-paper-plane"></i> OTP sent successfully to your email address.
</p>
<?php elseif (isset($_SESSION['mail_sent_mode']) && $_SESSION['mail_sent_mode'] === 'log'): ?>
<p style="font-size:12px;color:#666;text-align:center;margin-bottom:12px">
    <i class="fa-solid fa-bug"></i> <strong>Dev Mode Notice:</strong> OTP has been logged to <code>logs/mail.log</code>
</p>
<?php endif; ?>

<form method="post">

<input type="text"
name="otp"
placeholder="Enter OTP"
required>


<!-- PASSWORD FIELD WITH FONT AWESOME ICON -->

<div class="password-box">

<input type="password"
id="password"
name="password"
placeholder="New Password"
onkeyup="checkStrength()"
required>

<i class="fa-solid fa-eye-slash toggle-eye"
onclick="togglePassword('password', this)">
</i>

</div>


<div id="strengthMsg" class="strength"></div>


<button type="submit" name="verify">
Reset Password
</button>

</form>

</div>

</div>


<script>

/* UNIVERSAL TOGGLE SCRIPT */

function togglePassword(id, icon)
{
    const input = document.getElementById(id);

    if (input.type === "password")
    {
        input.type = "text";

        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
    else
    {
        input.type = "password";

        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }
}


/* PASSWORD STRENGTH */

function checkStrength()
{
    const pass = document.getElementById("password").value;
    const msg  = document.getElementById("strengthMsg");

    if (pass.length < 8)
    {
        msg.textContent = "Weak (min 8 characters)";
        msg.className = "strength weak";
    }
    else if (pass.length <= 10)
    {
        msg.textContent = "Medium";
        msg.className = "strength medium";
    }
    else if (pass.length <= 15)
    {
        msg.textContent = "Strong";
        msg.className = "strength strong";
    }
    else
    {
        msg.textContent = "Max 15 characters only";
        msg.className = "strength weak";
    }
}

</script>

</body>
</html>
