<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Models\SalesLossReason;
use App\Models\Service;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Addresses\AddressLookup;
use App\Support\Addresses\MapboxAddressLookup;
use App\Support\Addresses\UnavailableAddressLookup;
use App\Support\Navigation\Navigation;
use App\Support\ServiceAreas\PostalCodeServiceAreas;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Navigation::class);

        // Address lookup is offered only when a provider's token has been set up.
        // Without one, staff type addresses by hand and nothing else changes.
        $this->app->bind(AddressLookup::class, function (): AddressLookup {
            $token = config('services.mapbox.token');

            return is_string($token) && $token !== ''
                ? new MapboxAddressLookup($token)
                : new UnavailableAddressLookup;
        });

        $this->app->bind(ServiceAreaResolver::class, PostalCodeServiceAreas::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRecordNames();
        $this->configureAuthorization();
    }

    /**
     * Give each kind of record a short, fixed name for wherever one record points at "any kind of record".
     *
     * Audit events store these names, not PHP class names, so they stay
     * readable if a class is ever moved or renamed. Every kind of record that
     * can be pointed at this way must be listed here; an unlisted one is refused.
     */
    protected function configureRecordNames(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'location' => Location::class,
            'marketing_source' => MarketingSource::class,
            'lead_source' => LeadSource::class,
            'lead_channel' => LeadChannel::class,
            'lead_disqualification_reason' => LeadDisqualificationReason::class,
            'sales_loss_reason' => SalesLossReason::class,
            'lead' => Lead::class,
            'service' => Service::class,
            'project_size' => ProjectSize::class,
        ]);
    }

    /**
     * Define one gate per permission.
     *
     * A gate checked with a location answers for that location. Checked without
     * one, a company-level permission answers company-wide and a location-level
     * permission answers for the staff member's current location.
     */
    protected function configureAuthorization(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission, function (User $user, ?Location $location = null) use ($permission): bool {
                if ($location !== null) {
                    return $user->hasPermissionAt($permission, $location);
                }

                if ($permission->isCompanyLevel()) {
                    return $user->hasPermissionAcrossAllLocations($permission);
                }

                $context = $this->app->make(CurrentLocation::class)->context($user);

                return $context !== null && $user->hasPermissionIn($permission, $context);
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
