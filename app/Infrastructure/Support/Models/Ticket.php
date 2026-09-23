<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Models;

use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation with a due date.
 *
 * The dates are the point. A ticket without a clock is an inbox, and an
 * inbox is where support requests go to be forgotten. `first_responded_at`
 * is stamped by the first **public** staff reply; an internal note is not
 * an answer to the customer, however useful it is to the agent.
 *
 * @property string $number
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property CarbonImmutable|null $first_response_due_at
 * @property CarbonImmutable|null $resolution_due_at
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $last_reply_at
 * @property string|null $last_reply_by
 * @property CarbonImmutable|null $created_at
 */
final class Ticket extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'tickets';

    protected $fillable = [
        'organization_id',
        'number',
        'customer_id',
        'contact_id',
        'department_id',
        'assigned_to',
        'service_id',
        'domain_id',
        'invoice_id',
        'subject',
        'status',
        'priority',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'resolved_at',
        'last_reply_at',
        'last_reply_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<TicketReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->oldest();
    }

    /**
     * What the customer is allowed to read.
     *
     * A separate relation rather than a filter the caller remembers:
     * forgetting `where is_internal = false` once puts an agent's private
     * note in front of the person it was about.
     *
     * @return HasMany<TicketReply, $this>
     */
    public function publicReplies(): HasMany
    {
        return $this->replies()->where('is_internal', false);
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Whether the first-response promise has already been broken.
     */
    public function hasBreachedFirstResponse(): bool
    {
        $due = $this->first_response_due_at;

        if ($due === null) {
            return false;
        }

        // A late answer is still a breach: the comparison is against when
        // the answer came, not against now.
        return ($this->first_responded_at ?? CarbonImmutable::now())->isAfter($due);
    }

    public function minutesUntilFirstResponseDue(): ?int
    {
        $due = $this->first_response_due_at;

        return $due === null || $this->first_responded_at !== null
            ? null
            : (int) CarbonImmutable::now()->diffInMinutes($due, false);
    }

    public function auditLabel(): string
    {
        return $this->number.' — '.$this->subject;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeAwaitingUs(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::CustomerReply->value,
        ]);
    }

    /**
     * Tickets whose first-response clock has run out.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeBreachingSla(Builder $query): Builder
    {
        return $query
            ->whereNull('first_responded_at')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<', CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'first_response_due_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'last_reply_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
