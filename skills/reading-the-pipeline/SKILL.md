---
name: reading-the-pipeline
description: How to read and report the pipeline — value and weighted value, the forecast by month against quota, the funnel from stage history, rotting and no-next-step, win rate and cycle time, what one says to a seller and what to a manager. For the Expert's answers and the Assistant's manager note.
---

# Reading the pipeline

**Value and weighted.** `pipeline_summary` gives each open stage's count, value and weighted value (value × probability, the
stage's or the seller's override) in the base currency; deals in another currency are counted apart, never converted. Say the
weighted number to a manager, the value to a seller.

**The forecast** (`forecast`): rows per seller (or per stage), columns per month of expected close for the next six; open value,
weighted, won so far, the quota. "On track" is weighted + won ≥ quota; say the gap in money.

**The funnel** (`funnel`): per open stage in a period — how many deals entered, how many moved on, how many were lost from it, how
many sit there still, the median days. The weakest stage is the one with the highest lost share or the longest median.

**Needing a hand** (`deals_at_risk`): *no next step* (no open activity), *rotting* (in the stage longer than the stage's days),
*closing untouched* (closing within two weeks with no touch in a week), *overdue close* (the close date has passed). Name them in
that order; each is one line: the deal, the account, the value, the days.

**Win rate and cycle** (`win_rate`): won / (won + lost) in a period by seller, source or pipeline; the average and median days from
creation to won. Compare a seller against the team's, never against another seller by name in the seller's own note.

**To a seller**: today, overdue, no next step, rotting, closing this week — their own records, their numbers. **To a manager**: the
month against quota, the funnel's weak stage, the stalled deals, who has nothing scheduled, the leaderboard. Never a body, never a
note's text; the record's name and a link.
