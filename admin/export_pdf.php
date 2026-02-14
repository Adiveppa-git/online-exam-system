<?php
session_start();
require_once "../config/db.php";

/* LOAD DOMPDF */
require_once "../dompdf/autoload.inc.php";

use Dompdf\Dompdf;
use Dompdf\Options;

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit("Unauthorized");
}

/* FILTER */
$exam_id = $_GET['exam_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

$where="WHERE 1";

if($exam_id!=""){
$where.=" AND v.exam_id=".(int)$exam_id;
}

if($student_id!=""){
$where.=" AND v.user_id=".(int)$student_id;
}

/* FETCH DATA */
$sql="
SELECT 
u.name AS student,
e.title AS exam,
COUNT(v.id) AS violation_count,
MAX(v.created_at) AS created_at

FROM violations v
JOIN users u ON u.id=v.user_id
JOIN exams e ON e.id=v.exam_id

$where

GROUP BY v.user_id,v.exam_id
ORDER BY created_at DESC
";

$result=$conn->query($sql);

/* BUILD HTML */
$html='
<h2 style="text-align:center;">Exam Violation Report</h2>

<table border="1" width="100%" cellspacing="0" cellpadding="8">

<tr>
<th>SL No</th>
<th>Student</th>
<th>Exam</th>
<th>Violations</th>
<th>Status</th>
<th>Date</th>
</tr>
';

$i=1;

while($row=$result->fetch_assoc()){

$status=$row['violation_count']>=3?"VIOLATED":"OK";

$html.="
<tr>
<td>".$i++."</td>
<td>".$row['student']."</td>
<td>".$row['exam']."</td>
<td>".$row['violation_count']."</td>
<td>".$status."</td>
<td>".$row['created_at']."</td>
</tr>";
}

$html.="</table>";

/* GENERATE PDF */

$options = new Options();
$options->set('isRemoteEnabled', TRUE);

$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);

$dompdf->setPaper('A4','portrait');

$dompdf->render();

/* DOWNLOAD PDF */

$dompdf->stream("violation_report.pdf", [
    "Attachment" => true
]);

exit;
?>
