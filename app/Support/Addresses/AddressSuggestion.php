<?php

namespace App\Support\Addresses;

/**
 * One address offered while someone types. It is shown and then discarded:
 * only the address a person actually selects is looked up to be kept.
 */
final readonly class AddressSuggestion
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}

    /**
     * @return array{id: string, label: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}
