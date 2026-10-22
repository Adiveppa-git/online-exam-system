<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || !isset($_POST['exam_id'])) {
    exit;
}

/* CSRF VALIDATION */
$post_token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
    exit;
}

$user_id = $_SESSION['user_id'];
$exam_id = (int)$_POST['exam_id'];

/* INSERT VIOLATION */
$stmt = $conn->prepare("
INSERT INTO violations (user_id, exam_id)
VALUES (?,?)
");

$stmt->bind_param("ii", $user_id, $exam_id);
$stmt->execute();

/* FETCH UPDATED VIOLATION COUNT */
$cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM violations WHERE user_id = ? AND exam_id = ?");
$cntStmt->bind_param("ii", $user_id, $exam_id);
$cntStmt->execute();
$totalViolations = (int)($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);

/* SYNC WITH exam_violations IF TABLE EXISTS */
try {
    $syncStmt = $conn->prepare("
        INSERT INTO exam_violations (student_id, exam_id, violation_count)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE violation_count = VALUES(violation_count), last_violation = CURRENT_TIMESTAMP
    ");
    if ($syncStmt) {
        $syncStmt->bind_param("iii", $user_id, $exam_id, $totalViolations);
        $syncStmt->execute();
    }
} catch (Throwable $t) {}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'saved',
    'violations' => $totalViolations
]);
?>
