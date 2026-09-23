<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Models;

use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a message says, in one language.
 *
 * Data rather than a class, because a white-label platform whose
 * customer-facing sentences live in PHP cannot be white-labelled. An
 * operator edits, previews and resets; the shipped wording is the fallback,
 * so a reset restores rather than blanks.
 *
 * @property NotificationEvent $event
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property string|null $action_label
 * @property bool $is_customised
 * @property bool $is_active
 */
final class NotificationTemplate extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'notification_templates';

    protected $fillable = [
        'organization_id',
        'event',
        'locale',
        'subject',
        'body',
        'action_label',
        'is_customised',
        'is_active',
    ];

    /**
     * @var array<string, bool>
     */
    protected $attributes = ['is_customised' => true, 'is_active' => true];

    public function auditLabel(): string
    {
        return $this->event->value.' ('.$this->locale.')';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'is_customised' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
