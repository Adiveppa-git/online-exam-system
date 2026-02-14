<?php
session_start();
require_once "../config/db.php";

$error = "";

if (isset($_POST['login']))
{
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare(
        "SELECT id, name, password, role
         FROM users
         WHERE email=?"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1)
    {
        $user = $res->fetch_assoc();

        if (password_verify($password, $user['password']))
        {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['name']    = $user['name'];

            if ($user['role'] === 'admin')
            {
                header("Location: ../admin/dashboard.php");
            }
            else
            {
                header("Location: ../student/dashboard.php");
            }
            exit;
        }
        else
        {
            $error = "Invalid password";
        }
    }
    else
    {
        $error = "Email not registered";
    }
}
?>

<!DOCTYPE html>
<html>
<head>

<title>Login</title>

<link rel="stylesheet" href="../assets/css/style.css">

<!-- FONT AWESOME -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

/* Password Eye */
.password-box {
    position: relative;
}

.password-box input {
    padding-right: 45px;
}

.password-box i {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #666;
    font-size: 16px;
}

.password-box i:hover {
    color: #0d6efd;
}

/* Forgot password link */
.auth-link {
    display: inline-block;
    margin-top: 4px;
    margin-bottom: 14px;
    font-size: 14px;
    color: #0d6efd;
    text-decoration: underline;
}

.auth-link:hover {
    color: #0b5ed7;
}

/* Signup section */
.signup-text {
    margin-top: 18px;
    text-align: center;
    font-size: 15px;
}

.signup-text a {
    color: #0d6efd;
    text-decoration: underline;
    font-weight: 500;
}

.signup-text a:hover {
    color: #0b5ed7;
}

</style>

</head>

<body>

<div class="center-screen">
<div class="login-box">

<h2>Login</h2>

<?php if ($error): ?>
<p style="color:red;font-weight:bold">
<?= $error ?>
</p>
<?php endif; ?>

<form method="post">

<!-- EMAIL -->
<input type="email"
name="email"
placeholder="Email address"
required>

<!-- PASSWORD -->
<div class="password-box">
    <input type="password"
           id="password"
           name="password"
           placeholder="Password"
           required>

    <i class="fa-solid fa-eye-slash"
       onclick="togglePassword('password', this)">
    </i>
</div>

<!-- FORGOT PASSWORD -->
<div style="text-align:right;">
    <a href="forgot_password.php" class="auth-link">
        Forgot password?
    </a>
</div>

<!-- LOGIN BUTTON -->
<button type="submit" name="login">
    Login
</button>

<!-- REGISTER LINK -->
<div class="signup-text">
    Don’t have an account?
    <a href="register.php">Sign up</a>
</div>

</form>

</div>
</div>

<script>

function togglePassword(id, eye)
{
    let input = document.getElementById(id);

    if (input.type === "password")
    {
        input.type = "text";
        eye.classList.remove("fa-eye-slash");
        eye.classList.add("fa-eye");
    }
    else
    {
        input.type = "password";
        eye.classList.remove("fa-eye");
        eye.classList.add("fa-eye-slash");
    }
}

</script>

</body>
</html>
