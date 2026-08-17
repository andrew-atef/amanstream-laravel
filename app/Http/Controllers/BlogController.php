<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Services\ShortcodeParser;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function __construct(private readonly ShortcodeParser $shortcodeParser) {}

    /**
     * Editorial blog hub: paginated published guides/blog posts with the
     * categories that actually publish blog content.
     */
    public function index(): View
    {
        $posts = Article::query()
            ->with('category')
            ->where('type', 'blog')
            ->where('is_published', true)
            ->latest()
            ->paginate(12);

        $blogCategories = Category::query()
            ->whereHas('articles', fn ($query) => $query->where('type', 'blog')->where('is_published', true))
            ->orderBy('name')
            ->get();

        return view('blog.index', [
            'posts' => $posts,
            'blogCategories' => $blogCategories,
        ]);
    }

    /**
     * Render a single published blog post with parsed content, related blog
     * posts from the same category and a clean editorial layout (no product).
     */
    public function show(string $slug): View
    {
        $post = Article::query()
            ->with('category')
            ->where('slug', $slug)
            ->where('type', 'blog')
            ->where('is_published', true)
            ->firstOrFail();

        $parsedContent = $this->shortcodeParser->parse($post);

        $relatedQuery = Article::query()
            ->with('category')
            ->where('type', 'blog')
            ->where('is_published', true)
            ->where('id', '!=', $post->id);

        // Category is optional for blog posts: only narrow by it when present,
        // otherwise fall back to the latest editorial posts site-wide.
        if ($post->category_id !== null) {
            $relatedQuery->where('category_id', $post->category_id);
        }

        $relatedPosts = $relatedQuery
            ->latest()
            ->limit(6)
            ->get();

        return view('blog.show', [
            'post' => $post,
            'parsedContent' => $parsedContent,
            'relatedPosts' => $relatedPosts,
        ]);
    }
}
