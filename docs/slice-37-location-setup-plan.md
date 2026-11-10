# Slice 37 — Setting Up a Location: Plan

Status: **proposed on 2026-11-10 at Jacob's request; approved with every
recommendation the same day (decision 220). Step 37a is built; 37b
follows on his go-ahead.** Written as what the implementation agent
recommended.

## Why this, now

Jacob asked for "a setup wizard so that whenever we create a new location,
it guides us through these exact steps", the steps an empty production
needs before it can sell (decision 217; slice 30, 30c-5). Today they are
spread across Settings, Finance and two terminal commands, and the
production check lists only whether each exists anywhere, not for which
Location.

Two of them need a terminal on the server: the first Owner
(`backoffice:create-owner` asks questions, which Forge's Commands tab cannot
answer) and the PaintScout imports (the saved file has to be on the server).

## What slice 37 is, and is not

Two steps.

| Step | Delivers |
| --- | --- |
| 37a | **No terminal needed.** `backoffice:create-owner --name="…" --email="…"` runs from Forge's Commands tab without questions and emails the new Owner a link to choose their own password; the asking version stays. Prices and the agreement wording are imported from the saved PaintScout file by uploading it in Settings > Pricing Settings, for whoever may change prices. The file is read and not kept. |
| 37b | **Setting up a Location.** Settings > Locations > a Location > **Set up**: a checklist of its steps in order, each marked done or not, with a button to the page where it is done and, for the short ones, done right there. A new Location opens on it. The production check names, for each Location, the required steps still missing. |

The checklist, for each Location (✱ required before it can be switched on):

1. ✱ Its name and time zone.
2. ✱ Its service area: the ZIP codes it serves.
3. ✱ Its prices: on the default price book, which must have prices, or its
   own.
4. ✱ A tax rate in that price book.
5. ✱ The agreement's wording (once, for the company; shown done when it is).
6. ✱ Its people: at least one manager and one salesperson invited.
7. Its deposit and target margin (50% and 55% unless changed).
8. Pay plans for its salespeople, needed before commission is worked out.
9. Its scorecard owner and targets.
10. Its QuickBooks company connected.
11. Its subcontractors, needed before a job is awarded.

Above the list, once for the company until done: an Owner who can sign in,
the default price book imported, the agreement wording, customer email's
sending domain, and QuickBooks's live keys.

Not in slice 37: importing a different PaintScout file for each Location
(each starts from the default and can be changed); creating staff without
an invitation; anything that changes how the steps themselves work.

## Decisions needed

1. **A checklist, not a locked sequence.** *Recommended:* the steps in
   order with a "Next step" button, but any can be done first, by whoever
   may, and the page stays afterwards to show what is missing. A locked
   wizard would stop at a step someone else must do, such as QuickBooks.
2. **A Location stays switched off until its ✱ steps are done.**
   *Recommended:* yes. Switched off, no Lead is routed to it and nothing is
   booked there. The other steps show as still to do, without blocking.
3. **The imports by upload in Settings.** *Recommended:* yes, for those who
   may change prices (Owner and Leadership), recorded in the Audit Log. The
   commands stay for a server.
4. **The first Owner by an emailed link.** *Recommended:* yes, as in 37a.
   The command is run by Jacob in Forge, and the password is chosen only by
   the Owner, on the page the link opens; nobody else ever sees it.
5. **Order.** *Recommended:* 37a first and on its own, since it unblocks
   first use now; 37b after.

## Step 37a as built (decision 220)

- **The first Owner from Forge's Commands tab:**
  `php artisan backoffice:create-owner --name="Their Name" --email=their@address`
  asks nothing. It creates the Owner, records it in the Audit Log, and
  emails them the usual staff invitation, whose link lets them choose their
  own password for 72 hours. Until it is used, they cannot sign in and the
  production check still says no Owner can. If the email cannot be sent,
  nothing is created. Run again for an Owner who never used their link, it
  sends a fresh one. With no options it asks in a terminal, as before.
- **Import from PaintScout,** a card at the top of Settings > Pricing
  Settings on the default price book, for those who may change prices. The
  saved settings file is uploaded; ticked, it fills the default price book
  (once; replacing stays `backoffice:pricing-import --replace`) and adds the
  agreement's wording (a new version; the same wording again is refused).
  Through the same actions as the commands, so the Audit Log names the
  person. The uploaded file is deleted once read.
- The production check's advice names these two ways.
- Tests: `tests/Feature/Console/Commands/CreateOwnerTest.php`,
  `tests/Feature/Pricing/PaintScoutUploadTest.php`. Browser review on local
  data: `.claude/tools/playwright/reviews/slice-37a.mjs`.

## Known limits of this plan

- A Location already switched on stays on; the checklist only shows what it
  lacks.
- Whether a manager or salesperson accepted their invitation is shown, but
  does not block.
