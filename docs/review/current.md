# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-08 · **Branch:** `office/2-follow-up-fix-critical-npm-warnings-and`
**State:** Job #2 (Chore, follow-up to job #1) is built and locally verified. Two
of the five critical npm warnings are fixed. Three remain and need Jacob's
decision, because their only fix is outside what decision 64 allows. The two
GitHub jobs are confirmed by the office on the exact commit after the push.

## Objective

Finish job #1's npm security chore on this branch: carry over its already-reviewed
work, prove it again here, and record the real results. Non-breaking updates only,
and nothing else changes. The approval and its limits are decision 64 in
[../decisions.md](../decisions.md). The full audit record is in
[../security-notes.md](../security-notes.md), "npm dependency audit (2026-10-08)".

## What changed

- `package-lock.json`: `shell-quote` 1.9.0 to 1.12.0 and `concurrently` 10.0.5 to
  10.0.6. These are the only two entries that changed (7 lines replaced). The
  file is now identical to job #1's lockfile.
- `docs/security-notes.md`: a new dated section with the before and after audit.
  Every number in it was confirmed again by the runs on this branch.
- This file.

`package.json`, `.npmrc`, the GitHub workflow, Composer files and all application
code, configuration, views, routes, migrations and tests are unchanged. No package
was added or removed, and no `overrides` block was added. The project name
`backoffice` and all 54 `libc` markers in the lockfile are as they were.

The guideline half of the job was already done by the Architect and was only
checked here: line 11 of `.ai/guidelines/collaboration.md` agrees with "Standing
staging authorization" in [../collaboration.md](../collaboration.md) and with
decision 61. All three say the same thing: completed, verified work inside
approved scope may merge into `main` and deploy to staging without asking again,
while production deployment, force-pushes, destroying important data and expanded
feature scope still need Jacob's explicit approval. Decision 64 matches what was
done.

## Tests and checks

All of these were run in this branch's working copy, with npm 10.9.9 on Node 22.

- `npm audit` before: **5 critical**, 0 high, 0 moderate, 0 low. The five were
  `shell-quote`, `concurrently`, `tinypool`, `oxfmt` and `vite-plus`.
- Lockfile comparison: identical to job #1's copy. Compared with this branch's
  original, only the `shell-quote` and `concurrently` entries differ.
- A clean `npm ci` from the updated lockfile succeeded.
- `npm audit` after: **3 critical**, 0 high, 0 moderate, 0 low. Still open:
  `tinypool`, `oxfmt` and `vite-plus`.
- `npm run build`: passed. The only warning is the existing optional Fontaine
  optimization warning. All 22 built files are byte-for-byte identical to a build
  made on this branch before the update.
- `composer test`: passed Pint, PHPStan (0 errors) and **1,527 tests / 6,660
  assertions**, the same counts as `main`.
- Changed files: only the three listed under "What changed".
- GitHub jobs "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)":
  **confirmed by the office on the exact commit after the push.** They cannot run
  before the branch is pushed and a pull request exists, so they have not run yet
  and are not reported here as passed.

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
  security fix, so job #1 kept the original lockfile and carried over only npm's
  two updated entries, including npm's own integrity hashes. This job did the
  same by matching job #1's lockfile exactly.
- `npm audit fix` was run here only as a preview that writes nothing
  (`--dry-run`). `npm audit fix --force` was never run.
- The carried-over entries were then proven here: the clean `npm ci` checks each
  downloaded package against the lockfile's integrity hash, and the audit, build
  and full test suite were all run afterwards.

## Known limitations

- Three critical warnings remain open, as above.
- How exposed a build machine is to the `tinypool` flaw was not investigated.
- The GitHub workflow installs with `npm install`, which may resolve newer
  in-range versions than the lockfile on any given run. That is existing
  behaviour and was not changed.
- No browser review applies: nothing visible changed, and the built assets are
  identical.
