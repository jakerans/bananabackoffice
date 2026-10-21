# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-21 · **Branch:** `checkpoint-slice-16c-work-photos`

**State:** step 16c, photos of the work, is built and waits for Jacob's
review (decision 160). Not merged. What was built is in
[../slice-16-document-storage-plan.md](../slice-16-document-storage-plan.md),
"Step 16c as built". It is the last step of slice 16.

## Browser review (done locally by the agent)

On a job's page, as its Production Manager:

1. A Work Package with confirmed dates: "Add photos", two chosen at once;
   both appear as thumbnails under Before.
2. A PDF is refused: "Add a JPEG, PNG or HEIC photo."
3. The × on a photo: Cancel has the focus; with a reason, it disappears.
4. A punch item: "Add photos", choose As found or Fixed.
5. The walkthrough has its own "Walkthrough photos".
6. The job's activity names each photo added and removed.
7. As the salesperson: the photos are there, with no buttons.

## Decisions needed

1. **Merge 16c.** Slice 16 is then complete.
2. **Staging's upload limit: 20 MB or 100 MB.** Jacob raised it to 20 MB on
   2026-10-21 (read back: `upload_max_filesize` and `post_max_size` both
   20M). One file is now fine, but "Add photos" sends all the photos chosen
   in one request, so several large phone photos together can pass 20 MB
   and be refused. *Recommended:* set Forge's max upload size to 100; the
   app still refuses any single file over 20 MB.
