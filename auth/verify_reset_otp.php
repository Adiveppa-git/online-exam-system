<?php
session_start();
require_once "../config/db.php";

/* ===== AUTH CHECK ===== */
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];
$msg = "";

/* ===== VERIFY OTP ===== */
if (isset($_POST['verify'])) {

    $otp     = trim($_POST['otp']);
    $newPass = $_POST['password'];

    if (strlen($newPass) < 8 || strlen($newPass) > 15) {
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

        if (
            $res &&
            $res['reset_otp'] === $otp &&
            strtotime($res['otp_expiry']) >= time()
        ) {

            $hashed = password_hash($newPass, PASSWORD_DEFAULT);

            $update = $conn->prepare("
                UPDATE users 
                SET password=?, reset_otp=NULL, otp_expiry=NULL 
                WHERE email=?
            ");
            $update->bind_param("ss", $hashed, $email);
            $update->execute();

            session_destroy();
            header("Location: login.php");
            exit;

        } else {
            $msg = "Invalid or expired OTP";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>

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
