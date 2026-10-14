<?php
/**
 * KHWWC Server Diagnostics
 * Run: php diagnose-stk.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = '/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc';
chdir($base);

echo "=== Server Diagnostics ===\n\n";

// 1. Check file permissions
echo "1. File permissions:\n";
$files = [
    'app/Console/Commands/CoopReconcileTransfers.php',
    'app/Services/CoopBank/CoopBankTransactionService.php',
    'app/Services/CoopBank/CoopBankAccountService.php',
    'app/Services/PaymentService.php',
    'public/build/index.html',
];
foreach ($files as $f) {
    $exists = file_exists($f);
    $readable = $exists ? is_readable($f) : false;
    echo "   $f: " . ($exists ? ($readable ? "OK" : "NOT READABLE") : "NOT FOUND") . "\n";
}

// 2. Syntax check key files
echo "\n2. PHP syntax check:\n";
$phpFiles = [
    'app/Console/Commands/CoopReconcileTransfers.php',
    'app/Services/CoopBank/CoopBankTransactionService.php',
];
foreach ($phpFiles as $f) {
    $output = shell_exec("php -l $base/$f 2>&1");
    echo "   $f: " . trim($output) . "\n";
}

// 3. Check Laravel log
echo "\n3. Laravel recent errors:\n";
$logFile = $base . '/storage/logs/laravel.log';
if (file_exists($logFile)) {
    $logs = file($logFile);
    $recent = array_slice($logs, -30);
    foreach ($recent as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "   No log file found at: $logFile\n";
}

// 4. Check if artisan commands work
echo "\n4. Artisan status:\n";
$output = shell_exec("php artisan config:clear --no-interaction 2>&1");
echo "   config:clear: " . trim($output) . "\n";

$output = shell_exec("php artisan route:clear --no-interaction 2>&1");
echo "   route:clear: " . trim($output) . "\n";

$output = shell_exec("php artisan config:cache --no-interaction 2>&1");
echo "   config:cache: " . trim($output) . "\n";

// 5. Test key routes
echo "\n5. Route test:\n";
$output = shell_exec("php artisan route:list --no-interaction 2>&1 | grep payments");
echo "   Payment routes:\n";
foreach (explode("\n", $output) as $line) {
    if (trim($line)) echo "   " . trim($line) . "\n";
}

echo "\n=== Done ===\n";
echo "Check https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health\n";
