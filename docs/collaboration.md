# How We Work: Planning, Review and Approval

The standing agreement for how work on Backoffice is proposed, reviewed, approved
and built. It is short on purpose. What Backoffice is and the rules that never bend
are in [project-brief.md](project-brief.md); the canonical target direction is in
[product-architecture.md](product-architecture.md); what is authorized and built
is in [roadmap.md](roadmap.md).

## Roles

| Who | Role |
| --- | --- |
| **Jacob** | Product owner and final decision maker. Only Jacob's approval authorizes a step, a merge, or any change to staging or production. |
| **Implementation agent** | Works in the connected repository: proposes, builds only what Jacob approved, tests, reports, and stops. Tool or repository access grants no product authority. |
| **Review partner** | Helps Jacob review product, architecture and implementation evidence. It may inspect connected sources when Jacob provides access, but it has no authority to approve or change anything. |

Advice from any review partner or tool counts only when Jacob records it as his
decision, and not before.

## The record

- **The approved documents in `docs/` are the canonical record:** the roadmap,
  decisions, permission model, navigation map, security notes and the current
  checkpoint plan. [README.md](README.md) lists them.
- A decision made in conversation becomes part of the record when it is written
  into those documents. After that, the documents are the record and the
  conversation is not.
- A newer explicit decision from Jacob supersedes an earlier proposal where they
  conflict. It is written into the documents in the same change that acts on it.
- If two documents disagree and no decision settles it, stop and ask. Do not
  guess.

## The workflow

For each meaningful checkpoint or step:

1. **Read first.** Read the relevant product-architecture sections, roadmap,
   decisions, permission and navigation rules, security notes and checkpoint
   material for the paths in scope. Use incremental context; do not reread or
   re-audit unrelated material without a change-driven reason.
2. **Do not ask Jacob to repeat** a decision those documents already hold.
3. **Unresolved decisions stop the work.** If the step needs a product or
   architecture decision that is not recorded, write a concise planning report to
   [review/current.md](review/current.md) and stop before implementation.
4. **Jacob reviews,** with ChatGPT if he chooses, and returns only the decisions.
5. **Build only the approved step.** Nothing from the next one.
6. **Run the complete local check:** `composer test` (style, static analysis,
   every test).
7. **Push the working branch and confirm both GitHub jobs:** SQLite, and MySQL 8.4.
8. **Report:**
   - what changed;
   - tests and checks, with their real results;
   - architectural decisions made while building;
   - deviations from the approved plan;
   - known limitations;
   - decisions still needed;
   - exact browser-review steps, when a visual review is appropriate.
9. **Stop.** The next checkpoint or step starts only when Jacob says so.

## Review handoff

[review/current.md](review/current.md) is the one place a review is handed over,
so a review does not need a long conversational prompt.

- Claude Code updates it whenever a step needs product or architecture review:
  a plan awaiting decisions, or built work awaiting approval.
- It holds only what the review needs: the checkpoint or step, the objective, a
  summary of the proposal or the implementation, the decisions needed with their
  tradeoffs and a recommendation, and browser-review steps where they apply.
- It points at the canonical documents and does not copy them.
- It is written to be read by someone who cannot see the repository.
- When Jacob returns decisions, they are written into the canonical documents,
  and the file is then updated or cleared for the next review. Earlier handoffs
  stay in the Git history; there is no archive folder.

## Limits that speed never overrides

- **No secrets, ever, in a review file or any other tracked file:** no
  credentials, tokens, passwords or keys. **No customer personal information**
  either. Examples use fictitious people.
- Jacob is never asked to paste a secret into any chat. Secrets are entered by
  Jacob directly where they are used.
- **Branch discipline stays.** Work happens on the checkpoint branch, one step at
  a time. Nothing is merged into `main` without Jacob's approval.
- **Staging and production change only with Jacob's explicit approval** at the
  checkpoint that calls for it.
- **Testing, authorization and audit requirements are never weakened** to move
  faster. A check that was not run is never reported as passed.
