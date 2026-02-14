<?php
session_start();
require_once "../config/db.php";

/* ===== AUTH CHECK ===== */
if (!isset($_SESSION['user_id']))
{
    header("Location: login.php");
    exit;
}

$message = "";
$error = "";

/* ===== UPDATE PASSWORD ===== */
if (isset($_POST['update_password']))
{
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    $user_id = $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT password FROM users WHERE id=?"
    );

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($db_pass);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current, $db_pass))
    {
        $error = "Current password is incorrect";
    }
    elseif ($new !== $confirm)
    {
        $error = "Passwords do not match";
    }
    elseif (strlen($new) < 8)
    {
        $error = "Password must be at least 8 characters";
    }
    else
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "UPDATE users SET password=? WHERE id=?"
        );

        $stmt->bind_param("si", $hash, $user_id);
        $stmt->execute();

        $message = "Password updated successfully";
    }
}
?>

<!DOCTYPE html>
<html>
<head>

<title>Change Password</title>

<link rel="stylesheet" href="../assets/css/style.css">

<!-- FONT AWESOME -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

/* CONTENT FULL WIDTH */
.content
{
    margin-left:240px;
    padding:40px;
    width:calc(100% - 240px);
    box-sizing:border-box;
}

/* PASSWORD CONTAINER */
.password-container
{
    max-width:500px;
    background:#ffffff;
    padding:30px;
    border-radius:8px;
    box-shadow:0 0 15px rgba(0,0,0,0.1);
}

/* FORM GROUP */
.form-group
{
    margin-bottom:20px;
}

/* LABEL */
.form-group label
{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

/* PASSWORD BOX */
.password-box
{
    position:relative;
}

/* INPUT */
.password-box input
{
    width:100%;
    height:45px;
    padding:10px;
    padding-right:45px;
    font-size:16px;
    border:1px solid #ccc;
    border-radius:6px;
    box-sizing:border-box;
}

/* EYE ICON */
.password-box i
{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    cursor:pointer;
    color:#666;
    font-size:16px;
}

.password-box i:hover
{
    color:#0d6efd;
}

/* BUTTON */
.password-container button
{
    width:100%;
    height:45px;
    background:#0d6efd;
    color:white;
    border:none;
    font-size:16px;
    font-weight:bold;
    border-radius:6px;
    cursor:pointer;
}

.password-container button:hover
{
    background:#0b5ed7;
}

/* MESSAGES */
.success
{
    background:#d4edda;
    color:#155724;
    padding:10px;
    margin-bottom:15px;
    border-radius:6px;
    font-weight:bold;
}

.error
{
    background:#f8d7da;
    color:#721c24;
    padding:10px;
    margin-bottom:15px;
    border-radius:6px;
    font-weight:bold;
}

</style>

</head>

<body>

<div class="wrapper">

<?php include "../student/sidebar.php"; ?>

<div class="content">

<h1>Change Password</h1>

<div class="password-container">

<?php if($message): ?>
<div class="success"><?= $message ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="error"><?= $error ?></div>
<?php endif; ?>


<form method="post">


<!-- CURRENT PASSWORD -->

<div class="form-group">

<label>Current Password</label>

<div class="password-box">

<input type="password"
id="current"
name="current_password"
required>

<i class="fa-solid fa-eye-slash"
onclick="togglePassword('current', this)">
</i>

</div>

</div>



<!-- NEW PASSWORD -->

<div class="form-group">

<label>New Password</label>

<div class="password-box">

<input type="password"
id="new"
name="new_password"
required>

<i class="fa-solid fa-eye-slash"
onclick="togglePassword('new', this)">
</i>

</div>

</div>



<!-- CONFIRM PASSWORD -->

<div class="form-group">

<label>Confirm New Password</label>

<div class="password-box">

<input type="password"
id="confirm"
name="confirm_password"
required>

<i class="fa-solid fa-eye-slash"
onclick="togglePassword('confirm', this)">
</i>

</div>

</div>



<button type="submit" name="update_password">
Update Password
</button>


</form>

</div>
</div>
</div>



<script>

/* UNIVERSAL TOGGLE */
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
