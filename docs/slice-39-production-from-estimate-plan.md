# Slice 39: production from the accepted estimate

From the first walkthrough on production
([walkthrough-2026-11-23.md](walkthrough-2026-11-23.md)): "Work Package
needs to pull automatically from the estimate"; "automatically take the
days"; "not pulling anything from the estimate into production"; "Production
should not see any new projects until the deposit"; "the production flow is
strange". Approved in the batch of decision 234, with the answers of decision
235. Built 2026-11-23 (decision 237).

## As built

- **Production does not see a sold job until its deposit is collected or
  waived.** A job sold with a deposit to collect is held: it is in none of
  the Workspace's lists, a Production Manager cannot take it, and Production
  is not told about it. The moment its deposit invoice is paid in full, or a
  manager waives the deposit (Payments, **Change the deposit**, 0), it goes
  through, and Production is told then if the handoff is done (otherwise at
  the handoff, as before). Once through it stays through, even if a payment
  is reversed later. Jobs sold before this slice are not held. A job with no
  deposit to collect (waived on the estimate, or a Location that asks for
  none) is never held.
- **Managers see what is held:** the Workspace has **Waiting for the
  deposit** for whoever assigns Production Managers there, with the deposit
  asked for and how long ago it sold. The job's own page says it is waiting.
  The salesperson can complete the handoff meanwhile.
- **The first work package starts from the signed estimate:** its name is
  the option the customer signed; its work is what the salesperson handed
  over, or the option's rooms and surfaces when there is no handoff yet; its
  working days are the estimate's hours (painting and prep) for a crew of two
  at eight hours a day, rounded up; its crew is two. The form says where the
  figures come from; the Production Manager changes any of it before saving.
- **A window's last day fills in from its first:** offering dates, or
  rescheduling, the last day is the first day plus the package's working
  days, Monday to Friday (decision 235). It can still be changed. Public
  holidays are not known.
- **The Brand Ambassador package is carried over.** An estimate sold with a
  discount named for the programme ("Brand Ambassador Gold", or "Brand
  Ambassador" at a tier's percent: 10, 15 or 25) records that tier's package
  on the job, with what the customer promised, whether it was signed on the
  tablet or marked sold on the card. A discount whose tier cannot be told is
  left for the package to be recorded on the job by hand, as before.
- **One line through Production** at the top of the job's page, **Where
  this job is**: Deposit, Handoff from Sales, Production Manager, Welcome
  call, Rough timeframe, Colors approved (any time), Work package, Work given
  out, Dates confirmed, Work started, Work finished, Walkthrough and signoff,
  Final invoice, Paid in full. Each is ticked by what is recorded; **Next**
  jumps to where the next one is done. It changes no rule about what may be
  done when.

Also in this change (decision 234): the reschedule notice inside the app
names the customer and the address, for a moved appointment and for a
rescheduled job; the email still leaves both out.

## Choices made while building

- **Two people, eight hours a day** is the start for the days. Nothing in
  Backoffice says how big a crew is before the work is given out; say if
  another start is better, or if it should be a setting.
- **The hold is by the deposit invoice paid in full,** as "Deposit paid" is
  everywhere else. A manager who wants a job through sooner waives or lowers
  the deposit.
- **The package is told from the discount's name.** For this to work the
  price book's Brand Ambassador discounts are named for their tier, or are at
  10, 15 or 25 percent.

## Code

`App\Actions\Projects\ReleaseToProduction`,
`App\Support\Projects\WorkFromEstimate`, `App\Support\Projects\ProductionPath`,
`App\Models\WorkPackageWindow::lastDayFor`,
`App\Support\Projects\AmbassadorPackage::fromEstimate`; tests in
`tests/Feature/Projects/ProductionFromEstimateTest.php`.
