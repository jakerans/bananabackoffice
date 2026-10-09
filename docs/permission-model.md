# Permission Model

Status: **Implemented rules and role grants through B7 are approved and built.**
Canonical target guardrails are locked product direction. Any new permission,
role grant or widening of scope still needs an approved implementation slice.
**Slice 4 is such a slice. Step 4a (merged) added its two permissions to the
code (`settings.contact_center.manage` and, by decision 74, `leads.work_queue`).
Step 4b (merged) is the queue permission's first use: the queue
engine's one eligibility rule reads it. Step 4c (merged) makes it
open the Call Queue page: its one route and its one navigation item.** The rest of slice 4
is set out under "Slice 4" at the end of this document and must not be described
as implemented until its step is merged.

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

## Canonical target authorization guardrails

- **Responsibility is not permission.** Assignment, ownership, queue placement or
  responsibility selects who is accountable; it never grants record access.
- **Grants do not union into broader authority.** One grant must contain the
  permission and cover the action's scope. A company-wide permission from one
  role cannot be combined with a location permission from another to manufacture
  access neither grant provides alone.
- **Related-record access is explicit and one-way.** A visible Lead or Project may
  make its related Customer, Contact or Property visible under the documented
  policy. Seeing that shared record never reveals other Leads, Projects,
  Locations or relationships the viewer cannot independently access.
- **Sensitive financial data needs its own permission boundary.** Customer access
  does not imply access to GP, commissions, subcontractor payouts, job-cost detail
  or other finance-sensitive fields. The Contact Center target explicitly excludes
  those fields even where central support has broader customer visibility.
- **Navigation placement grants nothing.** Targeting People & Access under Finance
  does not give the Finance role `settings.users.manage`; moving Settings under
  Company likewise changes no current grant.
- Future broader Contact Center search, record types and field-level financial
  views require explicit backend permissions and policy tests in their approved
  implementation slices. They are not implied by the target architecture.

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

## Implemented Center and Settings permissions

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
| `settings.contact_center.manage` | Company | Manage the Contact Center settings page: call cadence, calling hours and days, hold length (added in step 4a; held by Owner and Leadership) |
| `leads.work_queue` | Location | Work the Call Queue. **Held by Owner, Leadership, Location Manager and Call Center Agent since step 4a (decision 74), but guards nothing: no route, navigation item, policy or action reads it until step 4b.** |

Permissions are defined when the feature they guard is built. Finer permissions
(view job costs, approve commissions, edit leads) are added when the feature is
built. The one exception is `leads.work_queue`, added in step 4a ahead of the
queue it will guard (decision 74): it is held by its roles but read by nothing
until step 4b.

## Implemented Center role matrix

| Role | Company Overview | Marketing | Sales | Production | Finance | Color Studio | Settings |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Owner | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Leadership | Yes | Yes | Yes | Yes | No | Yes | Lead & Sales Lists and Contact Center only |
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

## Implemented authorization through B7

Status: **B1 to B7 are built, approved and merged.** Each permission was added to
the code in the step that built what it guards. Implemented:
`settings.lists.manage` (B2); `leads.view`, `leads.manage` and
`leads.view_unrouted` (B3); `leads.route`, `leads.transfer`, `leads.return` and
`leads.assign` (B4); `notes.write` and `notes.remove` (B5); `accounts.view`
(B6a); `accounts.manage` and `accounts.archive` (B6b);
`leads.waive_contact_wait` was added in B7 and is now a legacy grant only:
no lifecycle action consumes it (decision 55). Existing other authorization is
unchanged. The records and their
rules are described in [checkpoint-b.md](checkpoint-b.md).

### The rules implemented through B7

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
11. **Changing a Lead's status follows assignment too (B7).** The assignee may
    qualify, schedule follow-up or disqualify their Lead. So may anyone who may assign Leads
    at its Location. A Sales Consultant cannot change the status of a colleague's
    Lead, though they can see it.
