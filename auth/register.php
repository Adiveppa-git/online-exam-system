<?php
session_start();
require_once "../config/db.php";
require_once "send_mail.php";

$msg = "";
$step = 1;

/* ================= SEND OTP ================= */
if (isset($_POST['send_otp']))
{
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $role = "student";
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    {
        $msg = "Invalid email format";
    }
    elseif (strlen($password) < 8 || strlen($password) > 15)
    {
        $msg = "Password must be 8 to 15 characters";
    }
    elseif ($password !== $confirm)
    {
        $msg = "Passwords do not match";
    }
    else
    {
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email=?"
        );

        $check->bind_param("s", $email);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0)
        {
            $msg = "Email already registered";
        }
        else
        {
            $lastRegKey = 'last_otp_time_' . md5($email);
            if (isset($_SESSION[$lastRegKey]) && (time() - (int)$_SESSION[$lastRegKey]) < OTP_REQUEST_COOLDOWN_SECONDS)
            {
                $msg = "❌ Please wait before requesting another verification code.";
                $step = 1;
            }
            else
            {
                $otp = rand(100000, 999999);
                $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                $_SESSION['reg_data'] =
                [
                    'name'=>$name,
                    'email'=>$email,
                    'role'=>$role,
                    'password'=>password_hash($password, PASSWORD_DEFAULT),
                    'otp'=>$otp,
                    'expiry'=>$expiry
                ];

                $body = buildOtpEmailHtml("Email Verification", $otp, 10);

                if (sendMail($email, "Verify Your Email - Online Examination System", $body))
                {
                    $_SESSION[$lastRegKey] = time();
                    $_SESSION['reg_otp_attempts_' . md5($email)] = 0;
                    $step = 2;
                }
                else
                {
                    $err = getUserFacingMailError() ?: "Email service is temporarily unavailable. Please try again later.";
                    $msg = "❌ " . $err;
                    $step = 1;
                }
            }
        }
    }
}

