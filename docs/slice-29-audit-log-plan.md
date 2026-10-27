# Slice 29 — Settings > Audit Log: Plan

Status: **chosen while Jacob was away and built straight through 29a and
29b with the recommendation on every question (decision 191), and merged
on 2026-10-26 (decision 192).** Written as what the implementation agent recommended.

## Why this, now

Jacob asked for production readiness to wait until he is back, and for the
next slice to start meanwhile (decision 191). Production needs him; this
does not.

1. **Every important change is already recorded** (checkpoint B, extended
   in every slice since): 192 kinds, from a Lead's routing to a payroll
   period being paid, each with who made it, when, the values before and
   after, the Location and why. Records cannot be changed or removed.
2. **No page shows them,** except a Project's Activity and a
   subcontractor's history. Settings > Audit Log is the last Settings item
   besides the company configuration pages still Coming Soon, and checkpoint
   B left "the company-wide Audit Log screen" for later.
3. **It is needed before production holds real data:** the Owner should be
   able to answer "who changed this, and when?" without asking anyone to
   look in the database.

## What slice 29 is, and is not

Two reviewed steps.

| Step | Delivers |
| --- | --- |
| 29a | **The Audit Log.** Settings > Audit Log, newest first, 50 to a page: when, who (and the Owner, when the change was made while viewing Backoffice as someone), what was done, to which record, with a link to it where Backoffice has a page for it, at which Location, and why. "What changed" opens each change's values before and after. |
| 29b | **Finding a change.** Filters by area, Location, person and dates, and one record's whole history. "Changes" links from a staff member's page (what they did) and from a Location (what was done there). Settings > Integrations says to add a Location first when there are none. |

Not in slice 29:

- **Downloading the log:** it holds customers' details and pay; a download
  can follow if Jacob asks for one.
- **Recording anything new:** what is recorded stays as each slice decided.
- **Changing or removing a record:** never possible.

## Decisions (the recommendation, taken as the answer)

1. **Who sees it:** the Owner only, through a new company-level permission
   "View the audit log", held by the Owner role and no other. It shows pay,
   every Location's customers and every use of impersonation.
2. **What each line shows:** as in 29a. Who is the name recorded at the
   time, so a later rename does not rewrite history. Money is shown as
   money; other values as recorded.
3. **What is filtered:** area (Leads, Projects, Customers, Staff, Locations
   and settings, Subcontractors, Warranty, Pay, Scorecards, Marketing,
   Files and notes), Location, person and a date range; and one record's
   history, from its link.
4. **Read only:** the page changes nothing.

## Step 29a as built

- **Settings > Audit Log** (`/settings/audit-log`, Live), for whoever holds
  the new company-level permission `settings.audit_log.view`: the Owner role
  and no other (question 1). Leadership, Finance and managers are refused;
  an Owner viewing Backoffice as someone else sees it only if that person
  is an Owner too.
- **Each change, newest first, 50 to a page** (question 2): what was done
  in words ("Project · Deposit changed"), the record it was done to, linked
  where Backoffice has a page for it (a staff member, Location, Lead,
  Project, customer, subcontractor, warranty case or scorecard), when, who
  as recorded at the time ("Olive Owner, impersonating Morgan Manager" when
  it was done while viewing as someone; "The command line" when nobody was
  signed in), the Location and why.
- **"What changed"** opens the values recorded before and after: money as
  money, yes or no, and anything else as recorded. A record that no longer
  exists is named by its kind and number.
- **Words for every kind of change** come from its stored name, so a kind
  added by a later slice is named by itself. One about a new kind of record
  needs that record's name and area added, and a test fails until it is.
- **Read only** (question 4): the page has no action; recorded changes
  still cannot be altered or removed.

## Step 29b as built

- **Filters** (question 3): area, Location (active or not), made by (anyone
  who has made a recorded change) and a range of days in Banana's own
  calendar. Each is kept in the page's address, so a filtered view can be
  bookmarked; anything that is not a real choice is ignored. "Show
  everything" clears them.
- **One record's history:** "History" beside each change shows every change
  to that record, under "The history of" it, linked to its page.
- **Links in:** a staff member's page in Users and Access has "Changes they
  made" and "History" (changes to their account and access); each Location
  in Settings > Locations has "Changes" (everything done there). Both show
  only to whoever may see the Audit Log.
- **Settings > Integrations,** with no Location yet, says to add one first,
  with a link to Settings > Locations.
- **Browser review** (local, fictitious data): the log opened from the
  Settings menu with 659 changes on 14 pages; "What changed" opened; the
  Projects area, a Project's history, a Location's and a person's changes,
  and an empty date range each showed what they should; Finance was
  refused. On a phone the page's own content fits; the header's Location
  and account menus are wider than the screen on every page, which is not
  part of this slice and is noted for a separate fix.
