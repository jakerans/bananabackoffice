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
| `finance.access` | Location | Open the Finance Center; record a Project's invoices and payments there, approve cash and check payments, and reverse a payment (slice 17, step 17a); see Finance > Receivables for the Locations where it is held (step 17c); reconcile a job's costing, approve its final gross margin, and close or reopen it, and see Finance > Job Costing (slice 19) |
| `color_studio.access` | Location | Open Color Studio |
| `settings.locations.manage` | Company | Manage locations, including each Location's deposit policy (slice 17, step 17b) |
| `settings.users.manage` | Company | Manage staff and access grants |
| `settings.lists.manage` | Company | Manage the Lead & Sales Lists page (added in step B2; held by Owner and Leadership) |
| `projects.view` | Location | See the Projects at the location (added in slice 8 step 8a, decision 130; held by Owner, Leadership, Location Manager, Sales Consultant, Production Manager and Finance) |
| `payouts.view` | Location | See what Banana will pay subcontractors for a Project's work; with the pre-production rule, set it (added in slice 13 step 13a, decision 151; held by Owner, Leadership, Location Manager, Production Manager and Finance; never Sales or the Contact Center) |
| `subcontractors.view` | Location | See the subcontractors who work at the location, their page and their history, and open their compliance documents (step 16b) (added in slice 12 step 12a, decision 148; held by Owner, Leadership, Location Manager and Production Manager) |
| `subcontractors.manage` | Location | Add subcontractors there; change their details, where they stand and the VIP mark; record their compliance items (step 12b) and attach or remove their documents (step 16b) (added in slice 12 step 12a, decision 148; held by the same roles) |
| `projects.manage` | Location | Edit a Project's title and description; in step 8b, assign its Production Manager; with the Project's own Production Manager, add and remove photos of the work (step 16c), and record its invoices and payments (step 17a); lower or waive a job's deposit, with a reason (step 17b); with the Project's own Production Manager, draft, sign off and withdraw its change orders, and alone draft one that lowers the price, with a reason (slice 18, step 18a); with `payouts.view`, record a job's costs and submit its job costing (slice 19) (added in slice 8 step 8a, decision 130; held by Owner, Leadership and Location Manager) |
| `compensation.manage` | Company | See everyone's pay plans and variable-compensation ledger in Finance > Payroll; set a person's pay plan from a day, and record a manual adjustment with why; finalize a pay period for payroll and mark it paid (step 20c); keep each person's payroll ID (decision 173); never for their own pay (added in slice 20 step 20a, decision 172, question 4; held by Owner and Finance, and counted only through an all-locations grant, because pay is one company payroll). Everyone sees their own plans and ledger at "My pay" without it |
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

**Since slice 8 step 8a (decision 130)** two Project permissions are real:
`projects.view` and `projects.manage`, as the table above this section
describes them. `projects.view` is the historical row of that name. The other
historical `projects.*` rows below are still not built, and
`projects.sales_outcome` never will be as written: a sale is recorded on a
proposal, not on a Project (decision 124).

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
| `appointments.book` | Location | Book an appointment for a Lead, which issues it to Sales (slice 6, step 6a, decision 90) |
| `appointments.manage` | Location | Mark an appointment "booked in error" (step 6a); move it or send its Lead back (step 6b, not built) |

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
| Book an appointment for a Lead (built in 6a) | Yes | Yes | Yes | Yes | Own Leads, for themselves | No | No |
| Mark an appointment "booked in error" (built in 6a) | Yes | Yes | Yes | No | No | No | No |

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

## Slice 6: appointments

Status: **steps 6a, 6b and 6c are built and merged (2026-10-10, decisions 90
to 120).** The plan is
[slice-6-appointments-plan.md](slice-6-appointments-plan.md). Steps 6b and 6c
added no permission and changed no role.

Two permissions, both checked at the Lead's own Location:

| Permission | Lets a person | Held by |
| --- | --- | --- |
| `appointments.book` | Book an appointment for a Lead they may see there | Owner, Leadership, Location Manager, Call Center Agent |
| `appointments.manage` | Mark an appointment "booked in error"; move it to another salesperson; send the Lead back to Contact Center; say a Lead leaving Sales should never have been booked; book a Lead that is with Sales with a different salesperson | Owner, Leadership, Location Manager |

Rules:

