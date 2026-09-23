<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use App\Domain\Notifications\RenderedMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One mailable for every message this platform sends.
 *
 * There is no `OrderPaidMail`, `InvoiceIssuedMail` and so on, because the
 * wording lives in a template row an operator edits. A class per event
 * would put half the message in the database and half in PHP, which is the
 * worst of both.
 *
 * The **from** address and name come from the brand, so a reseller's
 * customer gets a message from the reseller. Only the identity: the
 * credentials that actually send it stay in configuration, because a
 * from-address an operator can type is not a mail account they should be
 * able to take over.
 */
final class TemplatedMessage extends Mailable
{
    public function __construct(public readonly RenderedMessage $message) {}

    public function envelope(): Envelope
    {
        $brand = $this->message->brand;

        return new Envelope(
            from: $brand?->emailFromAddress === null
                ? null
                : new Address($brand->emailFromAddress, $brand->emailFromName ?? $brand->name),
            subject: $this->message->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.templated',
            with: [
                'body' => $this->message->body,
                'actionUrl' => $this->message->actionUrl,
                'actionLabel' => $this->message->actionLabel,
                'brand' => $this->message->brand,
            ],
        );
    }
}
