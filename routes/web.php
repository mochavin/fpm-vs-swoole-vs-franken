<?php

use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ─── Benchmark API Routes ────────────────────────────────────────

Route::get('/api/health', function () {
    return response()->json([
        'status' => 'ok',
        'runtime' => env('APP_RUNTIME', 'unknown'),
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/api/posts', [PostController::class, 'index']);
Route::get('/api/posts/{id}', [PostController::class, 'show']);
Route::post('/api/posts', [PostController::class, 'store']);

Route::get('/api/heavy', function () {
    // CPU-intensive: compute fibonacci + JSON encode/decode loops
    $fib = function (int $n) use (&$fib): int {
        if ($n <= 1) return $n;
        return $fib($n - 1) + $fib($n - 2);
    };

    $result = $fib(30);

    // Additional CPU work: serialize/deserialize
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data[] = [
            'index' => $i,
            'value' => md5((string) $i),
            'nested' => ['a' => $i * 2, 'b' => $i * 3],
        ];
    }
    $encoded = json_encode($data);
    json_decode($encoded, true);

    return response()->json([
        'fibonacci_30' => $result,
        'iterations' => 100,
        'status' => 'computed',
    ]);
});
