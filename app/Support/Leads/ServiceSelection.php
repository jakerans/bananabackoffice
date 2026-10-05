<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;

/**
 * The services chosen for a Lead, and which one is the primary.
 *
 * A Lead may have none. When it has any, exactly one is primary: the one that
 * was named, or failing that the first in the list's order.
 */
final readonly class ServiceSelection
{
    /**
     * @param  Collection<int, Service>  $services
     */
    private function __construct(
        public Collection $services,
        public ?Service $primary,
    ) {}

    public static function none(): self
    {
        return new self(new Collection, null);
    }

    /**
     * The services a Lead has now.
     */
    public static function ofLead(Lead $lead): self
    {
        return new self($lead->services, $lead->primaryService());
    }

    /**
     * Build the selection from IDs that came from a form.
     *
     * IDs that do not match a service are ignored here; the form validates them.
     *
     * @param  array<int, int|string>  $serviceIds
     */
    public static function fromIds(array $serviceIds, int|string|null $primaryServiceId = null): self
    {
        $ids = array_values(array_unique(array_map(intval(...), $serviceIds)));

        $services = $ids === []
            ? new Collection
            : Service::query()->whereKey($ids)->ordered()->get();

        $primary = $services->firstWhere('id', (int) $primaryServiceId) ?? $services->first();

        return new self($services, $primary);
    }

    /**
     * The service names in list order, for display and for the audit history.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values($this->services->pluck('name')->all());
    }

    /**
     * What to give the relationship so it stores these services and marks the primary.
     *
     * @return array<int, array{is_primary: bool}>
     */
    public function forSync(): array
    {
        return $this->services
            ->mapWithKeys(fn (Service $service): array => [$service->id => ['is_primary' => $service->is($this->primary)]])
            ->all();
    }

    /**
     * Whether another selection has the same services and the same primary.
     */
    public function matches(self $other): bool
    {
        return $this->services->modelKeys() === $other->services->modelKeys()
            && $this->primary?->id === $other->primary?->id;
    }
}
