<?php
session_start();
require_once "../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit("Unauthorized");
}

/* FILTER VALUES */
$exam_id = $_GET['exam_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

$where = "WHERE 1";

if ($exam_id != "") {
    $where .= " AND v.exam_id=".(int)$exam_id;
}

if ($student_id != "") {
    $where .= " AND v.user_id=".(int)$student_id;
}

/* FETCH CORRECT VIOLATION COUNT */
$sql = "
SELECT 
u.name AS student,
e.title AS exam,
COUNT(v.id) AS violation_count,
MAX(v.created_at) AS created_at

FROM violations v

JOIN users u ON u.id = v.user_id
JOIN exams e ON e.id = v.exam_id

$where

GROUP BY v.user_id, v.exam_id

ORDER BY created_at DESC
";

$result = $conn->query($sql);

/* FORCE DOWNLOAD EXCEL */
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=violation_report.xls");
header("Cache-Control: no-cache");

/* OUTPUT TABLE */
echo "
<table border='1'>
<tr>
<th>SL No</th>
<th>Student</th>
<th>Exam</th>
<th>Violations</th>
<th>Status</th>
<th>Date</th>
</tr>
";

$i = 1;

if ($result->num_rows > 0) {

    while ($row = $result->fetch_assoc()) {

        $status = $row['violation_count'] >= 3 ? "VIOLATED" : "OK";

        echo "
        <tr>
        <td>".$i++."</td>
        <td>".$row['student']."</td>
        <td>".$row['exam']."</td>
        <td>".$row['violation_count']."</td>
        <td>".$status."</td>
        <td>".$row['created_at']."</td>
        </tr>
        ";
    }

} else {

    echo "<tr><td colspan='6'>No data found</td></tr>";

}

echo "</table>";

exit;
?>
