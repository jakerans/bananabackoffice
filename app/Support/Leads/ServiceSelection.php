<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The services chosen for a Lead, and which one is the primary.
 *
 * A single service is the primary without anyone saying so. With several, the
 * primary is the one a person named. It is never chosen for them: when several
 * are selected and none of them is named, there is no primary, and the actions
 * that save a Lead refuse it.
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

        $primary = $services->count() === 1
            ? $services->first()
            : $services->firstWhere('id', (int) $primaryServiceId);

        return new self($services, $primary);
    }

    /**
     * Refuse a selection a Lead cannot be saved with: no service at all, or
     * several with none identified as the main one.
     *
     * @throws ValidationException
     */
    public function ensureComplete(): void
    {
        if ($this->services->isEmpty()) {
            throw ValidationException::withMessages([
                'serviceIds' => __('Choose at least one service.'),
            ]);
        }

        if ($this->primary === null) {
            throw ValidationException::withMessages([
                'primaryServiceId' => __('Choose which service is the main one.'),
            ]);
        }
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
