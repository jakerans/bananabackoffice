# Stakeholder Discovery — Remaining Questions

Updated: 2026-10-13

This is the deduplicated decision list after comparing *Banana Backoffice
Stakeholder Discovery Questions.docx* with the canonical product architecture
and decisions. The Word document is source material; this file is the project
record of what is still unanswered.

Questions that the questionnaire answered are incorporated into
[product-architecture.md](product-architecture.md) and decision 131. Commercial
Sales remains deliberately deferred and its unanswered questionnaire section
does not block residential work.

## Needed for slice 8b — Production handoff

1. **Which handoff questions must Sales answer?** Recommended first version:
   exact included scope; exclusions; promises outside the proposal; requested
   timing; site access; colors; condition/prep risks; customer priorities and
   expectations learned during WNBET discovery; and an optional “anything else
   Production should know?” field.

   Recommended rules: required fields accept an explicit “None”; Production
   can see a waiting Project but cannot take it until the handoff is complete;
   the salesperson sees a persistent prompt and receives one bell/email reminder
   the next morning; keep the questions fixed in code for the first version.

2. **Which property does a proposal concern when one Lead covers work at more
   than one house?** Recommended: every proposal selects one of the customer’s
   properties, defaulting to the Lead’s property. Its Project takes that
   property. A proposal cannot span properties; use a separate proposal and
   Project for each.

## Needed before the affected Sales work

3. **Which salesperson receives the Lead Issued and sale figures after an
   appointment is moved or a Lead is re-issued?** Recommended: Lead Issued stays
   with the salesperson who first received that issued Lead; appointment figures
   follow the salesperson assigned when the appointment ran; Sold Revenue and
   the win follow the salesperson who owned the Lead when the proposal was sold.
   Corrections create auditable metric adjustments rather than silently
   rewriting history.

4. **Are there any legitimate attended no-demo exceptions beyond an intentional
   rehash or an absent decision-maker?** The questionnaire says a customer
   controlling the conversation is possible but still requires coaching.
   Recommended: no additional exclusion. Record the coaching reason, but it
   still counts as a no-demo.

5. **What does “disqualifier if they choose this structure” mean for the
   $45,000 base + 2% of gross-margin-dollars Sales plan?** Recommended: do not
   implement a disqualification until its consequence and authority are
   explicit. Model the three plans as effective-dated, mutually exclusive
   plans; changing plans affects future periods only.

6. **Is there any other residential Sales rule that materially changes the
   architecture?** Recommended: treat this as final Sales-leadership sign-off,
   not a blocker now. Commercial Sales remains a separate deferred discovery
   exercise.

## Needed before Production compensation, scheduling or subcontractor work

7. **What exactly counts as a materially incorrect PM job cost?** Missing POs
   are confirmed as the main failure, and the third occurrence should notify
   management, but the amount and lookback period are unset. Recommended: a
   strike is a missing PO or a post-submission correction greater than the
   larger of $250 or 2% of Project revenue; exclude normal timing and Finance
   reclassification. Notify GM/Ops on the third strike in a rolling 90-day
   period. Keep every correction visible even below the threshold.

8. **How is the 80% Production utilization target calculated?** Recommended:
   scheduled productive crew-days divided by available crew-days for the next
   six weeks, by Location. Exclude weekends, holidays, approved time off and
   unavailable crews; show the raw percentage and planned 20% buffer.

9. **What exact deduction applies when Banana supplies a subcontractor’s
   insurance/workers’ compensation coverage?** The answer describes both about
   5% of revenue and about 10% of subcontractor payout. Recommended: define one
   effective-dated percentage of subcontractor payout (initially 10%), with
   Location/state overrides; show the dollar deduction on the work order and
   payable. Do not store two competing formulas.

10. **Are there Location/state-specific subcontractor licenses or documents
    beyond W-9, general liability, workers’ compensation with waiver of
    subrogation, the agreement, and the annual GRT waiver certificate?**
    Recommended: make those the base checklist and allow effective-dated
    requirements per Location without hard-coding state law.

11. **Does PFAD paint pickup have a separate fee?** The answered rule says
    shopping/travel consumes the purchased day and paint is billed at retail,
    but gives no add-on price. Recommended: no separate fee in version one.

12. **Who may reduce a PFAD deposit from 75% to 50%, and can anyone go below
    50%?** Recommended: a Location Manager or Leadership may approve 50%; nobody
    may go below 50% without Owner approval, a reason and an audit event.

13. **What is the complete W-2 painter Performance Pay formula?** Needed: base
    or floor, production budget, budgeted hours, team splitting, overtime and
    non-production time, quality/callback effects, and payroll finalization.
    Recommended: do not infer it. Store the eventual plan as effective-dated and
    preserve every pay period’s inputs and calculation.

14. **What budget and Performance Pay rate applies to warranty work?** It is
    now confirmed eligible. Recommended: use a separately approved warranty
    budget and the worker’s normal effective-dated P4P plan; do not invent a
    special reduced rate.

## Needed before Finance and accounting work

