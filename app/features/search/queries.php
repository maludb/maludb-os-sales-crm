<?php
declare(strict_types=1);

/**
 * The one search box (screen `search`; the records server's `search` tool reads the same function): crm_search() over Spaces' canonical
 * search_index — leads, accounts, contacts and deals by name, number, company, email, domain, tags, description; websearch syntax; a
 * misspelt name by word-trigram; every row judged by the wall in the function (db/014). PHP adds nothing to the rule.
 */
const SEARCH_KINDS = ['lead' => 'Leads', 'account' => 'Accounts', 'contact' => 'Contacts', 'deal' => 'Deals'];
const SEARCH_PAGE = 50;

/** One page of results: ['rows' => [...], 'total' => int]. $kinds empty = every kind. */
function search(PDO $pdo, string $q, array $kinds, int $page): array
{
    $kinds = array_values(array_intersect($kinds, array_keys(SEARCH_KINDS)));
    $st = $pdo->prepare('SELECT entity_kind, entity_id, title, headline, status, account_id, owner_member_id, rank, total
                           FROM crm_search(:q, CAST(:k AS text[]), :lim, :off)');
    $st->execute(['q' => $q, 'k' => $kinds === [] ? null : '{' . implode(',', $kinds) . '}', 'lim' => SEARCH_PAGE, 'off' => (max(1, $page) - 1) * SEARCH_PAGE]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** A result's screen. */
function search_result_url(array $r): string
{
    return ['lead' => '/leads/', 'account' => '/accounts/', 'contact' => '/contacts/', 'deal' => '/deals/'][$r['entity_kind']] . (int) $r['entity_id'];
}

/** ts_headline's <b>…</b> as <mark>, everything else escaped: the words matched, highlighted, and nothing of the record's text as markup. */
function search_headline_html(?string $headline): string
{
    return str_replace(['&lt;b&gt;', '&lt;/b&gt;'], ['<mark>', '</mark>'], e((string) $headline));
}
