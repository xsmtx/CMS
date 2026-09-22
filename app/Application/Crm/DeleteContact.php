<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

final readonly class DeleteContact
{
    public function handle(Contact $contact, ?Model $actor = null): void
    {
        Audit::action('crm.contact.deleted')
            ->by($actor)
            ->on($contact)
            ->forOrganization($contact->organization_id)
            ->withMetadata(['email' => $contact->email])
            ->write();

        $contact->tokens()->delete();
        $contact->delete();
    }
}
