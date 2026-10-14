<?php
$file = 'public/dist/assets/MemberDashboard-CzP0MD1t.js';
$content = file_get_contents($file);

// Patch my-contributions query - use JavaScript Date.now() as literal string
$search1 = '/rest/v1/contributions?member_id=eq.';
$replace1 = '/rest/v1/contributions?member_id=eq.&_t=${Date.now()}';
$count1 = substr_count($content, $search1);
echo 'Found ' . $count1 . ' my-contributions occurrences' . PHP_EOL;

// Patch welfare-settings query
$search2 = '/rest/v1/welfare_settings?limit=1';
$replace2 = '/rest/v1/welfare_settings?limit=1&_t=${Date.now()}';
$count2 = substr_count($content, $search2);
echo 'Found ' . $count2 . ' welfare-settings occurrences' . PHP_EOL;

if ($count1 > 0) {
    $content = str_replace($search1, $replace1, $content);
}
if ($count2 > 0) {
    $content = str_replace($search2, $replace2, $content);
}

if ($count1 > 0 || $count2 > 0) {
    file_put_contents($file, $content);
    echo 'Patched' . PHP_EOL;
} else {
    echo 'No patterns found' . PHP_EOL;
}
