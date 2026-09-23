<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\TicketReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a ticket.
 *
 * `is_internal` is the most consequential boolean in this phase: it decides
 * whether the customer sees the text, and whether the first-response clock
 * stops. The author's name is copied rather than joined, so a deleted staff
 * account does not blank out a year of history.
 *
 * @property string $author_type
 * @property string|null $author_id
 * @property string $author_name
 * @property string $body
 * @property bool $is_internal
 * @property CarbonImmutable $created_at
 */
final class TicketReply extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TicketReplyFactory> */
    use HasFactory;

    use HasUlids;

    public const string AUTHOR_STAFF = 'staff';

    public const string AUTHOR_CUSTOMER = 'customer';

    public const string AUTHOR_SYSTEM = 'system';

    public $timestamps = false;

    protected $table = 'ticket_replies';

    protected $fillable = [
        'organization_id',
        'ticket_id',
        'author_type',
        'author_id',
        'author_name',
        'body',
        'is_internal',
        'created_at',
    ];

    /**
     * @var array<string, bool>
     */
    protected $attributes = ['is_internal' => false];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'reply_id');
    }

    public function isFromStaff(): bool
    {
        return $this->author_type === self::AUTHOR_STAFF;
    }

    /**
     * Whether this reply answers the customer.
     *
     * A public staff reply does. An internal note does not, however much
     * work went into it.
     */
    public function answersCustomer(): bool
    {
        return $this->isFromStaff() && ! $this->is_internal;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
