# Slice 16 — Document Storage: Plan

Status: **approved on 2026-10-15 (decision 157) in three steps, each stopping
for Jacob's review, with the recommendation on all ten questions. Step 16a
is built and merged (decision 158); see "Step 16a as built".** It was written as what the implementation agent
recommended, once Jacob had set up the storage (decision 156).

It follows decision 33 (one attachment model, in private storage of its
own), [security-notes.md](security-notes.md) ("Database backups do NOT cover
uploaded files"), and product-architecture.md 7.2 (compliance documents) and
6.8 and 7.10 (photos of the work).

## Why this is next

1. **Every slice since 12 has stopped at "no files".** Compliance is
   somebody's word that a document was received (slice 12); the work, the
   walkthrough and the scorecard have no photos (slice 14).
2. **The storage now exists.** Jacob created two private Wasabi buckets,
   one for staging and one for production, each with a user whose key
   reaches only its own bucket (decision 156).
3. **The security notes require a backup strategy first:** private,
   protected against deletion and overwriting, restorable, and tested,
   before the first real file is stored. This slice is that strategy and the
   first two uses.

## What is already settled

| Settled | Where |
| --- | --- |
| One general attachment record, not a table per kind of file. | Decision 33 |
| Files in private storage used for nothing else, one bucket per environment, each with a key limited to it. | Decision 33; decision 156 |
| Access by permission check, then a short-lived link. No public file addresses. | Decision 33 |
| A removed file is hidden at once, kept 30 days, then may be purged. | Decision 33 |
| Laravel's standard S3-compatible storage driver. | Decision 33 |
| Database backups do not cover files; files need their own backup, tested. | security-notes.md |

## What slice 16 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers |
| --- | --- |
| 16a | **The storage itself.** The connection to Wasabi, the attachment record, uploading, opening by a short-lived link, removing and purging, and a written, tested restore procedure. Nothing uses it yet but a test. |
| 16b | **Compliance documents.** A file attached to each compliance item recorded on a subcontractor: insurance certificates, the signed agreement, the New Mexico waiver. |
| 16c | **Photos of the work.** Before, progress and after photos on a job's Work Packages, and photos on the walkthrough and punch list. |

Not in slice 16:

- **Photos at the sale** (the estimate, the proposal), **color approvals** and
  **customer uploads** through a portal: each its own plan.
- **Scanning files for viruses:** it needs a service that is not approved.
  See question 6.
- **Replication to a second region** for production. See question 8.

## Step 16a — The storage itself

- **The connection:** a storage disk named `files`, used for nothing else,
  pointing at Wasabi (`https://s3.us-east-1.wasabisys.com`). Its settings
  are new environment variables, `FILES_BUCKET`, `FILES_REGION`,
  `FILES_ENDPOINT`, `FILES_ACCESS_KEY_ID` and `FILES_SECRET_ACCESS_KEY`,
  blank in `.env.example`. On a developer's machine and in the tests, the
  disk is a private folder on disk, so no key is ever needed there.
- **The key goes in by Jacob, not by the agent:** after 16a is merged,
  Jacob pastes the staging user's two keys into Forge's environment for
  staging. The agent never sees them.
- **The record:** `attachments`, one row per file: what it belongs to, its
  kind, the file's stored name (random, never the original name), the name
  it was uploaded with, its type and size, who uploaded it and when, and if
  removed, who, when and why.
- **Uploading:** a file of an allowed type and size (question 5) is checked,
  stored under a random name, and recorded, in one step; a file stored with
  no record is removed again.
- **Opening:** the page checks the person may see what the file belongs to,
  then gives a link that works for five minutes (question 7).
- **Removing:** hidden at once, with a reason; the file is deleted from the
  bucket 30 days later by the nightly timer. Bucket versioning keeps a copy
  of anything overwritten or deleted, as the backup.
- **Restoring:** a written procedure in security-notes.md for bringing back
  a file from its earlier version, tried on staging before 16a is merged.
- **Audit:** every upload and removal is an audit event. Opening a file is
  not logged (decision 34).

## Step 16b — Compliance documents

- **On each compliance item** recorded on a subcontractor, "Attach the
  document": the certificate, the agreement or the waiver, as PDF or a
  photo. It is shown beside the item, for those who may see the
  subcontractor.
- **Renewing** attaches the new document to the new record; the earlier
  one stays with the earlier record.
- **The W-9** carries a tax ID number. See question 4.

## Step 16c — Photos of the work

- **On each Work Package:** before, progress and after photos, added by the
  Production Manager or a manager, seen by everyone who may see the job.
- **On the walkthrough and each punch item:** photos of what was found and
  of it fixed.
- **The scorecard's "progress photos" item** shows whether any were added.

## Who may do what

| What | Who |
| --- | --- |
| Attach or remove a compliance document | `subcontractors.manage` at one of the subcontractor's Locations. |
| Open a compliance document | `subcontractors.view` there. |
| Add or remove photos of the work | The Project's Production Manager, or anyone with `projects.manage` there. |
| See photos of the work | Everyone who may see the Project. |

## Tests

Every refusal (type, size, permission); a file and its record always
together; a link only for those allowed; a removed file hidden at once and
purged after 30 days; nothing ever stored under its original name; and the
migrations on MySQL 8.4. The tests use a local disk, never Wasabi.

## Decisions needed

**Answered by Jacob on 2026-10-15 (decision 157): "looks good", confirmed as
approving the plan.** The questions are kept below as they were asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The package.** Laravel needs one package to talk to S3-style storage,
   `league/flysystem-aws-s3-v3`, maintained with Laravel's own file system.
   *Recommended:* approve it. Adding a package needs Jacob's approval.
2. **The steps and their order.** *Recommended:* 16a, then 16b, then 16c,
   each reviewed.
3. **Who adds the key to Forge.** *Recommended:* Jacob, after 16a is merged,
   from the password manager; the agent gives the exact names to paste.
4. **The W-9.** It carries a tax ID number, which decision 148 chose not to
   keep. *Recommended:* do not store the W-9 file; keep recording it as
   received, and keep the file where it is today. *Alternative:* store it,
   seen only by managers.
5. **What can be uploaded.** *Recommended:* PDF, JPEG, PNG and HEIC (iPhone
   photos), up to 20 MB each.
6. **Virus scanning.** *Recommended:* none for now, as it needs a new
   service; files are only ever downloaded, never shown inside a page except
   as images, and only staff upload. *Alternative:* add a scanning service,
   which needs its own approval.
7. **How long a link works.** *Recommended:* five minutes.
8. **Replication for production.** *Recommended:* decide before production
   stores real files; not needed for staging. It doubles the storage cost.
9. **Other keys in the account.** *Recommended:* before production stores
   real files, Jacob checks that no other key in the Wasabi account can
   reach Backoffice's buckets.
10. **Deleting for good.** *Recommended:* a removed file is purged after 30
    days, and its earlier versions after a further 30, so nothing lingers
    for ever.

To start, Jacob needs to approve the plan, or step 16a alone, and answer
questions 1, 3, 5, 6, 7 and 10. Question 4 is needed before 16b; 8 and 9
before production stores real files.

## Known limits of this plan

- **Nothing is scanned for viruses** (question 6).
- **Wasabi bills a minimum of 90 days** for anything stored, even if deleted
  sooner.
- **The restore procedure is tried on staging only** until production
  exists.

## Step 16a as built (2026-10-15)

Built on `checkpoint-slice-16a-storage`. Nothing in the app uses it yet but
its tests; 16b and 16c are the first uses.

- **The package** `league/flysystem-aws-s3-v3` (question 1), version 3.
- **The disk** `files`, set by `FILES_DRIVER`: `s3` points at Wasabi with
  the `FILES_*` settings, private, and stops on any storage error; anything
  else (developers' machines, the tests) is a private folder,
  `storage/app/files`, which is never committed.
- **The record** `attachments`: what it belongs to, its kind, the stored
  path (`<what it belongs to>/<its id>/<random>.<type>`), the name it came
  with, type, size, who added it, and if removed who, when, why, and when
  its file was purged. The row can never be deleted.
- **Attaching** (`AttachFile`): the caller names the ability that allows
  changing what the file belongs to. Only PDF, JPEG, PNG and HEIC, judged
  by the file's content as well as its name, up to 20 MB. If the record
  cannot be saved, the stored file is deleted again. Audit event
  `attachment.added`.
- **Opening** (`AttachmentLink`): checks the ability that allows seeing
  what it belongs to, refuses a removed file, and gives a five-minute link
  that downloads under the original name (photos open in the browser).
  Not logged (decision 34).
- **Removing** (`RemoveAttachment`): a reason of up to 1,000 characters,
  hidden at once, once only. Audit event `attachment.removed`.
- **Purging** (`PurgeRemovedAttachments`, the nightly
  `backoffice:files-purge` at 03:15): deletes from storage each file
  removed 30 or more days ago and marks its record purged. One failure does
  not stop the rest; it is tried again the next night. Audit event
  `attachment.purged`.
- **The storage check and restore drill** (`backoffice:files-check`):
  stores, reads, links and deletes a test file, then, with `--restored`,
  confirms it came back whole after a restore in the bucket. It never
  prints a key or a link.
- **The restore procedure** is in security-notes.md, "Restoring a file".
- **Tests:** `tests/Feature/Attachments/AttachmentsTest.php`, 25 tests on a
  fake disk, never Wasabi.

### Deviations from the plan

1. **Earlier versions are deleted by the bucket, not by the application**
   (question 10). The key Jacob created can store, read and delete files,
   but not touch earlier versions. That is safer: a stolen key cannot
   destroy the backup. So the second 30 days is a lifecycle rule Jacob
   sets on each Backoffice bucket in Wasabi ("permanently delete
   noncurrent versions" after 30 days), and the `versions_purged_at`
   column was not built. Agreed by Jacob (decision 158).
2. **The restore was not tried on staging before merge.** The staging key
   goes in only after 16a is merged (question 3). Instead, after the
   merge, Jacob adds the key and the drill is run on staging with his
   approval, before 16b stores any real file.
