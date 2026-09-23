<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Support\Exceptions\AttachmentRefused;
use App\Application\Support\OpenTicket;
use App\Application\Support\StoreAttachment;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Support\TicketPriority;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketAttachment;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    Storage::fake('local');

    $this->customer = Customer::factory()->create();
    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create();

    $this->ticket = app(OpenTicket::class)->handle(
        $this->customer,
        $this->contact,
        null,
        'Logs attached',
        'Here is what the server said.',
        TicketPriority::Normal,
    );

    $this->store = app(StoreAttachment::class);
});

it('stores an accepted file under a generated name', function (): void {
    $attachment = $this->store->handle(
        $this->ticket,
        UploadedFile::fake()->image('../../etc/passwd.png'),
    );

    expect($attachment->path)->toStartWith('tickets/'.$this->ticket->id.'/')
        // The browser's filename is kept for display and never becomes a path.
        ->and($attachment->path)->not->toContain('..')
        ->and($attachment->original_name)->toContain('passwd')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

it('refuses a file whose extension is not on the list', function (): void {
    $this->store->handle($this->ticket, UploadedFile::fake()->create('shell.php', 4, 'text/plain'));
})->throws(AttachmentRefused::class);

it('refuses a file whose content type is not on the list even when the extension is', function (): void {
    // Both are strings the browser sent, so both are checked.
    $this->store->handle(
        $this->ticket,
        UploadedFile::fake()->create('report.pdf', 4, 'text/html'),
    );
})->throws(AttachmentRefused::class);

it('refuses a file over the configured size', function (): void {
    config(['platform.support.attachments.max_kilobytes' => 1]);

    $this->store->handle($this->ticket, UploadedFile::fake()->create('big.txt', 64, 'text/plain'));
})->throws(AttachmentRefused::class);

it('lets the customer who owns the ticket read the file', function (): void {
    $attachment = $this->store->handle($this->ticket, UploadedFile::fake()->image('screen.png'));

    $this->actingAs($this->contact, 'client')
        ->get('/attachments/'.$attachment->id)
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
});

it('tells another customer the file does not exist', function (): void {
    $attachment = $this->store->handle($this->ticket, UploadedFile::fake()->image('screen.png'));

    $other = Customer::factory()->create();
    $stranger = Contact::factory()->forCustomer($other)->primary()->create();

    // 404 rather than 403: a 403 confirms the file is there.
    $this->actingAs($stranger, 'client')
        ->get('/attachments/'.$attachment->id)
        ->assertNotFound();
});

it('refuses an anonymous request for a file', function (): void {
    $attachment = $this->store->handle($this->ticket, UploadedFile::fake()->image('screen.png'));

    // Sent to sign in rather than served: the question of who is asking has
    // to have an answer before the file can be handed over.
    $this->get('/attachments/'.$attachment->id)->assertRedirect();
});

it('lets staff with the permission read any ticket file', function (): void {
    $attachment = $this->store->handle($this->ticket, UploadedFile::fake()->image('screen.png'));

    $staff = StaffUser::factory()->create();
    $staff->assignRole(SystemRole::Support);

    $this->actingAs($staff, 'staff')
        ->get('/attachments/'.$attachment->id)
        ->assertOk();
});

it('keeps an attachment inside its ticket owner boundary', function (): void {
    $attachment = TicketAttachment::factory()
        ->forTicket(Ticket::factory()->create())
        ->create();

    expect($attachment->organization_id)->not->toBeNull();
});
