<?php

declare(strict_types=1);

use App\Support\View\StorefrontImagery;

/**
 * The shop's artwork, and the two things about it that can quietly break.
 *
 * A file's **size** is read from the file rather than written into a
 * template, because the document reserves a box from those two numbers and a
 * box of the wrong shape is empty space nobody can find in a stylesheet. And
 * a file that is not an image at all has to read as no picture: the tiles are
 * composed to work without one, and a broken `<img>` on a public shop is
 * worse than a tile that never mentioned a picture.
 */
function storefrontFile(string $relative, string $bytes): void
{
    $path = public_path('storefront/'.$relative);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, recursive: true);
    }

    file_put_contents($path, $bytes);

    test()->beforeApplicationDestroyed(fn (): bool => @unlink($path));
}

/** The smallest valid PNG there is: 1x1, transparent. */
function onePixelPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
        .'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        strict: true,
    );
}

it('has nothing to say when the file is not there', function (): void {
    expect(app(StorefrontImagery::class)->product('no-such-plan'))->toBeNull();
});

it('takes a picture size from the file rather than from a template', function (): void {
    storefrontFile('products/measured-plan.png', onePixelPng());

    $picture = app(StorefrontImagery::class)->product('measured-plan');

    expect($picture)->not->toBeNull()
        ->and($picture?->width)->toBe(1)
        ->and($picture?->height)->toBe(1)
        ->and($picture?->url)->toContain('storefront/products/measured-plan.png')
        // The modification time, so replacing the artwork in place is seen.
        ->and($picture?->url)->toContain('?v=');
});

it('reads a file it cannot measure as no picture at all', function (): void {
    storefrontFile('products/broken-plan.png', 'this is not a png');

    expect(app(StorefrontImagery::class)->product('broken-plan'))->toBeNull();
});
