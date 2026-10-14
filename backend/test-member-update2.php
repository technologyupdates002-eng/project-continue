<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECK MEMBERS TABLE STRUCTURE ===\n";
$columns = DB::select('DESCRIBE members');
foreach ($columns as $col) {
    echo "  " . $col->Field . " (" . $col->Type . ") NULL=" . $col->Null . " KEY=" . $col->Key . "\n";
}

echo "\n=== CHECK MEMBERS TABLE INDEXES ===\n";
$indexes = DB::select('SHOW INDEX FROM members WHERE Key_name != "PRIMARY"');
foreach ($indexes as $idx) {
    echo "  " . $idx->Key_name . " | " . $idx->Column_name . " | Unique=" . $idx->Non_unique . "\n";
}

echo "\n=== CHECK MEMBERS TABLE TRIGGERS ===\n";
$triggers = DB::select('SHOW TRIGGERS LIKE "members"');
foreach ($triggers as $trigger) {
    echo "  " . $trigger->Trigger_name . " | " . $trigger->Event . " | " . $trigger->Action . "\n";
}
if (empty($triggers)) {
    echo "  No triggers found\n";
}

echo "\n=== TEST UPDATE ===\n";
try {
    $result = DB::table('members')->where('id', '11111111-1111-1111-1111-111111111111')->update(['name' => 'Admin User Test Update']);
    echo "Update result: " . $result . " rows affected\n";
} catch (Exception $e) {
    echo "Update failed: " . $e->getMessage() . "\n";
}

echo "\n=== CHECK UPDATED VALUE ===\n";
$member = DB::table('members')->where('id', '11111111-1111-1111-1111-111111111111')->first();
if ($member) {
    echo "Member name: " . $member->name . "\n";
} else {
    echo "Member not found\n";
}

echo "\n=== REVERT TEST UPDATE ===\n";
try {
    DB::table('members')->where('id', '11111111-1111-1111-1111-111111111111')->update(['name' => 'Admin User']);
    echo "Reverted\n";
} catch (Exception $e) {
    echo "Revert failed: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
unlink(__FILE__);