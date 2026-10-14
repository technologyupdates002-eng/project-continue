<?php
$file = 'app/Services/PaymentService.php';
$content = file_get_contents($file);

$search = "if (\$existing && \$existing->status === 'paid') {\n                    throw new \App\Exceptions\HttpException(\n                        422, 'contribution_already_paid',\n                        'Contribution for ' . \$now->format('F Y') . ' is already fully paid.'\n                    );\n                }\n\n                if (! \$existing) {";

$replace = "if (\$existing && \$existing->status === 'paid') {\n                    \$targetMonth = \$currentMonth;\n                    \$targetYear = \$currentYear;\n\n                    for (\$i = 0; \$i < 60; \$i++) {\n                        \$targetMonth++;\n\n                        if (\$targetMonth > 12) {\n                            \$targetMonth = 1;\n                            \$targetYear++;\n                        }\n\n                        \$next = Contribution::where('member_id', \$memberId)\n                            ->where('month', \$targetMonth)\n                            ->where('year', \$targetYear)\n                            ->first();\n\n                        if (! \$next || \$next->status !== 'paid') {\n                            break;\n                        }\n                    }\n\n                    \$currentMonth = \$targetMonth;\n                    \$currentYear = \$targetYear;\n                    \$existing = Contribution::where('member_id', \$memberId)\n                        ->where('month', \$currentMonth)\n                        ->where('year', \$currentYear)\n                        ->first();\n                }\n\n                if (! \$existing) {";

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;

if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched ' . $file . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
