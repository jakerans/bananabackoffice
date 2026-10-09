# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-08 · **Branch:** `office/10-slice-4a-location-timezones-and-cadence`
**State:** Job #10 (Build, step 4a) is built and committed on its branch. It is not
pushed and has no pull request yet; the office does both after review.

## Step

Slice 4, step 4a: location timezones and the Contact Center settings. Plan:
[../slice-4-plan.md](../slice-4-plan.md) ("Step 4a"); decisions 65 to 74 in
[../decisions.md](../decisions.md).

## Objective

Put in place the configuration the 4b engine and 4c screen will read: each
Location's timezone, the call cadence, the calling hours and days, and the hold
length. After 4a nothing is scheduled, no queue exists, and no existing screen
behaves differently.

## What changed

- **Location timezone.** New column `locations.timezone` (default
  `America/New_York`; existing Locations get it). Company > Locations has a
  Timezone column and a Timezone button per Location, offering the plan's seven US
  zones. Anyone who passes the existing `LocationPolicy::update` (Owner today) can
  change it; the server refuses anything off the list. Audit event
  `location.timezone_changed` with before and after. The list of zones lives in
  one place, `app/Support/Queue/LocationTimezones.php`, for 4b to reuse.
- **Settings storage.** New table `contact_center_settings`; the migration creates
  version 1 with the exact defaults (day 1 waits 0, 30, 90; days 2 to 5 spread
  3, 2, 2, 2: twelve attempts; 480 to 1260 minutes; all seven days; hold 5).
  `changed_by` has its own index. Rows are never updated or deleted (the model
  refuses both).
- **Permissions.** `settings.contact_center.manage` (company-level, Owner and
  Leadership). `leads.work_queue` (location-level, not company-level; Leadership,
  Location Manager, Call Center Agent; Owner through all cases). Nothing reads
  `leads.work_queue`: a search of `app/`, `routes/`, `resources/`, `config/` and
  `bootstrap/` finds it only in `Permission.php` and `Role.php`, and a test keeps
  it that way.
- **The page.** Company > Settings > Contact Center at `/settings/contact-center`
  (route and navigation ID `settings.contact_center`), four sections each with its
  own Save: Call cadence (with total attempts shown), Calling hours (with Reset to
  default through the shared confirmation dialog), Calling days, Hold length. It
  says changes apply to attempts scheduled from now on and that hours are applied in
  each Location's local time.
- **Saving rules** (`app/Actions/ContactCenter/ChangeContactCenterSettings.php`):
  everything is validated on the server to the plan's limits; a save inserts
  `loaded version + 1`; a second save from the same loaded version is refused as
  stale and writes nothing (and the page reloads the latest settings); the row and
  its audit event are saved in one transaction; a save that changes nothing writes
  neither; each section changes only its own fields. Audit events:
  `contact_center.cadence_changed`, `contact_center.calling_hours_changed` (also
  used for calling days, and carries `reset: true` for a reset) and
  `contact_center.hold_length_changed`, recorded against the settings record under
  the short name `contact_center_settings`.
- **Navigation.** New Settings item after Lead & Sales Lists; the permission is
  added to the `company` and `settings` entry lists only (not to the list the
  unbuilt Settings pages borrow); `/settings` gains it as the fourth landing choice.
- **Shared dialog.** `x-confirmation-modal` gained an optional `confirm-variant`
  (default stays `danger`) so Reset to default is not drawn as a destructive button.
  Every existing use is unchanged.
- **Documents.** roadmap, slice-4-plan, permission-model, navigation-map and README
  describe 4a as built and 4b and 4c as not built. This file replaced.

## Tests and checks, with their real results

- `composer test` (style, static analysis, every test), run on this branch after the
  last code change: **Pint passed; PHPStan passed with 0 errors; Pest passed, 1698
  tests, 1698 passed, 7239 assertions.** (SQLite, the local database.)
- Every test under the plan's "Step 4a" > "Tests" exists and passes. New:
  `tests/Feature/Settings/ContactCenterSettingsTest.php` (migration defaults, access
  by URL and direct Livewire call for every role, single-location Leadership,
  hours/days/cadence/hold refusals, versions, stale refusal, navigation, the
  queue-permission-is-inert search). Extended: `RoleTest`,
  `NavigationLandingControllerTest`, `NavigationTest`, `SettingsNavigationTest`,
  `LocationsTest`, `LocationAuditEventsTest`, `AuditAtomicityTest` (audit event made
  to fail), `ImpersonationTest`, `ApplicationConfirmationDialogsTest` (including the
  native-dialog guard over the new page).
- Migrations were run fresh and rolled back and re-applied locally on SQLite; they
  are reversible like the existing ones. They use only plain columns, a json column
  and a seeded insert, written to be valid on MySQL 8.4. **Not run here: MySQL 8.4.**
- **Office to fill in after the push:** "Checks and tests (SQLite)": ______ ·
  "Migrations and tests (MySQL 8.4)": ______ . I have not claimed either.
