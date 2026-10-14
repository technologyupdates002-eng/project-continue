<?php
$file = 'public/dist/assets/MemberDownloads-CMQ8IW3T.js';
$content = file_get_contents($file);

// Patch member-stats query key
$search1 = 'queryKey:["member-stats",C]';
$replace1 = 'queryKey:["member-stats",C,"v20260908"]';
$count1 = substr_count($content, $search1);
echo 'Found ' . $count1 . ' member-stats query keys' . PHP_EOL;

// Patch my-contributions query key
$search2 = 'queryKey:["my-contributions",C]';
$replace2 = 'queryKey:["my-contributions",C,"v20260908"]';
$count2 = substr_count($content, $search2);
echo 'Found ' . $count2 . ' my-contributions query keys' . PHP_EOL;

if ($count1 > 0) {
    $content = str_replace($search1, $replace1, $content);
}
if ($count2 > 0) {
    $content = str_replace($search2, $replace2, $content);
}

if ($count1 > 0 || $count2 > 0) {
    file_put_contents($file, $content);
    echo 'Patched MemberDownloads bundle' . PHP_EOL;
} else {
    echo 'No patterns found' . PHP_EOL;
}
