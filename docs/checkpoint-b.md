# Checkpoint B — Leads, Customers and Projects

Status: **Approved by the owner (2026-10-05), including the final review.**
**B0 (this document) is complete. The roadmap shows which steps are built.**

The few points still marked **Proposed** are details the owner has seen and not
objected to; they are confirmed or changed in the step that builds them.

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
- A Contact is connected to an Account through a relationship that carries one or
  **more roles**: Owner, Spouse / Partner, Property Manager, Tenant, Business
  Contact, Billing Contact, Other. A person may be several at once, such as Owner
  and Billing Contact; nobody has to pick one "best" role.
- One Contact may be connected to several Accounts, with different roles on each.
  A property manager who looks after ten owners is one Contact with ten
  relationships, never ten copies of the same person.
- One Contact may be marked **primary** for an Account. Primary is a separate
  mark, not a role. A Residential Account must have a primary Contact. A
  Commercial Account may exist without any Contact at first.
- A Contact is always created in the context of an Account.
- **Phone and email are matching signals, not identity.** Neither is unique:
  households share an email address and offices share a phone. A match is a
  question for a person, never an automatic link.

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
| **Qualified** | A legitimate sales opportunity. Projects may be created from it. |
| **Nurture** | The opportunity remains viable but should be followed up later. Carries a next follow-up date. Never treated as Disqualified or as a Sales Loss. |
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

#### Lead capture, as built in step B3

- **To save a Lead:** a Lead Channel, at least one Service, a Project Size, and at
  least one of name, phone or email. Nothing else is required.
- **The form is one page in two groups.** Quick capture: name, phone and email,
  business name, channel, service(s), project size, "Tell us about what we're
  painting today", property address. Attribution and routing: marketing source,
  lead source, location, received time. Typing starts in the name. The channel and
  the project size are each one tap and are never chosen for you.
- **Services.** At least one is required. With one, it is the primary without
  anyone saying so. With several, the intake person must say which is the main
  one; it is never chosen for them by list order. The Primary Service is the
  dominant opportunity, for reporting and later routing. The database itself
  refuses a second primary on one Lead.
- **Project Size** is required: Small, Medium or Large, shown with the expected
  value each covers. It is the intake person's judgment from the conversation, not
  an estimate, and it is stored apart from any estimate or sold amount.
- **The free-text field stays,** labelled "Tell us about what we're painting
  today". The structured choices do not replace what a person can describe.
- **Property address** is looked up, or typed by hand. See "Property address, as
  built" below.
- **Both sources start on their "unknown" option,** so a Lead can be saved without
  touching them. That option is marked in the data, may be renamed, and can never
  be deactivated.
- **Location.** Company-wide lead staff start on "Not yet routed" and may choose a
  location while creating. Location staff create the Lead at one of their own
  locations and cannot leave it unrouted. Changing the location afterward,
  returning it to central and assigning a person are step B4.
- **Phone.** What was typed is kept, beside a matching form in international
  notation ("+15552013344"). Ordinary U.S. numbers are recognized; a number that is
  not is saved as typed with a gentle hint and simply cannot be matched. Nothing in
  the data limits it to U.S. numbers.
- **Email.** Kept as typed, beside a lowercase form for matching. A malformed
  address is refused.
- **Address.** Kept as typed. It is not parsed or looked up.
- **Earlier inquiries.** When a phone or email matches earlier Leads the person is
  already allowed to see, they are listed on the form. It never blocks saving.
  Nothing at all is shown about a match the person may not see.
- **Received time** is typed and shown in the company's timezone and stored in UTC.
  It may be set earlier, never in the future.
- **Statuses built:** New and Working. "Start working" is the only way a Lead
  becomes Working. Correcting or adding information never changes the status.
- **Correcting.** Anyone who may work the Lead may correct the inquiry details and
  change its attribution. A Lead keeps an option that was deactivated after it was
  chosen, but cannot be moved to a deactivated one.
- **Save and add another,** and pressing Save twice records one Lead.
- **The list** shows the Leads of the location the person is working in, newest
  first, with an "Unrouted" queue beside it for those allowed to see it. It can be
  searched by name, business, phone or email and narrowed by status. On a narrow
  screen it becomes cards.
