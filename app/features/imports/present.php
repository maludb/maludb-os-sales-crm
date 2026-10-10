<?php
declare(strict_types=1);

function present_import(array $i): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['import_id' => (int) $i['import_id'], 'kind' => $i['kind'], 'file_name' => $i['file_name'], 'status' => $i['status'], 'dry_run' => (bool) $i['dry_run'], 'row_count' => $int($i['row_count']),
            'created' => (int) $i['rows_created'], 'updated' => (int) $i['rows_updated'], 'skipped' => (int) $i['rows_skipped'], 'refused' => (int) $i['rows_refused'],
            'mapping' => json_decode((string) $i['mapping'], true) ?: (object) [], 'dedupe_policy' => $i['dedupe_policy'], 'owner_member_id' => $int($i['owner_member_id']), 'source_id' => $int($i['source_id']),
            'list_id' => $int($i['list_id']), 'template_id' => $int($i['template_id']), 'rollback_until' => json_ts($i['rollback_until']), 'rolled_back_at' => json_ts($i['rolled_back_at']),
            'created_by' => $int($i['created_by']), 'created_at' => json_ts($i['created_at']), 'finished_at' => json_ts($i['finished_at']), 'url' => '/import/' . (int) $i['import_id']];
}

/** What the import's state means, in words. */
function import_state_words(array $i): string
{
    return match (true) {
        $i['status'] === 'queued' && (bool) $i['dry_run'] => 'Waiting for its dry run',
        $i['status'] === 'queued' => 'Waiting to run',
        $i['status'] === 'running' => (bool) $i['dry_run'] ? 'Dry-running' : 'Running',
        $i['status'] === 'done' && (bool) $i['dry_run'] => 'Dry run done — nothing written yet',
        $i['status'] === 'done' => 'Done',
        $i['status'] === 'rolled_back' => 'Rolled back',
        default => 'Failed',
    };
}
