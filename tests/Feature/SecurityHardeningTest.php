<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Jobs\RunServiceAction;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Http\Exceptions\UnsafeUrl;
use App\Support\Http\SafeUrl;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeProvisioningModule;

/**
 * Phase 17's hardening, as tests.
 *
 * Three things, and each of them is a real attack rather than a checklist item:
 *
 * - **SSRF.** An operator-supplied URL pointed at `169.254.169.254` reads the
 *   cloud instance's credentials out of the response body a webhook delivery
 *   records. The check is at the call site and not in a form request, because
 *   DNS can change between validation and the call and that is the technique.
 * - **The CSP.** The realistic attack on an admin panel is not injection but a
 *   dependency that is already there talking somewhere it should not, which
 *   `connect-src 'self'` is what stops.
 * - **Recent authentication.** A stolen session cookie passes every other check
 *   in this product — the boundary, the permission, the policy — and fails this
 *   one.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();
});

// --- SSRF -------------------------------------------------------------------

/**
 * Written against a public IP literal rather than a hostname, on purpose: the
 * test suite has no network, so a hostname would be testing the resolver.
 */
it('allows an ordinary public URL', function (): void {
    expect(SafeUrl::allows('https://93.184.216.34/infracms'))->toBeTrue();
    expect(SafeUrl::allows('http://93.184.216.34:8080/webhook'))->toBeTrue();
});

/**
 * A host that does not resolve is a transient network problem, not an attack.
 * Refusing it here would record the delivery as *unsafe* — which is a permanent
 * failure — so a customer's ten-minute DNS outage would silently end their
 * webhook deliveries. Letting it through means the HTTP client fails to connect,
 * which is retryable and correct.
 */
it('lets a host that does not resolve through, to fail as a connection error', function (): void {
    expect(SafeUrl::allows('https://nothing-resolves-here.invalid/hook'))->toBeTrue();
});

/**
 * The one that matters. The cloud metadata endpoint lives at a link-local
 * address and hands out credentials to anybody who asks from inside the
 * instance.
 */
it('refuses the cloud metadata address and everything like it', function (): void {
    foreach ([
        'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'http://127.0.0.1/admin',
        'http://localhost:8000/webhook',
        'http://10.0.0.5/internal',
        'http://192.168.1.1/',
        'http://172.16.0.1/',
        'http://metadata.google.internal/computeMetadata/v1/',
        'http://[::1]/',
    ] as $url) {
        expect(SafeUrl::allows($url))->toBeFalse();
    }
});

it('refuses a scheme that is not http or https', function (): void {
    foreach ([
        'file:///etc/passwd',
        'gopher://example.com/',
        'dict://example.com:11211/stat',
        'ftp://example.com/',
    ] as $url) {
        expect(SafeUrl::allows($url))->toBeFalse();
    }
});

/**
 * `https://user:pass@host` sends a header the operator did not know they were
 * sending, and it appears in the log.
 */
it('refuses credentials in a URL', function (): void {
    expect(SafeUrl::allows('https://admin:hunter2@example.com/hook'))->toBeFalse();
});

/**
 * A webhook endpoint on port 6379 is somebody asking this platform to talk to
 * their Redis.
 */
it('refuses a port nobody allowed', function (): void {
    expect(SafeUrl::allows('http://example.com:6379/'))->toBeFalse();
    expect(SafeUrl::allows('http://example.com:22/'))->toBeFalse();
});

/**
 * No message echoes the URL back. It is the thing somebody is trying to smuggle,
 * and a refusal that quoted it would put the metadata path into a log line, a
 * flash message and eventually a screenshot in a support ticket.
 */
it('never echoes the URL back in a refusal', function (): void {
    try {
        SafeUrl::check('http://169.254.169.254/latest/meta-data/iam/');
    } catch (UnsafeUrl $refused) {
        expect($refused->getMessage())->not->toContain('169.254');
        expect($refused->getMessage())->not->toContain('meta-data');

        return;
    }

    // Reaching here means it was allowed, which is the actual failure.
    expect(false)->toBeTrue();
});

it('lets an installation widen the ports deliberately', function (): void {
    expect(SafeUrl::allows('http://example.com:9000/'))->toBeFalse();

    config()->set('platform.security.outbound_ports', [80, 443, 9000]);

    // Widened on purpose, which is the point: it had to be said out loud.
    expect(SafeUrl::allows('http://example.com:9000/'))->toBeTrue();
});

// --- the content security policy --------------------------------------------

