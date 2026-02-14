<div class="sidebar">

<h2>Student Panel</h2>

<a href="../student/dashboard.php"
class="<?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>">
Dashboard
</a>

<a href="../student/exams.php"
class="<?= basename($_SERVER['PHP_SELF'])=='exams.php'?'active':'' ?>">
Available Exams
</a>

<a href="../student/result.php"
class="<?= basename($_SERVER['PHP_SELF'])=='result.php'?'active':'' ?>">
My Results
</a>

<a href="../auth/change_password.php"
class="<?= basename($_SERVER['PHP_SELF'])=='change_password.php'?'active':'' ?>">
Change Password
</a>

<a href="../auth/logout.php">
Logout
</a>

</div>
