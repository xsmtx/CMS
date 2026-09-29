<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Api\Devices\DeviceOwners;
use App\Application\Api\Devices\DeviceSessions;
use App\Application\Identity\AuthenticateUser;
use App\Application\Identity\TwoFactorAuthenticator;
use App\Domain\Api\ApiScope;
use App\Domain\Api\DevicePlatform;
use App\Domain\Api\DeviceSession;
use App\Domain\Api\Exceptions\SessionRefused;
use App\Domain\Identity\Guard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceTokenRequest;
use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use App\Support\Errors\UnauthenticatedException;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where a device session begins, renews and ends (ADR 0049).
 *
 * Three endpoints, and none of them is a way round anything the browser
 * already enforces:
 *
 * **Signing in reuses `AuthenticateUser::attempt()`**, which throttles per
 * address and per identity, runs a hash comparison on a miss so the response
 * time does not say which accounts exist, and refuses an account that may not
 * authenticate. A second implementation here would eventually disagree with
 * the one the browser uses, and the one that disagreed would be the way in.
 *
 * **A second factor is required when the account has one.** A device session
 * that skipped it would be two-factor authentication with an opt-out, which
 * is no two-factor authentication at all.
 *
 * **Refreshing is unauthenticated on purpose** — the refresh token *is* the
 * credential — and throttled, because an endpoint that mints a session from
 * one string is the endpoint worth guessing at.
 *
 * Every refusal is the same `unauthenticated`, whatever actually happened.
 * The distinctions are named on the audit row and on the revoked device,
 * never to the caller.
 */
final class DeviceTokenController extends Controller
{
    public function __construct(
        private readonly AuthenticateUser $auth,
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly DeviceSessions $devices,
        private readonly DeviceOwners $owners,
        private readonly CurrentActor $actor,
    ) {}

    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $guard = $this->guard($request);

        $attempt = $this->auth->attempt(
            $guard,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        if (! $attempt->successful || ! $attempt->subject instanceof Model) {
            throw new UnauthenticatedException((string) __('api.errors.unauthenticated'));
        }

        $subject = $attempt->subject;

        if ($subject->hasTwoFactorEnabled() && ! $this->secondFactorHolds($request, $subject)) {
            throw new UnauthenticatedException((string) __('api.errors.unauthenticated'));
        }

        $session = $this->devices->open(
            owner: $subject,
            name: $request->string('device_name')->toString(),
            platform: DevicePlatform::match($request->input('platform')),
            scopes: $this->grantable($subject, $request),
            staff: $guard === Guard::Staff,
        );

        return response()->json($this->payload($session), 201);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $request->string('refresh_token')->toString();

        if ($token === '') {
            throw new UnauthenticatedException((string) __('api.errors.unauthenticated'));
        }

        try {
            $session = $this->devices->rotate($token, staff: $this->guard($request) === Guard::Staff);
        } catch (SessionRefused) {
            // Deliberately flattened. Which of the five refusals fired is
            // information the holder of a stolen token would use.
            throw new UnauthenticatedException((string) __('api.errors.unauthenticated'));
        }

        return response()->json($this->payload($session));
    }

    /**
     * Signing out: the calling device, ended everywhere.
     *
     * The device comes from the token that authenticated this request rather
     * than from the body. A device id in a payload would be a way to sign
     * somebody else's phone out of an account you share.
     */
    public function destroy(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        $device = $token instanceof PersonalAccessToken && $token->api_device_id !== null
            ? ApiDevice::query()->find($token->api_device_id)
            : null;

        if (! $device instanceof ApiDevice) {
            // A token that belongs to no device is a server-to-server token,
            // and this is not how one of those is revoked.
            throw new UnauthenticatedException((string) __('api.errors.unauthenticated'));
        }

        $this->devices->revoke($device, reason: 'lost', by: $this->actor->model());

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DeviceSession $session): array
    {
        return [
            'data' => [
                'device_id' => $session->deviceId,
                'access_token' => $session->accessToken,
                'access_expires_at' => $session->accessExpiresAt->toIso8601String(),
                'refresh_token' => $session->refreshToken,
                'refresh_expires_at' => $session->refreshExpiresAt->toIso8601String(),
            ],
        ];
    }

    private function secondFactorHolds(DeviceTokenRequest $request, Model&AuthenticatableAccount $subject): bool
    {
        $code = $request->string('two_factor_code')->toString();

        if ($code === '') {
            return false;
        }

        // A recovery code is longer than a TOTP one, which is how the browser
        // challenge tells them apart too.
        return $this->twoFactor->challenge($subject, $code, isRecoveryCode: strlen($code) > 6);
    }

    /**
     * What this person may actually consent to share.
     *
     * Filtered rather than refused, like the portal's token screen: a scope
     * the holder is not permitted to use would mean nothing at request time
     * anyway, and dropping it keeps the token honest about what it can do.
     *
     * @return list<string>
     */
    private function grantable(Model $subject, DeviceTokenRequest $request): array
    {
        /** @var list<string> $requested */
        $requested = $request->validated('scopes') ?? [];

        return array_values(array_filter($requested, function (string $value) use ($subject): bool {
            $scope = ApiScope::tryFrom($value);

            if (! $scope instanceof ApiScope) {
                return false;
            }

            return array_all($scope->requiredPermissions(), fn (string $permission): bool => $this->owners->may($subject, $permission));
        }));
    }

    private function guard(Request $request): Guard
    {
        return Guard::fromRouteName($request->route()?->getName()) ?? Guard::Client;
    }
}
