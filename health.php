<?php
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'service' => 'exam-online-php'
]);
exit();
