<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\TicketAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file somebody attached.
 *
 * The one place in this platform where a customer uploads bytes that staff
 * will open. `original_name` is for display only — the stored path is
 * generated, because a filename from a browser is an attacker's string, not
 * a path.
 *
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property CarbonImmutable $created_at
 */
final class TicketAttachment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TicketAttachmentFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'ticket_attachments';

    protected $fillable = [
        'organization_id',
        'ticket_id',
        'reply_id',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'created_at',
    ];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketReply, $this>
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1).' '.$units[$unit];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