- **Audit events:** Lead received; inquiry details corrected (old and new values
  of each changed field); attribution changed; services changed; project size
  changed; work started.

#### Services

- A managed list like the others, on the Lead & Sales Lists page: Owner and
  Leadership add, rename, reorder, activate and deactivate; nothing is deleted.
- Starting values: Interior Painting · Exterior Painting · Cabinet Painting ·
  Deck / Fence · Commercial Interior · Commercial Exterior · Other.
- A Lead has at least one, and exactly one is primary. A Lead keeps a service
  that was deactivated after it was chosen, but cannot be given a deactivated one.
- Services stay on the Lead & Sales Lists page for now.
- "Services and Products" in the Settings menu is a different, unbuilt page (for
  products and pricing later). It is unchanged.

#### Project Size

- Three sizes, fixed: **Small, Medium, Large.** Those are the business values;
  nothing is named Minnow, Fish or Whale underneath.
- Each covers a range of expected project value. Starting ranges: Small under
  $2,000; Medium $2,000 to $9,999; Large $10,000 or more.
- **The ranges are data, not code.** Owner and Leadership change where Medium and
  Large begin on the Project Sizes tab of the Lead & Sales Lists page. The three
  always cover every value with no gap and no overlap. Each change is audited.
- **A Lead keeps the range it was classified under.** The range is copied onto the
  Lead when it is classified, so a Lead called "Medium" last year can still be
  compared fairly with what was estimated and sold after the ranges move.
  Choosing the same size again does not re-copy it; choosing a different size does.
- Staff must choose a size to save or correct a Lead. The data allows a Lead to
  have none, for Leads recorded before sizes existed and for inquiries that will
  one day arrive automatically and cannot be classified by a person.

#### What this preserves for future salesperson routing

Nothing here recommends a salesperson, and step B4 built no algorithm. The data a
later recommendation would need is kept from the first Lead:

| Factor | Where it comes from |
| --- | --- |
| Location eligibility | Access grants |
| Primary and additional Services | The Lead's services |
| Project Size | The Lead's size, with the range it meant |
| Current workload, fair distribution | The current assignee and the assignment history (B4) |
| Speed: received → routed → assigned → first worked | Four times kept on the Lead (B4) |
| Qualification performance | Who held the Lead when it was qualified (history, with B7) |
| Close rate by Service and by Size, sold revenue | The Project's own services, size, responsibilities, outcome and Sold Amount (B8, B9) |
| Availability and capacity | Not modelled yet; a later addition |

A recommendation, when it is built, appears as a marker beside a person in the
same Assign dialog ("Recommended: Roseanne"). Management still chooses, and may
choose someone else. Whether an assignment followed a recommendation is added
with the recommender: every assignment before then was made by a person.

#### Property address, as built

- **Looked up or typed.** With an address provider set up, staff start typing and
  choose from suggestions. Without one, or whenever it has nothing to offer, they
  type the address in parts. Either way a Lead can be saved; an address is optional
  and any part may be left empty.
- **What is stored:**

| Field | Notes |
| --- | --- |
| Display address | The whole address on one line |
| Line 1, line 2 | Street number and name; unit, suite or lot |
| City, state, postal code, country | |
| Latitude, longitude, accuracy | Only when the address was looked up |
| Provider and place identifier | Only when the address was looked up |
| How it was entered | "lookup" or "manual" |

- **Nothing is invented.** An address typed by hand has no coordinates and no
  provider. The lead page says which way an address was entered.
- **The provider is Mapbox** (decisions.md, 42). Suggestions while typing are
  temporary results that are shown and never kept. The one address a person selects
  is requested again as a permanent result, the kind Mapbox allows to be stored.
- **Requests are held back:** nothing is asked until five characters are typed,
  typing is debounced, and each person is limited to 60 lookups a minute.
- **The access token stays on the server.** The browser talks to Backoffice and
  Backoffice talks to Mapbox. Only the address text is sent.
- **Failure is quiet for staff and loud in the log.** If Mapbox is down, refuses
  the token or answers nonsense, no suggestions appear and the address is typed.
  The failure is reported, without the token in the message.
