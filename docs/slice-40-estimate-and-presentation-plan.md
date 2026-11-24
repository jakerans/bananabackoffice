# Slice 40: the estimate and its presentation

From the first walkthrough on production
([walkthrough-2026-11-23.md](walkthrough-2026-11-23.md)): "Custom
presentation" (a description for each option, with its rates); "Easy
Options" (all rooms, closets, ceilings, baseboards); "put something at the
top of the terms page". Approved in the batch of decision 234, with "yes
prices per area" (decision 235). Built 2026-11-23 (decision 238).

## As built

- **The presentation shows each room's price.** Under each option, **Rooms
  and areas** lists every room with its own price, and **Details** every
  line item with its price (an optional one says it is not in the price).
  When a discount or tax comes between them and the option's price, a short
  sum follows: rooms and details, each discount, tax, price. Still never the
  rates, the paint, the hours or the hourly rate.
- **Each option's own description** was already there: on the estimate,
  **Name and describe**, "What the customer reads about it". It shows under
  the option's name on the presentation.
- **Work added to several rooms at once.** Adding work to a room, **Add it
  to other rooms too** lists the option's other rooms, with **All rooms** and
  **None**: the same work (the rate, coats, paint and prep chosen) is added
  to each room ticked, each measured by itself. A room that already has that
  work is left as it is. This is how every ceiling, or every room's
  baseboards, goes in with one save.
- **Work removed from every room at once.** Removing a piece of work that
  other rooms of the option have too, the dialog offers **Remove it from the
  N other rooms that have it too**.
- **Agreeing at the top of the terms.** The customer agreement has **I have
  read and agree to the terms below** above the terms: it ticks the
  agreement by the signature and goes straight down to it. The tick by the
  signature is still there and still needed, with the name and the
  signature.

## Not in this slice, and why

- **Closets** have no place of their own in Backoffice's estimate: a closet
  is a room like any other, or a rate in the price book. Adding or removing
  them in one go works through the two quick choices above once the price
  book says how a closet is priced. To settle with Jacob: how PaintScout's
  closet choice priced them.
- **The work in each room in the customer's words** ("Bedroom: Paint Walls,
  Paint Doors") on the presentation is still an open question from slice 31;
  only the room and its price are shown.

## Code

`resources/views/pages/leads/⚡estimate.blade.php` (`alsoEveryArea`,
`saveSurface`, `removeEverywhere`), `⚡estimate-present.blade.php`,
`⚡estimate-sign.blade.php`; tests in
`tests/Feature/Estimates/EstimateQuickChoicesTest.php`,
`EstimatePresentationTest.php` and `tests/Feature/Agreements/SignAgreementTest.php`.
