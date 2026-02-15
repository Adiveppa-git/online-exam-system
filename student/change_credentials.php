<?php
session_start();
require_once "../config/db.php";

/* AUTH CHECK */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = "";

/* FETCH CURRENT EMAIL */
$user = $conn->query(
    "SELECT email FROM users WHERE id = $user_id"
)->fetch_assoc();

/* HANDLE FORM SUBMIT */
if (isset($_POST['update'])) {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    /* EMAIL VALIDATION */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Invalid email format";
    }
    /* PASSWORD VALIDATION */
    elseif (strlen($password) < 6) {
        $message = "❌ Password must be at least 6 characters";
    }
    else {
        /* HASH PASSWORD */
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        /* UPDATE DATABASE */
        $stmt = $conn->prepare(
            "UPDATE users SET email = ?, password = ? WHERE id = ?"
        );
        $stmt->bind_param("ssi", $email, $hashed_password, $user_id);
        $stmt->execute();

        $message = "✅ Email and password updated successfully";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Change Email & Password</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="wrapper">

    <!-- SIDEBAR -->
    <?php include "../student/sidebar.php"; ?>

    <!-- CONTENT -->
    <div class="content">
        <h1>Change Email & Password</h1>

        <?php if ($message): ?>
            <p style="margin:15px 0;font-weight:bold;">
                <?= $message ?>
            </p>
        <?php endif; ?>

        <form method="post" style="max-width:400px">

            <label>Email</label>
            <input type="email"
                   name="email"
                   value="<?= htmlspecialchars($user['email']) ?>"
                   required>

            <br><br>

            <label>New Password</label>
            <input type="password"
                   name="password"
                   placeholder="Minimum 6 characters"
                   required>

            <br><br>

            <button type="submit" name="update">
                Update
            </button>
        </form>
    </div>

</div>

</body>
</html>
