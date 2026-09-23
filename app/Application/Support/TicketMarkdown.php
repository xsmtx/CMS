<?php

declare(strict_types=1);

namespace App\Application\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;

/**
 * A ticket body, rendered.
 *
 * What is stored is what the person typed. Rendering happens on the way
 * out, in this one class, because the escaping is the whole point and a
 * second renderer is a second chance to forget it.
 *
 * **Everything the author wrote is data, never markup.** `html_input:
 * strip` removes any HTML in the source rather than escaping it into view,
 * and unsafe links — `javascript:`, `data:` — are dropped. A support inbox
 * is the single most attractive place in a hosting platform to put a
 * script tag: anybody can open a ticket, and an operator will read it.
 *
 * Read by both areas. An operator seeing bold text while the customer sees
 * `**bold**` is exactly the drift that makes a support thread confusing to
 * everyone in it.
 */
final readonly class TicketMarkdown
{
    public function toHtml(string $body): string
    {
        $environment = new Environment([
            // Author-supplied HTML is removed rather than escaped: a
            // ticket has no legitimate reason to carry markup, and
            // "escaped so it renders as text" is one config change away
            // from "rendered".
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);

        try {
            return (new MarkdownConverter($environment))->convert($body)->getContent();
        } catch (Throwable) {
            // A body that cannot be parsed is still a body somebody needs
            // to read. Shown as the text it is rather than as an error
            // page: a support thread that will not open is worse than one
            // that lost its formatting.
            return '<p>'.nl2br(e($body)).'</p>';
        }
    }
}
