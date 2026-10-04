<?php

namespace App\Support\Navigation;

use App\Enums\NavigationState;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Arr;

/**
 * The single definition of Backoffice navigation. See docs/navigation-map.md.
 *
 * A feature that is not built yet is listed here as "coming soon" and has no
 * route, table or page. It borrows its center's permissions until it is built.
 */
class Navigation
{
    /** @var list<NavigationItem>|null */
    private ?array $centers = null;

    public function __construct(private Gate $gate) {}

    /**
     * Get every center, in display order.
     *
     * @return list<NavigationItem>
     */
    public function centers(): array
    {
        return $this->centers ??= [
            $this->company(),
            $this->marketing(),
            $this->sales(),
            $this->production(),
            $this->finance(),
            $this->colorStudio(),
            $this->settings(),
        ];
    }

    public function center(string $id): ?NavigationItem
    {
        return Arr::first($this->centers(), fn (NavigationItem $center): bool => $center->id === $id);
    }

    /**
     * Get the center that a route belongs to.
     */
    public function centerForRoute(?string $routeName): ?NavigationItem
    {
        if ($routeName === null) {
            return null;
        }

        return Arr::first(
            $this->centers(),
            fn (NavigationItem $center): bool => Arr::first(
                $center->flatten(),
                fn (NavigationItem $item): bool => $item->ownsRoute($routeName),
            ) !== null,
        );
    }

    /**
     * Determine whether the staff member may see an item in their current location.
     */
    public function allows(User $user, NavigationItem $item): bool
    {
        return $this->gate->forUser($user)->any($item->permissions);
    }

    /**
     * Get the centers the staff member may open in their current location.
     *
     * @return list<NavigationItem>
     */
    public function centersFor(User $user): array
    {
        return array_values(array_filter(
            $this->centers(),
            fn (NavigationItem $center): bool => $this->allows($user, $center),
        ));
    }

    private function company(): NavigationItem
    {
        $permissions = [Permission::CompanyOverviewAccess];

        return new NavigationItem(
            id: 'company',
            label: 'Company Overview',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'company.home',
            icon: 'building-office-2',
            description: 'Company-wide performance across every location.',
            children: [
                new NavigationItem('company.performance', 'Company Performance', $permissions),
                new NavigationItem('company.location_comparison', 'Location Comparison', $permissions),
                new NavigationItem('company.reports', 'Company Reports', $permissions),
            ],
        );
    }

    private function marketing(): NavigationItem
    {
        $permissions = [Permission::MarketingAccess];

        return new NavigationItem(
            id: 'marketing',
            label: 'Marketing Center',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'marketing.home',
            icon: 'megaphone',
            description: 'Leads, the call center, campaigns and reviews.',
            children: [
                new NavigationItem('marketing.performance', 'Performance Board', $permissions),
                new NavigationItem('marketing.call_center', 'Call Center', $permissions, children: [
                    new NavigationItem('marketing.call_center.inbox', 'Central Inbox', $permissions),
                    new NavigationItem('marketing.call_center.leads', 'Leads', $permissions),
                    new NavigationItem('marketing.call_center.appointments', 'Appointments', $permissions),
                    new NavigationItem('marketing.call_center.close_assist', 'Close Assist', $permissions),
                    new NavigationItem('marketing.call_center.canceled', 'Canceled Appointments', $permissions),
                    new NavigationItem('marketing.call_center.reports', 'Call Center Reports', $permissions),
                ]),
                ...$this->shared($permissions),
                new NavigationItem('marketing.campaigns', 'Campaigns', $permissions),
                new NavigationItem('marketing.social', 'Social', $permissions),
                new NavigationItem('marketing.reviews', 'Reviews', $permissions),
                new NavigationItem('marketing.reporting', 'Reporting', $permissions, children: [
                    new NavigationItem('marketing.reporting.lead_sources', 'Lead Sources', $permissions),
                    new NavigationItem('marketing.reporting.leads', 'Leads', $permissions),
                    new NavigationItem('marketing.reporting.appointments', 'Appointments', $permissions),
                    new NavigationItem('marketing.reporting.call_center', 'Call Center', $permissions),
                    new NavigationItem('marketing.reporting.campaigns', 'Campaigns', $permissions),
                    new NavigationItem('marketing.reporting.roi', 'Marketing ROI', $permissions),
                ]),
            ],
        );
    }

    private function sales(): NavigationItem
    {
        $permissions = [Permission::SalesAccess];

        return new NavigationItem(
            id: 'sales',
            label: 'Sales Center',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'sales.home',
            icon: 'currency-dollar',
            description: 'Appointments, estimates, proposals and commissions.',
            children: [
                new NavigationItem('sales.performance', 'Performance Board', $permissions),
                new NavigationItem('sales.appointments', 'Appointments', $permissions),
                new NavigationItem('sales.estimates', 'Estimates', $permissions),
                new NavigationItem('sales.proposals', 'Proposals', $permissions),
                new NavigationItem('sales.relationships', 'Relationships', $permissions),
                ...$this->shared($permissions),
                new NavigationItem('sales.commissions', 'Commissions', $permissions),
                new NavigationItem('sales.reports', 'Reports', $permissions),
            ],
        );
    }

