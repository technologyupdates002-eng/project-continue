<?php
$file = 'public/dist/assets/MemberDashboard-CzP0MD1t.js';
$content = file_get_contents($file);

// Find and patch the member stats query to include a version suffix
// This forces TanStack Query to treat it as a new query
$search = 'queryKey:["member-stats",r]';
$replace = 'queryKey:["member-stats",r,"v20260908"]';
$count = substr_count($content, $search);
echo 'Found ' . $count . ' member-stats query keys' . PHP_EOL;

if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched member-stats query key' . PHP_EOL;
} else {
    echo 'member-stats pattern not found' . PHP_EOL;
}

// Also patch my-contributions query key
$search2 = 'queryKey:["my-contributions",O]';
$replace2 = 'queryKey:["my-contributions",O,"v20260908"]';
$count2 = substr_count($content, $search2);
echo 'Found ' . $count2 . ' my-contributions query keys' . PHP_EOL;

if ($count2 > 0) {
    $content = str_replace($search2, $replace2, $content);
    file_put_contents($file, $content);
    echo 'Patched my-contributions query key' . PHP_EOL;
} else {
    echo 'my-contributions pattern not found' . PHP_EOL;
}

// Patch welfare-settings query key
$search3 = 'queryKey:["welfare-settings"]';
$replace3 = 'queryKey:["welfare-settings","v20260908"]';
$count3 = substr_count($content, $search3);
echo 'Found ' . $count3 . ' welfare-settings query keys' . PHP_EOL;

if ($count3 > 0) {
    $content = str_replace($search3, $replace3, $content);
    file_put_contents($file, $content);
    echo 'Patched welfare-settings query key' . PHP_EOL;
} else {
    echo 'welfare-settings pattern not found' . PHP_EOL;
}
