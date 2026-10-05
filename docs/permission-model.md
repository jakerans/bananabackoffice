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
Checkpoint B, Leads and Projects carry their own location, and access to them is
decided by that location against the person's grants, whatever the selector says.
Accounts, Contacts and Properties have no location; access to them follows the
Leads and Projects they are connected to (see "Checkpoint B" below). Lists are
always limited to what the person's grants allow, in every view.

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
| `settings.lists.manage` | Company | Manage the Lead & Sales Lists page (added in step B2; held by Owner and Leadership) |

Only permissions that guard something real are defined. Finer permissions (view
job costs, approve commissions, edit leads) are added when the feature is built.

## Roles and matrix

| Role | Company Overview | Marketing | Sales | Production | Finance | Color Studio | Settings |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Owner | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Leadership | Yes | Yes | Yes | Yes | No | Yes | Lead & Sales Lists only |
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

## Owner impersonation

An Owner can view Backoffice as another active staff member, to support them or
to check what a role sees. See decision 46 in [decisions.md](decisions.md).

- **Only the Owner role may start it.** It is tied to the role itself, not to a
  permission, so no other role can be given it.
- **The staff member's access is used alone.** While impersonating, every gate,
  policy, location and menu is worked out for the staff member. The Owner's
  access is never added to it.
- **It is checked on every request.** The Owner must still be an active Owner and
  the staff member must still be active. A change to either takes effect on the
  next request.
- **Sign-in details are out of reach.** The staff member's name, email, password,
  two-factor codes and passkeys cannot be seen or changed while impersonating.
- **Staff accounts and access cannot be managed while impersonating.**
- **Both people are recorded** on every audit event made while impersonating.

## Homeowners

Homeowners will never be rows in the staff `users` table and will never sign in
through the staff sign-in page. The future portal gets its own sign-in, its own
account table and its own access rules.

## Checkpoint B

Status: **Approved by the owner (2026-10-05). Not built yet.** Each permission is
added to the code in the step that builds what it guards. Built so far:
`settings.lists.manage` (B2); `leads.view`, `leads.manage` and
`leads.view_unrouted` (B3); `leads.route`, `leads.transfer`, `leads.return` and
`leads.assign` (B4). The records and their
rules are described in [checkpoint-b.md](checkpoint-b.md).

### The rules Checkpoint B adds

1. **Location stays the authorization boundary.** A Project is governed by its
   Location. A routed Lead is governed by its Location.
2. **An unrouted Lead has no Location,** so no location grant covers it. It is
   visible only through its own company-level permission, which counts only when
   held through an all-locations grant, exactly like Company Overview.
3. **Accounts, Contacts and Properties have no Location.** A person may see an
   Account, with its Contacts and Properties, if they can see at least one of its
   Leads or Projects, or if they hold the account permission through an
   all-locations grant.
4. **Seeing an Account never widens access.** Its page lists only the Leads and
   Projects the viewer is authorized for, and reveals nothing about the rest.
5. **A Property's address never decides access.**
6. **Assignment grants nothing.** Being the assigned person on a Lead, or holding a
   responsibility on a Project, gives no access. The person must already have
   access at that Location, or the assignment is refused.
7. **Routing, returning to central and transferring are their own permissions,**
   separate from being able to edit the record.
8. **A direct move by a Location Manager is checked at both ends.** They must hold
   `leads.transfer` at the Location the Lead is leaving and at the one it is going
   to. Without the company-wide `leads.route`, no other destination is accepted.
9. **Only someone who can already work a Lead may be assigned it.** For an
   unrouted Lead that means company-wide lead staff. When a person loses that
   access, the Leads they can no longer work are unassigned.
10. **Starting work follows assignment.** The assignee may start their Lead.
    Someone who may assign may start an unassigned Lead, which assigns it to them.
    Nobody else may: starting work is never a way to take a Lead.
### Permissions