- **Correcting a Lead** can replace or remove its address. An unchanged address is
  left exactly as it was, coordinates included. Replacing a looked-up address by
  hand clears its coordinates, because they belonged to the other address.
- These parts are the same ones a Property will have in step B6.

#### Service areas and the suggested location, as built

- **A service area is a list of ZIP codes for a Location.** It is managed with the
  Location, on Settings > Locations ("Service area"), by whoever may manage
  locations. Every change is audited with what was added and removed.
- **The suggestion is one question:** given an address, which Location normally
  serves it? It is answered only from those lists. **It is never worked out from
  which office is nearest**, and an address's coordinates play no part.
- **Exactly one active Location must list the ZIP code.** If none does, or more than
  one does, or the only one is deactivated, there is no suggestion and nothing is
  guessed. The Locations page points out ZIP codes that two locations both list.
- **On the new-lead form,** when the address has a ZIP code (looked up or typed),
  the suggested Location is selected and the form says why. With no suggestion,
  company-wide staff leave the Lead unrouted or choose, exactly as before.
- **A person's own choice wins.** Once someone picks a Location themselves, no
  later suggestion replaces it. Anyone allowed to capture for another Location may
  override the suggestion.
- **A suggestion never grants anything.** It selects a Location only if the person
  may capture a Lead there, and the Location actually saved is authorized on the
  server like any other. Location staff whose address falls in another Location's
  area keep their own Location and are simply told where it is normally served.
- **The Lead records what was suggested** in its "Lead received" audit event,
  beside the Location it was actually given.
- **Correcting a Lead's address later does not move it.** Changing a Lead's
  Location after creation is step B4.
- **Room for territories drawn on a map.** The rest of Backoffice asks the one
  question above and does not know how it is answered. A map-based answer can be
  added behind the same question later; Leads store only the Location they end up
  with, so they do not change.
#### Routing and assignment, as built in step B4

Two separate questions, kept as separate actions and separate data:

- **Routing: which Location owns this Lead?**
- **Assignment: who is responsible for working it?**

**Routing**

| Action | From → to | Who | Reason |
| --- | --- | --- | --- |
| Route | Unrouted → a Location | Owner, Leadership, company-wide Call Center | Optional |
| Move | Location → another Location | The same people; and a Location Manager when they manage **both** Locations | Required |
| Return to central | Location → Unrouted | Owner, Leadership, Location Manager, Call Center | Required |

- The dialog pre-selects the Location whose service area lists the Lead's ZIP
  code, and says why. Choosing another is the manual override. Both the suggestion
  and the choice are recorded.
- There is no suggestion when the Lead has no ZIP code, when no Location lists it,
  or when two do.
- A Location Manager is offered only the other Locations they manage. The
  permission is checked at both ends on the server. A manager of one Location can
  only return a Lead to central.
- The destination must be an active Location, and not the one the Lead is at.
- Routing never changes a Lead's status.
- **A dialog that is out of date saves nothing.** If someone else moved or
  reassigned the Lead after the dialog was opened, the action is refused.
- Changing a service area, or correcting a Lead's address, never moves a Lead.
- Someone who returns a Lead they can then no longer see is taken back to the list.

**Assignment**

- **A Lead has one assignee, or none.** The history of everyone who has held it is
  kept in `lead_assignments`: who, who assigned them, at which Location, from when
  to when, and why it ended (reassigned, released, the Lead changed Location,
  access was removed). The database allows one open assignment per Lead and no
  assignment is ever deleted.
- **Who may assign, reassign or leave a Lead unassigned:** Owner, Leadership,
  Location Manager and Call Center, where they hold that role.
- **Who may be assigned:** anyone who can already work Leads where the Lead is.
  For an unrouted Lead that is company-wide lead staff only. A deactivated person,
  or one who has not accepted their invitation, cannot be assigned. **Assignment
  never grants access.**
- **Sales Consultants do not pick up Leads.** They cannot assign, reassign or
  release, and they have no Unassigned queue. They work the Leads given to them.
- **Every change of Location clears the assignee,** even when that person also
  works at the destination. A move is a handoff; the new Location decides.
- **Losing access clears assignments.** When a grant is removed or an account is
  deactivated, any Lead the person holds and can no longer work becomes
  unassigned, in the same transaction. Reactivating does not give them back.