12. **Reopening belongs to the people who hand out Leads (B7).** A disqualified
    Lead has no assignee, and a reopened one comes back unassigned, so reopening
    it, or correcting why it was disqualified, needs `leads.assign` at its
    Location. A Sales Consultant cannot, even for a Lead they disqualified.
13. **A disqualified Lead is closed (B7).** It cannot be routed, returned,
    assigned or have its status changed until it is reopened.

### How someone sees a customer, which has no Location

`accounts.view` is granted like any other permission, at a Location or across all
Locations. Because an Account has no Location, it is applied like this:

- **Through an all-locations grant:** every customer.
- **Through a location grant:** only customers with at least one Lead (later, a
  Project) that the person can see at that Location.

`accounts.manage` is applied the same way: every customer through an
all-locations grant, otherwise the customers with a Lead the person can see at a
Location where they hold it. Creating a customer with no Lead needs the
all-locations grant, because nobody else could see it afterwards.

So a Sales Consultant, Location Manager or Call Center agent at one Location sees
the customers with a Lead there. A Production Manager or Finance person at one
Location sees none until Projects exist, because they cannot see Leads. Seeing a
customer never shows another Location's Leads; those are filtered wherever they
are listed or counted.

### Linking a Lead to a customer

- **Who:** someone who may work the Lead and holds `accounts.manage` where the
  Lead is (company-wide for an unrouted Lead).
- **To which customer:** one they can see, or a new one. A customer they cannot
  see can be linked only through a masked match on the Lead's exact phone or
  email, which the server works out itself; the browser never names it.
- **What that gives them:** sight of the customer, its Contacts and Properties,
  through their own Lead. Never sight of another Location's Leads or Projects.
- Archiving and restoring a customer needs `accounts.archive`, company-wide.

### Current and historical planned permissions

The Account, Lead, Note and Settings rows below describe implemented permissions
through B7. `leads.convert`, every `projects.*` row, `attachments.manage` and
`history.view` are preserved historical proposals from the old B8–B12 sequence;
they are not implemented grants and do not authorize that sequence.

| Permission | Scope | Allows |
| --- | --- | --- |
| `accounts.view` | Location | See Accounts, Contacts and Properties, under rule 3 |
| `accounts.manage` | Location | Add and edit a customer's Contacts and Properties. Through an all-locations grant: every customer, and creating one that has no Lead |
| `accounts.archive` | Company | Archive and restore Accounts, Contacts, their connections and Properties |
| `leads.view` | Location | See Leads routed to the location |
| `leads.manage` | Location | Capture, edit and work Leads; qualify, schedule follow-up and disqualify a Lead assigned to them |
| `leads.view_unrouted` | Company | See and work Leads that have no location yet |
| `leads.route` | Company | Send a Lead to any location, or move it to any other |
| `leads.transfer` | Location | Move a Lead directly between two locations, when held at both |
| `leads.return` | Location | Return a Lead to the central queue, with a reason |
| `leads.assign` | Location | Assign, reassign or unassign a Lead; start working an unassigned one; change the status of a Lead that is not theirs; reopen a disqualified Lead or correct its reason |
| `leads.waive_contact_wait` | Location | Legacy grant retained; no current action accepts a waiver or nonresponse closure |
| `leads.convert` | Location | Create a Project from a Qualified Lead |
| `projects.view` | Location | See Projects at the location |
| `projects.create` | Location | Create a Project, from a Lead or directly |
| `projects.update` | Location | Edit a Project |
| `projects.assign` | Location | Set a Project's responsibilities |
| `projects.transfer` | Company | Move a Project to another location |
| `projects.archive` | Location | Archive and restore Projects |
| `projects.sales_outcome` | Location | Mark a Project Won (with its Sold Amount) or Lost |
| `projects.correct_sold_amount` | Location | Correct the Sold Amount of a Won Project |
| `notes.write` | Location | Add notes to a record the person can see, and remove their own from view |
| `notes.remove` | Location | Remove anyone's note from view |
| `attachments.manage` | Location | Upload and remove attachments |
| `history.view` | Location | See a record's audit history |
| `settings.lists.manage` | Company | Manage the Lead & Sales Lists page in Settings, and nothing else there |
| `settings.contact_center.manage` | Company | Manage the Contact Center settings page in Settings, and nothing else there (built in 4a) |
| `leads.work_queue` | Location | Work the Call Queue (added to the code in 4a, decision 74; first used in 4b) |

