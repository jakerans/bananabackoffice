# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-08 · **Branch:** `office/2-follow-up-fix-critical-npm-warnings-and`
**Pull request:** https://github.com/jakerans/bananabackoffice/pull/2
**State:** Job #2 (Chore, follow-up to job #1) is built, reviewed and pushed. Both
GitHub jobs passed. It has not been merged yet. Two of the five critical npm
warnings are fixed. Three remain and need Jacob's decision, because their only
fix is outside what decision 64 allows.

## Objective

Finish job #1's npm security chore on this branch: carry over its already-reviewed
work, prove it again here, and record the real results. Non-breaking updates only,
and nothing else changes. The approval and its limits are decision 64 in
[../decisions.md](../decisions.md). The full audit record is in
[../security-notes.md](../security-notes.md), "npm dependency audit (2026-10-08)".

## What changed

Five files changed on this branch, in two commits.

By the builder:

- `package-lock.json`: `shell-quote` 1.9.0 to 1.12.0 and `concurrently` 10.0.5 to
  10.0.6. These are the only two entries that changed.
- `docs/security-notes.md`: a new dated section with the before and after audit.
- This file.

By the Architect, before the build:

- `docs/decisions.md`: decision 64, which records Jacob's approval of this
  follow-up and its limits.
- `.ai/guidelines/collaboration.md`: line 11, the merge guideline.

`package.json` is unchanged. No package was added or removed, and no `overrides`
block was added. The project name `backoffice` and all 54 `libc` markers in the
lockfile are as they were. No application code, configuration, view, route,
migration or test is among the changed files.

Line 11 of `.ai/guidelines/collaboration.md` agrees with "Standing staging
authorization" in [../collaboration.md](../collaboration.md) and with decision 61.
All three say the same thing: completed, verified work inside approved scope may
merge into `main` and deploy to staging without asking again, while production
deployment, force-pushes, destroying important data and expanded feature scope
still need Jacob's explicit approval.

## Tests and checks

Confirmed by the office:

- `composer test` (style, static analysis and every test): **passed**.
- GitHub job "Checks and tests (SQLite)": **passed**.
  https://github.com/jakerans/bananabackoffice/actions/runs/37804865298/job/113406374028
- GitHub job "Migrations and tests (MySQL 8.4)": **passed**.
  https://github.com/jakerans/bananabackoffice/actions/runs/37804865298/job/113406374396
- Click-through testing in a browser: **not run**. The office has no test browser
  or test environment for this project yet. Nothing visible was meant to change.

Reviews:

- Reviewer: **approved**. Only the two permitted lockfile entries changed, and the
  audit and handoff are documented. The reviewer could not run commands, so this
  review rests on the changes themselves and the office's records.
- Architect: **approved**. The lockfile changes only the `shell-quote` and
  `concurrently` entries and matches job #1's copy on those entries; the project
  name and all 54 `libc` markers are intact; `package.json` is unchanged. The
  Architect could read files but not run commands.
- No fix rounds were needed (0 of at most 3).

Recorded by the builder in this working copy, with npm 10.9.9 on Node 22. Neither
reviewer could run these again, so these figures are the builder's own, supported
by the changes themselves and by the passing checks above:

- `npm audit` before: 5 critical, 0 high, 0 moderate, 0 low. The five were
  `shell-quote`, `concurrently`, `tinypool`, `oxfmt` and `vite-plus`.
- A clean `npm ci` from the updated lockfile succeeded.
- `npm audit` after: 3 critical, 0 high, 0 moderate, 0 low. Still open:
  `tinypool`, `oxfmt` and `vite-plus`.
- `npm run build` passed. The only warning was the existing optional Fontaine
  optimization warning. All 22 built files were byte-for-byte identical to a
  build made before the update.
- `composer test`: 1,527 tests / 6,660 assertions, the same counts as `main`.

## Merge, staging and production

- **Merge:** not merged yet. The office merges into `main` only if every GitHub
  check passes on the final commit.
- **Staging:** the project deploys to staging by itself after a merge. The office
  does not deploy and has not verified any deployment.
- **Production:** not touched. Production always waits for Jacob.

## Decision needed

**Should `vite-plus` move from 0.3.0 to 0.3.3 to clear the last three critical
warnings?**

All three come from one cause. `tinypool` 2.1.0 has a flaw that can lead to code
execution. It is required at exactly that version by `oxfmt` 0.64.0, which is
required at exactly that version by `vite-plus` 0.3.0, which `package.json` pins
exactly. The only fix is `vite-plus` 0.3.3, and npm will apply it only with
`npm audit fix --force`. Decision 64 rules that out, so it was left alone.

- **Approve the bump (recommended).** npm does not class it as a major change,
  but `vite-plus` is the build tool, it is a pre-1.0 release, and the bump brings
  newer versions of the tools bundled with it. It should be its own small job:
  change the pin to exactly 0.3.3, then prove the build output and the full test
  suite are still good.
- **Leave it for now.** The affected code is the formatter bundled in the build
  tool. It is installed where assets are built (development machines, CI, the
  server's build step), is not part of the application served to staff, and is
  not called by Backoffice's own build scripts. The warning stays open in
  `npm audit` and in the security notes, and should be closed before production.

## Decisions made while building

No product or architecture decision, and no deviation from the work order.

- The two lockfile entries were carried over from job #1 rather than produced by
  running `npm audit fix` again. Job #1 found that `npm audit fix` (never
  `--force`) chooses these two updates but also rewrites unrelated parts of the
  lockfile: it renames the project to the working folder's name and removes the
  `libc` markers from 54 platform-specific optional packages. Neither is a
  security fix, so only npm's two updated entries were kept, including npm's own
  integrity hashes.
- The builder ran `npm audit fix` here only as a preview that writes nothing
  (`--dry-run`). `npm audit fix --force` was never run.

## Known limitations

- Three critical warnings remain open, as above.
- How exposed a build machine is to the `tinypool` flaw was not investigated.
- The npm audit, `npm ci` and build results were not run again by either
  reviewer; see "Tests and checks".
- The GitHub workflow installs with `npm install`, which may resolve newer
  in-range versions than the lockfile on any given run. That is existing
  behaviour and was not changed.
- No browser review applies: nothing visible was meant to change, and no
  click-through testing was run.
- No staging deployment has been verified.
