# Architectural Decisions

Each entry records a decision, why it was made, and what it rules out. Entries are
added, not rewritten. A reversed decision gets a new entry that refers to the old one.

Status of entries 1–10: **Approved** (2026-10-04).

## 1. Keep the installed stack

Laravel 13, Livewire 4 single-file components, Flux free, Fortify, Tailwind 4, Pest.
No new packages are needed for Checkpoint A.

Why: everything Checkpoint A needs is already installed and tested.

## 2. No `companies` table and no `company_id` columns

Banana Painting is one company. A company table with one row, and a company ID on
every record, is the structure of software sold to many companies. It adds a
filter that must never be forgotten and buys nothing here.

Company-level details (name, branding) live in configuration until the Settings >
Company page is built.

Rules out: treating locations as tenants or as separate copies of the system.

## 3. Roles and permissions in code, grants in the database

Permissions and roles are PHP enums. Which person holds which role, and where, is
stored in the `access_grants` table.

Why: the list of roles changes rarely and should be reviewed and tested when it
does. Who holds a role changes often and belongs to an administrator.

Considered: the `spatie/laravel-permission` package. Not chosen because it would
add a dependency, and its location scoping does not express "explicitly
company-wide" as cleanly as a stated scope column.

## 4. What and where are stored together on each grant

A grant is person + role + scope + location. They are not two separate lists (a
person's roles, and a person's locations).

Why: with two separate lists, a salesperson at one location who also works the
call center across all locations would silently receive sales access everywhere.
Storing them together makes that impossible.

## 5. "All locations" is always an explicit value

A grant's scope is either `location` or `all_locations`. A missing location never
means everywhere, and a location-scoped grant without a location cannot be saved.
The same applies to the current-location selector: nothing remembered
means "pick an authorized location", never "all".

## 6. The current location is a view, not a permission

It is kept in the session and re-validated on every request. Access to a record is
always decided by the record's own location against the person's grants.

Why: a session value can be stale or tampered with, and a person may have two
browser tabs open on different locations.

Considered: putting the location in every URL. Not chosen for now because
Livewire's follow-up requests do not carry the page URL, so the check must exist
independently of the URL either way.

## 7. The `users` table is staff only

Homeowners get their own account table and their own sign-in when the portal is
built. No "user type" column is added to `users`.

Why: one mistake in a shared table could expose staff screens to a homeowner.
Separate tables and sign-ins fail closed.

## 8. Staff accounts: no registration, command-line first owner, deactivate not delete

- Public registration stays off (already the case in the installed starter kit).
- The first Owner is created with `php artisan backoffice:create-owner`, which
  prompts for the details. No default password exists in the code or the seeders.
- Other staff are invited from Settings > Users and Access.
- The starter kit's "delete my account" feature is removed. Accounts are
  deactivated so past activity stays attributable.

## 9. Reference material stays out of version control and out of the web root

`docs/reference/` is listed in `.gitignore`. It sits outside `public/`, so it is
not web-accessible, and because Forge deploys from git it will not be deployed.

Why: the screenshots contain another company's staff names, photos, figures and
some unredacted customer names.

## 10. One navigation registry with stable IDs

All navigation is defined in one place with stable IDs, permission requirements
and a built / coming-soon state. Unbuilt features get no routes, tables, buttons
or invented numbers.

Why: the shell can show the whole intended system honestly, and a feature moves
from "coming soon" to "live" by changing one entry.

## 11. Invitations use Laravel's password-reset machinery with their own table

Made while building Checkpoint A (2026-10-04).

Staff invitations use a second "password broker" named `invitations`, backed by its
own `staff_invitation_tokens` table, with a 72-hour lifetime.

Why: Laravel's broker already provides hashed, single-use, expiring tokens, so no
new security code had to be written. A separate table means an invitation link can
never be used on the ordinary password reset form, and a "forgot password" request
never cancels a pending invitation.

Considered: signed links. Not chosen because a signed link stays valid until it
expires, where a broker token is destroyed the moment it is used.

## 12. Deactivated staff are stopped in two places

Made while building Checkpoint A (2026-10-04).

Sign-in refuses a deactivated account, and a check on every web request signs out
anyone who is deactivated while signed in. The second check also covers passkey
sign-in and "remember me", which do not pass through the password check.

The "this account is deactivated" message appears only after the correct password
is given, so it cannot be used to discover which accounts exist.

## 13. Application code beyond Laravel's standard folders lives in `app/Support`

Made while building Checkpoint A (2026-10-04).

`app/Support/Access` holds the current-location resolver and `app/Support/Navigation`
holds the navigation registry. Staff operations are action classes in
`app/Actions/Staff`, next to the starter kit's `app/Actions/Fortify`.

Why: these are neither models, controllers nor Livewire pages, and Laravel has no
standard home for them. One clearly named folder is easier to find than several.

## 14. Personal account pages stay at `/settings/profile` and `/settings/security`

Made while building Checkpoint A (2026-10-04).

The starter kit's profile, password, two-factor and passkey pages were left where
they were and relabelled "My account". The company Settings center shares the
`/settings` address prefix.

Why: moving them would have touched working sign-in security code for a cosmetic
gain. Route names hide the addresses, so they can be moved later in one place.

## Future decisions already constrained by the brief

- Estimates hold any number of named, ordered options. No fixed tiers.
- Accepted proposals are stored as versioned snapshots.
- Projects carry several narrow states (sales, production, payment), not one
  combined status.
- Customer-visible information is opted in field by field, never by default.
- Customer uploads use private storage.
