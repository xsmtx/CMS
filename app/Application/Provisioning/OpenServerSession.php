<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Domain\Provisioning\Contracts\OpensPanelSessions;
use App\Domain\Provisioning\PanelSession;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Let an operator into a server's panel without a password being typed,
 * shown, or stored anywhere new.
 *
 * The platform already holds an API credential for this server, because it
 * cannot create accounts without one. This asks the panel to issue a
 * short-lived session with that credential and hands back the URL. Three
 * things follow from doing it this way rather than revealing the password:
 *
 * - **The credential never leaves the server this runs on.** Nothing is
 *   rendered into a page, nothing reaches a browser, nothing is written to
 *   a log — `PanelSession` refuses to print its own URL.
 * - **It expires.** A password read off a screen is valid until somebody
 *   changes it, which is usually never.
 * - **It is attributable.** Every session is audited against the operator
 *   who asked, the server they asked about and the moment they asked. "Who
 *   was in root on that box on Tuesday" has an answer.
 *
 * A module that cannot do this is not an error. Most panels cannot, and the
 * manual module never will: the answer is "this server does not offer it".
 */
final readonly class OpenServerSession
{
    public function __construct(private ModuleRegistry $modules) {}

    /**
     * @throws Throwable when the panel was asked and answered badly.
     */
    public function handle(Server $server, ?Model $actor = null): ?PanelSession
    {
        $module = $server->module === null ? null : $this->modules->find($server->module);

        if (! $module instanceof OpensPanelSessions) {
            return null;
        }

        // Outside any transaction, like every other remote call in this
        // platform: a database transaction held open across somebody
        // else's network is a lock waiting for their timeout.
        $session = $module->openServerSession($server->connection());

        Audit::action('provisioning.server.session_opened')
            ->by($actor)
            ->on($server)
            ->forOrganization($server->organization_id)
            ->withMetadata([
                'panel' => $session?->panel,
                'issued' => $session instanceof PanelSession ? 'yes' : 'no',
                // The URL is a bearer credential for its lifetime. The
                // record says a session was issued, never which one.
                'expires_at' => $session?->expiresAt?->toIso8601String(),
            ])
            ->write();

        return $session;
    }
}