Company-scope permissions count only when held through an all-locations grant.

### Matrix

"Yes" means the role contains the permission. Where it applies still depends on
each grant's scope.

Rows involving Projects, attachments or History preserve the old proposed matrix
for review context. They are not current code or implementation approval and must
be revalidated against the canonical architecture before use.

The owner approved this matrix at the final review on 2026-10-05.

| Capability | Owner | Leadership | Location Manager | Call Center Agent | Sales Consultant | Production Manager | Finance |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| See Accounts, Contacts, Properties | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Create and edit Accounts, Contacts, Properties | Yes | Yes | Yes | Yes | Yes | No | No |
| Archive Accounts, Contacts, Properties (all-locations grant only) | Yes | Yes | No | No | No | No | No |
| See routed Leads | Yes | Yes | Yes | Yes | Yes | No | No |
| Capture, edit, work, qualify and disqualify Leads | Yes | Yes | Yes | Yes | Yes | No | No |
| See unrouted Leads (all-locations grant only) | Yes | Yes | No | Yes | No | No | No |
| Route a Lead to a location, or move it to any other (all-locations grant only) | Yes | Yes | No | Yes | No | No | No |
| Move a Lead between two locations where they hold this role | Yes | Yes | Yes | No | No | No | No |
| Return a Lead to the central queue | Yes | Yes | Yes | Yes | No | No | No |
| Assign, reassign or unassign a Lead | Yes | Yes | Yes | Yes | No | No | No |
| Qualify, schedule follow-up or disqualify a Lead assigned to them | Yes | Yes | Yes | Yes | Yes | No | No |
| Qualify, schedule follow-up or disqualify a Lead assigned to someone else, or to nobody | Yes | Yes | Yes | Yes | No | No | No |
| Reopen a disqualified Lead, or correct its reason | Yes | Yes | Yes | Yes | No | No | No |
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
| Add notes, and remove their own from view | Yes | Yes | Yes | Yes | Yes | Yes | No |
| Remove anyone's note from view | Yes | Yes | Yes | No | No | No | No |
| Upload and remove attachments | Yes | Yes | Yes | No | Yes | Yes | No |
| See a record's audit history | Yes | Yes | Yes | No | No | No | No |
| Manage Lead & Sales Lists (all-locations grant only) | Yes | Yes | No | No | No | No | No |
| Manage the Contact Center settings (all-locations grant only; built in 4a) | Yes | Yes | No | No | No | No | No |
| Work the Call Queue (permission held since 4a; guards nothing until 4b) | Yes | Yes | Yes | Yes | No | No | No |

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
- Leadership holds two Settings permissions, each for one page: Lead & Sales Lists
  (`settings.lists.manage`) and Contact Center (`settings.contact_center.manage`,
  step 4a). They let Leadership open Settings and see those two pages. Neither gives
  access to Locations, Users and Access, or any other Settings page, which stay
  Owner-only, and neither opens the other's page.
- Every correction of a Sold Amount is an audit event recording the old and new
  amounts.
- Leads and Projects are each one shared page, shown in more than one center. A
  person opens them through the lead or project permission, not through access to
  a particular center.
- Finance sees Accounts and Projects and changes nothing in Checkpoint B.
- The Checkpoint A center permissions are unchanged. Which center's menu shows a
  shared page is a navigation question; see navigation-map.md.

## Slice 4: the call queue and its settings

