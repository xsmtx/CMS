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
use App\Http\Controllers\Admin\InfrastructureController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\InvoicePaymentController;
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
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\ServiceAddonController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
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

    // Staff and role administration. Every action is authorized by a policy
    // that asks the boundary question before the permission question.
    Route::resource('staff', StaffController::class)
        ->parameters(['staff' => 'staff'])
        ->except(['show']);

    Route::delete('staff/{staff}/two-factor', [StaffController::class, 'disableTwoFactor'])
        ->name('staff.two-factor.disable');

    Route::resource('roles', RoleController::class)->except(['show']);

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
    Route::post('services/{service}/actions', [ServiceController::class, 'action'])
        ->name('services.action');
    Route::put('services/{service}/status', [ServiceController::class, 'transition'])
        ->name('services.status');
    // Reading somebody's control panel password is an action in the audit
    // log, not a side effect of opening a screen.
    Route::post('services/{service}/credentials', [ServiceController::class, 'credentials'])
        ->name('services.credentials');

    Route::get('apps/infrastructure', [InfrastructureController::class, 'index'])->name('infrastructure');
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
    Route::delete('apps/infrastructure/servers/{server}', [InfrastructureController::class, 'destroyServer'])
        ->name('infrastructure.servers.destroy');
    Route::post('apps/infrastructure/servers/{server}/test', [InfrastructureController::class, 'test'])
        ->name('infrastructure.servers.test');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
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
    Route::delete('apps/modules/{module}', [ModuleController::class, 'uninstall'])
        ->name('modules.uninstall');

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
