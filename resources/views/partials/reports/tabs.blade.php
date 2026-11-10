{{-- A Center's reports, one tab each (docs/slice-32-reports-plan.md). $tabs: route name => label; $current: this report's route name. The range and Location travel with the tab. --}}
<flux:navbar class="-mb-2 border-b border-zinc-200" data-test="report-tabs">
    @foreach ($tabs as $routeName => $label)
        <flux:navbar.item :href="route($routeName, array_filter(['range' => $this->range, 'location' => $this->locationId, 'from' => $this->chosenRange === \App\Enums\ReportRange::Custom ? $this->from : '', 'to' => $this->chosenRange === \App\Enums\ReportRange::Custom ? $this->to : '']))" :current="$routeName === $current" wire:navigate wire:key="tab-{{ $routeName }}">{{ $label }}</flux:navbar.item>
    @endforeach
</flux:navbar>
