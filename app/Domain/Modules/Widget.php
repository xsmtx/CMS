<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * Something a module wants shown on a dashboard.
 *
 * **Data, not a component.** A module shipping JavaScript would mean a
 * build step on every installation and an XSS surface inside the admin
 * single-page app; a module shipping rows means core renders them, escapes
 * them, and knows what it drew.
 *
 * The cost is that a module cannot draw a chart nobody anticipated. That is
 * the trade, and it is the right way round for a first SDK: widening what a
 * widget may contain later is easy, and taking back the ability to execute
 * is not.
 */
final readonly class Widget
{
    /**
     * @param  list<array{label: string, value: string, tone?: string}>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $rows = [],
        public ?string $linkLabel = null,
        public ?string $linkPath = null,
        public ?string $permission = null,
    ) {}
}
