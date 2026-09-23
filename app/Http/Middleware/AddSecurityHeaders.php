<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a browser needs to be told, on every response.
 *
 * **The CSP is enforced, not report-only.** A report-only policy is a policy
 * nobody fixes: the reports go somewhere, nobody reads them, and the header sits
 * in production for two years doing nothing. So it is strict, and the few things
 * that genuinely have to be allowed are allowed by name.
 *
 * What has to be allowed, and why each one is not negotiable:
 *
 * - **`style-src 'unsafe-inline'`.** Vue's scoped styles are inline and a brand's
 *   colour overrides are written onto the document root as a style attribute
 *   (ADR 0036). Removing it would mean hashing every brand's palette at request
 *   time, and a policy that expensive is a policy somebody disables in week two.
 * - **`script-src 'self'`** and nothing else. Inertia's page object is a `data-`
 *   attribute rather than an inline script precisely so this can be strict, and
 *   the translations block is `type="application/json"`, which is not a script
 *   as far as a browser is concerned.
 * - **`font-src` and `style-src` allowing Google Fonts.** A theme author may ask
 *   for a webfont (ADR 0037 allows a theme to name one), and the alternative is
 *   a theme system whose typography cannot be changed.
 * - **`frame-ancestors 'none'`** rather than `X-Frame-Options`, plus the older
 *   header for browsers that only read that one. An admin panel in an iframe is
 *   a clickjacking target and never a feature.
 * - **`img-src data:`.** A brand logo can be a data URI, and QR codes for
 *   two-factor enrolment are generated as one.
 *
 * `connect-src 'self'` is what stops a compromised dependency exfiltrating a
 * page's contents, which is the realistic attack against an admin panel: not
 * somebody injecting a script, but a script that is already there talking to
 * somewhere it should not.
 *
 * **Nothing here is configurable.** A security header an operator can turn off
 * is a security header that is off on the installation that needed it. The one
 * exception is the report endpoint, because sending violations somewhere is
 * deployment-specific and its absence must not weaken the policy.
 */
final readonly class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($request) as $header => $value) {
            // `headers->set` rather than a blind overwrite of everything: a
            // response that deliberately set its own — a file download's
            // `Content-Disposition`, say — keeps it.
            if ($value !== null && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string|null>
     */
    private function headers(Request $request): array
    {
        return [
            'Content-Security-Policy' => $this->policy(),

            // An admin panel in an iframe is a clickjacking target. `DENY` for
            // the browsers that read this instead of `frame-ancestors`.
            'X-Frame-Options' => 'DENY',

            // Stops a browser guessing that a text file is JavaScript, which is
            // how a "harmless" upload becomes a script.
            'X-Content-Type-Options' => 'nosniff',

            /*
             * The path of an admin URL is information: `/admin/customers/01H…`
             * names a record. `strict-origin-when-cross-origin` sends the
             * origin and no path to a third party, which is what a webfont or
             * an external documentation link needs and no more.
             */
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            /*
             * Nothing in this product needs a camera, a microphone or a
             * location, so all three are refused for this document and every
             * frame in it. A feature policy is the one place where listing what
             * is *not* needed is cheap and complete.
             */
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',

            /*
             * HSTS only over TLS, and only in production. Sending it from a
             * development server would pin `localhost` to HTTPS in the
             * developer's browser, which takes an afternoon to work out.
             */
            'Strict-Transport-Security' => $request->secure() && app()->isProduction()
                ? 'max-age=31536000; includeSubDomains'
                : null,
        ];
    }

    private function policy(): string
    {
        $directives = [
            "default-src 'self'",

            // Same-origin only. Inertia's page object is a `data-` attribute
            // and the translations block is `application/json`, both so that
            // this line can stay this short.
            "script-src 'self'",

            // Inline styles are required by Vue's scoped styles and by a
            // brand's colour overrides written onto the document root.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            'font-src \'self\' data: https://fonts.gstatic.com',

            // A brand logo can be a data URI; so can a two-factor QR code.
            "img-src 'self' data: blob:",

            // The realistic attack on an admin panel is not injection but a
            // dependency that is already there talking somewhere it should not.
            "connect-src 'self'",

            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
        ];

        $report = config('platform.security.csp_report_uri');

        if (is_string($report) && trim($report) !== '') {
            // Reporting is additive. Its absence must never weaken the policy,
            // which is why the policy is built first and this is appended.
            $directives[] = 'report-uri '.trim($report);
        }

        return implode('; ', $directives);
    }
}
