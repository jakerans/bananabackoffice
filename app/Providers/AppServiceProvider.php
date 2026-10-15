<?php

namespace App\Providers;

use App\Actions\Staff\StopImpersonation;
use App\Enums\ImpersonationEndReason;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppointmentCancellationReason;
use App\Models\Contact;
use App\Models\ContactCenterSettings;
use App\Models\InsuranceDeductionRate;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\Objection;
use App\Models\Project;
use App\Models\ProjectSize;
use App\Models\Property;
use App\Models\SalesLossReason;
use App\Models\Service;
use App\Models\Subcontractor;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Access\Impersonation;
use App\Support\Addresses\AddressLookup;
use App\Support\Addresses\MapboxAddressLookup;
use App\Support\Addresses\UnavailableAddressLookup;
use App\Support\Navigation\Navigation;
use App\Support\ServiceAreas\PostalCodeServiceAreas;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        // One per request: it notes who the request is being served as.
        $this->app->scoped(Impersonation::class);

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
        $this->configureImpersonation();
    }

    /**
     * Signing out ends the whole session, so it also ends viewing Backoffice as
     * someone else. That is recorded like any other stop.
     */
    protected function configureImpersonation(): void
    {
        Event::listen(function (Logout $event): void {
            if ($this->app->make('request')->hasSession()) {
                $this->app->make(StopImpersonation::class)->handle(ImpersonationEndReason::SignedOut);
            }
        });
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
            'appointment_cancellation_reason' => AppointmentCancellationReason::class,
            'objection' => Objection::class,
            'lead' => Lead::class,
            'project' => Project::class,
            'service' => Service::class,
            'project_size' => ProjectSize::class,
            'account' => Account::class,
            'contact' => Contact::class,
            'property' => Property::class,
            'contact_center_settings' => ContactCenterSettings::class,
            'subcontractor' => Subcontractor::class,
            'insurance_deduction_rate' => InsuranceDeductionRate::class,
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
