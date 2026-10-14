<?php
$file = 'public/dist/assets/index-hJSLLOgU.js';
$content = file_get_contents($file);

$search = 'je.jsx(input,{type:password,maxLength:4,value:f,onChange:function(e){return p(e.target.value)},className:w-full text-center text-2xl tracking-widest py-2 border rounded})';
$replace = 'je.jsx("input",{type:"password",maxLength:4,value:f,onChange:function(e){return p(e.target.value)},className:"w-full text-center text-2xl tracking-widest py-2 border rounded"})';

$count = substr_count($content, $search);
echo 'Found ' . $count . ' broken PIN inputs' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched' . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
