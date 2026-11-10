<?php

namespace App\Support\Locations;

use App\Console\Commands\ProductionCheck;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Enums\SubcontractorStanding;
use App\Models\AccessGrant;
use App\Models\AgreementWording;
use App\Models\Location;
use App\Models\PayPlan;
use App\Models\PriceBook;
use App\Models\QuickBooksConnection;
use App\Models\Subcontractor;
use App\Models\User;
use App\Support\Queue\LocationTimezones;
use Illuminate\Support\Collection;

/**
 * What a Location needs before it can sell, in order, and what the company
 * needs once (docs/slice-37-location-setup-plan.md, step 37b; decision 220).
 * Each step reads what is already recorded where it is done; nothing is kept
 * here. The ✱ steps are required before the Location is switched on.
 */
class LocationSetup
{
    /**
     * The Location's steps, in the plan's order.
     *
     * @return list<SetupStep>
     */
    public function steps(Location $location): array
    {
        $priceBook = PriceBook::forLocation($location);
        $hasOwnBook = $priceBook !== null && $priceBook->location_id === $location->id;
        $grants = AccessGrant::query()
            ->with('user')
            ->where('scope', GrantScope::Location)
            ->where('location_id', $location->id)
            ->whereIn('role', [Role::LocationManager, Role::SalesConsultant])
            ->get()
            ->filter(fn (AccessGrant $grant): bool => $grant->user->is_active);
        $managers = $grants->where('role', Role::LocationManager)->map->user->unique('id')->values();
        $salespeople = $grants->where('role', Role::SalesConsultant)->map->user->unique('id')->values();
        $postalCodes = $location->servicePostalCodes()->count();
        $taxRates = $priceBook?->taxRates()->where('is_active', true)->count() ?? 0;
        $pricingUrl = route('settings.pricing', $hasOwnBook ? ['book' => $location->id] : []);

        return [
            new SetupStep('name', __('Its name and time zone'), true, true, $location->name.' · '.LocationTimezones::label($location->timezone)),
            new SetupStep(
                'service_area',
                __('Its service area'),
                true,
                $postalCodes > 0,
                $postalCodes > 0 ? trans_choice(':count ZIP code served|:count ZIP codes served', $postalCodes) : __('No ZIP codes yet. A new lead\'s address is matched to them.'),
            ),
            new SetupStep(
                'prices',
                __('Its prices'),
                true,
                $priceBook !== null && $priceBook->rates()->exists(),
                match (true) {
                    $priceBook === null || ! $priceBook->rates()->exists() => __('The default price book has no prices yet. Import them from PaintScout.'),
                    $hasOwnBook => __('Its own price book.'),
                    default => __('The default price book, until it has its own.'),
                },
                $pricingUrl,
                __('Settings > Pricing Settings'),
            ),
            new SetupStep(
                'tax_rate',
                __('A tax rate'),
                true,
                $taxRates > 0,
                $taxRates > 0 ? trans_choice(':count tax rate in its price book|:count tax rates in its price book', $taxRates) : __('None in its price book yet.'),
                route('settings.pricing', [...($hasOwnBook ? ['book' => $location->id] : []), 'tab' => 'hourly']),
                __('Settings > Pricing Settings > Hourly rates and tax'),
            ),
            new SetupStep(
                'agreement',
                __('The agreement\'s wording'),
                true,
                AgreementWording::current() !== null,
                AgreementWording::current() !== null ? __('Version :version, for the whole company.', ['version' => AgreementWording::current()->version]) : __('Not yet. It comes with the PaintScout import.'),
                route('settings.agreement'),
                __('Settings > Pricing Settings > Agreement'),
            ),
            new SetupStep(
                'people',
                __('Its people'),
                true,
                $managers->isNotEmpty() && $salespeople->isNotEmpty(),
                $this->peopleWords($managers, $salespeople),
                route('settings.users'),
                __('Settings > Users and Access'),
            ),
            new SetupStep('deposit_margin', __('Its deposit and target margin'), false, true, __(':deposit% deposit, :margin% target margin.', ['deposit' => $location->deposit_percent, 'margin' => $location->target_margin_percent])),
            $this->payPlans($salespeople),
            new SetupStep(
                'scorecard',
                __('Its scorecard owner and targets'),
                false,
                $location->scorecard_owner_id !== null && $location->nsli_target_cents !== null && $location->cost_per_close_target_cents !== null && $location->net_profit_target_percent !== null,
                $location->scorecard_owner_id === null ? __('The Owner, until one is chosen.') : __('Owned by :name.', ['name' => $location->scorecardOwner?->name]),
                route('settings.locations'),
                __('Settings > Locations > Scorecard owner'),
            ),
            new SetupStep(
                'quickbooks',
                __('Its QuickBooks company'),
                false,
                QuickBooksConnection::query()->where('location_id', $location->id)->exists(),
                QuickBooksConnection::query()->where('location_id', $location->id)->exists() ? __('Connected.') : __('Not connected.'),
                route('settings.integrations'),
                __('Settings > Integrations'),
            ),
            $this->subcontractors($location),
        ];
    }

