<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Marketplace\InstallFromMarketplace;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;
use App\Domain\Modules\Exceptions\InvalidModule;
use App\Http\Controllers\Controller;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the vendor offers, and one button that fetches it.
 *
 * Owner only, beside Modules and for the same reason: installing a package is
 * the first half of an act whose second half runs somebody else's code on this
 * machine, and an Administrator holds every staff permission by design.
 *
 * The screen shows the catalogue **merged with what is already installed**, so
 * that "Installed", "A newer version exists" and "Install" are three states of
 * one row rather than two lists an operator has to compare by eye.
 *
 * Installing here still does not enable. The row lands in `installed` and the
 * Modules screen is where somebody agrees to run it — which is why this screen
 * sends them there rather than growing a second Enable button.
 */
final class MarketplaceController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly MarketplaceClient $marketplace,
    ) {}

    public function index(): Response
    {
        $this->assertOwner();

        $enabled = (bool) config('platform.modules.enabled', true);

        $installed = ModuleRecord::query()
            ->get()
            ->keyBy('slug');

        $packages = array_map(
            fn (Listing $listing): array => $this->row($listing, $installed->get($listing->slug)),
            // An unreachable vendor is an empty catalogue, not an error: nothing
            // an installation depends on is behind this call.
            $enabled ? $this->marketplace->catalogue() : [],
        );

        return Inertia::render('Admin/Apps/Marketplace', [
            'packages' => array_values($packages),
            'state' => [
                'modulesEnabled' => $enabled,
                'configured' => is_string(config('platform.marketplace.api_url'))
                    && trim(config('platform.marketplace.api_url')) !== '',
                'signable' => is_readable((string) config('platform.marketplace.public_key_path', '')),
            ],
        ]);
    }

    public function install(Request $request, InstallFromMarketplace $install): RedirectResponse
    {
        $this->assertOwner();

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64'],
        ]);

        try {
            $install->handle((string) $data['slug'], $this->actor->model());
        } catch (PackageRefused|InvalidModule $refusal) {
            // A refusal is an answer, and every one of them names which check
            // said so (ADR 0047). Turning them into a 500 would make "the
            // mirror truncated a file" and "somebody served a package they did
            // not sign" look identical to an operator.
            // Against `slug`, the one field the form has: an error with no field
            // is an error Inertia's form helper cannot type, and one nobody
            // connects to what they pressed.
            return back()->withErrors(['slug' => $refusal->getMessage()]);
        }

        return back()->with('status', __('marketplace.installed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Listing $listing, ?ModuleRecord $record): array
    {
        return [
            'slug' => $listing->slug,
            'name' => $listing->name,
            'type' => $listing->type->value,
            'version' => $listing->version,
            'summary' => $listing->summary,
            'provider' => $listing->provider,
            'sizeBytes' => $listing->sizeBytes,
            'infoUrl' => $listing->infoUrl,
            'dependencies' => $listing->dependencies,
            'installed' => $record instanceof ModuleRecord,
            'installedVersion' => $record?->version,
            'state' => $record?->state->value,
            // Whether the marketplace may touch it at all. A module somebody put
            // in `modules/` by hand is their deliberate act, and a catalogue
            // entry with the same slug does not get to undo it.
            'ours' => $record === null || $record->source === 'marketplace',
            'outdated' => $record instanceof ModuleRecord
                && version_compare($listing->version, (string) $record->version, '>'),
        ];
    }

    private function assertOwner(): void
    {
        AppsController::assertSuperAdminFor($this->actor);
    }
}
