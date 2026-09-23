<?php

declare(strict_types=1);

namespace App\Support\View;

use Illuminate\Support\Str;

/**
 * Renders operator-written Markdown for display.
 *
 * **Escaped first, rendered second.** An article body is written by an
 * operator, but it is stored in a database and reaches a page — and a
 * platform that lets database content emit raw HTML has one SQL injection
 * or one compromised staff account between it and script execution in
 * every customer's browser. Being written by a colleague is not a security
 * property.
 *
 * So the pipeline is: escape everything, then allow the small, fixed set
 * of Markdown constructs a knowledge base actually needs.
 */
final readonly class Markdown
{
    public function render(string $source): string
    {
        // Laravel's converter escapes HTML in the source by default, which
        // is exactly the behaviour wanted here: `<script>` in an article
        // body renders as visible text, not as a tag.
        return (string) Str::markdown($source, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * A short, tag-free summary for a listing.
     */
    public function excerpt(string $source, int $characters = 180): string
    {
        $text = trim(strip_tags($this->render($source)));

        return Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, $characters);
    }
}
