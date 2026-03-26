<?php

function sendMail($to, $subject, $body)
{
    $apiKey = getenv("BREVO_API_KEY"); // 🔐 put new key

    $data = [
        "sender" => [
            "name" => "Online Examination System",
            "email" => "mailproject112@gmail.com"
        ],
        "to" => [
            ["email" => $to]
        ],
        "subject" => $subject,
        "htmlContent" => $body
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "api-key: $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // ✅ success only if API returns 201
    if ($httpCode == 201) {
        return true;
    } else {
        return false;
    }
}