- **Recording a Lead:** a Sales Consultant who records a Lead at a Location where
  they are a Sales Consultant is assigned it, recorded as an assignment like any
  other. A Lead recorded by anyone else is left unassigned.
- **The Assign dialog** lists the eligible people with how many open Leads each
  has. The count includes only Leads the person looking may see.

**Start working**

- The assignee may start working their Lead.
- Someone who may assign Leads may start an unassigned one, which assigns it to
  them. The button says so.
- Nobody may start a Lead assigned to someone else; it must be reassigned first.
- A Sales Consultant cannot acquire an unassigned Lead by starting it.

**Views on the Leads page**

| View | Shows | Order | Who has it |
| --- | --- | --- | --- |
| Assigned to me | The person's Leads at every Location they can see, whatever Location is selected | Oldest first | Everyone |
| Unassigned | Leads at the current Location with no assignee | Longest at that Location first | People who may assign there |
| All leads | Every Lead at the current Location | Newest first | Everyone |
| Unrouted | Leads not yet sent to a Location | Oldest first | People who may see unrouted Leads |

The page opens on: All leads for Owner and Leadership; Unrouted for company-wide
Call Center; Unassigned for a Location Manager (and a Call Center agent at one
Location); Assigned to me for a Sales Consultant. Nurture arrives with B7.

A Sales Consultant can still see unassigned Leads in All leads, because the
Location, not assignment, decides what may be seen. They cannot take one.

**Times kept on a Lead** (no report uses them yet): received, first routed, arrived
at its current Location, assigned to its current assignee, work first started.

**Correcting the Received time**

- Anyone who may correct the Lead may correct it, from the Lead page.
- A reason is required, because response times are measured from it.
- A future time is refused. The time before and after, who and why are recorded.
- **A Lead is received before anyone acts on it.** The corrected time may not be
  later than when the Lead was first routed, first assigned, or first worked,
  because that would make a response time negative. The earliest of those is the
  limit, and the refusal names it. Those other times are never adjusted to make
  a correction fit: the correction is refused and nothing changes.
- **When the Lead's record was created is not a limit.** That is a fact about the
  database row, not about the inquiry. A correct Received time can be later.
**Deactivating a Location**

- A Location with active Leads cannot be deactivated. The refusal says how many.
- They must first be moved to another Location or returned to central.
- Nothing overrides this, and there is no bulk "close a Location" action.
- **For B8:** active Projects must block deactivation the same way.

#### Recorded for step B8

- **Projects carry their own Services and Project Size.** When a Project is
  created from a Lead they are copied from the Lead as a starting point. A
  Project created directly captures them itself. Sales-performance reporting then
  reads the Project, not a Lead that may be corrected later or may not exist.

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
- **Marking a Project Won records its Sold Amount.** Until estimates and proposals
  exist this is the temporary source of sold value, so early revenue is not lost
  from the system. When accepted proposals exist they become the authoritative
  source, and the amount is no longer typed in a second time.
- Owner and Leadership may correct a Sold Amount. Every correction is an audit
  event recording the old and new amounts.

#### Responsibilities

- A Project has named responsibilities, one person each. Checkpoint B starts with
  **Sales Consultant** and **Production Manager**.
- Responsibility types are defined in one place so more can be added without
  touching how Projects are authorized.
- A responsibility is not a role and grants nothing. The person named must already
  have access at the Project's Location, or the assignment is refused.

## Managed lists

Six lists are data that Owner and Leadership manage, not values fixed in code.
(Services was added during the B3 review. Project Sizes sit on the same Settings
page but are not a list of options: there are always three, and only the value
each covers can change. See "Project Size".)

| List | Used on |
| --- | --- |
| Marketing Sources | Lead |
| Lead Sources | Lead |
| Lead Channels | Lead |
| Services | Lead |
| Disqualification Reasons | Lead |
| Sales Loss Reasons | Project |

For each list, Owner and Leadership can **add, rename, reorder, and activate or
deactivate** an option.

- A deactivated option disappears from the choices for new records. Every existing
  record keeps pointing at it, and history and reports still show it.
- An option that has been used is never deleted.
- All five live on one Settings page, **Lead & Sales Lists**: one simple pattern
  repeated five times. It is not a general "configure your CRM" tool.
