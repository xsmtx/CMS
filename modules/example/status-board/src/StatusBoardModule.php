<?php

declare(strict_types=1);

namespace Example\StatusBoard;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\RoleScope;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Modules\NavigationItem;
use App\Domain\Modules\Widget;
use RuntimeException;

/**
 * The worked example: a module written entirely outside core.
 *
 * It watches one URL an operator already trusts — a status page, a load
 * balancer, an upstream provider — and reports whether it answered. Small
 * on purpose, and chosen because it touches the four things every module
 * author will need on their first day and nothing they will not:
 *
 * - **Configuration it declares and never renders.** `configSchema()`
 *   returns fields; core draws the form, validates it and stores it. This
 *   class has no opinion about what the form looks like and no way to have
 *   one.
 * - **A health check**, which is how a module says whether it is actually
 *   working. It never throws and never returns a configuration value — the
 *   URL being watched is *not* in the report.
 * - **A widget**, which is data rather than a component. Core renders and
 *   escapes it.
 * - **A permission**, which becomes a real permission on the roles screen
 *   and is orphaned rather than deleted if this module goes away.
 *
 * What it does **not** do is the more useful half of the example. There is
 * no service provider, no container, no facade, no Eloquent model, no
 * migration and no JavaScript. Nothing here can register middleware or
 * replace a binding, because the SDK never offers the chance
 * ([ADR 0039](../../../../docs/adr/0039-the-sdk-is-platform-contracts.md)).
 *
 * Everything it imports lives in `app/Domain`, which is the SDK. If a
 * future version of core needs to change how a health report is stored,
 * this module does not care.
 */
final class StatusBoardModule extends BaseModule
{
    private string $url = '';

    private string $label = 'Upstream';

    private int $timeout = 5;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'url',
                label: 'URL to watch',
                type: ConfigFieldType::Url,
                required: true,
                hint: 'Asked for on every health run. Use a page that is cheap to serve.',
            ),
            new ConfigField(
                key: 'label',
                label: 'What to call it',
                required: false,
                hint: 'Shown on the dashboard. Defaults to "Upstream".',
                default: 'Upstream',
            ),
            new ConfigField(
                key: 'timeout',
                label: 'Timeout (seconds)',
                type: ConfigFieldType::Number,
                required: false,
                default: 5,
            ),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return [
            new PermissionDefinition('status-board.view', 'status-board', RoleScope::Staff),
        ];
    }

    /**
     * @return list<HealthCheck>
     */
    public function healthChecks(): array
    {
        return $this->url === '' ? [] : [new StatusBoardCheck($this->url, $this->timeout)];
    }

    /**
     * @return list<Widget>
     */
    public function widgets(): array
    {
        if ($this->url === '') {
            return [];
        }

        return [
            new Widget(
                key: 'status-board',
                title: $this->label,
                rows: [
                    ['label' => 'Checked by', 'value' => 'Status Board'],
                    ['label' => 'Result', 'value' => 'See the health page', 'tone' => 'neutral'],
                ],
                linkLabel: 'Open health',
                linkPath: '/admin/health',
                permission: 'status-board.view',
            ),
        ];
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Status Board', '/admin/health', 'status-board.view'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->url = $context->string('url');
        $this->label = $context->string('label', 'Upstream');
        $this->timeout = max(1, min($context->int('timeout', 5), 30));

        // Throwing here would leave the module `failed` with this sentence
        // recorded, which is the right outcome for a module that cannot
        // work. `url` is already `required` in the schema, so core refuses
        // before this runs — the guard is here because a schema and a boot
        // that disagree is exactly the bug an example should not ship.
        if ($this->url === '') {
            throw new RuntimeException('Status Board needs a URL to watch.');
        }

        $context->log->info('watching', ['timeout' => $this->timeout]);
    }
}
