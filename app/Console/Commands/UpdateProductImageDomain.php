<?php

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Models\Product;
use Illuminate\Console\Command;

class UpdateProductImageDomain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:update-domain
                            {--dry-run : Preview the changes without writing anything}
                            {--purge : Dispatch a Cloudflare cache purge for every changed article}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace the old media domain in every product image_url and any article image references.';

    /**
     * Old media domain replaced by the new one.
     */
    private const OLD_DOMAIN = 'media.amanstream.me';

    private const NEW_DOMAIN = 'media.amanprice.tech';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');

        $changedProducts = 0;
        $changedArticles = 0;
        $purgeUrls = [];

        // 1) المنتجات: image_url + raw_reviews_text
        Product::query()
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($dryRun, &$changedProducts) {
                foreach ($products as $product) {
                    $dirty = false;
                    $diffs = [];

                    foreach (['image_url', 'raw_reviews_text'] as $field) {
                        $value = (string) $product->{$field};

                        if ($value === '' || ! str_contains($value, self::OLD_DOMAIN)) {
                            continue;
                        }

                        $newValue = str_replace(self::OLD_DOMAIN, self::NEW_DOMAIN, $value);

                        if (! $dryRun) {
                            $product->{$field} = $newValue;
                        }
                        $dirty = true;
                        $diffs[] = "{$field}: ...".mb_substr($value, mb_strpos($value, self::OLD_DOMAIN) - 20, 70).'…';
                    }

                    if (! $dirty) {
                        continue;
                    }

                    $changedProducts++;

                    if (! $dryRun) {
                        $product->save();
                    }

                    $this->line("[Product {$product->id}] {$product->title}");
                    foreach ($diffs as $diff) {
                        $this->line("    {$diff}");
                    }
                }
            });

        // 2) المقالات: أي رابط صورة قديم داخل المحتوى أو الـ meta
        Article::query()
            ->orderBy('id')
            ->chunkById(200, function ($articles) use ($dryRun, $purge, &$changedArticles, &$purgeUrls) {
                foreach ($articles as $article) {
                    $dirty = false;
                    $diffs = [];

                    foreach (['title', 'meta_title', 'meta_description', 'content'] as $field) {
                        $value = (string) $article->{$field};

                        if ($value === '' || ! str_contains($value, self::OLD_DOMAIN)) {
                            continue;
                        }

                        $newValue = str_replace(self::OLD_DOMAIN, self::NEW_DOMAIN, $value);

                        if (! $dryRun) {
                            $article->{$field} = $newValue;
                        }
                        $dirty = true;
                        $diffs[] = "{$field}: ...".mb_substr($value, mb_strpos($value, self::OLD_DOMAIN) - 20, 70).'…';
                    }

                    if (! $dirty) {
                        continue;
                    }

                    $changedArticles++;

                    if (! $dryRun) {
                        $article->save();
                    }

                    if ($purge && $article->slug) {
                        $purgeUrls[] = route('articles.show', $article->slug, true);
                    }

                    $this->line("[Article {$article->id}] {$article->title}");
                    foreach ($diffs as $diff) {
                        $this->line("    {$diff}");
                    }
                }
            });

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry-run finished: {$changedProducts} product(s) and {$changedArticles} article(s) would be updated.");
        } else {
            $this->info("Updated image domain in {$changedProducts} product(s) and {$changedArticles} article(s).");
        }

        if ($purge && ! $dryRun && count($purgeUrls) > 0) {
            $uniqueUrls = array_values(array_unique($purgeUrls));

            foreach (array_chunk($uniqueUrls, 250) as $chunk) {
                PurgeCloudflareCacheJob::dispatch($chunk);
            }

            $this->info("Dispatched Cloudflare cache purge for ".count($uniqueUrls)." article URL(s).");
        } elseif ($purge) {
            $this->line('No changed article URLs to purge.');
        }

        return self::SUCCESS;
    }
}