- Leadership's permission is for this page alone. It opens no other Settings page.
- Lifecycle statuses, Account types, Contact roles and responsibility types are
  **not** managed lists. They carry behavior, so they stay in code.

As built in step B2:

- **There is no delete at all,** for used and unused options alike. An option is
  deactivated. The application also refuses to delete one if asked.
- **Reordering** is by moving an option up or down one place.
- **A list always keeps at least one active option,** or staff would have nothing
  to pick.
- **A name must be unique within its list.** The same name may appear in two
  different lists, as "Referral" does.
- **Renaming an option renames it everywhere,** because records point at the
  option and do not keep a copy of its name.
- **The reporting category** of a disqualification reason is chosen when it is
  added and can be changed later.
- **Two behaviours are set on the starting values and cannot be changed from the
  page yet:** "a note is required" (on "Other" in both reason lists) and "ask which
  competitor" (on "Chose Another Contractor"). A reason added later has neither.
  The page shows which reasons have them.
- Every add, rename, change of category, move, deactivation and reactivation is an
  audit event.

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

| Reason | Category |
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

## Customers: decisions for step B6

Approved by the owner on 2026-10-06. B6 is built in three separately reviewed
steps.

| Step | Delivers |
| --- | --- |
| **B6a** | The Account, Contact and Property records; who may see a customer; the Customers list; the customer page (read only). **Built.** |
| **B6b** | Managing Contacts and Properties, with address lookup and manual entry. |
| **B6c** | Resolving a Lead into a customer; duplicate suggestions; linking a shared Contact; "New lead for this customer"; archive and restore. |

- **A customer is not created at capture.** Lead capture stays as fast as it is.
  Matching and linking happen after the Lead is saved.
- **A customer with no Lead may be created by company-wide staff only.** Location
  staff establish their relationship through a Lead, and later a Project. Nobody
  creates a fake Lead to make a customer.
- **Compound names are not split.** "John & Mary Smith" is pre-filled as one
  Contact; staff add a second person deliberately.
- **Masked matches.** When a likely match is a customer the person cannot see,
  they are shown only the Account type, which detail matched, and a limited
  masked value such as "phone ending in 44". Location staff may link a Lead to
  such a match only on an exact normalized phone or email, never on an address
  alone. After they confirm, their own Lead legitimately shows them the shared
  customer, Contacts and Properties, and still never another location's Leads or
  Projects. Every such link is audited.
- **No notes on customers.** Notes stay on Leads, and later Projects, where the
  boundary is clear.
- **Archiving shared customer data is a company-wide management action:** Owner
  and Leadership, through an all-locations grant. A single-location manager
  cannot archive an Account, a Contact or its connection, or a Property. A
  refusal never reveals another location's work.
- **Before a Lead can become a Project (B8):** an Account, a Contact, a Property
  and a Location are required, and the Project's own Services and Project Size.
  A Project may still be created directly, with no originating Lead.
- **A Property never stores a Location.** Where it is may inform a routing
  suggestion later; it never grants or restricts access.

### Customers, as built in step B6a

**The records**

| Record | Holds |
| --- | --- |
| Account | Type (Residential or Commercial); business name; the name it is shown by; archived at, by |
| Contact | First and last name; email, phone, alternate phone, each with a normalized form; archived at, by |
| Connection | One per Account and Contact pair: the primary mark; archived at, by |
| Roles | One row per role a person holds on a connection, so several can be true |
| Property | The Account; a label; type; the same address parts a Lead keeps; archived at, by |
| Lead (added) | Account, Contact, Property: each empty until known |

- The database refuses a second primary Contact for an Account, the same person
  connected to an Account twice, and the same role twice on a connection.
- None of these records can be deleted. The application refuses, and the
  database refuses to remove one a Lead points at.
- A household is shown by its primary Contact's name, a business by its business
  name.

**Who may see a customer.** An Account has no Location, so there are two ways:

1. Holding the customer permission through an **all-locations grant**: every
   customer, including ones with no Lead.
2. Being able to see **at least one of the customer's Leads**, at a Location
   where the person also holds the customer permission.

- A customer whose only Lead is unrouted, or that has no Lead, is seen by
  company-wide staff alone.
