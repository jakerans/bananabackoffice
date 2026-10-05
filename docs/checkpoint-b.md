# Checkpoint B — Leads, Customers and Projects

Status: **Model approved by the owner (2026-10-05). Plan awaiting one last review.**
**B0 (this document) is complete. B1 has not been started. Nothing here is built.**

Where a point goes beyond what the owner decided, it is marked **Proposed** or
**Open** and listed again under "Open decisions" at the end.

## Objective

Backoffice becomes the system of record for the path from first contact to a
project, with location-aware access throughout:

> Lead capture → attribution → routing → Account / Contact / Property →
> qualification or disqualification → Project → sales outcome

When Checkpoint B is complete, staff can capture an inquiry exactly as it arrived,
record where it came from, route it to a location, assign it to a person, link it
to a customer, qualify or disqualify it with a reason, create one or more projects
from it, record whether each project was won or lost and why, keep notes, and
attach private project photos. Every list and page shows only what the person's
locations allow.

There is no existing customer or lead data to bring in. No import tooling is built.

## Vocabulary

| Term | Meaning |
| --- | --- |
| **Account** | The party we do business with: a household or a business. Called "Account" in the code and data, and **"Customers"** everywhere staff see it. |
| **Contact** | A person. May be connected to several Accounts. |
| **Property** | A physical place where work could occur. Belongs to one Account. |
| **Lead** | One inquiry. Never a Project. |
| **Project** | A specific qualified potential or actual scope of work at a Property. The one canonical record that Sales, Production and Finance will each view in their own way later. |
| **Location** | An operating location of Banana Painting. The authorization boundary. |
| **Routing** | Deciding which Location a Lead belongs to. |
| **Assignment** | Naming the staff member responsible. Never grants access. |

## The records

### Account

- Type is **Residential** or **Commercial**, from the start. Nothing assumes a
  homeowner.
- A commercial Account has a business name and is shown by it. A residential
  Account is shown by its primary Contact's name.
- An Account has many Contacts, many Properties and many Leads, and has Projects
  through its Properties.
- **An Account does not belong to a Location.**

### Contact

- A Contact is a person: name, email, phone, alternate phone.
- A Contact is connected to an Account through a relationship that carries a
  **role**: Owner, Spouse / Partner, Property Manager, Tenant, Business Contact,
  Billing Contact, Other.
- One Contact may be connected to several Accounts, with a different role on each.
  A property manager who looks after ten owners is one Contact with ten
  relationships, never ten copies of the same person.
- One Contact may be marked **primary** for an Account.
- A Contact is always created in the context of an Account.

### Property

- A physical place where work could occur. Belongs to one Account. Type is
  Residential or Commercial.
- **A Property's address never decides who may see anything.** A Property may sit
  outside the operating area of the person entering it. Access follows Leads and
  Projects and their Location.
- If a Property changes hands, the new owner's Account gets its own Property record
  and the earlier history stays where it was.

### Lead

- A Lead is one inquiry, kept historically separate from any Project. Someone who
  inquires again later gets a new Lead on the same Account.
- **The inquiry is preserved as received:** the name, phone, email, address and
  message the person gave, and when it arrived. These are recorded before the Lead
  is matched to anything and are not overwritten when it is linked. Any later
  correction to them is recorded as an audit event.
- A Lead may later be linked to an Account, a Contact, a Property and a Location.
  Each link is optional until it is known.
- A Lead with no Location is **unrouted**: received centrally and not yet sent to a
  location.

#### Lead lifecycle

| Status | Meaning |
| --- | --- |
| **New** | Received; nobody has worked it yet. |
| **Working** | Someone is in contact or trying to be. |
| **Nurture** (Proposed) | A real opportunity that is not ready now. Carries an optional "follow up on" date. Not a disqualification. |
| **Qualified** | A legitimate sales opportunity. Projects may be created from it. |
| **Disqualified** | Did not become a legitimate opportunity, or Banana Painting decided not to pursue it. Requires a reason. |

- There is no generic "closed" status. Disqualification and sales loss are never
  mixed: a Lead is disqualified; a **Project** is lost.
- A Lead keeps the date it was first qualified and the date it was disqualified,
  even if its status changes afterwards, so rates can be calculated correctly.
- A disqualified Lead can be reopened. A Qualified Lead with no Project can move to
  Nurture, back to Working, or to Disqualified. Once a Project exists, a lost
  opportunity is recorded on the Project, not by disqualifying the Lead. (Proposed)
- "Unable to Contact" is meant for use after proper follow-up, not after one missed
  call. Checkpoint B does not build follow-up automation; Nurture and the follow-up
  date are the foundation for it.
