# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-16 · **Branch:** `checkpoint-slice-16b-compliance-documents`

**State:** step 16b, compliance documents, is built and waits for Jacob's
review (decision 159). Not merged. What was built is in
[../slice-16-document-storage-plan.md](../slice-16-document-storage-plan.md),
"Step 16b as built".

## Browser review (done locally by the agent)

Production > Subcontractors > a subcontractor > Compliance:

1. "Renew or correct" on General liability insurance: choose the day it
   expires and a PDF under "The document", then Record. The certificate
   shows under the item.
2. Click its name: it opens in a new tab.
3. "Attach" on the signed agreement: a text file named `.pdf` is refused; a
   photo is attached.
4. The W-9 has no Attach and says why.
5. "Remove" on a document: Cancel has the focus; a reason is required; it
   disappears and the history says so.

## Decisions needed

1. **Merge 16b.**
2. **Raise staging's upload limit** to 20 MB (Forge, the server's PHP
   settings, "Max File Upload Size"). Today it is 2 MB, so a larger scanned
   certificate would be refused on staging.

## Not part of 16b

The app's top bar scrolls sideways on a phone, on every page. Offered as a
separate task.
