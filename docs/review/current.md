# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-20 · **Branch:** `checkpoint-slice-16c-work-photos`

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
2. **Staging's upload limit is still 2 MB.** In Forge: the BackOffice
   server, the **Runtime** tab, PHP, max upload size: set it to 20. Phone
   photos are often larger than 2 MB, so most would be refused on staging
   until then.
