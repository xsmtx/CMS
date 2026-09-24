<?php

declare(strict_types=1);

namespace InfraCMS\NotificationsNetgsm;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Netgsm, over its HTTP API.
 *
 * **A text message costs money and is measured in characters**, which makes it
 * unlike every other channel here. Two consequences are built in rather than
 * left to whoever writes the templates:
 *
 * - **The body is the subject and the link, and nothing else.** A notification's
 *   body is written for somebody reading an email; sending it as SMS would be
 *   several messages, charged as several, saying the same thing.
 * - **It is truncated, and the link is what survives.** The subject is cut to
 *   fit around the URL rather than the URL being cut, because half a URL is a
 *   message that cost money and did nothing.
 *
 * **A number is normalised before it is sent.** Stored numbers have spaces,
 * plus signs and parentheses in them, and Netgsm wants digits. A number with no
 * international prefix is given the configured one — and a number that ends up
 * implausible is refused here rather than paid for.
 *
 * **Netgsm answers 200 with a numeric code**, where `00` and `01` are success
 * and everything else is an error. A channel that read the HTTP status would
 * count every rejection as sent.
 *
 * It never throws: a provider that is down must not take down the operation that
 * triggered the message.
 *
 * It has never sent a real message. Written against the published documentation
 * and tested against faked HTTP.
 */
final readonly class NetgsmChannel implements DeliversNotifications
{
    private const ENDPOINT = 'https://api.netgsm.com.tr/sms/send/get';

    /** One GSM message. Beyond this Netgsm charges per part. */
    private const LENGTH = 155;

    public function __construct(
        private string $username,
        private string $password,
        private string $header,
        private string $defaultCountryCode = '90',
        private int $timeout = 15,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
    {
        $number = $this->normalise($recipient->phone);

        if ($number === null) {
            // Refused here rather than paid for: an implausible number is a
            // message that costs money and reaches nobody.
            return new DeliveryOutcome(delivered: false, error: 'That is not a number this can text.');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 250, throw: false)
                ->get(self::ENDPOINT, [
                    'usercode' => $this->username,
                    'password' => $this->password,
                    'gsmno' => $number,
                    'message' => $this->body($message),
                    'msgheader' => $this->header,
                    'dil' => 'TR',
                ]);
        } catch (Throwable $exception) {
            return new DeliveryOutcome(delivered: false, error: $exception->getMessage());
        }

        // `00 <jobid>` or `01 <jobid>` is accepted; anything else is a code
        // that means no, in a 200.
        $body = trim($response->body());
        [$code, $reference] = array_pad(explode(' ', $body, 2), 2, '');

        if (! $response->successful() || ! in_array($code, ['00', '01'], true)) {
            return new DeliveryOutcome(delivered: false, error: $this->reason($code));
        }

        return new DeliveryOutcome(delivered: true, reference: $reference === '' ? null : $reference);
    }

    /**
     * The subject and the link, cut to one message, with the link intact.
     */
    private function body(RenderedMessage $message): string
    {
        $link = $message->actionUrl ?? '';

        if ($link === '') {
            return mb_substr(trim($message->subject), 0, self::LENGTH);
        }

        // The subject gives way, never the URL: half a link is a message that
        // cost money and did nothing.
        $room = self::LENGTH - mb_strlen($link) - 1;

        $subject = $room > 0 ? mb_substr(trim($message->subject), 0, $room) : '';

        return trim($subject.' '.$link);
    }

    /**
     * Digits only, with a country code, or null.
     */
    private function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        // A local number written with a leading zero, as most people store it.
        if (str_starts_with($digits, '0')) {
            $digits = $this->defaultCountryCode.ltrim($digits, '0');
        } elseif (mb_strlen($digits) <= 10) {
            $digits = $this->defaultCountryCode.$digits;
        }

        // Shorter than a country code and a subscriber number, or longer than
        // E.164 allows, is not a number anybody can be texted at.
        return mb_strlen($digits) >= 10 && mb_strlen($digits) <= 15 ? $digits : null;
    }

    private function reason(string $code): string
    {
        return match ($code) {
            '20' => 'Netgsm refused the message text.',
            '30' => 'Netgsm refused the credentials.',
            '40' => 'That sender header is not registered with Netgsm.',
            '50', '51' => 'This Netgsm account cannot send that message.',
            '70' => 'Netgsm refused one of the parameters.',
            default => 'Netgsm refused the message ('.($code === '' ? 'no code' : $code).').',
        };
    }
}
