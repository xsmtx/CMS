<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * What a time-boxed grant can hand somebody, and it is a closed list.
 *
 * §17's just-in-time access, and the vocabulary is deliberately tiny. A grant
 * is a way for somebody to exceed their standing permissions for two hours,
 * which is the opposite of a permission in every way that matters — so the
 * set of things it can hand out is a decision made here, once, rather than a
 * free string a screen could put anything into.
 *
 * **A grant only ever adds.** There is no member that takes something away
 * and there must not be one: a mechanism that could remove a permission for a
 * window is a mechanism somebody can use to lock an operator out, and this
 * platform already has roles for deciding what people may do.
 *
 * `Connect` is the first member because it is the case that exists today: a
 * support engineer who does not hold `infrastructure.connect` needs into a
 * panel for one ticket, and the alternative to a grant is somebody emailing
 * them a root password — which is what Connect was built to stop in the first
 * place.
 */
enum GrantableCapability: string
{
    /**
     * Opening a panel session from Connect, for a window.
     *
     * The same permission Support holds permanently. Granting it does not
     * reveal a credential — Connect asks the panel for a short-lived session
     * with the token this platform already has, and the token never leaves
     * the server.
     */
    case Connect = 'infrastructure.connect';

    /**
     * The permission slug this grant stands in for.
     *
     * Identical to the value today and a method rather than a cast, because
     * a future member might grant something that is not one permission — and
     * a call site reading `->value` as a slug would be the place that broke.
     */
    public function permission(): string
    {
        return $this->value;
    }

    public function labelKey(): string
    {
        return 'network.access.capabilities.'.str_replace('.', '_', $this->value);
    }
}
