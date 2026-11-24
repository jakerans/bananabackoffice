# Slice 41: the handoff

From the first walkthrough on production
([walkthrough-2026-11-23.md](walkthrough-2026-11-23.md)), two notes on the
project handoff: it should save as it is typed, and it should carry a
Company Cam link. Approved in the batch of decision 234; "completed"
(decision 235) is read as: the link is needed to complete a handoff. Built
2026-11-23 (decision 239).

## As built

- **The answers are kept as they are typed.** While a handoff is not
  complete, every answer is saved a moment after it is typed, with no button.
  The page says when it was last saved. Leaving and coming back, on any
  device, opens it as it was left. The draft is nobody's record yet: nothing
  is checked, nobody is told, it is not in the job's history, and Production
  still cannot take the job. **Complete the handoff** checks every answer and
  keeps them for good, as before; the draft is then gone.
- **A handoff already complete is corrected as before,** with **Save**:
  what the answers were is kept and the Production Manager is told, so those
  changes are not saved by themselves.
- **Question 10 is the job's Company Cam link:** the link Company Cam shares
  for the job's project or walkthrough video. It is needed to complete a
  handoff, and must be a whole link starting with https://. The job's page
  shows it under **Handoff from Sales** as **Open this job in Company Cam**,
  opening in a new tab.
- **A handoff completed before the link was asked for** can still be
  corrected without one; the link can be added to it at any time.

## To confirm with Jacob

- **That the link is required.** His answer was one word, "completed"; if
  it should be optional, that is one line to change.
- **Any https link is taken,** not only one at companycam.com, in case
  Company Cam shares links from another address.

## Code

`App\Actions\Projects\SaveHandoffDraft`,
`App\Actions\Projects\CompleteProjectHandoff`,
`resources/views/pages/projects/⚡handoff.blade.php`; tests in
`tests/Feature/Projects/ProjectHandoffTest.php`.
