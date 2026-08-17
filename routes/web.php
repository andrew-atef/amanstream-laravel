<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\WellKnownController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// JSON-RPC 2.0 Model Context Protocol endpoint consumed by the in-browser
// WebMCP bridge (document.modelContext) — POST only, CSRF-exempt, CORS-enabled.
Route::match(['post', 'options'], '/mcp', [McpController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->name('mcp');

// Clean, crawlable category hub URLs (replaces the faceted `?category=` links).
Route::get('/category/{slug}', [HomeController::class, 'index'])->name('categories.show');

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/articles/{slug}', [ArticleController::class, 'show'])
    ->name('articles.show');

// Editorial blog / guides hub (dual-article architecture: blog posts are not
// reviews, so they live under /blog and never render product components).
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');

Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

Route::get('/llms.txt', [LlmsTxtController::class, 'index'])
    ->name('llms.txt');

// RFC 9727 protocol discovery for AI agents / crawlers.
Route::get('/.well-known/api-catalog', [WellKnownController::class, 'catalog'])
    ->name('api.catalog');

Route::get('/auth.md', [WellKnownController::class, 'authGuide'])
    ->name('auth.md');

Route::get('/openapi.json', [WellKnownController::class, 'openApi'])
    ->name('openapi');

Route::get('/docs', [WellKnownController::class, 'docs'])
    ->name('docs');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])
    ->name('sitemap');

// Agent discovery documents (Level 5 Agent-Native): A2A agent card + the
// Agent Skills discovery index and skill payloads. All are read-only.
Route::get('/.well-known/agent-card.json', [WellKnownController::class, 'agentCard'])
    ->name('agent.card');

Route::get('/.well-known/agent-skills/index.json', [WellKnownController::class, 'agentSkillsIndex'])
    ->name('agent.skills.index');

Route::get('/.well-known/agent-skills/{skill}/SKILL.md', [WellKnownController::class, 'agentSkill'])
    ->where('skill', '[A-Za-z0-9_-]+')
    ->name('agent.skills.skill');

// Served from the controller (not public/) so Content-Type is always
// application/json and the Link header's `type="application/json"` stays true.
Route::get('/.well-known/mcp/server-card.json', [WellKnownController::class, 'mcpServerCard'])
    ->name('mcp.server-card');
Route::get('/.well-known/http-message-signatures-directory', [WellKnownController::class, 'webBotAuthDirectory'])
    ->name('web-bot-auth.directory');

Route::get('/robots.txt', function () {
    $sitemapUrl = config('app.url').'/sitemap.xml';

    // ملاحظة: في الإنتاج تُسبَق هذه القواعد بكتلة "Cloudflare Managed"
    // تُدار على الحافة (edge) للتحكم في روبوتات الذكاء الاصطناعي (GPTBot,
    // ClaudeBot, Bytespider, Google-Extended وغيرها). نكتفي هنا بقواعد
    // محركات البحث الأساسية مع حماية /admin و /cart لأي روبوت آخر.
    $robots = <<<TXT
User-agent: Googlebot
Disallow:

User-agent: Bingbot
Disallow:

User-agent: * 
Disallow: /admin
Disallow: /cart

Sitemap: {$sitemapUrl}
TXT;

    return response($robots)->header('Content-Type', 'text/plain');
})->name('robots');

Route::get('/{key}.txt', function (string $key) {
    $configuredKey = config('services.indexnow.key');

    if (blank($configuredKey) || $key !== $configuredKey) {
        abort(404);
    }

    return response($configuredKey)->header('Content-Type', 'text/plain');
})->where('key', '[A-Za-z0-9_-]+')->name('indexnow.key');