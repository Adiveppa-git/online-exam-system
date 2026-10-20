<?php
/**
 * Test Suite: Email Deliverability, Template Construction, Plain-Text Fallback, and Domain Inspection
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../auth/send_mail.php';

echo "====================================================\n";
echo "   Email Deliverability & Construction Test Suite   \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest($description, $condition, $details = "")
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$description}\n";
        if ($details) {
            echo "       └─ {$details}\n";
        }
        $passed++;
    } else {
        echo "[FAIL] {$description}\n";
        if ($details) {
            echo "       └─ Error: {$details}\n";
        }
        $failed++;
    }
}

// 1. Subject line construction
$subject = buildOtpEmailSubject("Password Reset", "839401");
assertTest(
    "1. Subject line is concise, transactional, and starts with brand/purpose instead of raw numbers",
    $subject === "Your Online Examination System Password Reset Code: 839401",
    "Subject: '{$subject}'"
);

// 2. Plain-text alternative construction
$text = buildOtpEmailText("Password Reset", "839401", 10);
assertTest(
    "2. Plain-text alternative contains code, expiration, and security warnings",
    strpos($text, "839401") !== false &&
    strpos($text, "10 minutes") !== false &&
    strpos($text, "SECURITY WARNING") !== false &&
    strpos($text, "Online Examination System") !== false,
    "Generated clean text length: " . strlen($text) . " bytes"
);

// 3. HTML email structure & deliverability features
$html = buildOtpEmailHtml("Password Reset", "839401", 10);
$hasCleanPreheader = strpos($html, "mso-hide: all") !== false && strpos($html, "839401") !== false;
$hasNoLowContrastHack = !preg_match('/(?<![a-zA-Z\-])color:\s*#f4f6f9/i', $html); // Does not use font-same-as-bg trick
$hasTableWrapper   = strpos($html, "<table role='presentation'") !== false;
$hasMonospaceOtp   = strpos($html, "Courier New") !== false && strpos($html, "letter-spacing") !== false;
$hasSecurityAlert  = strpos($html, "Security") !== false;
$hasUnsolicitedNote = strpos($html, "If you did not request") !== false;
$hasMetaTags       = strpos($html, "x-apple-disable-message-reformatting") !== false && strpos($html, "viewport") !== false;

assertTest(
    "3. HTML template contains clean preheader (no text-hiding hacks), table layout, monospace OTP, and security notices",
    $hasCleanPreheader && $hasNoLowContrastHack && $hasTableWrapper && $hasMonospaceOtp && $hasSecurityAlert && $hasUnsolicitedNote && $hasMetaTags,
    "Clean preheader, Outlook table layout, monospace font, and security advice verified"
);

// 4. HTML to clean plain text converter
$convertedText = convertHtmlToCleanPlainText($html);
assertTest(
    "4. HTML-to-plain-text fallback extracts readable content without HTML tags",
    strpos($convertedText, "839401") !== false &&
    strpos($convertedText, "<table") === false &&
    strpos($convertedText, "<div") === false &&
    strlen($convertedText) > 100,
    "Extracted text length: " . strlen($convertedText) . " bytes"
);

// 5. PHPMailer SMTP multipart/alternative plain-text (AltBody) capture
putenv("MAIL_DRIVER=smtp");
putenv("SMTP_HOST=smtp.gmail.com");
putenv("SMTP_PORT=587");
putenv("SMTP_USERNAME=mailproject112@gmail.com");
putenv("SMTP_PASSWORD=valid_mock_pass_16");
putenv("SENDER_EMAIL=mailproject112@gmail.com");
putenv("SENDER_NAME=Online Examination System");

$capturedSmtpConfig = null;
setSmtpHandler(function($to, $subj, $body, $config) use (&$capturedSmtpConfig) {
    $capturedSmtpConfig = $config;
    return true;
});

$testSend = sendMail("student@domain.org", $subject, $html, $text);
setSmtpHandler(null);

assertTest(
    "5. SMTP transmission supplies plain-text alternative (AltBody) for multipart MIME",
    $testSend === true && !empty($capturedSmtpConfig['textBody']) && strpos($capturedSmtpConfig['textBody'], "839401") !== false,
    "AltBody passed to mail transport"
);

// 6. Anti-spam and transactional headers
assertTest(
    "6. SMTP transmission includes transactional headers and excludes 'Precedence: bulk' spam flag",
    !empty($capturedSmtpConfig['headers']['Auto-Submitted']) &&
    $capturedSmtpConfig['headers']['Auto-Submitted'] === 'auto-generated' &&
    $capturedSmtpConfig['headers']['X-Auto-Response-Suppress'] === 'All' &&
    empty($capturedSmtpConfig['headers']['Precedence']) &&
    strpos($capturedSmtpConfig['headers']['X-Mailer'], 'Online Examination System') !== false,
    "Headers: Auto-Submitted, X-Auto-Response-Suppress, no Precedence bulk, and custom X-Mailer verified"
);

// 7. Reply-To header consistency
assertTest(
    "7. Reply-To header matches legitimate sender address",
    !empty($capturedSmtpConfig['headers']['Reply-To']) && strpos($capturedSmtpConfig['headers']['Reply-To'], "mailproject112@gmail.com") !== false,
    "Reply-To: " . ($capturedSmtpConfig['headers']['Reply-To'] ?? 'none')
);

// 8. Brevo REST API delivers textContent and replyTo
putenv("MAIL_DRIVER=brevo");
putenv("BREVO_API_KEY=test_brevo_key_12345");
putenv("SENDER_EMAIL=mailproject112@gmail.com");

$capturedBrevoPayload = null;
setBrevoHttpHandler(function($url, $headers, $payloadJson) use (&$capturedBrevoPayload) {
    $capturedBrevoPayload = json_decode($payloadJson, true);
    return ['code' => 201, 'response' => '{"messageId":"<mock>"}', 'error' => null];
});

$brevoSend = sendMail("student@domain.org", $subject, $html, $text);
setBrevoHttpHandler(null);

assertTest(
    "8. Brevo API payload includes textContent, replyTo, and transactional headers",
    $brevoSend === true &&
    !empty($capturedBrevoPayload['textContent']) &&
    !empty($capturedBrevoPayload['replyTo']['email']) &&
    !empty($capturedBrevoPayload['headers']['Auto-Submitted']),
    "Brevo payload validated with textContent and replyTo"
);

// 9. Automatic plain text generation when 4th parameter omitted
setSmtpHandler(function($to, $subj, $body, $config) use (&$capturedSmtpConfig) {
    $capturedSmtpConfig = $config;
    return true;
});
putenv("MAIL_DRIVER=smtp");
$sendWithoutTextParam = sendMail("user@domain.org", "Test", "<p>Your OTP is 123456</p>");
setSmtpHandler(null);

assertTest(
    "9. sendMail auto-converts HTML to text alternative when text parameter is omitted",
    $sendWithoutTextParam === true &&
    !empty($capturedSmtpConfig['textBody']) &&
    strpos($capturedSmtpConfig['textBody'], "Your OTP is 123456") !== false,
    "Auto-converted text: '" . trim($capturedSmtpConfig['textBody'] ?? '') . "'"
);

// 10. Rejection of placeholder and invalid sender addresses
putenv("SENDER_EMAIL=noreply@example.com");
$invalidSenderSend = sendMail("user@domain.org", "Subject", "<p>Test</p>");
assertTest(
    "10. Placeholder sender addresses (e.g. noreply@example.com) rejected safely",
    $invalidSenderSend === false && getLastMailError() !== null,
    "Safely rejected with error: " . getLastMailError()
);

// 11. Delivery confirmation truthfulness: failure clears session success
putenv("SENDER_EMAIL=mailproject112@gmail.com");
setSmtpHandler(function() {
    return ['success' => false, 'error' => 'Simulated transport drop'];
});
$_SESSION['mail_sent_success'] = true;
$failedSend = sendMail("user@domain.org", "Subject", "<p>Test</p>");
setSmtpHandler(null);

assertTest(
    "11. Transport failure clears session success flag and returns false",
    $failedSend === false && empty($_SESSION['mail_sent_success']),
    "Session success flag cleared on transport error"
);

// 12. Sender Domain Deliverability Inspection
$report = inspectSenderDomainDeliverability("mailproject112@gmail.com");
assertTest(
    "12. inspectSenderDomainDeliverability evaluates authenticated SMTP, MX, SPF, and DMARC",
    $report['domain'] === 'gmail.com' &&
    $report['smtp_authenticated'] === true &&
    $report['has_mx'] === true &&
    $report['has_spf'] === true &&
    $report['has_dmarc'] === true,
    "MX: " . count($report['mx_records']) . " servers | SPF: " . substr($report['spf_record'] ?? '', 0, 30) . " | DMARC Policy: " . ($report['dmarc_policy'] ?? 'none')
);

echo "\n====================================================\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
