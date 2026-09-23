<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\NotificationPreferenceRequest;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Support\Identity\CurrentCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What we have told this customer, and what they want to hear about.
 *
 * The preferences screen says which categories are always sent rather than
 * showing a switch that does nothing: a disabled checkbox with no
 * explanation reads as a bug.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly CurrentCustomer $customer) {}

    public function index(): Response
    {
        $contact = $this->customer->contact();

        $notifications = InAppNotification::query()
            ->where('notifiable_type', $contact::class)
            ->where('notifiable_id', $contact->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return Inertia::render('Client/Notifications/Index', [
            'notifications' => $notifications
                ->map(static fn (InAppNotification $notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'actionUrl' => $notification->action_url,
                    'readAt' => $notification->read_at?->toIso8601String(),
                    'createdAt' => $notification->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'preferences' => array_values(array_map(
                fn (NotificationCategory $category): array => [
                    'value' => $category->value,
                    'label' => (string) __($category->labelKey()),
                    'enabled' => (bool) $contact->getAttribute($category->preferenceColumn()),
                    // Said rather than shown as a dead switch.
                    'alwaysSent' => $this->hasTransactionalMessages($category),
                ],
                NotificationCategory::cases(),
            )),
        ]);
    }

    public function markRead(): RedirectResponse
    {
        $contact = $this->customer->contact();

        InAppNotification::query()
            ->where('notifiable_type', $contact::class)
            ->where('notifiable_id', $contact->id)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);

        return back();
    }

    public function updatePreferences(NotificationPreferenceRequest $request): RedirectResponse
    {
        $contact = $this->customer->contact();

        $contact->forceFill([
            'notify_invoices' => $request->boolean(NotificationCategory::Invoices->value),
            'notify_support' => $request->boolean(NotificationCategory::Support->value),
            'notify_product' => $request->boolean(NotificationCategory::Product->value),
            'notify_marketing' => $request->boolean(NotificationCategory::Marketing->value),
        ])->save();

        return back()->with('status', __('crm.profile_updated'));
    }

    /**
     * Whether anything in this category goes out regardless.
     */
    private function hasTransactionalMessages(NotificationCategory $category): bool
    {
        return array_any(NotificationEvent::forCategory($category), fn (NotificationEvent $event): bool => $event->isTransactional());
    }
}
