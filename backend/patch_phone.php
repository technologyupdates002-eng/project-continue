<?php
$file = 'public/dist/assets/MemberDashboard-CzP0MD1t.js';
$content = file_get_contents($file);

$search = 'ee(de?.phone||"")';
$replace = 'ee((de?.phone||"").replace(/^254/,"0"))';

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;

if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched ' . $file . PHP_EOL;
} else {
    echo 'Not found' . PHP_EOL;
}
