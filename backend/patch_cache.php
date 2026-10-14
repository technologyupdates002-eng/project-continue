<?php
$file = 'app/Http/Controllers/Api/MemberStatsController.php';
$content = file_get_contents($file);

$search = <<<'CODE'
    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $memberId = $request->query('member_id');

        if ($memberId) {
            return response()->json([
                'data' => $this->statsService->getStats($memberId),
            ]);
        }

        $own = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
        if (! $own) {
            return response()->json(['data' => null]);
        }
        return response()->json([
            'data' => $this->statsService->getStats($own),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->statsService->getSummary(),
        ]);
    }
CODE;

$replace = <<<'CODE'
    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $memberId = $request->query('member_id');

        if ($memberId) {
            return response()->json([
                'data' => $this->statsService->getStats($memberId),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        $own = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
        if (! $own) {
            return response()->json(['data' => null]);
        }
        return response()->json([
            'data' => $this->statsService->getStats($own),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->statsService->getSummary(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
CODE;

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched ' . $file . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
