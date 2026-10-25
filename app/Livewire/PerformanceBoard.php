<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A Performance board (docs/slice-23-performance-boards-plan.md): which
 * Locations and month it shows: one Location or all of the person's, and
 * this month or one of the eleven before it, in the company's time. Each
 * board extends it.
 */
abstract class PerformanceBoard extends Component
{
    #[Url(as: 'location')]
    public ?int $locationId = null;

    #[Url(as: 'month')]
    public string $month = '';

    /** The permission the board needs at a Location. */
    abstract protected function boardPermission(): Permission;

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere($this->boardPermission())->sortBy('name')->values();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function shownLocationIds(): array
    {
        $ids = array_values($this->locations()->map(fn (Location $location): int => $location->id)->all());

        return $this->locationId !== null && in_array($this->locationId, $ids, true) ? [$this->locationId] : $ids;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function months(): array
    {
        $now = CarbonImmutable::now((string) config('app.display_timezone'))->startOfMonth();

        return array_map(fn (int $back): array => [
            'value' => $now->subMonths($back)->format('Y-m'),
            'label' => $now->subMonths($back)->format('F Y'),
        ], range(0, 11));
    }

    /**
     * The month shown: the one asked for if offered, otherwise this month.
     */
    #[Computed]
    public function chosenMonth(): string
    {
        $offered = array_column($this->months(), 'value');

        return in_array($this->month, $offered, true) ? $this->month : $offered[0];
    }

    public function chosenMonthWords(): string
    {
        return (CarbonImmutable::createFromFormat('!Y-m', $this->chosenMonth()) ?: CarbonImmutable::now())->format('F Y');
    }
}
