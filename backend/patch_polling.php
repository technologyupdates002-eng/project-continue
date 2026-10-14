<?php
$file = 'public/dist/assets/MemberDashboard-CzP0MD1t.js';
$content = file_get_contents($file);
$search = 'setTimeout(t,1e4)';
$replace = 'setTimeout(t,3e3)';
$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences of 10s timeout' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched to 3s interval' . PHP_EOL;
} else {
    echo 'Not found' . PHP_EOL;
}
