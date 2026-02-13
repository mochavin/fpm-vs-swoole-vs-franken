<?php

/**
 * Laravel Benchmark HTML Generator
 * Aggregates k6 JSON results and generates a beautiful report.
 */

$resultsDir = __DIR__ . '/results';
$latestRun = null;
$timestamp = date('Y-m-d H:i:s');

// Find the latest result folder OR find all JSON files in the root results dir
// The run-bench.sh script creates a timestamped folder, let's use that if possible.
$dirs = glob($resultsDir . '/*', GLOB_ONLYDIR);
if (!empty($dirs)) {
    usort($dirs, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $latestRunDir = $dirs[0];
    $latestRun = basename($latestRunDir);
}

// In case the JSONs are in the root results/ (as seen in the earlier list_dir)
// Actually, handleSummary in k6/benchmark.js saves to /results/ directly.
$runtimes = ['fpm', 'swoole', 'franken'];
$data = [];

foreach ($runtimes as $runtime) {
    // Find the newest JSON for this runtime
    $files = glob($resultsDir . "/{$runtime}_*.json");
    if (empty($files)) {
        continue;
    }
    
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $jsonPath = $files[0];
    $content = json_decode(file_get_contents($jsonPath), true);
    if ($content) {
        $data[$runtime] = $content;
        $data[$runtime]['_source'] = basename($jsonPath);
        $data[$runtime]['_mtime'] = filemtime($jsonPath);
    }
}

if (empty($data)) {
    die("No benchmark data found in {$resultsDir}\n");
}

// Extract metrics for comparison
$scenarios = [
    'health_check' => 'Health Check (Throughput)',
    'read_posts' => 'List Posts (DB Read)',
    'single_post' => 'Single Post (DB Read)',
    'write_posts' => 'Create Post (DB Write)',
    'heavy_compute' => 'Heavy Compute (CPU)'
];

$metrics = [
    'http_reqs' => 'Total Requests',
    'iterations' => 'Total Iterations',
    'http_req_duration' => 'Avg Response Time',
    'http_req_failed' => 'Error Rate'
];

$colors = [
    'fpm' => '#EF4444',     // Red
    'swoole' => '#3B82F6',   // Blue
    'franken' => '#10B981'   // Green
];

// Start generating HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel Runtime Benchmark Results</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .gradient-text {
            background: linear-gradient(135deg, #60A5FA 0%, #A78BFA 50%, #F472B6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .chart-container { position: relative; height: 300px; width: 100%; }
    </style>
</head>
<body class="bg-[#0B0F1A] text-gray-200 min-h-screen pb-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12">
        <!-- Header -->
        <div class="mb-12 text-center">
            <h1 class="text-5xl font-extrabold tracking-tight mb-4">
                <span class="gradient-text">Laravel 12</span> Benchmark
            </h1>
            <p class="text-xl text-gray-400">Comparing PHP-FPM, Swoole, and FrankenPHP</p>
            <div class="mt-4 inline-flex items-center px-4 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-sm">
                Generated on <?= $timestamp ?>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <?php 
            $bestRps = max(array_map(fn($r) => $r['metrics']['http_reqs']['values']['rate'], $data));
            $bestLatency = min(array_map(fn($r) => $r['metrics']['http_req_duration']['values']['avg'], $data));
            
            foreach ($data as $runtime => $res): 
                $rps = $res['metrics']['http_reqs']['values']['rate'];
                $latency = $res['metrics']['http_req_duration']['values']['avg'];
            ?>
                <div class="glass rounded-2xl p-6 transition-all border border-transparent hover:border-gray-700">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold uppercase tracking-wider" style="color: <?= $colors[$runtime] ?>"><?= $runtime ?></h3>
                        <div class="flex flex-col items-end">
                            <?php if ($rps == $bestRps): ?>
                                <span class="bg-yellow-500/10 text-yellow-500 text-[10px] px-2 py-0.5 rounded border border-yellow-500/20 font-bold uppercase mb-1">Speed King</span>
                            <?php elseif ($latency == $bestLatency): ?>
                                <span class="bg-blue-500/10 text-blue-500 text-[10px] px-2 py-0.5 rounded border border-blue-500/20 font-bold uppercase mb-1">Efficiency Pro</span>
                            <?php endif; ?>
                            <span class="text-[8px] text-gray-600 font-mono"><?= $res['_source'] ?></span>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <p class="text-gray-500 text-xs uppercase font-semibold">Max Throughput</p>
                            <p class="text-3xl font-bold"><?= number_format($rps, 2) ?> <span class="text-sm font-normal text-gray-500">req/s</span></p>
                        </div>
                        <div class="grid grid-cols-2 gap-4 border-t border-gray-800 pt-4">
                            <div>
                                <p class="text-gray-500 text-xs uppercase font-semibold">Avg Latency</p>
                                <p class="text-xl font-medium"><?= number_format($latency, 2) ?> <span class="text-xs text-gray-500">ms</span></p>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs uppercase font-semibold">Success Rate</p>
                                <p class="text-xl font-medium <?= $res['metrics']['http_req_failed']['values']['rate'] > 0.01 ? 'text-red-400' : 'text-green-400' ?>">
                                    <?= number_format((1 - $res['metrics']['http_req_failed']['values']['rate']) * 100, 2) ?>%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Main Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
            <!-- Requests Per Second -->
            <div class="glass rounded-2xl p-8">
                <h3 class="text-xl font-bold mb-6 flex items-center">
                    <svg class="w-5 h-5 mr-3 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Throughput (Requests/s)
                </h3>
                <div class="chart-container">
                    <canvas id="rpsChart"></canvas>
                </div>
            </div>

            <!-- p95 Latency -->
            <div class="glass rounded-2xl p-8">
                <h3 class="text-xl font-bold mb-6 flex items-center">
                    <svg class="w-5 h-5 mr-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    P95 Latency (ms)
                </h3>
                <div class="chart-container">
                    <canvas id="latencyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Scenario Breakdown -->
        <div class="glass rounded-2xl overflow-hidden mb-12">
            <div class="p-8 border-b border-gray-800 bg-white/[0.01]">
                <h3 class="text-xl font-bold">Latency Comparison (Avg ms)</h3>
                <p class="text-sm text-gray-500 mt-1">Detailed response times across different workload scenarios.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-gray-400 text-xs border-b border-gray-800">
                            <th class="p-6 font-bold uppercase tracking-widest">Workload</th>
                            <?php foreach ($data as $runtime => $res): ?>
                                <th class="p-6 font-bold uppercase tracking-widest text-right" style="color: <?= $colors[$runtime] ?>"><?= $runtime ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php 
                        $scenarioMetrics = [
                            'health_duration' => ['title' => 'Health Check', 'desc' => 'Static JSON, no DB'],
                            'list_posts_duration' => ['title' => 'List Posts', 'desc' => 'DB query (Limit 20)'],
                            'single_post_duration' => ['title' => 'Single Post', 'desc' => 'DB Find by ID'],
                            'create_post_duration' => ['title' => 'Create Post', 'desc' => 'DB Insert + Validation'],
                            'heavy_duration' => ['title' => 'Heavy Compute', 'desc' => 'Fibonacci 30 + JSON loops']
                        ];
                        foreach ($scenarioMetrics as $key => $meta): 
                        ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="p-6">
                                    <p class="font-bold text-gray-200"><?= $meta['title'] ?></p>
                                    <p class="text-xs text-gray-500"><?= $meta['desc'] ?></p>
                                </td>
                                <?php foreach ($data as $runtime => $res): ?>
                                    <td class="p-6 text-right">
                                        <span class="text-xl font-mono font-medium"><?= isset($res['metrics'][$key]) ? number_format($res['metrics'][$key]['values']['avg'], 2) : 'N/A' ?></span>
                                        <span class="text-[10px] text-gray-500 ml-1">ms</span>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Raw Metrics Export -->
        <div class="text-center text-gray-500 text-sm">
            Detailed reports available in <code>./results/</code> directory.
        </div>
    </div>

    <script>
        const data = <?= json_encode($data) ?>;
        const colors = <?= json_encode($colors) ?>;
        const runtimes = Object.keys(data);

        // RPS Chart
        new Chart(document.getElementById('rpsChart'), {
            type: 'bar',
            data: {
                labels: runtimes.map(r => r.toUpperCase()),
                datasets: [{
                    label: 'Req/s',
                    data: runtimes.map(r => data[r].metrics.http_reqs.values.rate),
                    backgroundColor: runtimes.map(r => colors[r] + '44'),
                    borderColor: runtimes.map(r => colors[r]),
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9CA3AF' } },
                    x: { grid: { display: false }, ticks: { color: '#9CA3AF' } }
                },
                plugins: { legend: { display: false } }
            }
        });

        // Latency Chart (p95)
        new Chart(document.getElementById('latencyChart'), {
            type: 'bar',
            data: {
                labels: runtimes.map(r => r.toUpperCase()),
                datasets: [{
                    label: 'P95 ms',
                    data: runtimes.map(r => data[r].metrics.http_req_duration.values['p(95)']),
                    backgroundColor: runtimes.map(r => colors[r] + '44'),
                    borderColor: runtimes.map(r => colors[r]),
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9CA3AF' } },
                    x: { grid: { display: false }, ticks: { color: '#9CA3AF' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
file_put_contents(__DIR__ . '/benchmark-report.html', $html);
echo "Report generated: " . __DIR__ . "/benchmark-report.html\n";
