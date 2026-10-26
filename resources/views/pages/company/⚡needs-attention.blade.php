<?php

use App\Enums\Permission;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Company > Needs Attention (docs/slice-26-needs-attention-plan.md, step 26b;
 * decision 185, question 4; product-architecture.md, 18): every item the
 * exception engine found, company-wide, for the Owner and Leadership.
 */
new #[Title('Needs Attention')] class extends Component {
    public function mount(): void
    {
        $this->authorize(Permission::CompanyOverviewAccess);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="company-needs-attention">
    <div>
        <flux:heading size="xl" level="1">{{ __('Needs Attention') }}</flux:heading>
        <flux:subheading>{{ __('What needs someone across the company, the most serious first, with who owns each.') }}</flux:subheading>
    </div>

    <livewire:pages::attention.list scope="company" />
</section>
