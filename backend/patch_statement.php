<?php
$file = 'public/dist/assets/MemberDownloads-CMQ8IW3T.js';
$content = file_get_contents($file);

// Patch my-contributions-statement query key
$search = 'queryKey:["my-contributions-statement",C]';
$replace = 'queryKey:["my-contributions-statement",C,"v20260908"]';
$count = substr_count($content, $search);
echo 'Found ' . $count . ' my-contributions-statement query keys' . PHP_EOL;

if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched' . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
