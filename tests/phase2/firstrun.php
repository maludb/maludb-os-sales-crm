<?php
/**
 * Proof: the first-run home (sso-shell.md "Proof": "the home for a new person (one button)"). Runs FIRST, on the empty book — before the browser
 * proof seeds it: a Rep, a manager and the admin see ONE button, Add your first lead (to /leads/new — slice 2's form, a plain link until it ships);
 * a Viewer, who may not add, is told the book is empty and shown no button; the JSON says first_run and what the person may do.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
ok((int) one('SELECT (SELECT count(*) FROM leads) + (SELECT count(*) FROM accounts) + (SELECT count(*) FROM deals)') === 0, 'the book is empty (no lead, account or deal)');
foreach ([30 => 'a Rep (Dana)', 27 => 'the Sales Manager (Marco)', 1 => 'the admin'] as $m => $who) {
    [$j, ] = sign_on($m);
    $h = page($j, '/')['body'];
    ok(str_contains($h, 'id="home-first-run"') && substr_count($h, 'id="home-first-run-btn"') === 1 && str_contains($h, 'Add your first lead') && str_contains($h, 'href="/leads/new"'), "$who sees one button, Add your first lead, pointing at /leads/new");
    ok(!str_contains($h, 'id="home-today"') && !str_contains($h, 'id="home-closing"') && !str_contains($h, 'id="home-forecast"'), "$who sees nothing else on the home — no Today, no closing tile, no forecast");
    $formBuilt = is_file(dirname(__DIR__, 2) . '/html/leads/form.php');
    ok($formBuilt ? str_contains($h, 'hx-get="/leads/new"') : !str_contains($h, 'hx-get="/leads/new"'),
        "$who: the button swaps by HTMX once slice 2's form is built, and is a plain link before (never an HTMX swap into a 404)");
    $d = json_decode(page($j, '/', $json)['body'], true)['data'] ?? [];
    ok(($d['first_run'] ?? null) === true && ($d['may']['lead'] ?? null) === true && ($d['today'] ?? null) === [], "$who's home JSON: first_run true, may.lead true, nothing today");
}
[$jv, ] = sign_on(29);
$h = page($jv, '/')['body'];
ok(str_contains($h, 'id="home-first-run"') && !str_contains($h, 'id="home-first-run-btn"') && str_contains($h, 'id="home-first-run-viewer"'), 'a Viewer, who may not add, is told the book is empty and shown no button');
$d = json_decode(page($jv, '/', $json)['body'], true)['data'] ?? [];
ok(($d['first_run'] ?? null) === true && ($d['may']['lead'] ?? null) === false, 'the Viewer\'s JSON: first_run true, may.lead false');
finish();
