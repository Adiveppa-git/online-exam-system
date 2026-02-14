<?php
session_start();
require_once "../config/db.php";
require_once "send_mail.php";

$msg = "";

/* ================= SEND OTP ================= */

if (isset($_POST['send_otp'])) {

    $email = trim($_POST['email']);

    /* VALIDATE EMAIL */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $msg = "❌ Invalid email address";

    } else {

        /* CHECK EMAIL EXISTS */
        $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {

            /* GENERATE OTP */
            $otp = rand(100000, 999999);
            $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            /* SAVE OTP */
            $stmt = $conn->prepare(
                "UPDATE users SET reset_otp=?, otp_expiry=? WHERE email=?"
            );

            $stmt->bind_param("sss", $otp, $expiry, $email);
            $stmt->execute();

            /* SEND MAIL */
            $body = "
                <h2>Password Reset</h2>
                <p>Your OTP is:</p>
                <h1>$otp</h1>
                <p>This OTP is valid for 10 minutes.</p>
            ";

            if (sendMail($email, "Password Reset OTP", $body)) {

                $_SESSION['reset_email'] = $email;

                header("Location: verify_reset_otp.php");
                exit;

            } else {

                $msg = "❌ Failed to send OTP. Try again.";

            }

        } else {

            $msg = "❌ Email not registered";

        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>

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
<p style="color:red;font-weight:bold">
<?= $msg ?>
</p>
<?php endif; ?>

<form method="post">

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
