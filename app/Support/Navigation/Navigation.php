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
 *
 * A center has no page of its own: opening it goes straight to its primary
 * section. That one section therefore always has a page, which is a plain
 * "coming soon" page until the feature is built.
 */
class Navigation
{
    /** @var list<NavigationItem>|null */
    private ?array $centers = null;

    /** @var list<NavigationItem>|null */
    private ?array $externalSurfaces = null;

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
        ];
    }

    /**
     * Get external surfaces that remain reachable from the Backoffice shell.
     *
     * @return list<NavigationItem>
     */
    public function externalSurfaces(): array
    {
        return $this->externalSurfaces ??= [
            $this->colorStudio(),
        ];
    }

    /**
     * Get every navigation destination that may own a route or shell context.
     *
     * @return list<NavigationItem>
     */
    public function destinations(): array
    {
        return [...$this->centers(), ...$this->externalSurfaces()];
    }

    public function center(string $id): ?NavigationItem
    {
        return Arr::first($this->centers(), fn (NavigationItem $center): bool => $center->id === $id);
    }

    public function destination(string $id): ?NavigationItem
    {
        return Arr::first($this->destinations(), fn (NavigationItem $destination): bool => $destination->id === $id);
    }

    /**
     * Find a center or section by its stable ID.
     */
    public function find(string $id): ?NavigationItem
    {
        foreach ($this->destinations() as $destination) {
            foreach ($destination->flatten() as $item) {
                if ($item->id === $id) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Get the section a center opens on for this staff member: its primary
     * section, or failing that the first section they may open.
     */
    public function landingFor(User $user, NavigationItem $center): ?NavigationItem
    {
        $candidates = array_filter([$center->primaryChild(), ...$center->children]);

        return Arr::first(
            $candidates,
            fn (NavigationItem $section): bool => $section->isReachable() && $this->allows($user, $section),
        );
    }

    /**
     * Get the first navigation destination that a route belongs to.
     */
    public function destinationForRoute(?string $routeName): ?NavigationItem
    {
        if ($routeName === null) {
            return null;
        }

        return Arr::first(
            $this->destinations(),
            fn (NavigationItem $destination): bool => Arr::first(
                $destination->flatten(),
                fn (NavigationItem $item): bool => $item->ownsRoute($routeName),
            ) !== null,
        );
    }

    /**
     * Get every navigation destination whose menu contains a route.
     * A shared page belongs to more than one.
     *
     * @return list<NavigationItem>
     */
    public function destinationsForRoute(?string $routeName): array
    {
        if ($routeName === null) {
            return [];
        }

        return array_values(array_filter(
            $this->destinations(),
            fn (NavigationItem $destination): bool => Arr::first(
                $destination->flatten(),
                fn (NavigationItem $item): bool => $item->ownsRoute($routeName),
            ) !== null,
        ));
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

    /**
     * Get the external surfaces the staff member may open in their current location.
     *
     * @return list<NavigationItem>
     */
    public function externalSurfacesFor(User $user): array
    {
        return array_values(array_filter(
            $this->externalSurfaces(),
            fn (NavigationItem $surface): bool => $this->allows($user, $surface),
        ));
    }

    private function company(): NavigationItem
    {
        $overviewPermissions = [Permission::CompanyOverviewAccess];
        $entryPermissions = [
            Permission::CompanyOverviewAccess,
            Permission::ManageLocations,
            Permission::ManageUsers,
            Permission::ManageLeadSalesLists,
            Permission::ManageContactCenterSettings,
        ];

        return new NavigationItem(
            id: 'company',
            label: 'Company',
            permissions: $entryPermissions,
            state: NavigationState::Live,
            route: 'company.home',
            icon: 'building-office-2',
            description: 'Company-wide performance, locations and settings.',
            primary: 'company.performance',
            children: [
                new NavigationItem('company.performance', 'Executive Board', $overviewPermissions, route: 'company.performance'),
                new NavigationItem('company.leadership_scorecards', 'Leadership Scorecards', $overviewPermissions),
                new NavigationItem('company.needs_attention', 'Needs Attention', $overviewPermissions),
                new NavigationItem(
                    id: 'settings.locations',
                    label: 'Locations',
                    permissions: [Permission::ManageLocations],
                    state: NavigationState::Live,
                    route: 'settings.locations',
                ),
                new NavigationItem('company.reports', 'Reports', $overviewPermissions),
                $this->settings(),
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
            description: 'Performance, the Contact Center, campaigns and customers.',
            primary: 'marketing.performance',
            children: [
                new NavigationItem('marketing.performance', 'Performance', $permissions, route: 'marketing.performance'),
                new NavigationItem('marketing.call_center', 'Contact Center', $permissions, primary: 'shared.leads', children: [
                    $this->leads(),
                    $this->callQueue(),
                ]),
                new NavigationItem('shared.inbox', 'Inbox', $permissions),
                new NavigationItem('marketing.campaigns', 'Campaigns', $permissions),
                $this->customers(),
                new NavigationItem('marketing.reporting', 'Reports', $permissions),
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
            description: 'Performance, the sales workspace, calendar and commissions.',
            primary: 'sales.performance',
            children: [
                new NavigationItem('sales.performance', 'Performance', $permissions, NavigationState::Live, route: 'sales.performance'),
                new NavigationItem('sales.workspace', 'Workspace', $permissions, primary: 'shared.leads', children: [
                    $this->leads(),
                ]),
                new NavigationItem('sales.calendar', 'Calendar', $permissions, NavigationState::Live, route: 'sales.calendar'),
                new NavigationItem('sales.commissions', 'Commissions', $permissions),
                new NavigationItem('sales.leaderboard', 'Leaderboard', $permissions),
                $this->customers(),
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
            description: 'Performance, project delivery, scheduling and subcontractors.',
            primary: 'production.performance',
            children: [
                new NavigationItem('production.performance', 'Performance', $permissions, route: 'production.performance'),
                new NavigationItem('production.workspace', 'Workspace', $permissions, NavigationState::Live, route: 'production.workspace'),
                new NavigationItem('production.schedule', 'Schedule', $permissions, NavigationState::Live, route: 'production.schedule'),
                new NavigationItem('production.crews', 'Subcontractors', [Permission::ViewSubcontractors], NavigationState::Live, route: 'production.subcontractors'),
                new NavigationItem('production.warranty', 'Warranty', $permissions),
                $this->customers(),
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
            description: 'Cash management, job costing, payroll and financial reporting.',
            primary: 'finance.performance',
            children: [
                new NavigationItem('finance.performance', 'Performance', $permissions, route: 'finance.performance'),
                new NavigationItem('finance.cash', 'Cash Management', $permissions, primary: 'finance.cash.receivables', children: [
                    new NavigationItem('finance.cash.receivables', 'Receivables', $permissions, NavigationState::Live, route: 'finance.receivables'),
                    new NavigationItem('finance.cash.payments', 'Payments', $permissions),
                    new NavigationItem('finance.cash.payouts', 'Payouts', $permissions),
                ]),
                new NavigationItem('finance.job_costing', 'Job Costing', $permissions, NavigationState::Live, route: 'finance.job-costing'),
                new NavigationItem('finance.payroll', 'Payroll', $permissions),
                new NavigationItem(
                    id: 'finance.people_access',
                    label: 'People & Access',
                    permissions: [Permission::ManageUsers],
                    state: NavigationState::Live,
                    route: 'settings.users',
                ),
                $this->customers(),
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
            primary: 'color_studio.projects',
            children: [
                new NavigationItem('color_studio.projects', 'Color Projects', $permissions, route: 'color_studio.projects'),
                new NavigationItem('color_studio.sample_requests', 'Sample Requests', $permissions),
                new NavigationItem('color_studio.estimate_requests', 'Estimate Requests', $permissions),
                new NavigationItem('color_studio.catalog', 'Color Catalog', $permissions),
            ],
        );
    }

    private function settings(): NavigationItem
    {
        // Unbuilt Settings pages borrow these two. They deliberately do not borrow the
        // lists or Contact Center permissions, so holding either shows its own page and nothing else.
        $permissions = [Permission::ManageLocations, Permission::ManageUsers];

        return new NavigationItem(
            id: 'settings',
            label: 'Settings',
            permissions: [...$permissions, Permission::ManageLeadSalesLists, Permission::ManageContactCenterSettings],
            state: NavigationState::Live,
            route: 'settings.home',
            icon: 'cog-6-tooth',
            description: 'Locations, staff access, lead and sales lists, and company configuration.',
            primary: 'settings.users',
            children: [
                new NavigationItem('settings.company', 'Company', $permissions),
                new NavigationItem(
                    id: 'settings.users',
                    label: 'Users and Access',
                    permissions: [Permission::ManageUsers],
                    state: NavigationState::Live,
                    route: 'settings.users',
                ),
                new NavigationItem(
                    id: 'settings.lists',
                    label: 'Lead & Sales Lists',
                    permissions: [Permission::ManageLeadSalesLists],
                    state: NavigationState::Live,
                    route: 'settings.lists',
                ),
                new NavigationItem(
                    id: 'settings.contact_center',
                    label: 'Contact Center',
                    permissions: [Permission::ManageContactCenterSettings],
                    state: NavigationState::Live,
                    route: 'settings.contact_center',
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
     * Leads is one page with one address, shown in more than one center.
     *
     * It is opened by the lead permission, not by access to the center whose
     * menu shows it, so a Sales Consultant reaches the same page through Sales
     * that a Call Center Agent reaches through Marketing.
     */
    private function leads(): NavigationItem
    {
        return new NavigationItem(
            id: 'shared.leads',
            label: 'Leads',
            permissions: [Permission::ViewLeads],
            state: NavigationState::Live,
            route: 'leads',
        );
    }

    /**
     * The Call Queue is in Marketing > Contact Center only. It is opened by the
     * queue permission, and the Contact Center still opens on Leads.
     */
    private function callQueue(): NavigationItem
    {
        return new NavigationItem(
            id: 'marketing.call_center.queue',
            label: 'Call Queue',
            permissions: [Permission::WorkLeadQueue],
            state: NavigationState::Live,
            route: 'marketing.call_center.queue',
        );
    }

    /**
     * Customers is one page with one address, reached from several centers.
     */
    private function customers(): NavigationItem
    {
        return new NavigationItem(
            id: 'shared.customers',
            label: 'Customers',
            permissions: [Permission::ViewAccounts],
            state: NavigationState::Live,
            route: 'customers',
        );
    }
}
