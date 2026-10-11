<?php
declare(strict_types=1);

/**
 * The test box (screen admin-rules; GET /admin/rules/test): a lead's fields in, the rule that would take it out — WITHOUT assigning anything. crm_assign_lead() advances a round-robin rule as it chooses; this asks
 * crm_assign_lead_dry() (db/024, STABLE: it cannot write), which reads the same conditions and names the member whose turn is next without moving the turn. Nothing is stored; no lead is made.
 */

/** The rule a lead with these fields matches, or null: {rule_id, rule_name, assign_kind, owner_member_id, owner_name, position}. $leadFields: source_id, campaign_id, country, email, utm_source. */
function rule_test(PDO $pdo, array $leadFields): ?array
{
    $lead = array_filter(['source_id' => $leadFields['source_id'] ?? null, 'campaign_id' => $leadFields['campaign_id'] ?? null, 'country' => $leadFields['country'] ?? null, 'email' => $leadFields['email'] ?? null], static fn ($v) => $v !== null && $v !== '');
    if (($leadFields['utm_source'] ?? '') !== '') {
        $lead['utm'] = ['utm_source' => (string) $leadFields['utm_source']];
    }
    $st = $pdo->prepare('SELECT d.rule_id, d.rule_name, d.assign_kind, d.owner_member_id, (SELECT display_name FROM members WHERE id = d.owner_member_id) AS owner_name,
                                (SELECT position FROM assignment_rules WHERE id = d.rule_id) AS position
                           FROM crm_assign_lead_dry(jsonb_populate_record(NULL::leads, CAST(:j AS jsonb))) d');
    $st->execute(['j' => json_encode($lead === [] ? new stdClass() : $lead, JSON_THROW_ON_ERROR)]);
    $r = $st->fetch();
    return $r === false ? null : ['rule_id' => (int) $r['rule_id'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'position' => (int) $r['position']] + $r;
}
