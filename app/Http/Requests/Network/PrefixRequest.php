<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use App\Domain\Network\Exceptions\InvalidAddress;
use App\Domain\Network\IpAddress;
use App\Domain\Network\IpPrefix;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A network, as an operator types it.
 *
 * The CIDR is validated by parsing it rather than by a regular expression: the
 * value object already knows what an address is, and a second answer to that
 * question in a regex would be a second answer that disagrees. Its refusal
 * carries the sentence too — `192.0.2.5/24` comes back saying the network was
 * `192.0.2.0/24`, which is the correction the operator needs.
 *
 * The table a rule names is the **table**: `vlans`, which is also what the model
 * is called here, but checking has cost nothing twice now and cost a phase once.
 */
final class PrefixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ip_pool_id' => ['required', 'string', 'exists:ip_pools,id'],
            'cidr' => ['required', 'string', 'max:45', $this->parses(
                static fn (string $value): IpPrefix => IpPrefix::parse($value),
            )],
            'gateway' => ['nullable', 'string', 'max:45', $this->parses(
                static fn (string $value): IpAddress => IpAddress::parse($value),
            )],
            'vlan_id' => ['nullable', 'string', 'exists:vlans,id'],
            'site' => ['nullable', 'string', 'max:96'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A rule that refuses whatever the value object refuses, in its own words.
     *
     * @param  Closure(string): mixed  $parser
     */
    private function parses(Closure $parser): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($parser): void {
            if (! is_string($value)) {
                return;
            }

            try {
                $parser($value);
            } catch (InvalidAddress $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
