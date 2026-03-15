<?php

$host = "hopper.proxy.rlwy.net";
$user = "root";
$pass = "TkYHRIMDtvoiyKgdhaJixjMfxnddFZFi";
$db   = "railway";
$port = 47504;

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

?>