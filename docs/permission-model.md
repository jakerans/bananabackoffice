# Permission Model

Status: **Approved** (2026-10-04). Roles and matrix confirmed by the owner.

## The idea in plain English

Every access decision asks two separate questions:

1. **What** — is this person allowed to do this kind of thing?
2. **Where** — are they allowed to do it for this location?

Both must be yes. Neither answers the other.

## The building blocks

| Term | Meaning | Where it lives |
| --- | --- | --- |
| Location | An operating location of Banana Painting. | `locations` table |
| Permission | One thing a person can do, such as "open the Sales Center". | Code |
| Role | A named bundle of permissions, such as "Sales Consultant". | Code |
| Access grant | "This person holds this role, here." | `access_grants` table |

Roles and permissions live in code so that the matrix below, the code and the
tests always agree, and every change is reviewed. They can move into the database
later if a role editor is wanted.

### Access grants

An access grant ties together a person, a role and a place:

| Person | Role | Scope | Location |
| --- | --- | --- | --- |
| Avery | Sales Consultant | Location | Northside |
| Avery | Sales Consultant | Location | Lakeview |
| Blake | Call Center Agent | All locations | — |
| Casey | Finance | All locations | — |

(Fictitious people and locations.)

The scope is always stated: either `location` with a specific location, or
`all_locations`. A blank location never means "everywhere"; a location-scoped
grant that has no location is rejected and cannot be saved.

A person may hold several grants. Each grant stands alone. Holding "Sales
Consultant at Northside" and "Call Center Agent at all locations" does not give
sales access at Lakeview. This is why what and where are stored together on each
grant instead of as two separate lists.

## The rules

1. A person can use a permission at a location only if one of their grants has a
   role containing that permission and covers that location.
2. A grant covers a location if it names that location or is scoped to all locations.
3. A deactivated location is covered by no grant.
4. A deactivated person can do nothing and cannot sign in.
5. A person with no grants sees a "no access assigned yet" page.
6. Company-level permissions (Company Overview, Settings) count only when held
   through an all-locations grant.
7. The Owner role can only be granted at all-locations scope.
8. There is always at least one active Owner.

## The current location

The header shows which location a person is working in.

- The selector lists only locations the person has a grant for.
- "All Locations" is listed only for people holding at least one all-locations grant.
- In the "All Locations" view, a person sees only what their all-locations grants
  allow. Their single-location grants do not apply there.
- The choice is remembered in the session and re-checked against the person's
  current grants on every request, including Livewire requests.
- If the remembered choice is no longer valid, it is discarded.
- With nothing remembered, the person lands on one of their authorized locations.
  They never land on "All Locations" unless they chose it.
- Switching is a server request that is refused if the location is not authorized.

The current location is a way of viewing, not a source of permission. From
Checkpoint B, every record carries its own location, and access to a record is
decided by the record's location against the person's grants, whatever the
selector says. Lists are always limited to the person's authorized locations, in
every view.

## Permissions in Checkpoint A

| Permission | Scope | Allows |
| --- | --- | --- |
| `company_overview.access` | Company | Open Company Overview |
| `marketing.access` | Location | Open the Marketing Center |
| `sales.access` | Location | Open the Sales Center |
| `production.access` | Location | Open the Production Center |
| `finance.access` | Location | Open the Finance Center |
| `color_studio.access` | Location | Open Color Studio |
| `settings.locations.manage` | Company | Manage locations |
| `settings.users.manage` | Company | Manage staff and access grants |

Only permissions that guard something real are defined. Finer permissions (view
job costs, approve commissions, edit leads) are added when the feature is built.

## Roles and matrix

| Role | Company Overview | Marketing | Sales | Production | Finance | Color Studio | Settings |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Owner | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Leadership | Yes | Yes | Yes | Yes | No | Yes | No |
| Finance | No | No | No | No | Yes | No | No |
| Location Manager | No | Yes | Yes | Yes | No | Yes | No |
| Call Center Agent | No | Yes | No | No | No | No | No |
| Sales Consultant | No | No | Yes | No | No | Yes | No |
| Production Manager | No | No | No | Yes | No | No | No |

Notes:

- "Yes" means the role contains the permission. Where it applies still depends on
  each grant's scope.
- Company Overview needs an all-locations grant. Leadership granted at a single
  location does not open it.
- Call Center Agent is normally granted at all locations and still has no finance,
  payroll or settings access.
- Leadership does not include Finance. A leader who needs finance is given a
  separate Finance grant, so finance access is always a deliberate choice.
- The structure is extensible: a new role or permission is one new entry in code
  plus its line in this matrix.

## Where the rules are enforced

- Routes: each center and settings page declares the permission it needs. Laravel
  refuses the request before the page loads.
- Livewire: every action re-checks authorization. Laravel re-applies the route's
  authorization on each Livewire request, and values sent by the browser are
  treated as untrusted.
- Policies: Laravel policy classes decide access to Location and User records.
- Navigation: reads the same rules to decide what to show. It is never the only check.

## Staff accounts

- There is no public registration.
- The first Owner is created from the command line on the server.
- Other staff are invited by someone with `settings.users.manage`.
- Accounts are deactivated, not deleted, so history stays attributable.

### Invitations

- An invited account is created with a long random password that nobody is shown.
  Administrators never create, see or send a password.
- The staff member receives an email link and chooses their own password.
- The link works once, expires after 72 hours, and is stored only in hashed form.
- Sending a new invitation cancels the previous link. Deactivating the account
  cancels it too.
- Accepting the invitation also confirms the email address.
- Inviting is all or nothing. If the email cannot be sent, no account is created.
  If a resend fails, nothing changes and an earlier link keeps working.
- Someone invited who sets their password through "forgot password" has accepted
  the invitation: they stop showing as "Invited" and the unused link is retired.
- A new account has no access until a role is granted. It can sign in and sees
  "No access assigned yet".

### Email addresses

Every email address is stored in lowercase and compared in lowercase, at sign-in,
password reset, invitations, profile changes and Owner creation. Two accounts
cannot differ only by capital letters.

### Safeguards

- There is always at least one **usable Owner**: an Owner who is active, has a
  confirmed email address, and has chosen their own password. An Owner who was
  invited but never accepted does not count. The last usable Owner cannot be
  deactivated or lose the Owner role.
- Nobody can deactivate their own account.
- A deactivated person cannot sign in. Deactivation ends their existing sessions
  and destroys their "remember me" login, so reactivating never revives an old
  sign-in. Their grants are kept, so reactivating restores the access they had.
- The database refuses a duplicate grant: one person holds a role at most once per
  location and at most once company-wide.
- Each grant records who granted it.
- Only the Owner role contains `settings.users.manage` today. If another role is
  ever given it, add a rule that only an Owner may grant or remove the Owner role.

## Homeowners

Homeowners will never be rows in the staff `users` table and will never sign in
through the staff sign-in page. The future portal gets its own sign-in, its own
account table and its own access rules.

## Open decisions for Checkpoint B

- Who can see a centrally received lead before a location owns it. Proposed
  starting point: only people holding the relevant permission at all-locations scope.
- How access to a shared Customer is derived from its projects and properties.
