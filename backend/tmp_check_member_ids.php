<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Sample member_ids (first 10) ===\n";
$rows = DB::table('members')->select('id','name','member_id')->limit(10)->get();
foreach ($rows as $m) {
    echo $m->name.' => '.$m->member_id."\n";
}

echo "\n=== UUID-looking member_ids count ===\n";
$count = DB::table('members')->where('member_id', 'regexp', '^[0-9a-f]{8}-[0-9a-f]{4}')->count();
echo $count."\n";

echo "\n=== Human-readable member_ids count ===\n";
$count2 = DB::table('members')->where('member_id', 'not regexp', '^[0-9a-f]{8}-[0-9a-f]{4}')->count();
echo $count2."\n";

echo "\n=== NULL/empty member_ids count ===\n";
$count3 = DB::table('members')->whereNull('member_id')->orWhere('member_id','')->count();
echo $count3."\n";
