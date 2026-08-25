<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->handle(new Symfony\Component\Console\Input\ArgvInput());

$articles = App\Models\Article::where('is_published', true)->where('content', 'LIKE', '%**%جنيه%')->limit(3)->get();
foreach ($articles as $a) {
    echo "=== Article #{$a->id}: {$a->title} ===" . PHP_EOL;
    echo "product_id: " . ($a->product_id ?? 'null') . PHP_EOL;
    echo "Content (first 600): " . mb_substr($a->content, 0, 600) . PHP_EOL;
    echo PHP_EOL;
}