    /**
     * What the company needs once, for every Location, until it is done.
     *
     * @return list<SetupStep>
     */
    public function company(): array
    {
        $hasPrices = PriceBook::companyDefault()?->rates()->exists() ?? false;
        $hasWording = AgreementWording::current() !== null;

        return [
            new SetupStep('owner', __('An Owner who can sign in'), true, User::query()->usableOwners()->exists(), __('Created from Forge\'s Commands tab.')),
            new SetupStep('default_prices', __('The default price book imported'), true, $hasPrices, $hasPrices ? __('It has its prices.') : __('Upload the saved PaintScout file.'), route('settings.pricing'), __('Settings > Pricing Settings')),
            new SetupStep('wording', __('The agreement\'s wording'), true, $hasWording, $hasWording ? __('In place.') : __('Upload the saved PaintScout file.'), route('settings.pricing'), __('Settings > Pricing Settings')),
            new SetupStep('customer_email', __('Customer email\'s sending domain'), false, ProductionCheck::customerEmailIsReady(), __('Until it is set up, signed agreements are not emailed to customers.')),
            new SetupStep('quickbooks_keys', __('QuickBooks\'s live keys'), false, ProductionCheck::quickBooksIsLive(), __('After Intuit\'s review.')),
        ];
    }

    /**
     * The required steps the Location still lacks.
     *
     * @return list<SetupStep>
     */
    public function missing(Location $location): array
    {
        return array_values(array_filter($this->steps($location), fn (SetupStep $step): bool => $step->required && ! $step->done));
    }

    /**
     * The first step not done: the required ones first, then the rest.
     */
    public function next(Location $location): ?SetupStep
    {
        $open = array_filter($this->steps($location), fn (SetupStep $step): bool => ! $step->done);

        return collect($open)->sortBy(fn (SetupStep $step): int => $step->required ? 0 : 1)->first();
    }

    /**
     * @param  Collection<int, User>  $managers
     * @param  Collection<int, User>  $salespeople
     */
    private function peopleWords(Collection $managers, Collection $salespeople): string
    {
        $waiting = $managers->merge($salespeople)->unique('id')->filter(fn (User $user): bool => $user->invitationIsPending())->count();

        $words = __(':managers and :salespeople.', [
            'managers' => trans_choice('{0} No manager|{1} :count manager|[2,*] :count managers', $managers->count()),
            'salespeople' => trans_choice('{0} no salesperson|{1} :count salesperson|[2,*] :count salespeople', $salespeople->count()),
        ]);

        return $waiting === 0 ? $words : $words.' '.trans_choice(':count has not used their invitation yet.|:count have not used their invitations yet.', $waiting);
    }

    /**
     * @param  Collection<int, User>  $salespeople
     */
    private function payPlans(Collection $salespeople): SetupStep
    {
        $today = now()->toDateString();
        $without = $salespeople->filter(fn (User $user): bool => PayPlan::onDay($user->id, PayPlan::FAMILY_SALES, $today) === null);

        return new SetupStep(
            'pay_plans',
            __('Pay plans for its salespeople'),
            false,
            $salespeople->isNotEmpty() && $without->isEmpty(),
            match (true) {
                $salespeople->isEmpty() => __('No salespeople yet.'),
                $without->isEmpty() => __('Every salesperson has one.'),
                default => __('Still needed for :names. Commission waits for it.', ['names' => $without->pluck('name')->join(', ')]),
            },
            route('finance.payroll'),
            __('Finance > Payroll'),
        );
    }

    private function subcontractors(Location $location): SetupStep
    {
        $count = Subcontractor::query()
            ->where('standing', SubcontractorStanding::Active)
            ->whereRelation('locations', 'locations.id', $location->id)
            ->count();

        return new SetupStep(
            'subcontractors',
            __('Its subcontractors'),
            false,
            $count > 0,
            $count > 0 ? trans_choice(':count active subcontractor|:count active subcontractors', $count) : __('None yet. Needed before a job is awarded; added once it is switched on.'),
            route('production.subcontractors'),
            __('Production > Subcontractors'),
        );
    }
}