    private function production(): NavigationItem
    {
        $permissions = [Permission::ProductionAccess];

        return new NavigationItem(
            id: 'production',
            label: 'Production Center',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'production.home',
            icon: 'paint-brush',
            description: 'Scheduling, projects, crews and subcontractors.',
            children: [
                new NavigationItem('production.performance', 'Performance Board', $permissions),
                new NavigationItem('production.projects', 'Projects', $permissions, children: [
                    new NavigationItem('production.projects.ready', 'Ready to Schedule', $permissions),
                    new NavigationItem('production.projects.pre_production', 'Pre-Production', $permissions),
                    new NavigationItem('production.projects.in_production', 'In Production', $permissions),
                    new NavigationItem('production.projects.completed', 'Completed', $permissions),
                ]),
                new NavigationItem('production.crews', 'Crews / Subcontractors', $permissions),
                ...$this->shared($permissions),
                new NavigationItem('production.reports', 'Reports', $permissions),
            ],
        );
    }

    private function finance(): NavigationItem
    {
        $permissions = [Permission::FinanceAccess];

        return new NavigationItem(
            id: 'finance',
            label: 'Finance Center',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'finance.home',
            icon: 'banknotes',
            description: 'Receivables, job costing, payroll and commission approvals.',
            children: [
                new NavigationItem('finance.performance', 'Performance Board', $permissions),
                new NavigationItem('finance.cash', 'Cash Management', $permissions, children: [
                    new NavigationItem('finance.cash.receivables', 'Receivables', $permissions),
                    new NavigationItem('finance.cash.payments', 'Payments', $permissions),
                    new NavigationItem('finance.cash.payouts', 'Payouts', $permissions),
                ]),
                new NavigationItem('finance.job_costing', 'Job Costing', $permissions),
                new NavigationItem('finance.payroll', 'Payroll', $permissions),
                new NavigationItem('finance.commission_approvals', 'Commission Approvals', $permissions),
                ...$this->shared($permissions),
                new NavigationItem('finance.reports', 'Reports', $permissions),
            ],
        );
    }

    private function colorStudio(): NavigationItem
    {
        $permissions = [Permission::ColorStudioAccess];

        return new NavigationItem(
            id: 'color_studio',
            label: 'Color Studio',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'color_studio.home',
            icon: 'swatch',
            description: 'Color projects, sample requests and the color catalog.',
            children: [
                new NavigationItem('color_studio.projects', 'Color Projects', $permissions),
                new NavigationItem('color_studio.sample_requests', 'Sample Requests', $permissions),
                new NavigationItem('color_studio.estimate_requests', 'Estimate Requests', $permissions),
                new NavigationItem('color_studio.catalog', 'Color Catalog', $permissions),
            ],
        );
    }

    private function settings(): NavigationItem
    {
        $permissions = [Permission::ManageLocations, Permission::ManageUsers];

        return new NavigationItem(
            id: 'settings',
            label: 'Settings',
            permissions: $permissions,
            state: NavigationState::Live,
            route: 'settings.home',
            icon: 'cog-6-tooth',
            description: 'Locations, staff access and company configuration.',
            children: [
                new NavigationItem('settings.company', 'Company', $permissions),
                new NavigationItem(
                    id: 'settings.locations',
                    label: 'Locations',
                    permissions: [Permission::ManageLocations],
                    state: NavigationState::Live,
                    route: 'settings.locations',
                ),
                new NavigationItem(
                    id: 'settings.users',
                    label: 'Users and Access',
                    permissions: [Permission::ManageUsers],
                    state: NavigationState::Live,
                    route: 'settings.users',
                ),
                new NavigationItem('settings.service_areas', 'Service Areas', $permissions),
                new NavigationItem('settings.services', 'Services and Products', $permissions),
                new NavigationItem('settings.pricing', 'Pricing Settings', $permissions),
                new NavigationItem('settings.templates', 'Templates', $permissions),
                new NavigationItem('settings.integrations', 'Integrations', $permissions),
                new NavigationItem('settings.audit_log', 'Audit Log', $permissions),
            ],
        );
    }

    /**
     * Customers and Inbox each exist once and are reached from several centers.
     *
     * @param  list<Permission>  $permissions
     * @return list<NavigationItem>
     */
    private function shared(array $permissions): array
    {
        return [
            new NavigationItem('shared.customers', 'Customers', $permissions),
            new NavigationItem('shared.inbox', 'Inbox', $permissions),
        ];
    }
}
