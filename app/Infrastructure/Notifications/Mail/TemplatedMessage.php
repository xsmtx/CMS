<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use App\Domain\Notifications\RenderedMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One mailable for every message this platform sends.
 *
 * There is no `OrderPaidMail`, `InvoiceIssuedMail` and so on, because the
 * wording lives in a template row an operator edits. A class per event
 * would put half the message in the database and half in PHP, which is the
 * worst of both.
 */
final class TemplatedMessage extends Mailable
{
    public function __construct(public readonly RenderedMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.templated',
            with: [
                'body' => $this->message->body,
                'actionUrl' => $this->message->actionUrl,
                'actionLabel' => $this->message->actionLabel,
            ],
        );
    }
}