- Leads are never archived as a substitute for their lifecycle.

#### Attribution: three separate questions

Each Lead records three things. They belong to that Lead, not to the Account: the
same customer may answer differently on a later inquiry.

| Field | The question it answers |
| --- | --- |
| **Marketing Source** | How did this person originally hear about Banana Painting? |
| **Lead Source** | Where did this particular inquiry originate or enter our lead ecosystem? |
| **Lead Channel** | How did they actually communicate with us? |

- All three are independently reportable.
- **Unknown is better than invented.** Staff are never pushed to guess.
- Facebook and Instagram are one value, and paid versus organic is not split, where
  the only evidence would be the customer's recollection.
- Referral is one Marketing Source. If we later need to know who referred someone,
  a separate "Referred By" is added; the source list is not multiplied.
- Door Hanger and Direct Mail stay separate because they are different spending.
- Integrations may fill these in automatically later. No attribution integration or
  UTM automation is built in Checkpoint B.
- Proposed: Marketing Source and Lead Source are always filled, defaulting to their
  "Unknown" value, so that "unknown" is recorded one way only. Lead Channel is
  always chosen, because the person taking the inquiry knows how it arrived.

### Project

- A Project represents a specific qualified potential or actual scope of work at a
  Property.
- **A Project always has a Location.** The "not yet assigned to a location" state
  lives on the Lead. (This refines the original brief's "once assigned".)
- A Project belongs to a Property, and through it to the Account.
- A Qualified Lead may produce one or more Projects, once staff identify legitimate
  scopes of work. **Booking an estimate is not the universal conversion point.**
- A Project may be created directly, with no originating Lead, for legitimate
  repeat-customer work. The link to an originating Lead is therefore optional.
- There is one Project record. There are no separate Sales, Production or Finance
  projects.

#### Sales outcome

- A Project's sales outcome is its own field: **Open**, **Won**, **Lost**. It is
  separate from the Lead lifecycle, and it is not a general project status.
  Production workflow and Finance state will be added later as their own fields and
  will evolve independently.
- **Lost requires a Sales Loss Reason.** When the reason is "Other", an explanatory
  note is required. When it is "Chose Another Contractor", a competitor name may be
  typed in. There is no competitor list or management screen.
- In Checkpoint B the outcome is set by a person. Later it will follow from
  estimates and proposals.

#### Responsibilities

- A Project has named responsibilities, one person each. Checkpoint B starts with
  **Sales Consultant** and **Production Manager**.
- Responsibility types are defined in one place so more can be added without
  touching how Projects are authorized.
- A responsibility is not a role and grants nothing. The person named must already
  have access at the Project's Location, or the assignment is refused.

## Managed lists

Five lists are data that Owner and Leadership manage, not values fixed in code:

| List | Used on |
| --- | --- |
| Marketing Sources | Lead |
| Lead Sources | Lead |
| Lead Channels | Lead |
| Disqualification Reasons | Lead |
| Sales Loss Reasons | Project |

For each list, Owner and Leadership can **add, rename, reorder, and activate or
deactivate** an option.

- A deactivated option disappears from the choices for new records. Every existing
  record keeps pointing at it, and history and reports still show it.
- An option that has been used is never deleted.
- The Settings screen is one simple pattern repeated for the five lists. It is not a
  general "configure your CRM" tool.
- Lifecycle statuses, Account types, Contact roles and responsibility types are
  **not** managed lists. They carry behavior, so they stay in code.

### Starting values

**Marketing Sources:** Google Search / Organic · Google Ads · Facebook / Instagram ·
Previous Customer · Referral · Yard Sign · Vehicle / Trailer · Door Hanger ·
Direct Mail · Door-to-Door Canvassing · Event / Community · Radio · TV / Streaming ·
Other · Unknown / Unattributed

**Lead Sources:** Google Business Profile · Google Search / Website ·
Facebook / Instagram · Website — Direct · Previous Customer · Referral · Yard Sign ·
Vehicle / Trailer · Door Hanger · Direct Mail · Door-to-Door Canvassing ·
Event / Community · Radio · TV / Streaming · Other · Unknown

**Lead Channels:** Phone Call · Web Form · Meta Form · Text Message · Email ·
Facebook / Instagram Message · In Person · Other

**Disqualification Reasons** (with the reporting category proposed for each):

| Reason | Category (Proposed) |
| --- | --- |
| Outside Service Area | Serviceability |
| Service Not Offered | Serviceability |
| Project Too Small / Below Minimum | Serviceability |
| Not Authorized to Make Decision | Lead quality |
| Unable to Contact | Lead quality |
| Invalid Contact Information | Invalid |
| Duplicate Lead | Invalid |
| Spam / Solicitation | Invalid |
| Other | Other |

Future timing is deliberately not a disqualification reason; that is what Nurture
is for. The category exists for reporting. Frontline staff choose a reason and
never see or pick a category.

**Sales Loss Reasons:** Price · Chose Another Contractor · Customer Decided Not to
Proceed · Project Postponed / Cancelled · Timing / Scheduling · Scope / Solution
Not a Fit · Financing / Payment · Unable to Re-Engage / No Decision · Other

## Location, routing and assignment

- **Location is the primary authorization boundary.**
- A Lead captured by central staff may be left unrouted. A Lead captured by
  location staff is created at one of their own locations; they cannot create an
  unrouted Lead.
- **Routing** a Lead to a Location, and changing it, is an explicit action with its
  own permission. Each change is an audit event.
- **A Location Manager may return a Lead to the central queue.** A reason is
  required and an audit event is recorded. They then no longer see it.
- **Transferring** a Project to another Location is an explicit action reserved for
  Owner and Leadership, and is an audit event.
- **Assignment drives responsibility, queues and "Assigned to me" views, and nothing
  else.** Everyone with access at the Location still sees the record. A Lead has
  one assigned person; a Project has its responsibilities.

## Who can see what

| Record | Who can see it |
| --- | --- |
| Project | Anyone holding the project permission at the Project's Location. |
| Routed Lead | Anyone holding the lead permission at the Lead's Location. |
| Unrouted Lead | Only Owner, Leadership and Call Center, and only through a company-wide grant. Never ordinary location staff. |
| Account, its Contacts and Properties | Anyone who can see at least one Lead or Project of that Account; and anyone holding the account permission through a company-wide grant. |
| Note, attachment | Whoever can see the record it is attached to. |

- **Seeing an Account never reveals another location's work.** The Account page
  lists only the Leads and Projects the viewer is authorized for. Nothing about the
  others is shown, including whether any exist.
- An Account that has no Lead or Project is visible only to company-wide staff.
- A Contact shared between Accounts is shown with the Accounts the viewer can see.
  Their other relationships are not revealed. Their contact details are the same
  person's details everywhere, so a correction made through one Account applies to
  all, and is audited.
- The location selector remains a way of viewing. Access is always decided by the
  record's own Location against the person's grants.

## Duplicates

- Accounts, Contacts and Properties are **never merged silently.**
- Phone numbers and email addresses are stored in a normalized form alongside what
  was typed, so the same number written two ways can be recognized. Exact text
  matching alone is not the strategy.
- When a new Lead, Contact or Property looks like an existing one, staff are shown
  **suggestions** and decide: the same, or new. Anything ambiguous requires that
  human decision.
- Checkpoint B builds the normalized data and the suggestions at the point of
  entry. A tool for merging two existing records is not part of Checkpoint B.
- Open: how much of an existing Account a suggestion may show to someone who could
  not otherwise see it. See "Open decisions".

## Archiving

- Accounts, Contacts, Properties and Projects are **archived and restored**, not
  deleted. The interface has no delete for them.
- Archiving records who and when. Archived records are hidden from lists and
  choices by default, can be shown with a filter, and can be restored.
- An Account cannot be archived while it has a Lead that is New, Working or
  Nurture, or a Project whose sales outcome is Open. (Proposed)
- Relationships and list values on archived records stay intact.
- Leads are not archived; they have their lifecycle.

## Attachments

- One general attachment record that can belong to any kind of record. **Checkpoint
  B exposes project photos only.** The same model will carry PDFs, documents, paint
  specifications and signed files later.
- Each attachment records: what it belongs to, its kind, a generated storage key
  (never the uploaded file's name), the original filename, the file type as
  detected by the server, size, a checksum, an optional caption, and who uploaded it.
- **Storage is private object storage.** Staging uses the DigitalOcean Space
  `banana-backoffice-staging-files` in NYC3. It is separate from the database
  backup Space and has its own access key that can reach only that bucket.
  Production will have its own separate bucket and key.
- **No permanent public address exists for any file.** To show or download one, the
  application checks permission and then issues a link that expires within minutes.
- **Removing an attachment** hides it from the application immediately. The stored
  file is kept for 30 days and is then eligible for permanent purge.
- The owner has approved Laravel's standard S3-compatible storage driver. It is
  installed in the attachments step, not before.
- Local development and tests use a private local folder through the same code.
- Database backups do not cover these files. Their protection is part of the
  attachments step. See roadmap.md, "Backups".

## Notes, audit events and the timeline

| | What it is | Who writes it |
| --- | --- | --- |
| **Note** | Operational information in a person's own words: "customer prefers Tuesday". | Staff |
| **Audit event** | An append-only record of an important change: who, when, what, and the before and after values where they matter. | The system |
| **Timeline** | What staff read on a Lead, Project or Account page. | Composed from notes plus selected audit events, in plain English |

There is one store of system events. The timeline is a view over it, so the two can
never disagree. This is deliberately not a compliance product.

| Change | Audit event | Shown in the timeline |
| --- | :-: | :-: |
| Lead received | Yes | Yes |
| Lead routed, re-routed, returned to central (with reason) | Yes | Yes |
| Lead assigned or reassigned | Yes | Yes |
| Lead moved to Working, Nurture, Qualified or Disqualified (with reason); reopened | Yes | Yes |
| Lead linked to or unlinked from an Account, Contact or Property | Yes | Yes |
| Project created (from a Lead or directly) | Yes | Yes |
| Project transferred to another Location | Yes | Yes |
| Project responsibility changed | Yes | Yes |
| Project sales outcome set or changed (with reason) | Yes | Yes |
| Project, Account, Contact or Property archived or restored | Yes | Yes |
| Attachment added or removed | Yes | Yes |
| Original inquiry details corrected | Yes | No |
| Account, Contact or Property details edited | Yes | No |
| Contact connected, disconnected, role changed, primary changed | Yes | No |
| Note removed | Yes | No |
| Managed list option added, renamed, reordered, activated, deactivated | Yes | No |
| Staff invited; invitation accepted | Yes | No |
| Staff access granted or revoked | Yes | No |
| Staff deactivated or reactivated | Yes | No |
| Location created, renamed, deactivated, reactivated | Yes | No |
| Note added | No. The note is the record. | Yes |
| Viewing, searching, downloading, signing in | No | No |

- Audit events are never edited or deleted by the application.
- A note can be removed from view by its author or a manager. It is kept, and its
  removal is an audit event. Notes are not edited after saving. (Proposed)
- Managers get a "History" view of a record's audit events. The company-wide Audit
  Log screen in Settings stays Coming Soon.

## Tables

Proposed tables and their important fields. "Optional" means it may be empty.

| Table | Important fields |
| --- | --- |
| `accounts` | type; business name (commercial); display name; archived at, by |
| `contacts` | first name; last name; email; phone; alternate phone; normalized forms of each for matching; archived at, by |
| `account_contacts` | account; contact; role; primary flag. One row per pair. |
| `properties` | account; label; type; address lines, city, state, postal code; archived at, by |
| `marketing_sources`, `lead_sources`, `lead_channels` | name; position; active |
| `lead_disqualification_reasons` | name; position; active; category; whether a note is required |
| `sales_loss_reasons` | name; position; active; whether a note is required; whether a competitor may be recorded |
| `leads` | received at; marketing source; lead source; lead channel; inquiry name, phone, email, address, message (as received); account, contact, property (each optional); location (optional: empty is unrouted); assigned staff member (optional); status; follow-up date; first qualified at, by; disqualified at, by; disqualification reason and note |
| `projects` | property; location; originating lead (optional); primary contact (optional); title; description; sales outcome; outcome set at, by; sales loss reason and note; competitor name (optional); archived at, by |
| `project_responsibilities` | project; responsibility type; staff member; assigned by. One person per type per project. |
| `notes` | the record it belongs to (Lead, Project, Account or Property); text; author; removed at, by |
| `attachments` | the record it belongs to; kind; storage key; original filename; file type; size; checksum; caption; uploaded by; removed at, by; purged at |
| `audit_events` | when; who (and their name at the time); action; the record concerned; location (optional); before and after values; reason (optional) |

Relationships the application enforces and tests:

- A Lead's Contact and Property belong to the Lead's Account.
- A Project's primary Contact is a Contact of the Project's Account.
- A Project created from a Lead uses a Property of that Lead's Account.
- An Account has at most one primary Contact.
- Every foreign key has its own index, and every schema change is run on MySQL 8.4
  (decisions.md, 21).

## Reporting the model must support

No dashboards or reports are built in Checkpoint B. The data is kept so they can be
built correctly later:

| Measure | Comes from |
| --- | --- |
| Total Leads | Leads by date received |
| Qualification rate | Leads that were ever qualified ÷ total Leads |
| Disqualification rate and reasons | Disqualified Leads, by reason and category |
| Qualified opportunities | Projects |
| Won and lost | Project sales outcome |
| Sales close rate | Won ÷ (Won + Lost) Projects. **Disqualified Leads never enter this.** |
| Sales Loss Reasons | Lost Projects, by reason |
| Results by Marketing Source, Lead Source, Lead Channel | Each Lead's own three values, followed through to its Projects and their outcomes |

- A Project created directly has no Lead and so no attribution. It is reported as
  "no originating Lead", never given an invented source.
- Revenue will come from accepted proposals when estimating is built. Whether to
  record a sold amount before then is an open decision.

## Build sequence

Each step is small, has its own tests, runs the full check, and **stops for the
owner's review** before the next begins. The order follows the operational path
first; photos and history presentation come last.

| Step | Delivers |
| --- | --- |
| **B0** | This plan and the updated documents. No code. **Complete.** |
| **B1** | Audit event recording, and audit events for the Checkpoint A changes: staff invited, access granted and revoked, staff deactivated and reactivated, location changes. |
| **B2** | Managed lists: the three attribution lists with their starting values, and the Settings screen for Owner and Leadership. |
| **B3** | Lead capture: the inquiry as received, the three attribution fields, New and Working, the Leads list for a location, the Lead page. |
| **B4** | Routing and assignment: the unrouted queue, route and re-route, return to central with a reason, assign a person, "Assigned to me". |
| **B5** | Notes on a Lead. (Extended to Accounts and Projects as those arrive.) |
| **B6** | Accounts, Contacts and Properties: create them from a Lead or link an existing one with duplicate suggestions; the Customers list and page with the visibility rules; archive and restore. |
| **B7** | Qualification: Qualified, Disqualified with managed reasons, Nurture with a follow-up date, reopening. |
| **B8** | Projects: create one or more from a Qualified Lead, or directly; the Project page; responsibilities; transfer between locations; archive and restore; the minimum way to find Projects. |
| **B9** | Sales outcome: Open, Won, Lost, with managed Sales Loss Reasons. |
| **B10** | Attachments: storage setup for staging, the attachment model, project photos, removal and the 30-day purge. |
| **B11** | Timeline on Lead, Project and Account pages; the History view for managers. |
| **B12** | Documentation, full check, staging verification, owner review of the whole checkpoint. |

B5 is one step earlier than the owner's stated order. People working Leads need
somewhere to write down what was said from the first day. It is small and can be
moved if the owner prefers.

## Not in Checkpoint B

The call board, call cadence, auto-dialling and alarms; the Inbox; appointments;
estimates and proposals; production stages and scheduling; finance; dashboards and
reports; attribution integrations and UTM capture; import tooling; a tool for
merging records; competitor management; "Referred By"; automatic routing by service
area; follow-up automation; the company-wide Audit Log screen; the homeowner
portal; Color Studio.

## Open decisions

Nothing blocks B1.

| # | Decision | Recommendation | Needed by |
| --- | --- | --- | --- |
| 1 | **Where Leads live in the menu.** The approved place is Marketing > Call Center > Leads, but Sales Consultants cannot open the Marketing Center, and they work and convert Leads. | Make Leads a shared page like Customers: one address, shown under Marketing > Call Center and in the Sales Center, opened by the lead permission. | B3 |
| 2 | **The Settings page for the managed lists** has no approved menu item, and Leadership has no Settings access today. | Add one Settings item, "Lists". Leadership with a company-wide grant sees Settings containing only that page. | B2 |
| 3 | **Nurture as a fifth Lead status**, with a follow-up date. | Include it. Without it staff must leave such Leads in Working forever or disqualify them. | B7 |
| 4 | **How much a duplicate suggestion may reveal** about an Account the person could not otherwise see. | Show the name, the Account type and the phone or email that matched, and nothing about its Leads, Projects or Locations. | B6 |
| 5 | **The minimum way to find Projects.** There is no approved menu item for a plain Projects list. | One shared "Projects" list with filters for location, "assigned to me" and sales outcome, shown in the Sales and Production centers. | B8 |
| 6 | **A sold amount on a Won Project** before estimating exists. Without it nothing records what a job sold for at launch. | An optional amount entered when marking Won, plainly temporary and replaced by proposals later. | B9 |
| 7 | **The reporting category of each Disqualification Reason.** | As in the table above. | B7 |
| 8 | **Launch scope and timing.** Backoffice is to be the system of record from launch, which is about a week away. There is no production environment yet, and the items in security-notes.md that must precede real use are open. | Decide which steps are the minimum for the first day (B1 to B9 cover the operational path), and schedule production setup alongside them. | Planning |