15. **Confirm the system-of-record boundary.** Should Backoffice own operational
    workflow and approvals while QuickBooks owns the formal ledger, posted
    invoices/payments, bank reconciliation, tax and financial statements?
    Recommended: yes. Sync identifiers and statuses both ways; never maintain a
    second independent ledger in Backoffice.

16. **Which QuickBooks Online subscription and integration features are
    required?** Recommended: inventory the current subscription and validate
    invoice, payment, class/location, attachment and API needs in an integration
    spike. Choose the lowest plan satisfying verified needs rather than encoding
    a product tier in the architecture.

17. **Which records may originate only in QuickBooks?** Recommended: chart of
    accounts, journal entries, bank/credit-card reconciliation, tax filings and
    formal period close originate in QuickBooks. Backoffice may originate
    approved operational invoices, bills, credits and compensation entries,
    then receives the posted identity/status.

18. **How should payment methods sync?** Recommended: Backoffice records the
    operational expectation and immutable provider/QuickBooks references;
    QuickBooks is authoritative for posting, settlement and reconciliation.
    Cash/check entries require receipt evidence and Finance approval; retries
    must never duplicate a payment.

19. **How is the noncompliant-subcontractor insurance deduction represented in
    accounting?** Recommended: keep gross subcontractor payout, a separate
    documented insurance deduction, and Banana’s insurance expense/allocation as
    distinct entries; do not net the original work-order value.

20. **What is the materiality threshold for a late job-cost correction?**
    Recommended: record every correction; require Finance review and a visible
    variance flag above the larger of $250 or 2% of Project revenue. This is
    separate from whether the cost posts in the current month.

21. **Which month owns a completed Project, and may Production performance and
    accounting posting periods differ?** Answers say the Project completes when
    the balance is collected and the job closes, and also say not to maintain
    separate concepts. Recommended: one operational completion month, when work
    is accepted, final payment is collected and job costing is complete. Keep
    actual accounting posting dates as evidence, not a second performance month.
    Later warranty costs belong to their current month.

22. **Which known costs are accrued during the three-business-day close
    window?** Recommended: signed subcontractor payouts, received-but-unposted
    materials, earned W-2 labor, rentals, permits, known warranty work and
    approved direct Project costs. Finance owns approval and reversal.

23. **How should refunds, credits, cancellation fees and write-offs be
    recorded?** Recommended: preserve separate transaction types and approvals.
    Refunds reverse collected cash; credits reduce A/R; cancellation fees are
    revenue; unrecoverable direct costs remain current-month COGS; bad-debt
    write-offs are below gross margin but stay linked to the Project.

24. **Should Backoffice own AP capture/coding/approval and Project assignment,
    then send approved items to QuickBooks? Which AP stays only in QuickBooks?**
    Recommended: yes for operational AP. QuickBooks alone owns vendor accounting,
    payment execution, bank reconciliation, tax reporting and formal journal
    entries. Gusto remains the subcontractor direct-deposit executor unless the
    process changes.

25. **How should variable compensation reach payroll/accounting?** Recommended:
    Backoffice calculates an auditable, effective-dated ledger for Sales
    commissions, PM Production Bonuses, P4P, PFAD and clawbacks. An authorized
    person finalizes it and sends an export or integration entry to payroll;
    payroll may verify but does not have to recalculate.

26. **How should QuickBooks entities/classes/locations be structured?**
    Recommended starting point: one company file if legal entities permit; one
    shared chart of accounts; QuickBooks Location for operating Location and
    Class for department/service line, with Corporate for shared costs. An
    accountant must confirm this against the actual legal entities.

27. **What is the exact monthly close workflow and owner of each step?**
    Recommended sequence: operations cutoff; PM job-cost submission; AP and
    payroll accruals; cash/A/R reconciliation; unassigned-cost cleanup; Finance
    review; GM exception approval; compensation finalization; P&L review by the
    seventh. Record owner, due date and evidence for each step.

28. **What is preliminary versus final during close?** Recommended: preliminary
    until bank/payment reconciliation, known accruals, Project job costs and
    exception approvals are complete. Final means Finance locked the period;
    later discoveries become current-period adjustments linked to Projects.

29. **Which controls keep Backoffice and QuickBooks aligned?** Recommended:
    immutable external IDs, idempotent sync, a daily exception queue, control
    totals by transaction type and Location, monthly A/R/AP/cash/compensation
    reconciliation, and no silent overwrite of posted accounting records.

30. **Which additional Finance reports are required?** Recommended first
    additions: sync exceptions, unassigned transactions, compensation changes
    and clawbacks, deposit liability, tax liability, warranty cost, and close
    checklist status. This is Finance sign-off, not a Project-handoff blocker.

31. **Should Gross Receipts Tax be excluded from revenue and gross-margin
    calculations?** Recommended: yes. Treat collected GRT as a tax liability,
    not revenue or COGS, and preserve the QuickBooks-calculated jurisdiction and
    rate.

## Evidence still useful, but not a product decision

- Collect current compensation sheets, scorecards, SOPs, agreements, close
  checklists and reports before planning their modules.
- Obtain a sample manual Sherwin-Williams export before designing an import.
- Commercial Sales discovery is intentionally deferred; its blank questionnaire
  section is not counted as an unanswered residential decision.