1. **A Sales Consultant holds neither.** `LeadPolicy::bookAppointment` lets them
   book only a Lead already assigned to them, and the action lets them choose
   only themselves as the salesperson (decision 89). Being assigned grants
   nothing else: they must still be able to see the Lead where it is.
2. **A Lead with no Location cannot be booked by anyone,** whatever they hold.
3. **The salesperson must hold Sales Consultant access at the Lead's Location**
   and be someone the Lead could be assigned to. Their access is read again,
   under a lock, when the booking is saved.
4. **The roles that may book are checked again inside the booking,** on the
   Lead as it is at that moment.
5. **Who sees an appointment.** On the Lead: anyone who may see the Lead. In
   Sales > Calendar: a person's own appointments at Locations where they may
   still see Leads, and every appointment at Locations where they hold
   `appointments.manage`. Sales > Calendar itself opens with `sales.access`, so
   a Call Center Agent, who may book, has no Calendar.
6. **A notice belongs to one person.** The bell reads only the signed-in
   person's notices. A notice holds a time and a link, never customer details;
   the link opens only for someone who may see the Lead.
7. **Reschedule** (`LeadPolicy::rescheduleAppointment`): a manager where the
   Lead is, or the salesperson whose appointment it is while the Lead is still
   assigned to them. A Call Center Agent cannot (decision 88).
8. **Record a cancellation** (`LeadPolicy::cancelAppointment`): anyone who may
   book that Lead (decision 107), so a manager, a Call Center Agent, and the
   Sales Consultant it is assigned to.
9. **Move, send back, and "it should never have been booked"** are for a
   manager only (`appointments.manage`). The last is checked at the Location
   the Lead is leaving, before it moves. Someone else who moves, returns or
   disqualifies a Lead that is with Sales may still do so under the existing
   rules for those actions; the Lead then stays counted as issued.
10. **Booking a Lead that is with Sales again:** with the salesperson it is
    with, anyone who may book it; with a different one, a manager (decision
    109). A Sales Consultant still books only for themselves.
11. **Disqualifying a Lead while recording its cancellation** needs the same
    right as disqualifying it by hand (`LeadPolicy::changeStatus`), checked
    inside the cancellation.
12. **Who is told a Lead came back from Sales:** anyone who may open the Lead,
    on the Lead page and on the Call Queue card. It names the salesperson and
    the manager's note, both of which those people could already read on the
    Lead.
13. **The Cancelled Appointments queue** is worked by the same people as the
    ordinary queue, under the same rule (`leads.work_queue` where the Lead is,
    and calling hours there). It is a second button, not a second permission.
14. **Take back** (`LeadPolicy::takeBack`, decision 120): only the salesperson
    the timer released the Lead from, while the Lead is Qualified, still marked
    as released from them, at a Location where they hold Sales Consultant
    access and may see Leads. This is the one case in which a Sales Consultant
    takes a Lead that is not assigned to them (decision 44); it covers only a
    Lead that was theirs. A manager cannot take back on their behalf.
15. **The number of days** before a release is changed with
    `settings.contact_center.manage`, like the rest of that page.
16. **The release itself has no person.** The timer does it, and the history,
    the audit event and the notices record that nobody did.
17. **Run or no-show** (`LeadPolicy::closeOutAppointment`, slice 7 step 7a,
    decision 125): the same people who may reschedule, so a manager where the
    Lead is, or the salesperson whose appointment it is while the Lead is
    still theirs. From the appointment's start time. A Call Center Agent
    cannot. **Correcting an answer** is for a manager only
    (`appointments.manage`), with a note. No permission was added.
18. **Close out first** (decision 126). While a Lead has an appointment that is
    over and unanswered, nobody may book it again, send it back, move it,
    return it to central or disqualify it, whatever their role. A salesperson
    answers their own appointments oldest first; a manager is not held to
    that order. No permission was added.
19. **Proposals** (`LeadPolicy::manageProposals`, slice 7 step 7b): adding a
    proposal, or recording that one was presented at another appointment, is
    for a manager where the Lead is (`appointments.manage`) or the salesperson
    the Lead is with while it is with Sales. Anyone who may see the Lead sees
    its proposals and their prices. No permission was added.
20. **Results** (slice 7 step 7c). The same people as rule 19 give a proposal
    its result (sold, lost, still pending), change one that is still pending,
    and mark the Lead lost; the salesperson can still do so on their own Lead
    once it shows Sold. **A manager only** (`appointments.manage`), each with a
    note: correcting a result already given, marking a proposal "added in
    error", and undoing a lost Lead. **The after-action report** is opened and
    saved by whoever may answer that appointment (rule 17). No permission was
    added.