| Permission | Scope | Allows |
| --- | --- | --- |
| `accounts.view` | Location | See Accounts, Contacts and Properties, under rule 3 |
| `accounts.manage` | Location | Create and edit Accounts, Contacts and Properties |
| `accounts.archive` | Location | Archive and restore Accounts, Contacts and Properties |
| `leads.view` | Location | See Leads routed to the location |
| `leads.manage` | Location | Capture, edit and work Leads; qualify, disqualify, reopen |
| `leads.view_unrouted` | Company | See and work Leads that have no location yet |
| `leads.route` | Company | Send a Lead to any location, or move it to any other |
| `leads.transfer` | Location | Move a Lead directly between two locations, when held at both |
| `leads.return` | Location | Return a Lead to the central queue, with a reason |
| `leads.assign` | Location | Assign, reassign or unassign a Lead; start working an unassigned one |
| `leads.convert` | Location | Create a Project from a Qualified Lead |
| `projects.view` | Location | See Projects at the location |
| `projects.create` | Location | Create a Project, from a Lead or directly |
| `projects.update` | Location | Edit a Project |
| `projects.assign` | Location | Set a Project's responsibilities |
| `projects.transfer` | Company | Move a Project to another location |
| `projects.archive` | Location | Archive and restore Projects |
| `projects.sales_outcome` | Location | Mark a Project Won (with its Sold Amount) or Lost |
| `projects.correct_sold_amount` | Location | Correct the Sold Amount of a Won Project |
| `notes.write` | Location | Add notes to a record the person can see |
| `attachments.manage` | Location | Upload and remove attachments |
| `history.view` | Location | See a record's audit history |
| `settings.lists.manage` | Company | Manage the Lead & Sales Lists page in Settings, and nothing else there |

Company-scope permissions count only when held through an all-locations grant.

### Matrix

"Yes" means the role contains the permission. Where it applies still depends on
each grant's scope.

The owner approved this matrix at the final review on 2026-10-05.

| Capability | Owner | Leadership | Location Manager | Call Center Agent | Sales Consultant | Production Manager | Finance |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| See Accounts, Contacts, Properties | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Create and edit Accounts, Contacts, Properties | Yes | Yes | Yes | Yes | Yes | No | No |
| Archive Accounts, Contacts, Properties | Yes | Yes | Yes | No | No | No | No |
| See routed Leads | Yes | Yes | Yes | Yes | Yes | No | No |
| Capture, edit, work, qualify and disqualify Leads | Yes | Yes | Yes | Yes | Yes | No | No |
| See unrouted Leads (all-locations grant only) | Yes | Yes | No | Yes | No | No | No |
| Route a Lead to a location, or move it to any other (all-locations grant only) | Yes | Yes | No | Yes | No | No | No |
| Move a Lead between two locations where they hold this role | Yes | Yes | Yes | No | No | No | No |
| Return a Lead to the central queue | Yes | Yes | Yes | Yes | No | No | No |
| Assign, reassign or unassign a Lead | Yes | Yes | Yes | Yes | No | No | No |
| Be assigned a Lead, and work a Lead assigned to them | Yes | Yes | Yes | Yes | Yes | No | No |
| Create a Project from a Qualified Lead | Yes | Yes | Yes | No | Yes | No | No |
| See Projects | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Create a Project directly | Yes | Yes | Yes | No | Yes | No | No |
| Edit a Project | Yes | Yes | Yes | No | Yes | Yes | No |
| Set Project responsibilities | Yes | Yes | Yes | No | No | No | No |
| Transfer a Project to another location | Yes | Yes | No | No | No | No | No |
| Archive and restore Projects | Yes | Yes | Yes | No | No | No | No |
| Mark a Project Won or Lost | Yes | Yes | Yes | No | Yes | No | No |
| Correct a Sold Amount | Yes | Yes | No | No | No | No | No |
| Add notes | Yes | Yes | Yes | Yes | Yes | Yes | No |
| Upload and remove attachments | Yes | Yes | Yes | No | Yes | Yes | No |
| See a record's audit history | Yes | Yes | Yes | No | No | No | No |
| Manage Lead & Sales Lists (all-locations grant only) | Yes | Yes | No | No | No | No | No |

Notes:

- Call Center Agents capture, edit, work and route Leads. They do not create
  Projects.
- A Location Manager cannot route a Lead to another location. They can return it
  to the central queue with a reason, after which they no longer see it.
- Location staff who capture a Lead create it at one of their own locations. Only
  people who can see unrouted Leads can leave one unrouted.
- Leadership granted at a single location works within that location. Seeing
  unrouted Leads, transferring Projects and managing the lists need an
  all-locations grant.
- Leadership's permission is for the Lead & Sales Lists page specifically. It lets
  them open Settings and see that one page. It gives no access to Locations, Users
  and Access, or any other Settings page, which stay Owner-only.
- Every correction of a Sold Amount is an audit event recording the old and new
  amounts.
- Leads and Projects are each one shared page, shown in more than one center. A
  person opens them through the lead or project permission, not through access to
  a particular center.
- Finance sees Accounts and Projects and changes nothing in Checkpoint B.
- The Checkpoint A center permissions are unchanged. Which center's menu shows a
  shared page is a navigation question; see navigation-map.md.
