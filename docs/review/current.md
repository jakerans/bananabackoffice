# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-15 · **Branch:** `checkpoint-slice-16a-storage`

**State:** step 16a, the storage itself, is built and waits for Jacob's
review. Not merged. What was built is in
[../slice-16-document-storage-plan.md](../slice-16-document-storage-plan.md),
"Step 16a as built".

## Nothing to see on screen

16a has no page: nothing uses the storage yet but its tests. The first uses
are 16b (compliance documents) and 16c (photos of the work).

## Decisions needed

1. **Earlier versions deleted by the bucket, not the app.** The app's key
   cannot touch earlier versions, so a stolen key cannot destroy the backup.
   The second 30 days (question 10) becomes a lifecycle rule Jacob sets on
   each Backoffice bucket in Wasabi. *Recommended:* agree.
2. **Merge 16a.** Then, on staging, Jacob adds the keys and the lifecycle
   rule, and the restore drill is run there with his approval, before 16b.

## After the merge, for Jacob (nothing secret here)

- In Forge, staging site, Environment: set `FILES_DRIVER=s3`,
  `FILES_BUCKET=backoffice-staging`, and paste the staging user's two keys
  into `FILES_ACCESS_KEY_ID` and `FILES_SECRET_ACCESS_KEY` from the password
  manager. `FILES_REGION` and `FILES_ENDPOINT` can stay as they default.
  Then deploy once, so the new settings are read.
- In Wasabi, on `backoffice-staging` only: a lifecycle rule to permanently
  delete noncurrent versions after 30 days.
- Then the drill in security-notes.md, "The restore drill".
