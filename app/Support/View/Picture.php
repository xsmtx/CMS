<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * One piece of the shop's artwork, and the size it actually is.
 *
 * The dimensions come from the file rather than from a number written into a
 * template, because the template cannot know them: an operator drops a file
 * into `public/storefront/` and it is whatever shape they exported. A
 * hard-coded `width="2000" height="1200"` next to a 2000x860 render reserves a
 * box a third taller than the picture and `object-contain` centres the
 * artwork inside it - which reads as a gap under the product that is in no
 * stylesheet, because it is not in one.
 *
 * They are still written into the document: without them the page reflows
 * when the image arrives, which is the thing the attributes exist to stop.
 */
final readonly class Picture
{
    public function __construct(
        public string $url,
        public int $width,
        public int $height,
    ) {}
}
