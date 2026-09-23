<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who else is on this conversation.
 *
 * A ticket already reaches the contacts on the account. A CC is the
 * address that is **not** on the account: the developer the customer hired
 * for a fortnight, the accounts mailbox that wants the invoice thread, the
 * colleague the customer asked to be kept informed.
 *
 * Addresses rather than contact ids, because that is what they are. A CC
 * that had to be a contact first would mean creating a portal account for
 * somebody who is going to read three emails and disappear — and creating
 * accounts nobody asked for is how a customer list stops meaning anything.
 *
 * On the ticket rather than on each reply: it is a property of the
 * conversation, and a CC that had to be retyped on every reply is a CC
 * that gets dropped on the third one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->json('cc_recipients')->nullable()->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('cc_recipients');
        });
    }
};
