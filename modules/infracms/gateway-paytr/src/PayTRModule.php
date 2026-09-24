<?php

declare(strict_types=1);

namespace InfraCMS\GatewayPayTR;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * PayTR, as a module.
 *
 * Declares the three credentials PayTR issues and hands core a gateway. It has
 * no opinion about what the form looks like and no way to have one: core draws
 * it, validates it, stores it and masks it.
 *
 * The salt never leaves this process. It is not sent to PayTR in any request —
 * it only ever goes into a hash — which is exactly why it is a separate secret
 * from the key.
 */
final class PayTRModule extends BaseModule
{
    private string $merchantId = '';

    private string $merchantKey = '';

    private string $merchantSalt = '';

    private bool $testMode = false;

    private bool $instalments = true;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('merchant_id', 'Merchant ID', ConfigFieldType::Text, required: true),
            new ConfigField('merchant_key', 'Merchant key', ConfigFieldType::Secret, required: true),
            new ConfigField('merchant_salt', 'Merchant salt', ConfigFieldType::Secret, required: true),
            new ConfigField('test_mode', 'Test mode', ConfigFieldType::Boolean, required: false, default: false),
            new ConfigField('installment', 'Allow instalments', ConfigFieldType::Boolean, required: false, default: true),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->merchantId = (string) $context->config('merchant_id', '');
        $this->merchantKey = (string) $context->config('merchant_key', '');
        $this->merchantSalt = (string) $context->config('merchant_salt', '');
        $this->testMode = (bool) $context->config('test_mode', false);
        $this->instalments = (bool) $context->config('installment', true);
    }

    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        // Unconfigured means unregistered, so an operator picking a payment
        // method is picking from the set that actually works rather than one
        // that fails at the till.
        if ($this->merchantId === '' || $this->merchantKey === '' || $this->merchantSalt === '') {
            return [];
        }

        return [new PayTRGateway(
            merchantId: $this->merchantId,
            merchantKey: $this->merchantKey,
            merchantSalt: $this->merchantSalt,
            testMode: $this->testMode,
            instalmentsAllowed: $this->instalments,
        )];
    }
}
