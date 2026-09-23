<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Support\Exceptions\AttachmentRefused;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketAttachment;
use App\Infrastructure\Support\Models\TicketReply;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Takes a file a customer uploaded.
 *
 * The one place in this platform where somebody outside hands it bytes that
 * staff will later open, so it is deliberately suspicious:
 *
 * - **Extension and MIME are both checked**, against allow-lists. Either
 *   alone is trivially fooled: an extension is a string the browser sent,
 *   and a MIME type is a string the browser sent.
 * - **The stored name is generated.** A filename from a browser is an
 *   attacker's string, not a path; `original_name` is kept for display and
 *   never used to build one.
 * - **Nothing lands in the public directory.** Files are served by a
 *   controller that checks who is asking.
 */
final readonly class StoreAttachment
{
    public function handle(Ticket $ticket, UploadedFile $file, ?TicketReply $reply = null): TicketAttachment
    {
        $this->assertAcceptable($file);

        $path = $file->storeAs(
            'tickets/'.$ticket->id,
            Str::ulid().'.'.Str::lower($file->getClientOriginalExtension()),
            ['disk' => $this->disk()],
        );

        if ($path === false) {
            throw AttachmentRefused::couldNotStore();
        }

        return TicketAttachment::query()->create([
            'organization_id' => $ticket->organization_id,
            'ticket_id' => $ticket->id,
            'reply_id' => $reply?->id,
            // Display only. Never used to build a path.
            'original_name' => Str::limit($file->getClientOriginalName(), 190, ''),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        /** @var list<string> $extensions */
        $extensions = config('platform.support.attachments.allowed_extensions', []);
        /** @var list<string> $mimes */
        $mimes = config('platform.support.attachments.allowed_mime_types', []);
        $maxKilobytes = (int) config('platform.support.attachments.max_kilobytes', 5120);

        $extension = Str::lower($file->getClientOriginalExtension());

        if (! in_array($extension, $extensions, strict: true)) {
            throw AttachmentRefused::extension($extension);
        }

        // Checked as well, not instead: an extension is a string the
        // browser sent, and so is a content type.
        if (! in_array((string) $file->getMimeType(), $mimes, strict: true)) {
            throw AttachmentRefused::mimeType((string) $file->getMimeType());
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw AttachmentRefused::tooLarge($maxKilobytes);
        }
    }

    private function disk(): string
    {
        return (string) config('platform.support.attachments.disk', 'local');
    }
}
