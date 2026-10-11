-- Slice 7's synthetic book: 200 deals, 70 leads, 150 done activities, two campaigns and the quotas — DETERMINISTIC (arithmetic on the index only, dates counted back from today), so the proofs can
-- check the screens' numbers against the functions' and a few against hand-made counts. Run once per scratch database, as postgres. Every row's title/subject starts "RPT".
BEGIN;
INSERT INTO quotas (member_id, month, amount)
SELECT m.id, (date_trunc('month', current_date) + make_interval(months => k))::date, m.amt
  FROM (VALUES (26, 60000), (27, 40000), (30, 50000)) m(id, amt), generate_series(-3, 6) k
ON CONFLICT DO NOTHING;

INSERT INTO campaigns (name, kind, status, starts_on, budget, currency, owner_member_id, created_by) VALUES
    ('RPT Spring webinar', 'webinar', 'active', current_date - 60, 6000, 'USD', 27, 27),
    ('RPT Outbound push', 'outbound', 'done', current_date - 120, 2400, 'USD', 27, 27),
    ('RPT Idle idea', 'other', 'planned', NULL, 500, 'USD', 27, 27);

INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('RPT Account', 26, 26);
INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, created_by) SELECT 'Rae', 'Report', 'rae@rpt.example.invalid', id, 26, 26 FROM accounts WHERE name = 'RPT Account';

INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, owner_member_id, source_id, campaign_id, expected_close_date, won_at, lost_at, lost_reason_id, restricted_to_member_id, created_at, created_by)
SELECT 'RPT deal ' || i, (SELECT id FROM accounts WHERE name = 'RPT Account'), (SELECT id FROM contacts WHERE email = 'rae@rpt.example.invalid'),
       CASE WHEN i % 8 = 0 THEN 2 ELSE 1 END,
       CASE WHEN i % 10 < 4 THEN CASE WHEN i % 8 = 0 THEN 8 ELSE 2 END
            WHEN i % 10 < 6 THEN CASE WHEN i % 8 = 0 THEN 7 ELSE 1 END
            WHEN i % 8 = 0 THEN (ARRAY[11, 10, 9])[1 + i % 3]
            ELSE (ARRAY[6, 5, 4, 3])[1 + i % 4] END,
       1000 + ((i * 37) % 90) * 100,
       (ARRAY[26, 27, 30])[1 + i % 3],
       1 + i % 7,
       CASE WHEN i % 6 = 0 THEN (SELECT id FROM campaigns WHERE name = 'RPT Spring webinar') WHEN i % 9 = 0 THEN (SELECT id FROM campaigns WHERE name = 'RPT Outbound push') END,
       current_date + ((i * 11) % 150) - 30,
       CASE WHEN i % 10 < 4 THEN now() - make_interval(days => (i * 7) % 100) END,
       CASE WHEN i % 10 >= 4 AND i % 10 < 6 THEN now() - make_interval(days => (i * 5) % 100) END,
       CASE WHEN i % 10 >= 4 AND i % 10 < 6 THEN 1 + i % 7 END,
       CASE WHEN i % 30 = 0 THEN 26 END,
       CASE WHEN i % 10 < 4 THEN now() - make_interval(days => (i * 7) % 100 + 10 + i % 40)
            WHEN i % 10 < 6 THEN now() - make_interval(days => (i * 5) % 100 + 5 + i % 30)
            ELSE now() - make_interval(days => i % 120) END,
       26
  FROM generate_series(1, 200) i;

-- the stage history: the trigger wrote each deal's entry into the stage it was made in, at now(); an open deal entered there when it was created, a closed one entered the first stage and
-- went on (the won ones through the middle stages, the lost ones through one) to where it ended
UPDATE deal_stage_history h SET at = d.created_at FROM deals d WHERE d.id = h.deal_id AND d.title LIKE 'RPT deal %' AND d.status = 'open';
INSERT INTO deal_stage_history (deal_id, from_stage_id, to_stage_id, member_id, at, source)
SELECT d.id, NULL, CASE WHEN d.pipeline_id = 1 THEN 6 ELSE 11 END, d.owner_member_id, d.created_at, 'web'
  FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status <> 'open';
INSERT INTO deal_stage_history (deal_id, from_stage_id, to_stage_id, member_id, at, source)
SELECT d.id, 6, 5, d.owner_member_id, d.created_at + (d.won_at - d.created_at) * 0.3, 'web' FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status = 'won' AND d.pipeline_id = 1
UNION ALL SELECT d.id, 5, 4, d.owner_member_id, d.created_at + (d.won_at - d.created_at) * 0.6, 'web' FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status = 'won' AND d.pipeline_id = 1
UNION ALL SELECT d.id, 4, 2, d.owner_member_id, d.won_at, 'web' FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status = 'won' AND d.pipeline_id = 1
UNION ALL SELECT d.id, 6, 5, d.owner_member_id, d.created_at + (d.lost_at - d.created_at) * 0.5, 'web' FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status = 'lost' AND d.pipeline_id = 1
UNION ALL SELECT d.id, 5, 1, d.owner_member_id, d.lost_at, 'web' FROM deals d WHERE d.title LIKE 'RPT deal %' AND d.status = 'lost' AND d.pipeline_id = 1;

INSERT INTO leads (first_name, last_name, company, email, source_id, campaign_id, status, unqualified_reason_id, converted_at, owner_member_id, created_by, created_at)
SELECT 'Lee', 'Lead ' || i, 'RPT Co ' || i, 'rpt-lead-' || i || '@rpt.example.invalid', 1 + i % 7, CASE WHEN i % 4 = 0 THEN (SELECT id FROM campaigns WHERE name = 'RPT Spring webinar') END,
       CASE WHEN i % 3 = 0 THEN 'qualified' WHEN i % 5 = 0 THEN 'unqualified' ELSE 'working' END,
       CASE WHEN i % 3 <> 0 AND i % 5 = 0 THEN 8 END,
       CASE WHEN i % 3 = 0 THEN now() - make_interval(days => i % 50) END,
       (ARRAY[26, 27, 30])[1 + i % 3], 26, now() - make_interval(days => i % 100)
  FROM generate_series(1, 70) i;

INSERT INTO activities (kind, subject, deal_id, owner_member_id, created_by, due_at, starts_at, done_at, done_by, outcome, email_direction)
SELECT (ARRAY['call', 'email', 'meeting', 'task'])[1 + i % 4], 'RPT touch ' || i, d.id, d.owner_member_id, d.owner_member_id,
       now() - make_interval(days => i % 40), now() - make_interval(days => i % 40), now() - make_interval(days => i % 40), d.owner_member_id,
       (ARRAY['connected', 'sent', 'meeting_held', 'completed'])[1 + i % 4],
       CASE WHEN i % 4 = 1 THEN 'out' END
  FROM generate_series(1, 150) i JOIN deals d ON d.title = 'RPT deal ' || (1 + (i * 7) % 200);
COMMIT;