Status: **approved for implementation on 2026-10-08 (decisions 65 to 74).
Step 4a is merged: both permissions are in the code, and
`settings.contact_center.manage` guards the Contact Center settings page.
Step 4b is merged (job #11): `leads.work_queue` is read by the queue's
eligibility rule (`app/Support/Queue/QueueEligibility.php`), and
`LeadPolicy::returnToQueue` exists. Step 4c is merged (job #12):
`leads.work_queue` now also gates the Call Queue route and navigation item, and
every action on that page checks it again. The page's manager part (summary,
cadence-finished list, queue history, Put back in queue) belongs to people who
may assign Leads where the Lead is (`LeadPolicy::manageQueue`, `returnToQueue`).
No permission or role grant was added.** Each permission is added to the code in the step named below. The full
plan is [slice-4-plan.md](slice-4-plan.md). No existing permission, role grant or
rule above is changed.

### New permissions

| Permission | Scope | Allows | Step |
| --- | --- | --- | --- |
| `settings.contact_center.manage` | Company | Open Company > Settings > Contact Center and change the call cadence, calling hours and days, and hold length. Nothing else in Settings. | 4a |
| `leads.work_queue` | Location | Open the Call Queue, be given the next due Lead, and skip it with a reason | Added in 4a (decision 74); first used in 4b by the engine; opens the Call Queue page and its navigation item in 4c |

`settings.contact_center.manage` is a company-scope permission: it counts only
when held through an all-locations grant.

### Matrix rows

| Capability | Owner | Leadership | Location Manager | Call Center Agent | Sales Consultant | Production Manager | Finance |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Change the call cadence, calling hours and days, and hold length (all-locations grant only) | Yes | Yes | No | No | No | No | No |
| Work the Call Queue: get the next due Lead and skip it with a reason | Yes | Yes | Yes | Yes | No | No | No |
| Log the attempt for a queue Lead (existing: capture, edit and work Leads) | Yes | Yes | Yes | Yes | — | — | — |
| Disqualify a Lead from the queue card (existing B7 rule) | Yes | Yes | Yes | Yes | — | — | — |
| Put a Lead back in the queue, and see the manager summary (existing: assign Leads) | Yes | Yes | Yes | Yes | No | No | No |
| Set a Location's timezone (existing: manage locations, all-locations grant only) | Yes | No | No | No | No | No | No |

"—" means the row does not arise: the role cannot open the queue.

### Rules

1. **A Lead is offered only to someone who may work it where it is.** For a
   routed Lead the person must hold `leads.work_queue` at the Lead's own Location
   and pass the existing "work this Lead" rule there. The Location selected in
   the header neither widens nor narrows this.
2. **An unrouted Lead is offered only to company-wide lead staff:** the person
   holds `leads.work_queue` through an all-locations grant and already passes
   the existing unrouted rule (`leads.view_unrouted` and `leads.manage`, both
   company-wide). A Location Manager never receives an unrouted Lead, even with
   an all-locations grant.
3. **A hold is not assignment and grants nothing.** Holding a Lead gives no
   access the person did not already have, never changes the assignee, and ends
   at once if they stop being allowed to work that Lead. Rule 6 above
   ("assignment grants nothing") and decision 44 ("Sales Consultants do not claim
   Leads") are unchanged.
4. **Every save from the queue is authorized again on the server** against the
   Lead's current Location: taking, logging, skipping, disqualifying and putting
   back. Nothing the browser sends is trusted, including any timezone.
5. **Put back in queue belongs to the people who hand out Leads:** it needs
   `leads.assign` at the Lead's Location (for an unrouted Lead, the existing
   company-wide rule). No new permission. This includes Call Center Agents.
6. **Queue history follows the Location it was recorded at,** like contact
   attempts, and is shown only to people who may assign Leads there.
7. **Leadership sees two Settings pages (built in 4a),** each through a permission of its
   own: Lead & Sales Lists and Contact Center. `settings.lists.manage` still
   opens only the lists page (decision 37). Neither opens Locations or Users and
   Access.
8. **Grants still do not combine.** A queue permission from one grant and a lead
   permission from another do not add up to a Location neither covers.