/* ================= VERIFY OTP ================= */
if (isset($_POST['verify_otp']))
{
    if (!isset($_SESSION['reg_data']))
    {
        header("Location: register.php");
        exit;
    }

    $data = $_SESSION['reg_data'];
    $email = $data['email'] ?? '';
    $attemptKey = 'reg_otp_attempts_' . md5($email);
    if (!isset($_SESSION[$attemptKey]))
    {
        $_SESSION[$attemptKey] = 0;
    }

    $userOtp = trim($_POST['otp']);

    if ((int)$_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS)
    {
        unset($_SESSION['reg_data']);
        unset($_SESSION[$attemptKey]);
        $msg = "Too many failed verification attempts. Please request a new OTP.";
        $step = 1;
    }
    elseif ($userOtp != $data['otp'])
    {
        $_SESSION[$attemptKey] = (int)$_SESSION[$attemptKey] + 1;
        if ($_SESSION[$attemptKey] >= OTP_MAX_VERIFICATION_ATTEMPTS)
        {
            unset($_SESSION['reg_data']);
            unset($_SESSION[$attemptKey]);
            $msg = "Too many failed verification attempts. Please request a new OTP.";
            $step = 1;
        }
        else
        {
            $msg = "Invalid verification code.";
            $step = 2;
        }
    }
    elseif (strtotime($data['expiry']) < time())
    {
        unset($_SESSION['reg_data']);
        unset($_SESSION[$attemptKey]);
        $msg = "Verification code has expired. Please request a new one.";
        $step = 1;
    }
    else
    {
        $stmt = $conn->prepare(
        "INSERT INTO users (name,email,password,role)
         VALUES (?,?,?,?)");

        $stmt->bind_param(
            "ssss",
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role']
        );

        $stmt->execute();

        unset($_SESSION['reg_data']);
        unset($_SESSION[$attemptKey]);

        header("Location: login.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Register</title>

<link rel="stylesheet"
href="../assets/css/style.css">

<!-- FONT AWESOME -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

.password-box
{
    position:relative;
}

.password-box input
{
    padding-right:45px;
}

.password-box i
{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    cursor:pointer;
    color:#666;
}

.password-box i:hover
{
    color:#0d6efd;
}

.strength
{
    height:6px;
    background:#ddd;
    border-radius:5px;
    margin-top:5px;
}

.strength-bar
{
    height:6px;
    width:0;
    border-radius:5px;
}

.disabled
{
    opacity:.6;
    cursor:not-allowed;
}

</style>

</head>

<body>

<div class="center-screen">
<div class="login-box">

<h2>Register</h2>

<?php if ($msg): ?>
<p style="color:red;font-weight:bold">
<?= $msg ?>
</p>
<?php endif; ?>


<?php if ($step === 1): ?>

<form method="post">

<input name="name"
placeholder="Full Name"
required>

<input type="email"
name="email"
placeholder="Email"
required>




<!-- PASSWORD -->

<div class="password-box">

<input type="password"
id="password"
name="password"
placeholder="Password"
oninput="checkStrength();checkMatch()"
onkeyup="checkStrength();checkMatch()"
required>

<i class="fa-solid fa-eye-slash"
onclick="togglePassword('password',this)">
</i>

</div>

<div class="strength">
<div id="strengthBar"
class="strength-bar">
</div>
</div>

<div id="strengthText"
style="font-size:13px">
</div>



<!-- CONFIRM PASSWORD -->

<div class="password-box">

<input type="password"
id="confirmPassword"
name="confirm_password"
placeholder="Confirm Password"
oninput="checkMatch()"
onkeyup="checkMatch()"
required>

<i class="fa-solid fa-eye-slash"
onclick="togglePassword('confirmPassword',this)">
</i>

</div>

<div id="matchText"
style="font-size:13px;font-weight:bold">
</div>



<button name="send_otp"
id="registerBtn"
disabled
class="disabled">

Send OTP

</button>


<p style="text-align:center;margin-top:15px">

Already have account?

<a href="login.php"
style="color:#0d6efd">
Login
</a>

</p>

</form>


<?php else: ?>

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

<button name="verify_otp">
Verify & Register
</button>

</form>

<?php endif; ?>

</div>
</div>



<script>

/* UNIVERSAL EYE TOGGLE */

function togglePassword(id, eye)
{
    let input =
    document.getElementById(id);

    if(input.type==="password")
    {
        input.type="text";

        eye.classList.remove("fa-eye-slash");
        eye.classList.add("fa-eye");
    }
    else
    {
        input.type="password";

        eye.classList.remove("fa-eye");
        eye.classList.add("fa-eye-slash");
    }
}


/* PASSWORD STRENGTH */

function checkStrength()
{
    const p=document.getElementById('password')?.value || '';
    const bar=document.getElementById('strengthBar');
    const txt=document.getElementById('strengthText');

    if(!bar || !txt) return;

    let s=0;

    if(p.length>=8)s++;
    if(/[A-Z]/.test(p))s++;
    if(/[0-9]/.test(p))s++;
    if(/[@$!%*?&]/.test(p))s++;

    const map=
    [
     ["25%","red","Weak"],
     ["50%","orange","Medium"],
     ["75%","blue","Strong"],
     ["100%","green","Very Strong"]
    ];

    bar.style.width=map[s-1]?.[0]||"0";
    bar.style.background=map[s-1]?.[1]||"";
    txt.textContent=map[s-1]?.[2]||"";
}


/* PASSWORD MATCH */

function checkMatch()
{
    const pEl=document.getElementById('password');
    const cEl=document.getElementById('confirmPassword');
    const t=document.getElementById('matchText');
    const b=document.getElementById('registerBtn');

    if(!pEl || !cEl || !t || !b) return;

    const p=pEl.value;
    const c=cEl.value;

    if(!c)
    {
        t.textContent="";
        b.disabled=true;
        b.classList.add("disabled");
        return;
    }

    if(p !== c)
    {
        t.textContent="Passwords do not match";
        t.style.color="red";

        b.disabled=true;
        b.classList.add("disabled");
    }
    else if(p.length < 8 || p.length > 15)
    {
        t.textContent="Password must be 8 to 15 characters";
        t.style.color="red";

        b.disabled=true;
        b.classList.add("disabled");
    }
    else
    {
        t.textContent="Passwords match";
        t.style.color="green";

        b.disabled=false;
        b.classList.remove("disabled");
    }
}

</script>

</body>
</html>
