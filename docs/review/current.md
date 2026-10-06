# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
It holds only what the current review needs. It never contains secrets or real
customer information.

**Updated:** 2026-10-06 · **Branch:** `checkpoint-b-continuation`

Two things are waiting on Jacob. The first needs decisions; the second needs a look
in a browser.

---

## 1. Production-readiness checkpoint: plan awaiting decisions

**Step:** the production-readiness checkpoint, taken before B8a
([../roadmap.md](../roadmap.md), "Production readiness").
**State:** plan only. Nothing has been created: no server, database, site, DNS
record, credential, code or configuration.

### Objective

Stand up a production environment that is separate from staging and safe to hold
real customer data, before the remaining operational steps are built.

### What can be reused, and what must be separate

| Reuse | Separate for production |
| --- | --- |
| The DigitalOcean account and the Forge organization | A dedicated server (staging shares its server with another database) |
| The GitHub repository and `main` | Its own database, and a database account that reaches only that database |
| The Resend account and the verified sending domain `mail.bananapainting.com` | Its own Resend API key, send-only and limited to that domain |
| The Mapbox account | Its own Mapbox token |
| The existing Spaces subscription | Its own backup bucket, access key and Forge storage destination |
| | Its own application key, session cookie, Owner account and data |

### Findings that shape the plan

- **Required two-factor sign-in is not built.** It is optional for every role
  today. Requiring it is a small code change with tests.
- **Impersonation has no production policy in code.** It works wherever the
  application is deployed.
- **PHP versions differ:** staging runs 8.5; local development and both GitHub jobs
  run 8.4.
- **Database sessions are already the default.** Production only needs the setting
  confirmed.
- **No queue worker or scheduler is needed** today.
- **Forge's restore overwrites the database the backup came from.** The safest
  drill is on the new production database before any real data exists.
- **The Owner cannot be created by typing a password into Forge,** which stores
  command text. The staging method avoids this: a random password nobody sees,
  then "Forgot password".

### Proposed sequence

1. **Code first, reviewed:** required two-factor for the chosen roles; the chosen
   impersonation policy; PHP alignment if needed. Full check, both GitHub jobs,
   merge to `main`, verify on staging.
2. **Server and database:** a new server through Forge with MySQL 8.4; firewall
   limited to web and SSH; a database and one application account that reaches
   only it.
3. **Site, domain, HTTPS:** the site on the production domain, connected to
   `main`, automatic deploys off; DNS record; certificate; HTTPS forced.
4. **Configuration:** non-secret settings (production mode, debug off, database
   sessions, secure cookies, daily logs, sender name without "(Staging)", local
   file storage only). Jacob enters the secrets directly in Forge. The application
   key is generated on the server and never copied from staging.
5. **First deploy and checks:** migrations applied, health page, sign-in over
   HTTPS, sessions in the database, one real address lookup.
6. **Backups and the restore drill:** a production backup bucket with its own key;
   schedules; first backup confirmed; then a deliberate restore, and a written
   recovery procedure from what it showed.
7. **The Owner:** created with a random password nobody sees; Jacob sets his own
   through the reset email, which also proves production email; two-factor turned
   on at once; recovery codes stored offline.
8. **Operations:** monitoring and alerts; the deploy, migration and rollback
   procedures written down; a check that staging and production share no key,
   password, token, bucket or database; documents updated; Jacob signs off. Real
   customer data goes in only after that.

Deploy and rollback, in short: every change passes both GitHub jobs and is checked
on staging first; a backup is taken on demand before any production deploy that
changes the database; a code problem is undone by redeploying the previous
release; a database problem is fixed by a corrective change, with a restore as the
last resort because it loses everything entered since.

### Decisions needed

