<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

$msg = "";

if (!isset($_SESSION['reset_email'])) {
    if (!headers_sent()) {
        header("Location: forgot_password.php");
    }
    exit;
}

if (isset($_POST['reset'])) {

    if (!isset($conn) || !$conn) {
        $msg = "Database service is temporarily unavailable. Please try again later.";
    } elseif (time() - ($_SESSION['otp_time'] ?? 0) > 600) {
        die("OTP expired");
    } elseif (($_POST['otp'] ?? '') != ($_SESSION['reset_otp'] ?? '')) {
        $msg = "Invalid OTP";
    } else {

        $new = $_POST['password'] ?? '';
        $hash = password_hash($new, PASSWORD_DEFAULT);

        try {
            $stmt = $conn->prepare(
                "UPDATE users SET password=? WHERE email=?"
            );
            if ($stmt) {
                $stmt->bind_param("ss", $hash, $_SESSION['reset_email']);
                $stmt->execute();
            }

            session_destroy();
            if (!headers_sent()) {
                header("Location: login.php");
            }
            exit;
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
