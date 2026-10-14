<?php
$file = 'public/dist/assets/MemberDashboard-CzP0MD1t.js';
$content = file_get_contents($file);

// Add cache-busting timestamp to member stats queries
$search = 'queryFn:async()=>{const s=r?`/api/members/stats?member_id=${r}`:"/api/members/stats"';
$replace = 'queryFn:async()=>{const s=r?`/api/members/stats?member_id=${r}&_t=${Date.now()}`:"/api/members/stats?_t="+Date.now()';

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched' . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
