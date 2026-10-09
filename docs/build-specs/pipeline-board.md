# Build spec: the pipeline board (design §6.2; D6, D7) — read before slice 3

The board is the home screen of selling: a column per open stage, a card per deal, a drag that is a `POST`. This spec fixes how it is
drawn, polled, filtered and moved so slice 3 builds it once and the kernel's actions server, the command bar and a phone all reach the
same thing. The database is the referee (`crm_deal_move`, the required-fields rule, `weighted_value`, `next_activity_at`, rotting — db/008,
db/010); PHP renders and logs; JavaScript enhances.

## 1. The shape (Projects' board, carried over)
- **Page** `/pipeline/{pipeline}/board` (`html/pipeline/board.php`): the pipeline chooser (a dropdown of `find_pipelines`, the default
  first), the view switch (Board · List · Forecast, the same filters carried in the query string), the saved views (`saved_views` for
  `record_kind = deal`), the filter bar (owner, team, close window, tag, source, campaign, value range, "no next step", "rotting", `q`), the
  **columns region** `#board-columns`.
- **The columns partial** `html/pipeline/columns.php` (`/pipeline/{pipeline}/columns?…`): the same query as the page; polled every 30 s
  (`hx-trigger="every 30s, dealChanged from:body"`), swapped whole. One `<section class="board-col" data-stage-id>` per open stage in
  `position` order; after them **Won** and **Lost** as collapsed columns (`board-col-closed`: the head shows the month's count and value;
  the body is a drop target only; a drop opens the won or lost form as a full page with the deal prefilled — never a modal).
- **The column head**: the stage's name and colour bar, `count`, `value`, `weighted` (from `crm_pipeline_summary`), the WIP warning
  (`badge text-bg-warning` when `count > wip_limit`), the rotting count. The foot repeats count and value on long columns.
- **The card** `app/views/pipeline/partials/card.php` (`#deal-card-{id}`, `data-deal-id`, `data-position`): the title (a link to
  `/deals/{id}`), the account's name, the value with currency (short form: 45.7k), the owner's avatar (initials), **the next step** —
  an icon by kind and the date in the four colours (`next-scheduled` green, `next-today` amber, `next-overdue` red, `next-none` grey
  "No next step"), the rotting mark (a small hourglass, `title="N days in stage"`), up to three tag chips, a flag when
  `crm_stage_missing_fields` for the NEXT stage is non-empty (`title` names them). The card's menu (three dots): Move to… (a `<select>` of
  the pipeline's open stages — the no-JS move), Won, Lost, Log call, Schedule, Open.
- **At 375 px**: one column wide; a stage chooser (`<select>` above the region) scrolls the region to that column; the region scrolls
  sideways with a visible scrollbar (`overflow-x: auto; scroll-snap-type: x mandatory`); cards keep every element but the tag chips.