- When the last Lead a person can see moves away, returns to central or is
  unlinked, the customer goes out of their sight with it, including on a page
  they already had open.
- Location staff in roles that cannot see Leads (Production Manager, Finance) see
  no customers until Projects exist.
- Every list, search, filter and count starts from this rule, so a search cannot
  reach a customer the person may not see.

**The Customers page** is one shared page, shown in the Marketing, Sales,
Production and Finance menus to whoever holds the customer permission.

- Columns: the customer with its type; primary Contact; phone and email;
  Properties; open Leads.
- Search: customer name, business name, any Contact's name, phone, alternate
  phone, email, street or ZIP code.
- Filters: type; "with a lead at" a Location; show archived.
- **The location selector only sets where the location filter starts.** It never
  decides what may be seen. Only Locations where the person can see Leads are
  offered, and "Any location" shows every customer they can see.
- The open-Lead count includes only Leads the viewer may see. Someone who cannot
  see Leads is shown no count at all.

**The customer page** shows the customer's name and type, its Contacts (primary
first, with every role), its Properties, and its Leads.

- **Only the Leads the viewer may see are listed.** Leads at another Location are
  not shown, not counted and not hinted at.
- For a person who is a contact of several customers, "Also a contact of" lists
  only the other customers the viewer may see. Whether there are others is never
  shown, so a shared person with none visible looks like any other contact.
- Archived Contacts, connections and Properties are not shown.
- Nothing can be changed from this page yet. That is B6b and B6c.

## Location, routing and assignment

- **Location is the primary authorization boundary.**
- A Lead captured by central staff may be left unrouted. A Lead captured by
  location staff is created at one of their own locations; they cannot create an
  unrouted Lead.
- **Routing** a Lead to a Location, and changing it, is an explicit action with its
  own permission. Each change is an audit event.
- **A Location Manager may return a Lead to the central queue.** A reason is
  required and an audit event is recorded. They then no longer see it.
- **A Location Manager who manages both Locations may move a Lead directly between
  them.** The permission is checked at both ends.
- **Any change of a Lead's Location clears its assignee.**
- Routing and assignment as built are described under "Routing and assignment, as
  built in step B4".
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
- **A suggestion never becomes a way to discover customers.** When the likely
  duplicate is an Account the person could not otherwise see, the suggestion shows
  only the minimum needed to recognize it: the Account type and a masked form of
  the phone or email that matched. It shows no name, address, Lead, Project or
  Location. When the person can already see the Account, it is shown normally.

## Archiving

- Accounts, Contacts, Properties and Projects are **archived and restored**, not
  deleted. The interface has no delete for them.
- Archiving records who and when. Archived records are hidden from lists and
  choices by default, can be shown with a filter, and can be restored.
- An Account cannot be archived while it has a Lead that is New, Working or
  Nurture, or a Project whose sales outcome is Open.
- Archiving and restoring Accounts, Contacts, connections and Properties is done
  by Owner and Leadership through an all-locations grant only.
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
| Sold Amount corrected (old and new amount) | Yes | Yes |
| Project, Account, Contact or Property archived or restored | Yes | Yes |
| Attachment added or removed | Yes | Yes |
| Original inquiry details corrected | Yes | No |
| Account, Contact or Property details edited | Yes | No |
| Contact connected, disconnected, role changed, primary changed | Yes | No |
| Note removed | Yes | No |
| Managed list option added, renamed, reordered, activated, deactivated | Yes | No |
| Staff invited; invitation resent; invitation accepted | Yes | No |
| Staff access granted or revoked | Yes | No |
| Staff deactivated or reactivated | Yes | No |
| Location created, renamed, deactivated, reactivated | Yes | No |
| Note added | No. The note is the record. | Yes |
| Viewing, searching, downloading, signing in | No | No |

- Audit events are never edited or deleted by the application.
- A note can be removed from view by its author or a manager. It is kept, and its
  removal is an audit event. Notes are not edited after saving.

### Lead notes, as built in step B5

Notes exist on Leads. Projects, Accounts and Properties get them when those
records are built; the `notes` table already allows for it. The timeline and the
History view are step B11 and are not part of this.