it('sends a content security policy on every page', function (): void {
    $response = $this->actingAs($this->owner, 'staff')->get('/admin');

    $policy = (string) $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'");
    // Same-origin scripts only: Inertia's page object is a `data-` attribute
    // and the translations block is `application/json`, both so this can stay
    // this short.
    expect($policy)->toContain("script-src 'self'");
    // The realistic attack: a dependency already on the page talking somewhere
    // it should not.
    expect($policy)->toContain("connect-src 'self'");
    expect($policy)->toContain("frame-ancestors 'none'");
    expect($policy)->toContain("object-src 'none'");

    // Enforced, not report-only: a report-only policy is a policy nobody fixes.
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('sends the rest of the headers a browser needs', function (): void {
    $response = $this->actingAs($this->owner, 'staff')->get('/admin');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect((string) $response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

/**
 * A 500 rendered without a policy is a 500 an injected script runs on, and an
 * error page is exactly where an unescaped value is most likely to end up.
 */
it('sends the headers on the storefront and on an error page too', function (): void {
    expect($this->get('/')->headers->has('Content-Security-Policy'))->toBeTrue();

    expect($this->actingAs($this->owner, 'staff')
        ->get('/admin/customers/does-not-exist')
        ->headers
        ->has('Content-Security-Policy'))->toBeTrue();
});

/**
 * HSTS from a development server would pin `localhost` to HTTPS in the
 * developer's browser, which takes an afternoon to work out.
 */
it('does not send HSTS over plain http', function (): void {
    expect($this->get('/')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

// --- recent authentication --------------------------------------------------

/**
 * A stolen session cookie passes the boundary, the permission and the policy. It
 * fails this.
 */
it('asks for a password again before something irreversible', function (): void {
    Queue::fake();

    $registry = new ModuleRegistry;
    $registry->register(new FakeProvisioningModule);
    $this->app->instance(ModuleRegistry::class, $registry);

    $group = ServerGroup::factory()->create();
    $server = Server::factory()->inGroup($group)->create(['module' => 'fake']);
    $service = Service::factory()->on($server)->create(['module' => 'fake']);

    $this->actingAs($this->owner, 'staff')
        ->post("/admin/services/{$service->id}/actions", ['operation' => 'terminate'])
        ->assertRedirect('/admin/confirm-password');

    // Nothing queued: the guard runs before the controller.
    Queue::assertNothingPushed();
});

it('lets the action through once the password has been confirmed', function (): void {
    Queue::fake();

    $registry = new ModuleRegistry;
    $registry->register(new FakeProvisioningModule);
    $this->app->instance(ModuleRegistry::class, $registry);

    $group = ServerGroup::factory()->create();
    $server = Server::factory()->inGroup($group)->create(['module' => 'fake']);
    $service = Service::factory()->on($server)->create(['module' => 'fake']);

    $this->actingAs($this->owner, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => time()])
        ->post("/admin/services/{$service->id}/actions", ['operation' => 'terminate'])
        ->assertRedirect();

    Queue::assertPushed(RunServiceAction::class);
});

/**
 * A window, not a permanent grant. Fifteen minutes is a rule people follow;
 * forever is not a check.
 */
it('asks again once the window has passed', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->withSession([
            RequireRecentAuthentication::SESSION_KEY => time() - (16 * 60),
        ])
        ->delete("/admin/staff/{$this->owner->id}")
        ->assertRedirect('/admin/confirm-password');
});

it('confirms a correct password and sends them back where they were going', function (): void {
    $staff = StaffUser::factory()->create(['password' => bcrypt('correct-horse-battery')]);
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->withSession(['auth.intended' => '/admin/staff'])
        ->post('/admin/confirm-password', ['password' => 'correct-horse-battery'])
        ->assertRedirect('/admin/staff');

    expect(session()->has(RequireRecentAuthentication::SESSION_KEY))->toBeTrue();
    expect(AuditLog::query()->where('action', 'identity.password.confirmed')->exists())->toBeTrue();
});

/**
 * Somebody failing this is either an operator who mistyped or a session that is
 * not theirs, and the second is what an incident review has to be able to find.
 */
it('audits a wrong password and grants nothing', function (): void {
    $staff = StaffUser::factory()->create(['password' => bcrypt('correct-horse-battery')]);
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->from('/admin/confirm-password')
        ->post('/admin/confirm-password', ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    expect(session()->has(RequireRecentAuthentication::SESSION_KEY))->toBeFalse();
    expect(AuditLog::query()->where('action', 'identity.password.confirm_failed')->exists())
        ->toBeTrue();
});

/**
 * A confirmation form is a password oracle against a session somebody may
 * already have stolen — a better brute-force target than the sign-in screen,
 * because it leaks the account name for free.
 */
it('throttles the confirmation form', function (): void {
    $staff = StaffUser::factory()->create(['password' => bcrypt('correct-horse-battery')]);
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from('/admin/confirm-password')
            ->post('/admin/confirm-password', ['password' => 'wrong-'.$attempt]);
    }

    $this->from('/admin/confirm-password')
        ->post('/admin/confirm-password', ['password' => 'correct-horse-battery'])
        ->assertSessionHasErrors('password');

    // Even the correct password is refused while the limiter is closed.
    expect(session()->has(RequireRecentAuthentication::SESSION_KEY))->toBeFalse();
});

/**
 * A token holder has no password to re-enter, and a redirect to a confirmation
 * form is a redirect a script follows and cannot satisfy.
 */
it('answers a machine-readable refusal to an API request', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->postJson('/admin/licence/deactivate')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'recent_authentication_required');
});
