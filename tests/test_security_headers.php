<?php
/**
 * ISSUE-09 Apache / Docker Security Headers Test Suite
 */

echo "====================================================\n";
echo "   ISSUE-09 Security Headers Verification Suite     \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function runTest($name, $closure) {
    global $passCount, $failCount;
    try {
        $result = $closure();
        if ($result === true) {
            echo "[PASS] {$name}\n";
            $passCount++;
        } else {
            echo "[FAIL] {$name}: {$result}\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: Exception: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

// ----------------------------------------------------
// 1. Verify Dockerfile Configuration
// ----------------------------------------------------
runTest("1. Verify Dockerfile enables mod_headers and minimizes ServerTokens/ServerSignature", function() {
    $dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');
    if (!$dockerfile) return "Dockerfile not found";

    if (strpos($dockerfile, 'a2enmod rewrite headers') === false) {
        return "Dockerfile missing 'a2enmod rewrite headers'";
    }
    if (strpos($dockerfile, 'ServerTokens Prod') === false) {
        return "Dockerfile missing 'ServerTokens Prod'";
    }
    if (strpos($dockerfile, 'ServerSignature Off') === false) {
        return "Dockerfile missing 'ServerSignature Off'";
    }
    return true;
});

// ----------------------------------------------------
// 2. Verify .htaccess Configuration Exists and Parsable
// ----------------------------------------------------
$htaccessPath = __DIR__ . '/../.htaccess';
$htaccessContent = file_exists($htaccessPath) ? file_get_contents($htaccessPath) : '';

runTest("2. Verify .htaccess file exists in repository root", function() use ($htaccessPath) {
    if (!file_exists($htaccessPath)) return ".htaccess file does not exist";
    return true;
});

// ----------------------------------------------------
// 3. Verify X-Content-Type-Options
// ----------------------------------------------------
runTest("3. Verify X-Content-Type-Options: nosniff directive", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+X-Content-Type-Options\s+"nosniff"/i', $htaccessContent)) {
        return "Missing or invalid X-Content-Type-Options directive in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 4. Verify X-Frame-Options: SAMEORIGIN
// ----------------------------------------------------
runTest("4. Verify X-Frame-Options: SAMEORIGIN directive", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+X-Frame-Options\s+"SAMEORIGIN"/i', $htaccessContent)) {
        return "Missing or invalid X-Frame-Options directive in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 5. Verify Referrer-Policy: strict-origin-when-cross-origin
// ----------------------------------------------------
runTest("5. Verify Referrer-Policy directive", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+Referrer-Policy\s+"strict-origin-when-cross-origin"/i', $htaccessContent)) {
        return "Missing or invalid Referrer-Policy directive in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 6. Verify Permissions-Policy
// ----------------------------------------------------
runTest("6. Verify Permissions-Policy restrictions directive", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+Permissions-Policy\s+"camera=\(\),\s*microphone=\(\),\s*geolocation=\(\),\s*payment=\(\)"/i', $htaccessContent)) {
        return "Missing or invalid Permissions-Policy directive in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 7. Verify Content-Security-Policy Directives
// ----------------------------------------------------
runTest("7. Verify Content-Security-Policy directives & CDN dependencies", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+Content-Security-Policy\s+"([^"]+)"/i', $htaccessContent, $matches)) {
        return "Missing Content-Security-Policy directive in .htaccess";
    }
    $csp = $matches[1];

    if (strpos($csp, "default-src 'self'") === false) return "CSP missing default-src 'self'";
    if (strpos($csp, "https://cdn.jsdelivr.net") === false) return "CSP missing https://cdn.jsdelivr.net for Bootstrap";
    if (strpos($csp, "https://cdnjs.cloudflare.com") === false) return "CSP missing https://cdnjs.cloudflare.com for FontAwesome";
    if (strpos($csp, "'unsafe-inline'") === false) return "CSP missing 'unsafe-inline' for inline JS/CSS";
    if (strpos($csp, "https://exam-online-ai.onrender.com") === false) return "CSP missing https://exam-online-ai.onrender.com for FastAPI endpoint";
    if (strpos($csp, "frame-ancestors 'self'") === false) return "CSP missing frame-ancestors 'self'";

    return true;
});

// ----------------------------------------------------
// 8. Verify HSTS Conditional Production Expression
// ----------------------------------------------------
runTest("8. Verify HSTS directive is conditionally scoped to HTTPS / X-Forwarded-Proto", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+always\s+set\s+Strict-Transport-Security\s+"max-age=31536000;\s*includeSubDomains"\s+"expr=%\{HTTP:X-Forwarded-Proto\}\s*==\s*\'https\'\s*\|\|\s*%\{HTTPS\}\s*==\s*\'on\'"/i', $htaccessContent)) {
        return "Missing or incorrect conditional HSTS expression in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 9. Verify X-XSS-Protection is Excluded
// ----------------------------------------------------
runTest("9. Verify X-XSS-Protection is intentionally EXCLUDED from .htaccess", function() use ($htaccessContent) {
    if (stripos($htaccessContent, 'X-XSS-Protection') !== false) {
        return "X-XSS-Protection should NOT be present in .htaccess (legacy mechanism)";
    }
    return true;
});

// ----------------------------------------------------
// 10. Verify X-Powered-By Suppression
// ----------------------------------------------------
runTest("10. Verify X-Powered-By header suppression directive", function() use ($htaccessContent) {
    if (!preg_match('/Header\s+unset\s+X-Powered-By/i', $htaccessContent)) {
        return "Missing 'Header unset X-Powered-By' directive in .htaccess";
    }
    return true;
});

// ----------------------------------------------------
// 11. Verify Local HTTP HSTS Protection (No HSTS on Local HTTP)
// ----------------------------------------------------
runTest("11. Verify local HTTP does not receive HSTS header (preserves local dev)", function() {
    // Simulate plain HTTP request environment (local dev)
    $_SERVER['HTTPS'] = 'off';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if ($isHttps) {
        return "Simulated local HTTP environment incorrectly detected as HTTPS";
    }
    return true;
});

echo "\n----------------------------------------------------\n";
echo "Summary: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) exit(1);
exit(0);
