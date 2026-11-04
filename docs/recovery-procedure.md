# Recovery Procedure

Status: **written on 2026-11-01 (step 30c-3); not yet run.** The drill that
would prove it waits until after first use (decision 217), and is corrected
from what happens there. Until then, treat each step as untested.

This covers production only. Staging is not backed up (decision 216).

## Who does what

- **Jacob decides and presses each button.** Nothing is restored without him
  saying so.
- **The implementation agent reads and advises:** it can read production's
  backups and deployments in Forge, helps work out the time to go back to,
  and checks the result. It does not change production.
- **All times are UTC,** which is what the server and its commands print.
  Eastern is four or five hours behind it.

## First, which problem is it?

| What happened | What to do | What is lost |
| --- | --- | --- |
| One file was deleted | "One file", below | Nothing |
| A wrong change or a bad deploy, and the server is fine | "Back to a chosen minute", below | Everything after that minute |
| The database is damaged and the server is fine | "Back to a chosen minute", to just before the damage | Everything after that minute |
| The whole server is gone | "The whole server", below | Up to an hour, since the change log goes with the server |
| The main file bucket is gone | "The file bucket", below | Files from the last few minutes |

**A restore replaces the whole database,** for everyone, at every Location.
For one wrong record, correct that record in Backoffice instead.

## Back to a chosen minute

**Forge's Restore always writes over the live database.** It goes back to the
hour; the change log then brings it forward to the minute. The change log is
MySQL's own record of every change, kept 30 days on the server's disk.

### 1. Stop and write down the times

Work out the **chosen minute**: the last moment the data was right. Change
nothing else yet.

### 2. Close the site

In the site's Commands tab, run `php artisan down`. People see a maintenance
page, and the scheduler and queue stop, so nothing is sent or written while
the database changes.

### 3. Take a backup of how things are now

Use Forge's manual backup and wait for it to succeed. This is the way back if
the restore makes things worse. It is **not** the backup to restore from.

### 4. Restore

Find the last successful backup taken **before** the chosen minute. Press
Restore on it and wait for Forge to finish.

### 5. Bring it forward to the chosen minute

Skip this if the backup is already the moment wanted.

In a recipe run as root, on `backoffice-production` only, set `START` to five
minutes before that backup and `STOP` to the chosen minute:

```bash
#!/bin/bash
set -o pipefail
START='YYYY-MM-DD HH:MM:SS'
STOP='YYYY-MM-DD HH:MM:SS'
cd /var/lib/mysql
{ echo "SET SESSION rbr_exec_mode=IDEMPOTENT;"; mysqlbinlog --start-datetime="$START" --stop-datetime="$STOP" --database=banana_backoffice binlog.[0-9]*; } | mysql && echo "Put back to $STOP"
```

`START` is early on purpose: the backup does not record exactly where in the
log it was taken. The first line makes the overlap harmless. **`STOP` must be
before step 4,** or the restore itself would be replayed.

### 6. Confirm

- The result ends "Put back to" and the chosen minute.
- Someone who knows the data checks the last thing that should be there, and
  the first thing that should not.

### 7. Before opening

1. Run `php artisan cache:clear`. The restored cache can hold the scheduler's
   old locks, which would stop its jobs for a time.
2. **Look at what is waiting to be sent:**
   `php artisan tinker --execute 'echo DB::table("jobs")->count().PHP_EOL;'`.
   Anything above zero was already waiting at the chosen minute, and may send
   an email or a message a second time. Ask the agent before going on.
3. Run `php artisan up`, then sign in and open a recent Lead.
4. Run `php artisan backoffice:production-check`.

### 8. Afterward

- **Tell staff** what was lost, so it can be entered again.
- **The Audit Log goes back too,** so what was lost is not in it. Write down
  what happened and when.
- **QuickBooks:** if a nightly read fails afterward, the restored connection
  is out of date. Reconnect in Settings > Integrations.
- **Emails, payments recorded elsewhere and signed agreements** that happened
  after the chosen minute happened in the world, even though Backoffice no
  longer shows them.
- **Files** uploaded after the chosen minute stay in the bucket, unattached.
  They do no harm, and can be attached again.

### If it fails partway

- **The Restore fails:** the site stays closed. Press Restore on the same
  backup again; a failed attempt leaves nothing that a full one will not
  replace. If it fails twice, try the backup before it.
- **Step 5 fails:** the database is still at the backup's hour, which is
  whole. Send the agent what the recipe printed. Run step 4 again before
  retrying, so the replay starts from the same place.
- **The result is wrong, or worse than before:** restore the backup from step
  3, and stop to think.
- **Backups will not restore at all:** download the newest one from the
  backup bucket and load it by hand, as in "The whole server".

Keep the site closed until the result is confirmed. A closed site is an
inconvenience; new work on top of a wrong database is much harder to undo.

## One file

Follow "Restoring a file" in [security-notes.md](security-notes.md). A removed
file can be brought back for 30 days.

## The whole server

**Not rehearsed.** The drill proves the backups and the steps above, not a new
server. It takes about half a day.

1. Build a new server from the setup guide (step 30b), with one change: paste
   the saved Environment from the password manager. **It must keep the same
   `APP_KEY`:** with a new one, two-factor sign-in and QuickBooks's connection
   cannot be read.
2. Download the newest backup from `banana-backoffice-production-backups`.
3. Load it as root: `gunzip -c banana_backoffice.sql.gz | mysql`.
4. Deploy, turn on the scheduler and the change log, set up hourly backups,
   and point `app.bananapainting.com` at the new server.
5. Follow "Before opening" and "Afterward" above.

## The file bucket

Wasabi copies each new file to `banana-backoffice-copy`, in another region,
within minutes, and never copies a deletion.

**Not rehearsed:** only one test file has been checked in the copy, and
Backoffice has never run from it. To switch, change the `FILES_` settings in
Forge's Environment to the copy's bucket, region and endpoint, with a key that
can read and write it, then run `php artisan optimize`. The delete-only key
cannot do this: make a new one.

## Checking that this stays true

- `php artisan backoffice:production-check` confirms the change log is on and
  lists what to check by hand.
- Forge emails the address on the backup configuration when a backup fails.
  **Nothing is sent when backups simply stop,** so look at them now and then.
- Run the drill again after any change to the server, MySQL or Forge's backups.
