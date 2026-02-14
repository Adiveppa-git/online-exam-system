<?php
session_start();
require_once "../config/db.php";

$msg = "";

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

if (isset($_POST['reset'])) {

    if (time() - $_SESSION['otp_time'] > 600) {
        die("OTP expired");
    }

    if ($_POST['otp'] != $_SESSION['reset_otp']) {
        $msg = "Invalid OTP";
    } else {

        $new = $_POST['password'];
        $hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "UPDATE users SET password=? WHERE email=?"
        );
        $stmt->bind_param("ss", $hash, $_SESSION['reset_email']);
        $stmt->execute();

        session_destroy();
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Reset Password</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="center-screen">
<div class="login-box">
<h2>Reset Password</h2>

<p style="color:red"><?= $msg ?></p>

<form method="post">
<input type="text" name="otp" placeholder="Enter OTP" required>
<input type="password" name="password" placeholder="New Password" required>
<button name="reset">Reset Password</button>
</form>
</div>
</div>

</body>
</html>