## 2. The drag (the enhancement)
`assets/js/board.js` (Projects', adapted): `Sortable.create(col, {group: 'board', draggable: '.board-card', ghostClass, onEnd})` on every
column body when `window.Sortable` exists (the theme's `vendors.min.js` ships SortableJS; nothing else is loaded). `onEnd` posts
`/deals/{deal}/move` with `to_stage` (the new column's `data-stage-id`) and `position` (the card's new index) as an HTMX request
(`htmx.ajax('POST', url, {values, target: '#board-columns', swap: 'outerHTML'})`). The answer re-renders the columns (the server's truth);
a 422 (the stage refused — the fields named in `errors`) shows the flash with the field names and the columns re-render **so the card snaps
back**; a drop on Won or Lost navigates to `/deals/{id}/won` or `/lost`. Without SortableJS, the card menu's Move to… `<select>` posts the
same action. A drag never changes anything but `stage_id` and `position`.

## 3. The move (`deal_move`, `html/deals/move.php`)
`crm_handler_begin()`; `require_visible('deal', $id)`; `require_editable('deal', $id)` (the owner, unowned, a manager); `to_stage` resolved
(an id, or a stage name within the deal's pipeline); `crm_guard(fn() => crm_deal_move($id, $stage, $position))` — the database refuses a
stage of another pipeline, an archived stage, a Lost stage without a reason (the form sets it first), and **a stage whose required fields
the deal lacks**: the RAISE's HINT carries the field list; `crm_guard` turns it into `errors: {field: "required by the stage"}` per field
and the sentence "Proposal needs: primary contact, expected close date". Logged `deal.move` with `{from_stage, to_stage, stage_name,
seconds_in_stage, position}`; `HX-Trigger: dealChanged`; JSON `{status, did: "Moved <title> to <stage>", record_id}`. A move into Won or
Lost by this handler is allowed when the form's fields are already set (the agent's `deal_won` / `deal_lost` call them directly).

## 4. The list and the forecast (the same query, other views)
`find_board_deals(PDO, int $pipeline, array $filters, string $sort): array` is the ONE query behind the board, the list and the counts
(`mcp_deals` + the filters' predicates; the wall is in the view). The list (`/pipeline/{p}/list`) groups by stage or owner and takes its
columns from the saved view (`columns[]`: number, title, account, contact, stage, value, weighted, probability, close, owner, next, entered,
tags). The forecast (`/pipeline/{p}/forecast`) is `crm_forecast(pipeline, months, by, team)` as a grid: rows sellers (or stages), columns
months, each cell "value / weighted" and the won so far; the quota row under each seller (`quotas`); totals; a cell is a link to the list
filtered by that seller and month. Every view keeps the filters in the query string; a saved view stores them (`saved_view_save`).

## 5. Files (exactly these, slice 3)
`html/pipeline/{board,columns,list,forecast}.php`, `html/deals/{index,view,form,save,move,won,lost,reopen,assign}.php`, `html/deals/products/{add,save,remove}.php`,
`html/deals/contacts/{add,save,remove}.php`, `html/views/{index,save,delete}.php`; `app/features/deals/{queries,present,write}.php`,
`app/features/pipeline/{queries,present}.php`, `app/features/views/{queries,write}.php`; `app/views/pipeline/{board,list,forecast}.php`,
`app/views/pipeline/partials/{columns,column,card,filters,view-switch}.php`, `app/views/deals/{view,form,won,lost}.php`,
`app/views/deals/partials/{stage-bar,products,contacts,history-strip,next-step}.php`, `app/views/views/{index,form}.php`;
`html/assets/js/board.js`; `app/pickers.php` gains `deal`, `pipeline`, `stage` (with `pipeline` as a declared param), `product`
(over `inventory.availability_index` and `gl.items_index` through the bridge when connected, else a free-text line).

## 6. Status vocabulary and ids
Stage colours from `stages.colour`; the four next-step colours as above; `badge text-bg-success` won, `text-bg-danger` lost, `text-bg-secondary`
open. Ids: `board`, `board-pipeline`, `board-filters`, `board-columns`, `board-col-{stage_id}`, `board-col-{stage_id}-head`, `deal-card-{id}`,
`deal-card-{id}-menu`, `deal-stage-bar`, `deal-stage-bar-{stage_id}`, `forecast-grid`, `forecast-cell-{row}-{month}`.

## 7. Proof (slice 3's `tests/phase3/slice3/run.sh`)
The board renders every open stage and the two closed columns with the summary's numbers; a card shows each of the four colours (fixtures);
a move by the menu's select lands and re-renders; a move the stage refuses answers 422 naming the fields and the card stays; a drop on Won
goes to the won form and the posted form closes the deal, the account turns customer; a reopen by a Rep is 403, by a manager succeeds;
the list groups by stage with the saved view's columns; the forecast's cells sum the fixtures; the poll re-renders after another seller's
move; 375 and 1280 in the browser; JSON mode on the page and every handler; the registry `--check`.
