<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\RenderedMessage;
use App\Domain\Shared\Money;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;

/**
 * Turns an event and some data into words.
 *
 * Three fallbacks, in order, because a message that cannot be rendered is
 * worse than a plain one:
 *
 * 1. An operator's template for this event **in the recipient's locale**.
 * 2. An operator's template in the installation's default locale — better a
 *    customised message in the wrong language than a shipped one, because
 *    the customisation usually carries the brand.
 * 3. The shipped wording from `lang/`, which is always present.
 *
 * Placeholders are `:name` style, matching the rest of the platform. A
 * placeholder with no value is **left as itself** rather than blanked: a
 * visible `:invoice_number` in an email is a bug report from the message,
 * and an empty space is a bug nobody notices.
 *
 * A date or an amount is handed over **as itself**, not as a string, and is
 * worded here — in the locale this message is being rendered in. A caller
 * that formatted first could only use the locale of whichever process
 * happened to dispatch the event, which is how a Turkish invoice email came
 * to say `2026-10-11` among its Turkish sentences.
 */
final readonly class RenderTemplate
{
    /**
     * @param  array<string, string|int|float|CarbonInterface|Money|null>  $data
     */
    public function handle(
        NotificationEvent $event,
        string $locale,
        array $data = [],
        ?string $actionUrl = null,
    ): RenderedMessage {
        $template = $this->templateFor($event, $locale);

        // The locale the words will actually be in, which is the one the
        // dates and the amounts have to agree with.
        $rendered = $template === null ? $locale : $template->locale;
        $values = $this->worded($data, $rendered);

        $subject = $template === null
            ? $this->shipped($event, 'subject', $locale)
            : $template->subject;

        $body = $template === null
            ? $this->shipped($event, 'body', $locale)
            : $template->body;

        $actionLabel = $template === null || $template->action_label === null
            ? $this->shippedOrNull($event, 'action', $locale)
            : $template->action_label;

        return new RenderedMessage(
            event: $event,
            subject: $this->substitute($subject, $values),
            body: $this->substitute($body, $values),
            locale: $rendered,
            actionUrl: $actionUrl,
            actionLabel: $actionLabel === null ? null : $this->substitute($actionLabel, $values),
            data: $values,
        );
    }

    private function templateFor(NotificationEvent $event, string $locale): ?NotificationTemplate
    {
        $templates = NotificationTemplate::query()
            ->where('event', $event->value)
            ->where('is_active', true)
            ->whereIn('locale', array_unique([$locale, $this->fallbackLocale()]))
            ->get();

        return $templates->firstWhere('locale', $locale)
            ?? $templates->firstWhere('locale', $this->fallbackLocale());
    }

    private function shipped(NotificationEvent $event, string $part, string $locale): string
    {
        $key = 'notifications.messages.'.$event->translationKey().'.'.$part;
        $line = Lang::get($key, [], $locale);

        // A missing shipped string renders as its key rather than as
        // nothing, so it is reported rather than silently sent.
        return is_string($line) ? $line : $key;
    }

    private function shippedOrNull(NotificationEvent $event, string $part, string $locale): ?string
    {
        $key = 'notifications.messages.'.$event->translationKey().'.'.$part;
        $line = Lang::get($key, [], $locale);

        return is_string($line) && $line !== $key ? $line : null;
    }

    /**
     * Every value as the words it will appear as.
     *
     * A date is written long (11 October 2026, 11 Ekim 2026) rather than
     * ISO: a message is prose, and the one machine-readable string among
     * the sentences is the one that reads as a mistake.
     *
     * @param  array<string, string|int|float|CarbonInterface|Money|null>  $data
     * @return array<string, string>
     */
    private function worded(array $data, string $locale): array
    {
        $worded = [];

        foreach ($data as $key => $value) {
            $worded[$key] = match (true) {
                $value === null => '',
                $value instanceof CarbonInterface => $value->locale($locale)->isoFormat('LL'),
                $value instanceof Money => $value->format($locale),
                default => (string) $value,
            };
        }

        return $worded;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function substitute(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $text = str_replace(':'.$key, $value, $text);
        }

        return $text;
    }

    private function fallbackLocale(): string
    {
        return (string) config('app.fallback_locale', 'en');
    }
}
