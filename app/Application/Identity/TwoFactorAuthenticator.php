<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use App\Support\Audit\Facades\Audit;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification.
 *
 * Enrolment is three steps on purpose: generate a secret, confirm it with a
 * live code, then activate. A secret that is stored but never confirmed
 * protects nothing and locks out anyone whose authenticator app silently
 * failed to scan.
 */
final readonly class TwoFactorAuthenticator
{
    /**
     * One window either side, so a code is accepted for roughly ninety
     * seconds. Clocks on phones drift, and a stricter window turns into
     * support tickets rather than security.
     */
    private const int WINDOW = 1;

    public function __construct(private Google2FA $google2fa) {}

    /**
     * Begin enrolment. The secret is stored but inactive until confirmed.
     */
    public function beginEnrolment(Model&AuthenticatableAccount $subject): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $subject->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * Complete enrolment. Returns the recovery codes, which are shown once
     * and never retrievable again.
     *
     * @return list<string>|null Null when the code does not verify.
     */
    public function confirmEnrolment(Model&AuthenticatableAccount $subject, string $code): ?array
    {
        $secret = $subject->getAttribute('two_factor_secret');

        if (! is_string($secret) || ! $this->verifyCode($secret, $code)) {
            return null;
        }

        $subject->forceFill(['two_factor_confirmed_at' => CarbonImmutable::now()])->save();

        $codes = $subject->regenerateRecoveryCodes();

        Audit::action('identity.two_factor.enabled')
            ->by($subject)
            ->on($subject)
            ->write();

        return $codes;
    }

    public function disable(Model&AuthenticatableAccount $subject, ?Model $actor = null): void
    {
        $subject->disableTwoFactor();

        Audit::action('identity.two_factor.disabled')
            ->by($actor ?? $subject)
            ->on($subject)
            ->write();
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Model&AuthenticatableAccount $subject): array
    {
        $codes = $subject->regenerateRecoveryCodes();

        Audit::action('identity.two_factor.recovery_codes_regenerated')
            ->by($subject)
            ->on($subject)
            ->write();

        return $codes;
    }

    /**
     * Verify a challenge response: a six-digit code, or a recovery code.
     *
     * A recovery code is consumed on use and cannot be replayed.
     */
    public function challenge(Model&AuthenticatableAccount $subject, string $code, bool $isRecoveryCode = false): bool
    {
        if ($isRecoveryCode) {
            return $subject->consumeRecoveryCode(trim($code));
        }

        $secret = $subject->getAttribute('two_factor_secret');

        return is_string($secret) && $this->verifyCode($secret, $code);
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     */
    public function provisioningUri(Model&AuthenticatableAccount $subject, string $issuer): string
    {
        return $this->google2fa->getQRCodeUrl(
            $issuer,
            (string) $subject->getAttribute('email'),
            (string) $subject->getAttribute('two_factor_secret'),
        );
    }

    /**
     * Inline SVG for the provisioning URI.
     *
     * Rendered server side rather than by a third-party image endpoint,
     * because that endpoint would receive the shared secret.
     */
    public function qrCodeSvg(string $provisioningUri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd));

        return $writer->writeString($provisioningUri);
    }

    private function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }
}