| # | Decision | Recommendation | Tradeoff |
| --- | --- | --- | --- |
| 1 | The production domain, and where its DNS is managed | For example `backoffice.bananapainting.com` | None; needed to start |
| 2 | Server | A new DigitalOcean server through Forge, NYC3, 2 GB memory or more | A new monthly cost, which needs approval |
| 3 | Forge organization | Reuse the existing one, after reviewing who has access to it | It also holds other systems' servers, so its members can reach this one too. A separate organization is cleaner and slower to set up |
| 4 | PHP version | 8.4 in production, and move staging to 8.4 | 8.4 is what every test runs on. The alternative is 8.5 everywhere with the GitHub jobs changed to match |
| 5 | How production is deployed | Only when someone presses deploy; staging keeps deploying automatically from `main` | Slower than automatic, but nothing reaches production unreviewed |
| 6 | Backup schedule | Hourly keeping 72, plus daily keeping 30, with failure emails | Up to an hour of entries could be lost in a disaster. Anything finer means switching on MySQL's change log |
| 7 | Where the restore drill runs | On the new production database before real data, using only the Owner account and a throwaway Location | Tests the real pipeline with nothing to lose. A staging drill as well is optional |
| 8 | Two-factor policy | Required for Owner; decide whether Leadership and Finance too. Require an authenticator code even when a passkey is used. Consider a second Owner account as a way back in | Stricter sign-in against the risk of being locked out with one Owner and a lost device |
| 9 | Impersonation in production | Keep it Owner-only; require a fresh password confirmation and a typed reason stored in the audit event | The alternative is switching it off in production, which removes a useful support tool |
| 10 | Reply-To address for production email | A monitored inbox of Jacob's choosing | Staging uses an interim address |
| 11 | Monitoring | Daily log files, Forge's server alerts, backup failure emails, and an outside check of the health page | An error-tracking service would be a new package and possibly a cost |
| 12 | Whether Claude Code may act on the production server through Forge | Yes, for non-secret settings, deploys and checks only | Today's standing rule limits it to the staging server |
| 13 | What the first production deploy contains | Decide once B7's status is settled: `main` as it is (B1 to B6), or after B7 is merged | Deploying less first is simpler; deploying B7 avoids an early second deploy |

### What Jacob does by hand

About twelve steps, roughly two hours in total, plus waiting for DNS: approve the
cost and create the server; add the DNS record; create the backup bucket and key
and add them in Forge; create the Resend key and the Mapbox token and enter those
and the database password in Forge; set a password from the reset email, turn on
two-factor and store the recovery codes; review the code changes, observe the
restore drill and give the final sign-off.

---

## 2. B7: built, awaiting the final browser review

**Step:** B7, qualification, disqualification and nurture. Described in
[../checkpoint-b.md](../checkpoint-b.md), "Qualification, disqualification and
nurture, as built in step B7", and decision 52 in
[../decisions.md](../decisions.md).
**State:** built and pushed. The complete local check and both GitHub jobs pass.
The decisions raised while building are confirmed. Not merged into `main`.

**To confirm:** whether B7 is finally approved. An earlier message approved it; the
latest describes it as awaiting final review. The roadmap says "awaiting the
owner's browser review" until Jacob confirms.

### Browser review, at `http://backoffice.test`

Viewing as the local test Sales Consultant (Settings > Users and Access > the
person > Impersonate):

1. Open a lead being worked and press **Qualify**. With something missing, the
   dialog lists it and offers nothing to submit. With everything present, it
   qualifies.
2. On a lead being worked, press **Move to nurture**, set a date and a note. The
   lead shows the follow-up. A date of today shows "Follow-up due".
3. Press **Disqualify**, choose "Unable to Contact" on a lead just started. It is
   refused, with "Try again later instead" offered and no checkbox to skip the
   wait.

As the Owner:

4. Disqualify with "Unable to Contact", ticking the box to allow it early.
5. Disqualify a lead as "Duplicate Lead" and choose its original.
6. On a disqualified lead, press **Correct reason**, then **Reopen**. It returns as
   Working with nobody assigned.
7. On the Leads page, open **Follow-ups**. Put a lead in nurture, move it to
   another location or return it to central, and confirm it is still listed, shown
   as Unassigned.
8. In **All leads**, confirm disqualified leads appear only when the Status filter
   is set to Disqualified.

---

## Recently decided, now in the record

- **B8 (Projects):** plan approved in principle with twelve decisions, split into
  B8a and B8b. Not started; the production-readiness checkpoint comes first. See
  decision 54 in [../decisions.md](../decisions.md).
