<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\TicketAttachment;
use App\Support\Identity\CurrentActor;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a file somebody attached to a ticket.
 *
 * A controller rather than a public path, because the question "may this
 * person read this file" has an answer and a public directory cannot ask
 * it. Staff with the permission may read any; a customer may read only
 * files on their own tickets.
 *
 * The download is forced and the stored content type is sent back as-is,
 * so a file that claims to be HTML is not rendered as HTML in the
 * platform's own origin.
 */
final class AttachmentController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function show(string $attachment): StreamedResponse
    {
        $record = TicketAttachment::query()
            ->with('ticket')
            ->find($attachment);

        if (! $record instanceof TicketAttachment || ! $this->mayRead($record)) {
            // Not 403: a 403 confirms the file exists.
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('platform.support.attachments.disk', 'local'));

        if (! $disk->exists($record->path)) {
            throw new NotFoundHttpException;
        }

        return $disk->download($record->path, $record->original_name, [
            'Content-Type' => $record->mime_type,
            // Never rendered inline in our own origin.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mayRead(TicketAttachment $attachment): bool
    {
        $actor = $this->actor->model();

        if ($actor instanceof StaffUser) {
            return $actor->can('support.tickets.view');
        }

        if ($actor instanceof Contact) {
            return $attachment->ticket?->customer_id === $actor->customer_id;
        }

        return false;
    }
}