- **Where:** a Notes panel on the Lead page, under the inquiry. Newest first,
  each with who it is from and when.
- **Who can read:** anyone who can see the Lead.
- **Who can add:** anyone who can see the Lead and may write notes where the Lead
  is: Owner, Leadership, Location Manager, Call Center, Sales Consultant. For an
  unrouted Lead that means company-wide staff. Assignment is not a boundary: a
  Sales Consultant can add a note to a Lead assigned to a colleague.
- **A note changes nothing else.** Not the Lead's status, not its assignee. Adding
  one is not an audit event: the note is the record.
- **Never edited, never deleted.** The application refuses both. Up to 5,000
  characters; what is typed is always shown as plain text.
- **Removing from view:** the person a note is from, while they can still write
  notes there, or a manager there (Owner, Leadership, Location Manager). The note
  is kept with who removed it and when, and the removal is an audit event about
  the Lead. A removed note is hidden from everyone until the History view exists.
- **Notes stay with the Lead.** When a Lead moves, its notes go with it, and are
  read under the new Location's access.
- **Impersonation:** a note an Owner writes while viewing Backoffice as a staff
  member is shown as that staff member's, like any other note. Nothing on the
  Lead page mentions the Owner. An audit event is recorded with the note, naming
  the real Owner, the staff identity, the Lead and the note; it does not copy
  what the note says. Removing a note while impersonating works the same way:
  screens show the staff member, the audit history holds the Owner.- Managers get a "History" view of a record's audit events. The company-wide Audit
  Log screen in Settings stays Coming Soon.

## Tables

Proposed tables and their important fields. "Optional" means it may be empty.

| Table | Important fields |
| --- | --- |
| `accounts` | type; business name (commercial); display name; archived at, by |
| `contacts` | first name; last name; email; phone; alternate phone; normalized forms of each for matching; archived at, by |
| `account_contacts` | account; contact; primary flag; archived at, by. One row per pair. |
| `account_contact_roles` | the connection; role. One row per role held. |
| `properties` | account; label; type; address lines, city, state, postal code; archived at, by |
| `marketing_sources`, `lead_sources`, `lead_channels` | name; position; active |
| `lead_disqualification_reasons` | name; position; active; category; whether a note is required |
| `sales_loss_reasons` | name; position; active; whether a note is required; whether a competitor may be recorded |
| `leads` | received at; marketing source; lead source; lead channel; inquiry name, phone, email, address, message (as received); account, contact, property (each optional); location (optional: empty is unrouted); assigned staff member (optional); status; follow-up date; first qualified at, by; disqualified at, by; disqualification reason and note |
| `projects` | property; location; originating lead (optional); primary contact (optional); title; description; sales outcome; outcome set at, by; sold amount; sales loss reason and note; competitor name (optional); archived at, by |
| `project_responsibilities` | project; responsibility type; staff member; assigned by. One person per type per project. |
| `notes` | the record it belongs to (Lead, Project, Account or Property); text; author; the Owner who wrote it when impersonating (optional); removed at, by |
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
- Revenue comes from the Sold Amount recorded when a Project is marked Won, until
  accepted proposals take over as the authoritative source.

## Build sequence

Each step is small, has its own tests, runs the full check, and **stops for the
owner's review** before the next begins. The order follows the operational path
first; photos and history presentation come last.

