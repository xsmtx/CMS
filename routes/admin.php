<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Admin\AddonController;
use App\Http\Controllers\Admin\ApiActivityController;
use App\Http\Controllers\Admin\AppsController;
use App\Http\Controllers\Admin\AutomationController;
use App\Http\Controllers\Admin\CancellationController;
use App\Http\Controllers\Admin\CannedResponseController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ConnectController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerUserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\GatewayLogController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\InfrastructureController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\InvoicePaymentController;
use App\Http\Controllers\Admin\LicenceController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\OptionGroupController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderReviewController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ProductPricingController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\ResellerReportController;
use App\Http\Controllers\Admin\ResourceAdapterController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\ServiceAddonController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TelemetryController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TldController;
use App\Http\Controllers\Admin\TodoController;
use App\Http\Controllers\Admin\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
|
| Mounted at /admin with the `admin.` name prefix and the staff guard. The
| shared auth controllers read the guard from that name prefix.
|
*/

(require __DIR__.'/auth.php')(Guard::Staff);

Route::middleware(['auth:staff'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    /*
     * Staff and role administration. Every action is authorized by a policy
     * that asks the boundary question before the permission question.
     *
     * Deleting either wants a **recent password** on top (§20). These are the
     * two things a stolen session would be used for first: an account that can
     * sign in tomorrow, or a role change that quietly grants one. The boundary,
     * the permission and the policy all still run first — this answers the
     * different question of whether the person is still at the keyboard.
     */
    Route::resource('staff', StaffController::class)
        ->parameters(['staff' => 'staff'])
        ->except(['show', 'destroy']);

    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])
        ->middleware('auth.recent')
        ->name('staff.destroy');

    Route::delete('staff/{staff}/two-factor', [StaffController::class, 'disableTwoFactor'])
        ->middleware('auth.recent')
        ->name('staff.two-factor.disable');

    Route::resource('roles', RoleController::class)->except(['show', 'destroy']);

    Route::delete('roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('auth.recent')
        ->name('roles.destroy');

    // Customers, and the contacts that belong to them.
    // No `create` or `store`: a client is added through `ClientController`,
    // which makes the company, the first person and the address together.
    // Two ways to create the same record is one way to create half of it.
    Route::resource('customers', CustomerController::class)
        ->except(['create', 'store', 'destroy']);

    Route::get('customers/{customer}/export', [CustomerController::class, 'export'])
        ->name('customers.export');
    Route::post('customers/{customer}/anonymize', [CustomerController::class, 'anonymize'])
        ->name('customers.anonymize');

    Route::resource('customers.contacts', ContactController::class)->except(['index', 'show']);

    // The catalog. Pricing sits on its own routes because it answers to
    // its own permission.
    Route::prefix('catalog')->name('catalog.')->group(function (): void {
        Route::resource('groups', ProductGroupController::class)->except(['show']);
        Route::resource('products', ProductController::class)->except(['show']);

        Route::get('products/{product}/pricing', [ProductPricingController::class, 'edit'])
            ->name('products.pricing.edit');
        Route::put('products/{product}/pricing', [ProductPricingController::class, 'update'])
            ->name('products.pricing.update');

        Route::resource('products.options', OptionGroupController::class)
            ->parameters(['options' => 'group'])
            ->except(['show']);

        Route::resource('products.addons', AddonController::class)->except(['show']);
        Route::put('products/{product}/addons/{addon}/pricing', [AddonController::class, 'pricing'])
            ->name('products.addons.pricing');

        Route::resource('currencies', CurrencyController::class)->except(['show']);
    });

    // Orders. The review queue is its own screen rather than a filter:
    // an order sitting in it is not moving until someone decides.
    Route::get('orders/review', [OrderController::class, 'review'])->name('orders.review');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    // Before `orders/{order}`, or the word is read as an id.
    Route::get('orders/add', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');

    Route::post('orders/{order}/release', [OrderReviewController::class, 'release'])->name('orders.release');
    Route::post('orders/{order}/refuse', [OrderReviewController::class, 'refuse'])->name('orders.refuse');

    Route::resource('promotions', PromotionController::class)->except(['show']);

    // Billing. Every action that moves money is its own route, because
    // each answers to its own permission.
    Route::get('support', [TicketController::class, 'index'])->name('support.index');
    // Before `support/{ticket}`, or the words are read as ids.
    Route::get('support/overview', [TicketController::class, 'overview'])
        ->name('support.overview');
    Route::get('support/create', [TicketController::class, 'create'])->name('support.create');
    Route::post('support', [TicketController::class, 'store'])->name('support.store');

    Route::get('support/replies', [CannedResponseController::class, 'index'])
        ->name('support.replies');
    Route::post('support/replies', [CannedResponseController::class, 'store'])
        ->name('support.replies.store');
    Route::put('support/replies/{reply}', [CannedResponseController::class, 'update'])
        ->name('support.replies.update');
    Route::delete('support/replies/{reply}', [CannedResponseController::class, 'destroy'])
        ->name('support.replies.destroy');

    Route::get('support/{ticket}', [TicketController::class, 'show'])->name('support.show');
    Route::post('support/{ticket}/replies', [TicketController::class, 'reply'])
        ->name('support.reply');
    Route::put('support/{ticket}', [TicketController::class, 'update'])->name('support.update');

    Route::get('content/announcements', [ContentController::class, 'announcements'])
        ->name('content.announcements');
    Route::post('content/announcements', [ContentController::class, 'storeAnnouncement'])
        ->name('content.announcements.store');
    Route::put('content/announcements/{announcement}', [ContentController::class, 'updateAnnouncement'])
        ->name('content.announcements.update');
    Route::delete('content/announcements/{announcement}', [ContentController::class, 'destroyAnnouncement'])
        ->name('content.announcements.destroy');

    Route::get('content/articles', [ContentController::class, 'articles'])->name('content.articles');
    Route::post('content/articles', [ContentController::class, 'storeArticle'])
        ->name('content.articles.store');
    Route::put('content/articles/{article}', [ContentController::class, 'updateArticle'])
        ->name('content.articles.update');
    Route::delete('content/articles/{article}', [ContentController::class, 'destroyArticle'])
        ->name('content.articles.destroy');
    Route::post('content/categories', [ContentController::class, 'storeCategory'])
        ->name('content.categories.store');

    Route::get('notifications/templates', [NotificationTemplateController::class, 'index'])
        ->name('notifications.templates');
    Route::put('notifications/templates/{event}/{locale}', [NotificationTemplateController::class, 'update'])
        ->name('notifications.templates.update');
    Route::delete('notifications/templates/{template}', [NotificationTemplateController::class, 'reset'])
        ->name('notifications.templates.reset');
    Route::post('notifications/templates/{event}/{locale}/test', [NotificationTemplateController::class, 'test'])
        ->name('notifications.templates.test');
    Route::get('notifications/log', [NotificationTemplateController::class, 'log'])
        ->name('notifications.log');

    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::post('domains/{domain}/register', [DomainController::class, 'register'])
        ->name('domains.register');
    Route::post('domains/{domain}/actions', [DomainController::class, 'action'])
        ->name('domains.action');
    Route::put('domains/{domain}/status', [DomainController::class, 'transition'])
        ->name('domains.status');

    Route::get('catalog/tlds', [TldController::class, 'index'])->name('tlds.index');
    Route::post('catalog/tlds', [TldController::class, 'store'])->name('tlds.store');
    Route::put('catalog/tlds/{tld}', [TldController::class, 'update'])->name('tlds.update');
    Route::delete('catalog/tlds/{tld}', [TldController::class, 'destroy'])->name('tlds.destroy');

    // The cancellation queue. A request is paperwork; the service's own
    // status is the truth about what is running.
    Route::get('cancellations', [CancellationController::class, 'index'])
        ->name('cancellations.index');
    Route::post('cancellations/{request}/complete', [CancellationController::class, 'complete'])
        ->name('cancellations.complete');
    Route::post('cancellations/{request}/withdraw', [CancellationController::class, 'withdraw'])
        ->name('cancellations.withdraw');

    Route::get('todo', [TodoController::class, 'index'])->name('todo.index');
    Route::post('todo', [TodoController::class, 'store'])->name('todo.store');
    Route::put('todo/{todo}', [TodoController::class, 'update'])->name('todo.update');
    Route::delete('todo/{todo}', [TodoController::class, 'destroy'])->name('todo.destroy');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    // Before `services/{service}`, or the word "addons" is read as an id.
    Route::get('services/addons', [ServiceAddonController::class, 'index'])->name('services.addons');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::post('services/{service}/provision', [ServiceController::class, 'provision'])
        ->name('services.provision');
    /*
     * Suspend, unsuspend, sync — and terminate, which destroys an account at a
     * provider and cannot be undone by anything in this platform. The route
     * carries `auth.recent` for that one, which means the whole endpoint does:
     * splitting it would mean two routes, two request classes and two chances
     * for the wrong one to answer.
     */
    Route::post('services/{service}/actions', [ServiceController::class, 'action'])
        ->middleware('auth.recent')
        ->name('services.action');
    Route::put('services/{service}/status', [ServiceController::class, 'transition'])
        ->name('services.status');
    // Reading somebody's control panel password is an action in the audit
    // log, not a side effect of opening a screen.
    Route::post('services/{service}/credentials', [ServiceController::class, 'credentials'])
        ->name('services.credentials');

    /*
     * The fleet. Owner only, and `owner` is on the group so that it runs
     * **before** the password challenge below: with the check inside the
     * controller, a staff member who may not touch these screens was asked to
     * confirm their password and only then refused — rude, and a small oracle
     * (the lesson Phase 17 learned on the Licence screen).
     */
    Route::middleware('owner')->group(function (): void {
        Route::get('apps/infrastructure', [InfrastructureController::class, 'index'])
            ->name('infrastructure');
        Route::post('apps/infrastructure/groups', [InfrastructureController::class, 'storeGroup'])
            ->name('infrastructure.groups.store');
        Route::put('apps/infrastructure/groups/{group}', [InfrastructureController::class, 'updateGroup'])
            ->name('infrastructure.groups.update');
        Route::delete('apps/infrastructure/groups/{group}', [InfrastructureController::class, 'destroyGroup'])
            ->name('infrastructure.groups.destroy');
        Route::post('apps/infrastructure/servers', [InfrastructureController::class, 'storeServer'])
            ->name('infrastructure.servers.store');
        Route::put('apps/infrastructure/servers/{server}', [InfrastructureController::class, 'updateServer'])
            ->name('infrastructure.servers.update');

        // A server row holds credentials to somebody else's machine.
        Route::delete('apps/infrastructure/servers/{server}', [InfrastructureController::class, 'destroyServer'])
            ->middleware('auth.recent')
            ->name('infrastructure.servers.destroy');

        Route::post('apps/infrastructure/servers/{server}/test', [InfrastructureController::class, 'test'])
            ->name('infrastructure.servers.test');
    });

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    // Before the `{invoice}` route: `bulk` would otherwise be read as an
    // invoice id, and the 404 would be blamed on the record.
    Route::post('invoices/bulk', [InvoiceController::class, 'bulk'])->name('invoices.bulk');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('orders/{order}/invoice', [InvoiceController::class, 'storeForOrder'])
        ->name('orders.invoice');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

    Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])
        ->name('invoices.payments.store');
    Route::post('invoices/{invoice}/payments/{payment}/refund', [InvoicePaymentController::class, 'refund'])
        ->name('invoices.payments.refund');
    Route::post('invoices/{invoice}/credit/apply', [InvoicePaymentController::class, 'applyCredit'])
        ->name('invoices.credit.apply');
    Route::post('invoices/{invoice}/credit/add', [InvoicePaymentController::class, 'addCredit'])
        ->name('invoices.credit.add');
    Route::post('invoices/{invoice}/credit-note', [InvoicePaymentController::class, 'creditNote'])
        ->name('invoices.credit-note');

    /*
     * What the platform does on its own, and what became of it.
     *
     * "Run now" is a POST because it changes things — it can invoice a
     * thousand customers — and it runs in the request rather than on the
     * queue because somebody pressed it in order to watch.
     */
    Route::get('automation', [AutomationController::class, 'index'])->name('automation');
    Route::get('automation/dunning', [AutomationController::class, 'dunning'])
        ->name('automation.dunning');
    Route::post('automation/dunning', [AutomationController::class, 'storeStep'])
        ->name('automation.dunning.store');
    Route::delete('automation/dunning/{step}', [AutomationController::class, 'destroyStep'])
        ->name('automation.dunning.destroy');
    Route::post('automation/{task}/run', [AutomationController::class, 'run'])
        ->name('automation.run');

    Route::get('operations', [OperationController::class, 'index'])->name('operations');
    Route::post('operations/{operation}/retry', [OperationController::class, 'retry'])
        ->name('operations.retry');
    Route::post('operations/{operation}/resolve', [OperationController::class, 'resolve'])
        ->name('operations.resolve');

    /*
     * The Resource Graph and its adapters — handoff #2, Phase A.
     *
     * Named `resources.*` rather than `infrastructure.*` because that prefix is
     * already the Apps → Infrastructure server list from Phase 6, and two route
     * groups with one name is a link that goes somewhere surprising.
     *
     * No route here takes an id: the Explorer's detail is an `Inertia::optional`
     * prop on the list, so a drawer costs one partial reload rather than an
     * endpoint of its own (the Phase 11 rule).
     */
    Route::get('resources', [ResourceController::class, 'index'])->name('resources');
    Route::get('resources/telemetry', [TelemetryController::class, 'index'])
        ->name('resources.telemetry');
    Route::get('resources/adapters', [ResourceAdapterController::class, 'index'])
        ->name('resources.adapters');

    /*
     * Two endpoints rather than one, and the split is the whole point.
     *
     * Allowing an adapter to write is where this installation stops being a
     * window onto the estate and starts being a control plane over it, so it
     * carries the password challenge from Phase 17. Switching an adapter off is
     * what an operator does when something is going wrong, and a guard in front
     * of that would be a guard that made an outage longer.
     */
    Route::put('resources/adapters/{adapter}/writes', [ResourceAdapterController::class, 'writes'])
        ->middleware('auth.recent')
        ->name('resources.adapters.writes');
    Route::put('resources/adapters/{adapter}', [ResourceAdapterController::class, 'update'])
        ->name('resources.adapters.update');
    Route::post('resources/adapters/{adapter}/check', [ResourceAdapterController::class, 'check'])
        ->name('resources.adapters.check');

    /*
     * What this installation calls itself. The navigation has pointed here
     * since Phase 0; this is the phase that answers it.
     */
    /*
     * Everyone who can sign into the customer area, across all customers.
     * A different question from "who works at this company", asked by a
     * different person while a customer waits on the telephone.
     */
    // Adding a client the way an operator does it: the company, the
    // person and the address, once.
    Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');

    // The provider, its resellers and their customers. Read-only: an
    // organization is created by whatever needs one.
    Route::get('organizations', [OrganizationController::class, 'index'])
        ->name('organizations.index');

    /*
     * The reseller programme. Gated on `resellers.administer`, which is not a
     * permission — a reseller's own Administrator holds every staff
     * permission by design, so a permission here would let them set their own
     * margins and write their own balance.
     *
     * `create` before `{reseller}`: otherwise the literal would be read as an
     * organization id and the 404 would be blamed on the record.
     */
    Route::get('resellers', [ResellerController::class, 'index'])->name('resellers.index');
    Route::get('resellers/create', [ResellerController::class, 'create'])->name('resellers.create');
    Route::post('resellers', [ResellerController::class, 'store'])->name('resellers.store');
    Route::get('resellers/{reseller}', [ResellerController::class, 'show'])->name('resellers.show');
    Route::post('resellers/{reseller}/availability', [ResellerController::class, 'availability'])
        ->name('resellers.availability');
    Route::post('resellers/{reseller}/prices', [ResellerController::class, 'price'])
        ->name('resellers.prices');
    Route::post('resellers/{reseller}/ledger', [ResellerController::class, 'ledger'])
        ->name('resellers.ledger');

    /*
     * The monthly review, on one page. Boundary-scoped, so the same screen
     * answers the provider's question and a reseller's without a second
     * implementation.
     *
     * Before `reports/resellers`? No — both are literals, so order does not
     * matter here; they are listed with the general one first because that is
     * the one an operator opens.
     */
    Route::get('reports', ReportController::class)->name('reports');

    // The roll-up the provider cannot get from the boundary. A reseller's own
    // numbers are the dashboard they already have.
    Route::get('reports/resellers', ResellerReportController::class)
        ->name('reports.resellers');

    /*
     * This installation's relationship with the vendor.
     *
     * Owner only, and the controller enforces it: an Administrator holds every
     * staff permission by design, and a reseller's Administrator is an
     * Administrator. A reseller who could release the installation's licence
     * would be a reseller able to turn the vendor mark back on for the provider.
     */
    Route::middleware('owner')->group(function (): void {
        Route::get('licence', [LicenceController::class, 'index'])->name('licence');
        Route::post('licence/activate', [LicenceController::class, 'activate'])
            ->name('licence.activate');
        Route::post('licence/heartbeat', [LicenceController::class, 'heartbeat'])
            ->name('licence.heartbeat');

        // Releasing the installation's licence brings the vendor mark back on
        // every one of the operator's own customers' invoices. `owner` is on the
        // group and runs first, so a non-owner is refused rather than asked for
        // a password they were never going to be allowed to use.
        Route::post('licence/deactivate', [LicenceController::class, 'deactivate'])
            ->middleware('auth.recent')
            ->name('licence.deactivate');
    });

    /*
     * Bringing a previous system across. Owner only: an import writes
     * customers, invoices and ledger rows straight into the database — by
     * design, because it is a copy of history rather than a set of new business
     * events — and it reads a second database over a configured connection.
     */
    Route::middleware('owner')->group(function (): void {
        Route::get('import', [ImportController::class, 'index'])->name('import');

        // A live import writes customers, invoices and ledger rows straight into
        // the database. A dry run goes through the same endpoint, and asking for
        // a password before one is a small price for not having two.
        Route::post('import', [ImportController::class, 'store'])
            ->middleware('auth.recent')
            ->name('import.store');

        Route::get('import/{run}', [ImportController::class, 'show'])->name('import.show');
    });

    Route::get('customer-users', [CustomerUserController::class, 'index'])
        ->name('customer-users');
    Route::post('customer-users/{contact}/reset', [CustomerUserController::class, 'sendReset'])
        ->name('customer-users.reset');
    Route::put('customer-users/{contact}/password', [CustomerUserController::class, 'setPassword'])
        ->name('customer-users.password');

    // Extensions. Nothing here loads a module's code except the settings
    // form, which reads the schema from the package it belongs to.
    // Apps and Integrations: one door, and it is shut to everybody but a
    // super administrator. See `AppsController` for why that is not a
    // permission.
    // One box, every kind of record. A support call carries one fact and
    // no idea which screen it belongs to.
    Route::get('search', SearchController::class)->name('search');

    Route::get('apps', [AppsController::class, 'index'])->name('apps.index');

    // Connect: what this installation is joined to, and a way into the
    // servers whose credentials it already holds. The session is issued by
    // the panel, expires, and is audited — none of which is true of
    // revealing a stored password, which this platform does not do.
    Route::get('apps/connect', [ConnectController::class, 'index'])->name('apps.connect');
    Route::post('apps/connect/servers/{server}/session', [ConnectController::class, 'openSession'])
        ->name('apps.connect.session');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    // Before `{transaction}` would ever be, if one is added: a literal
    // segment registered after a wildcard is a literal nobody can reach.
    Route::get('transactions/add', [TransactionController::class, 'create'])
        ->name('transactions.create');
    Route::post('transactions', [TransactionController::class, 'store'])
        ->name('transactions.store');
    Route::get('billing/gateway-log', [GatewayLogController::class, 'index'])
        ->name('billing.gateway-log');

    /*
     * Modules. Owner only, for the same reason and in the same order:
     * enabling one runs code this repository does not contain, and being
     * refused should not cost somebody their password first.
     */
    Route::middleware('owner')->group(function (): void {
        Route::get('apps/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::post('apps/modules', [ModuleController::class, 'install'])->name('modules.install');
        Route::post('apps/modules/{module}/enable', [ModuleController::class, 'enable'])
            ->name('modules.enable');
        Route::post('apps/modules/{module}/disable', [ModuleController::class, 'disable'])
            ->name('modules.disable');
        Route::post('apps/modules/{module}/upgrade', [ModuleController::class, 'upgrade'])
            ->name('modules.upgrade');
        Route::put('apps/modules/{module}/config', [ModuleController::class, 'configure'])
            ->name('modules.configure');

        // Uninstalling runs the package's own migrations down.
        Route::delete('apps/modules/{module}', [ModuleController::class, 'uninstall'])
            ->middleware('auth.recent')
            ->name('modules.uninstall');
    });

    Route::get('settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('settings/brand', [SettingsController::class, 'updateBrand'])
        ->name('settings.brand');
    Route::put('settings/theme', [SettingsController::class, 'updateTheme'])
        ->name('settings.theme');

    Route::get('api/activity', [ApiActivityController::class, 'index'])->name('api.activity');

    Route::get('health', [HealthController::class, 'index'])->name('health');
    Route::put('health/maintenance', [AutomationController::class, 'maintenance'])
        ->name('health.maintenance');

    // Acting as a customer. Starting it is rate limited on top of the
    // permission and boundary checks.
    Route::post('contacts/{contact}/impersonate', [ImpersonationController::class, 'store'])
        ->name('contacts.impersonate');
});