- No 4b or 4c file exists: no `lead_cadences`, queue events, `QueueEligibility`,
  command, schedule entry, Call Queue page or navigation item. `routes/console.php`
  is unchanged.

## Architectural decisions made while building

None that need a new entry in `decisions.md`. Small implementation choices:

- One action class, `ChangeContactCenterSettings`, has a method per section and also
  checks `settings.contact_center.manage` itself, so no caller can skip the page's
  check.
- JSON values are rebuilt in one fixed key order before comparing, because MySQL
  does not keep JSON key order (the lesson recorded in decision 38).
- Reset to default restores only the opening and closing times, not the days.
- The unique `version` index is the only guard against two simultaneous saves
  (no row lock, as instructed); a unique-index refusal is reported as stale.

## Deviations from the plan

- **Decision 74:** `leads.work_queue` arrives in 4a instead of 4b, as Jacob approved.
  It is held by its four roles and guards nothing.
- One existing test was **updated, not weakened**, because the access it checks was
  meant to grow: `ListsTest` "leadership sees settings under company with only this
  page in it" expected Leadership to see only Lead & Sales Lists in Settings. It now
  expects Lead & Sales Lists and Contact Center, which is the intended 4a result.
  Every other existing test is unchanged. No Slice 3 or Lead test was touched.

## Known limitations

- **Nothing reads the settings yet.** The cadence, hours, days, hold length and each
  Location's timezone are stored and audited but drive nothing until 4b.
- Existing screens (Received time, follow-up dates and the rest) keep company time;
  the application timezone stays UTC.
- Changing a Location's timezone moves nothing already scheduled (nothing is
  scheduled yet).
- While running the migrations locally I used `migrate:fresh` against this
  worktree's own local SQLite file, which emptied that local development database.
  It is a disposable local copy; no shared or staging data was touched.

## Decisions needed from Jacob

- None for 4a.
- Still open from [../security-notes.md](../security-notes.md): whether to raise the
  `vite-plus` pin from 0.3.0 to 0.3.3 as its own approved dependency change (it
  carries the `tinypool` advisories). Needed before production; not changed here.
- Before 4b: the Forge scheduler on staging (see the plan, "The server's scheduler
  on staging"). Not needed for 4a.

## Browser review steps for Jacob (staging)

Staging: <https://bananabackoffice-xg8eqczb.on-forge.com> (after the office has
merged and deployed). Use fictitious data only.

1. **As Owner, change a Location's timezone.** Open Company > Locations. Each
   Location shows a Timezone column (Eastern by default). Press Timezone on one,
   pick Pacific, Save. You should see "Timezone saved" and the column now say
   Pacific. Open it again and save without changing: nothing new is recorded.
2. **Check the Contact Center defaults.** Open Company > Settings > Contact Center.
   You should see: Day 1 with attempt 1 "Right away", then 30 and 90 minutes; days
   2 to 5 with 3, 2, 2, 2 attempts; "Total attempts: 12"; opens 8:00 am, closes
   9:00 pm; all seven days ticked; hold length 5. The page says changes apply to
   attempts scheduled from now on and hours are applied in each Location's local
   time.
3. **Edit each of the four sections** and press that section's own Save: change a
   wait or add a day (the total updates); narrow the hours to 9:00 am to 8:00 pm;
   untick Sunday; set the hold to 10. Each Save shows a confirmation and changes
   only its own section. Reload the page and the values stay.
4. **Try to break the limits.** First, hours outside 8 am to 9 pm: the Opens and
   Closes lists offer only 8:00 am to 9:00 pm, so to send a value outside that range
   open the browser's developer tools (F12), right-click the Opens list in the
   Calling hours section, choose Inspect, double-click an option's `value` (for
   example 8:00 am, `480`) and change it to `420` (7:00 am); select that option and
   press Save under Calling hours. Repeat with the Closes list set to `1320` (10:00
   pm). Each time the server refuses with a message and nothing is saved; reload and
   the old hours are still shown. Then the other limits. The hour lists offer only 8:00 am to 9:00 pm. Try a
   closing time at or before the opening, or less than an hour after it: it is
   refused with a message. Untick every day and Save: refused. Set the hold to 0 or
   31: refused. Make day 1's later wait 2 minutes: refused. Nothing is saved on a
   refusal.
5. **Reset to default.** Press Reset to default under Calling hours. A styled dialog
   (not the browser's pop-up) opens with the cursor on Cancel; Escape and Cancel
   close it and return focus to the Reset button. Confirm it and the hours return
   to 8:00 am to 9:00 pm; the days and other settings stay as they were.
6. **As a Leadership member with an all-locations grant**, open Company > Settings:
   it lists Lead & Sales Lists and Contact Center only, and `/settings` opens Lead &
   Sales Lists. Contact Center opens and saves. `/settings/locations` and
   `/settings/users` are refused.
7. **As a Location Manager or Call Center Agent**, the Contact Center item is not
   shown and typing `/settings/contact-center` in the address bar is refused.
8. **Confirm existing Lead screens look and behave as before** (Leads list, a Lead's
   page, logging a contact attempt): nothing about them has changed, and there is no
   Call Queue anywhere yet.