| Step | Delivers |
| --- | --- |
| **B0** | This plan and the updated documents. No code. **Complete.** |
| **B1** | Audit event recording, and audit events for the Checkpoint A changes: staff invited, access granted and revoked, staff deactivated and reactivated, location changes. |
| **B2** | Attribution and managed lists: the Lead & Sales Lists page in Settings for Owner and Leadership, with all five lists and their starting values. (The two reason lists were first planned for B7 and B9; the owner moved them into B2. They are used from B7 and B9.) |
| **B3** | Lead capture: the inquiry as received, the three attribution fields, New and Working, the shared Leads page shown in Marketing and Sales, the Lead page. |
| **B4** | Routing and assignment: the unrouted queue, route and re-route, return to central with a reason, assign a person, "Assigned to me". |
| **B5** | Notes on a Lead. (Extended to Accounts and Projects as those arrive.) |
| **B6** | Accounts, Contacts and Properties, in three reviewed steps. **B6a:** the records, who may see a customer, the Customers list and page. **B6b:** managing Contacts and Properties. **B6c:** resolving a Lead into a customer with duplicate suggestions, shared Contacts, "New lead for this customer", archive and restore. |
| **B7** | Qualification: Qualified, Disqualified with managed reasons, Nurture with a follow-up date, reopening. |
| **B8** | Projects: create one or more from a Qualified Lead, or directly; the Project page; responsibilities; transfer between locations; archive and restore; the shared Projects page shown in Sales and Production. |
| **B9** | Sales outcome: Open, Won with its Sold Amount, Lost with managed Sales Loss Reasons; correcting a Sold Amount. |
| **B10** | Attachments: storage setup for staging, the attachment model, project photos, removal and the 30-day purge. |
| **B11** | Timeline on Lead, Project and Account pages; the History view for managers. |
| **B12** | Documentation, full check, staging verification, owner review of the whole checkpoint. |

B1 to B9 are the operational target for launch. See "Launch and production".

## Not in Checkpoint B

The call board, call cadence, auto-dialling and alarms; the Inbox; appointments;
estimates and proposals; production stages and scheduling; finance; dashboards and
reports; attribution integrations and UTM capture; import tooling; a tool for
merging records; competitor management; "Referred By"; automatic routing by service
area; follow-up automation; the company-wide Audit Log screen; the homeowner
portal; Color Studio.

## Launch and production

Banana Painting launches about a week after this plan was approved, and Backoffice
is its system of record from the start.

- **The operational target for launch is B1 to B9.** B10 (attachments and photos),
  B11 (timeline and history presentation) and B12 (wrap-up) may follow the first
  production launch if necessary.
- **Authorization, data integrity, tests and auditability are not traded for the
  date.**
- B1 to B9 are built and reviewed against staging.
- **Around B7 or B8, work stops for a production-readiness checkpoint,** so the
  production environment exists before the operational steps are finished.
  Production is not left until B12.
- **Production does not reuse staging,** and **real customer data is never entered
  into staging.**

Before real customer data is entered into production:

- a dedicated production environment and server;
- dedicated database credentials with least privilege;
- production backups;
- a deliberate restore drill;
- production Resend configuration and credentials;
- the real production domain, with HTTPS;
- the required two-factor policy for sensitive roles, as decided;
- a production file-storage strategy, before uploaded files are enabled.

## Decisions settled at the final review (2026-10-05)

| # | Question | Decision |
| --- | --- | --- |
| 1 | Where Leads live in the menu | One shared Leads page, shown in both Marketing and Sales to people whose permissions allow it. There is no separate Marketing Leads and Sales Leads data or behavior. |
| 2 | The Settings page for the managed lists | One Settings page named **Lead & Sales Lists**, holding all five lists. Owner and Leadership manage it. Leadership's permission is for this page only and opens no other Settings page. |
| 3 | Nurture | Approved as a Lead status with a next follow-up date. Never treated as Disqualified or as a Sales Loss. |
| 4 | What a duplicate suggestion may reveal | The minimum needed to recognize a likely duplicate: the Account type and a masked form of the matching phone or email. Never a way to discover customers the person may not see. |
| 5 | How Projects are found | One shared Projects page, shown in Sales and Production as appropriate. Future center screens are views of the same records. |
| 6 | Sold Amount | Recorded when a Project is marked Won, as the temporary source of sold value. Owner and Leadership may correct it; corrections are audited. Accepted proposals take over later. |
| 7 | Disqualification reporting categories | Approved as listed: serviceability, lead quality, invalid, other. Sales Loss Reasons are never mixed into them. |
| 8 | Launch scope | B1 to B9, with a production-readiness checkpoint around B7 or B8. See "Launch and production". |

## Open decisions

None blocks the next step. These are settled in the step that needs them:

- Whether Owner and Finance must use two-factor sign-in, and whether any other
  role should. Needed by the production-readiness checkpoint.
- How uploaded files are backed up and restored. Needed by B10.
- The production domain. Needed by the production-readiness checkpoint.
- **Whether a Property address should become required** once address lookup has
  been used for a while. It is optional today so a Lead can always be saved.
