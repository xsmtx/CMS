<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * How a configurable option is presented and priced.
 */
enum OptionType: string
{
    /** One choice from a list; each choice carries its own price. */
    case Select = 'select';

    /** The same, rendered as radio buttons. */
    case Radio = 'radio';

    /** On or off; the single option's price applies when on. */
    case Checkbox = 'checkbox';

    /** A number; the single option's price is multiplied by it. */
    case Quantity = 'quantity';

    public function labelKey(): string
    {
        return 'catalog.option_types.'.$this->value;
    }

    /**
     * Whether the customer picks from several options, or the group has
     * exactly one whose price is switched on or scaled.
     */
    public function hasMultipleChoices(): bool
    {
        return $this === self::Select || $this === self::Radio;
    }

    public function isQuantity(): bool
    {
        return $this === self::Quantity;
    }
}
