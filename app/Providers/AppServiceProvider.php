<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Carbon\CarbonImmutable;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
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
