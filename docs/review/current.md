# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-15 · **Branch:** `main`

**State:** step 16a, the storage itself, is merged (decision 158). Nothing
waits for review. Next is Jacob's setup on staging, then the restore drill;
step 16b waits for both and his word.

## For Jacob, on staging (nothing secret here)

1. **Forge, staging site, Environment:** set `FILES_DRIVER=s3` and
   `FILES_BUCKET=backoffice-staging`, and paste the staging user's two keys
   into `FILES_ACCESS_KEY_ID` and `FILES_SECRET_ACCESS_KEY` from the
   password manager. Save, then deploy once so the settings are read.
2. **Wasabi, `backoffice-staging` only:** a lifecycle rule, whole bucket,
   that permanently deletes noncurrent versions after 30 days.
3. **The drill:** in Forge, the site's Commands, run
   `php artisan backoffice:files-check`. It ends by printing a test file's
   name. Restore that file in the Wasabi console (security-notes.md,
   "Restoring a file"), then run
   `php artisan backoffice:files-check --restored=<that name>`.
   Report what each one printed.