21. **Sales figures** (Sales > Performance, slice 7 step 7d). The page opens
    with `sales.access`. A person sees figures only for a Location where they
    manage appointments (`appointments.manage`: everyone there, or any one
    salesperson) or are a Sales Consultant (their own figures only). No
    permission was added.
22. **Projects** (`ProjectPolicy`, slice 8 step 8a, decision 130). A Project is
    seen by anyone with `projects.view` at the Project's own Location: Owner,
    Leadership, the Location Manager, every Sales Consultant and Production
    Manager there, and Finance. Whose Project it is grants nothing (rule
    "Responsibility is not permission"). Its title and description are edited
    with `projects.manage`. Nobody makes or cancels a Project directly: the
    sale and its correction do (rule 20). A Call Center Agent cannot open a
    Project; on the customer page they see its title and sold amount when they
    may see the Lead it came from.
23. **The handoff and the Production Manager** (slice 8 step 8b, decisions 130
    and 132). **Answering or correcting the handoff questions**
    (`ProjectPolicy::completeHandoff`): the salesperson who sold the Project,
    or anyone with `projects.manage` there. A Production Manager reads the
    answers and cannot write them. **Assigning or changing the Production
    Manager** (`assignProductionManager`): `projects.manage`. **Taking a
    waiting job** (`take`): someone with the Production Manager role at the
    Project's own Location, for themselves, once the handoff is complete and
    nobody has it. Only a person with that role there can hold a Project.
    Holding one grants nothing. **Production > Workspace** opens with
    `production.access` and lists only the Locations where the person has
    `projects.view`. No permission was added.
24. **Pending and Rehash** (slice 9, decision 137). **Moving a proposal's
    "valid until" day** is rule 20's change of a pending proposal: the
    salesperson up to 30 days ahead, a manager (`appointments.manage`) to any
    day. **The Rehash queue** is worked by whoever works the call queue
    (`leads.work_queue`). **Marking a Lead lost from Rehash**
    (`LeadPolicy::markLostFromRehash`): whoever may book that Lead an
    appointment (`LeadPolicy::bookAppointment`), so a Call Center Agent and a
    manager there, and not the salesperson it was released from. **Taking it
    back** is rule 12 of the rulebook, unchanged: only that salesperson. The
    number of days a proposal is valid is changed with
    `settings.contact_center.manage`. No permission was added.
25. **Pre-production** (`ProjectPolicy::managePreProduction`, slice 10 step
    10a, decision 141). Ticking or unticking the welcome call and setting or
    changing the rough timeframe: the Project's Production Manager, or anyone
    with `projects.manage` at the Project's Location, once the Project has a
    Production Manager and while its sale stands. Another Production Manager
    there, and the salesperson who sold it, read both and write neither. No
    permission was added. **The Color Specification** (step 10b) follows the
    same rule: the same people write the colors, mark them as with the
    customer and record the customer's approval; everyone else who may see
    the Project reads them. **Readiness** (step 10c) is read by everyone who
    may see the Project and is written by nobody: it is worked out from the
    record. **Work Packages and the dates offered** (slice 11 step 11a,
    decision 144) follow the same rule as the colors, and only while the job
    is ready to schedule; everyone else who may see the Project reads them.
    **Confirming dates and rescheduling** (step 11b) follow the same rule.

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
**Since decision 85 (built 2026-10-09) a Call Center Agent has that manager
part only when their own switch is on.** The switch is a yes or no kept on the
person (`users.queue_manager_view`), off for everyone at first, and changed
only by the people who manage the Contact Center settings (Owner and
Leadership, all-locations grant) on that settings page, with an audit event
each time. The rule, in one place (`User::managesQueueAt` and
`managesQueueAcrossAllLocations`): the person may assign Leads there, and if
that comes from a Call Center Agent grant the switch must be on. A person who
may assign through any other role (Owner, Leadership, Location Manager) is not
affected, whatever their switch says, and the switch gives nothing to a person
who may not assign. It is not a permission and no role grant changed: an agent
with the switch off still assigns Leads and works the queue exactly as before.
**No permission or role grant was added.** Each permission is added to the code in the step named below. The full
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
