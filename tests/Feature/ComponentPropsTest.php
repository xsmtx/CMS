<?php

declare(strict_types=1);

/**
 * A prop a primitive does not have is an attribute that does nothing.
 *
 * Vue is happy to let a caller pass anything: an unknown prop falls through
 * to the root element as an attribute, silently. On a `<div>` that is often
 * harmless. On a `<tr>` it is a bug that renders perfectly.
 *
 * `AppTableRow` is the case that taught it. Two screens passed `:href` to it
 * — a row that opens the record is an obvious thing to want — and the
 * component has no such prop, so `href` landed on a `<tr>`, which browsers
 * ignore. One of those screens was Addressing, whose prefix detail page was
 * therefore a screen nothing in the product linked to: routed, tested,
 * rendered, and unreachable by clicking.
 *
 * A `<tr>` cannot be wrapped in an anchor, so the convention here is a link
 * in the identity cell. This refuses the mistake rather than the pattern.
 *
 * The check is a scan rather than a mount, because the failure is not in any
 * one component's behaviour: it is a caller writing an attribute the callee
 * never reads, and only reading every caller finds it.
 */

/**
 * Props each primitive genuinely accepts, for the ones where a fall-through
 * is invisible rather than merely untidy.
 *
 * Deliberately short. A list of every prop of every primitive would be a
 * second copy of the components, kept in step by hand and wrong within a
 * month; this names only the elements where an ignored attribute cannot be
 * seen on the screen.
 *
 * @return array<string, list<string>>
 */
function silentlyIgnoringProps(): array
{
    return [
        // Renders a `<tr>`. Anything else falls through and does nothing.
        // `key` and the directives are Vue's own and are not props.
        'AppTableRow' => ['id', 'label', 'key', 'v-for', 'v-if', 'v-else-if', 'v-show', 'class'],
    ];
}

/**
 * @return array<string, string>
 */
function vueSources(): array
{
    $root = dirname(__DIR__, 2).'/resources/js';
    $sources = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'vue') {
            continue;
        }

        $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

it('never passes a primitive a prop it does not have', function (): void {
    $offences = [];

    foreach (silentlyIgnoringProps() as $component => $allowed) {
        foreach (vueSources() as $path => $contents) {
            preg_match_all('/<'.$component.'\b([^>]*)>/s', $contents, $matches);

            foreach ($matches[1] as $attributes) {
                // `:name`, `v-bind:name`, `name=` and `@event` — the shorthands
                // a caller actually writes.
                preg_match_all('/(?:^|\s)(?::|v-bind:)?([a-zA-Z][\w-]*)=/', $attributes, $found);

                foreach ($found[1] as $attribute) {
                    // `some-prop` and `someProp` are the same prop.
                    $normalised = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $attribute))));

                    if (in_array($attribute, $allowed, strict: true)
                        || in_array($normalised, $allowed, strict: true)) {
                        continue;
                    }

                    $offences[] = basename($path).' passes '.$attribute.' to '.$component;
                }
            }
        }
    }

    expect($offences)->toBe([], implode('; ', $offences));
});

it('is actually reading the components', function (): void {
    // An audit that checks nothing passes. Both halves: there are Vue files,
    // and the component this is about is used by some of them.
    $usages = 0;

    foreach (vueSources() as $contents) {
        $usages += substr_count($contents, '<AppTableRow');
    }

    expect(vueSources())->not->toBeEmpty()
        ->and($usages)->toBeGreaterThan(10);
});
