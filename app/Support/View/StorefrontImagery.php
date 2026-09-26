<?php

declare(strict_types=1);

namespace App\Support\View;

use Illuminate\Support\Str;

/**
 * The pictures a shop needs, and whether this installation has them yet.
 *
 * DESIGN.md's own one-line description of the language is "photography-first":
 * a tile is a product resting on a surface, and the type frames it. So the
 * storefront has image slots, and this answers whether a slot is filled.
 *
 * **Nothing is invented when one is empty.** No placeholder frame, no grey
 * rectangle with a camera glyph, no gradient standing in for a photograph - a
 * public shop showing a picture of a missing picture is worse than one showing
 * none, and a tile composed for an image reads perfectly well as a typographic
 * tile when the image is absent. The empty state is the absence.
 *
 * Files live in `public/storefront/`, because `img-src` is `'self' data:
 * blob:` and an external image host is refused by the installation's own
 * content-security policy.
 *
 * The naming is a convention rather than a column on purpose: an operator
 * drops a file in and the shop picks it up, with no migration, no upload
 * screen and no decision about where a theme's assets live. A theme that wants
 * its own art overrides the template.
 */
final readonly class StorefrontImagery
{
    /** Under `public/`. */
    private const string ROOT = 'storefront';

    /** In the order they are tried: whichever the operator happened to export. */
    private const array EXTENSIONS = ['webp', 'png', 'avif', 'jpg'];

    /**
     * The hero render: a transparent PNG or WebP, about 2000x1200, resting on
     * the dark tile.
     */
    public function hero(): ?string
    {
        return $this->find('hero');
    }

    /**
     * One product, square, about 800x800 and transparent.
     *
     * Keyed by the product's slug, which is what a URL already uses - so the
     * file for `/store/starter` is `starter.png` and nobody has to look
     * anything up.
     */
    public function product(string $slug): ?string
    {
        return $this->find('products/'.Str::slug($slug));
    }

    /** What an operator should put where, for the empty-state sentence. */
    public function heroPath(): string
    {
        return self::ROOT.'/hero.png';
    }

    public function productPath(string $slug): string
    {
        return self::ROOT.'/products/'.Str::slug($slug).'.png';
    }

    private function find(string $name): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            $relative = self::ROOT.'/'.$name.'.'.$extension;

            if (is_file(public_path($relative))) {
                // The modification time busts a cached render when somebody
                // replaces the file without renaming it, which is what an
                // operator iterating on artwork does every time.
                return asset($relative).'?v='.filemtime(public_path($relative));
            }
        }

        return null;
    }
}
