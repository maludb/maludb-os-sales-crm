<?php
declare(strict_types=1);

/**
 * The directory mirror (sign-on-and-directory.md §3–4). Rows are written ONLY from a hand-off
 * token's claims, from the change feed (bin/directory_sync.php), or from the kernel's answer to a
 * write Sales CRM made as the acting person — never from a form.
 */

class DirectoryRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * A directory write as the acting person. Returns ['status', 'body', 'request_id']; throws
 * DirectoryRefused with the kernel's own sentence on a 4xx, and a 503 one when it is unreachable.
 */
function directory_call(string $method, string $path, array $body, int $actingMemberId): array
{
    $answer = kernel_call($method, '/api/v1/directory/' . ltrim($path, '/'), $body, ['X-Acting-Member: ' . $actingMemberId]);
    if ($answer === null) {
        throw new DirectoryRefused(503, 'The directory is not reachable right now; nothing was changed.');
    }
    if ($answer['status'] >= 400) {
        $message = (string) ($answer['body']['error']['message'] ?? 'The directory refused the change.');
        throw new DirectoryRefused($answer['status'], $message);
    }
    return $answer;
}

/** A directory read with the application token (the mirror's refresh). Null when unreachable. */
function directory_read(string $path): ?array
{
    $answer = kernel_call('GET', '/api/v1/directory/' . ltrim($path, '/'));
    return $answer !== null && $answer['status'] === 200 ? $answer['body'] : null;
}

// ---- applying rows to the mirror ------------------------------------------------------------
/** A member row as the feed or the claims carry it: id, display_name, email, member_kind, business_role, is_external, status, job_title, phone, timezone, updated_at, departments[]. */
function mirror_apply_member(PDO $pdo, array $m, ?string $capability = null, bool $capabilityKnown = false): void
{
    $id = (int) ($m['id'] ?? $m['member_id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $status = (string) ($m['status'] ?? 'active');
    $status = $status === 'active' ? 'active' : 'inactive';        // suspended / offboarded → inactive here
    $args = [
        'id' => $id,
        'kind' => in_array($m['member_kind'] ?? 'human', ['human', 'agent'], true) ? $m['member_kind'] : 'human',
        'name' => (string) ($m['display_name'] ?? ('Member #' . $id)),
        'email' => ($m['email'] ?? '') !== '' ? (string) $m['email'] : null,
        'role' => in_array($m['business_role'] ?? 'user', ['super_admin', 'dept_admin', 'user'], true) ? $m['business_role'] : 'user',
        'ext' => !empty($m['is_external']) ? 't' : 'f',
        'status' => $status,
        'title' => ($m['job_title'] ?? '') !== '' ? (string) $m['job_title'] : null,
        'phone' => ($m['phone'] ?? '') !== '' ? (string) $m['phone'] : null,
        'tz' => ($m['timezone'] ?? '') !== '' ? (string) $m['timezone'] : 'UTC',
        'dupd' => $m['updated_at'] ?? null,
        // A hand-off's claims carry no job title, phone or time zone (the feed does): a key the row does not carry keeps the stored
        // value on conflict, so a sign-on never blanks what the directory said a minute ago (found by the Phase 2 sync proof, 2026-10-09).
        'tg' => array_key_exists('job_title', $m) ? 't' : 'f',
        'pg' => array_key_exists('phone', $m) ? 't' : 'f',
        'zg' => array_key_exists('timezone', $m) ? 't' : 'f',
    ];
    $keep = 'job_title = CASE WHEN CAST(:tg AS boolean) THEN EXCLUDED.job_title ELSE members.job_title END, '
          . 'phone = CASE WHEN CAST(:pg AS boolean) THEN EXCLUDED.phone ELSE members.phone END, '
          . 'timezone = CASE WHEN CAST(:zg AS boolean) THEN EXCLUDED.timezone ELSE members.timezone END';
    if ($capabilityKnown) {
        $args['cap'] = $capability;
        $sql = <<<SQL
            INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, job_title, phone, timezone, directory_updated_at, synced_at)
            VALUES (:id, :kind, :name, :email, :role, :ext, :status, :cap, :title, :phone, :tz, :dupd, now())
            ON CONFLICT (id) DO UPDATE SET member_kind = EXCLUDED.member_kind, display_name = EXCLUDED.display_name, email = EXCLUDED.email,
                business_role = EXCLUDED.business_role, is_external = EXCLUDED.is_external, status = EXCLUDED.status, capability = EXCLUDED.capability,
                {$keep}, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
        SQL;
    } else {
        // The feed says nothing about this application's grant: keep what the last hand-off said.
        $sql = <<<SQL
            INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, job_title, phone, timezone, directory_updated_at, synced_at)
            VALUES (:id, :kind, :name, :email, :role, :ext, :status, NULL, :title, :phone, :tz, :dupd, now())
            ON CONFLICT (id) DO UPDATE SET member_kind = EXCLUDED.member_kind, display_name = EXCLUDED.display_name, email = EXCLUDED.email,
                business_role = EXCLUDED.business_role, is_external = EXCLUDED.is_external, status = EXCLUDED.status,
                {$keep}, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
        SQL;
    }
    $pdo->prepare($sql)->execute($args);
    if ($status !== 'active') {
        end_member_sessions($pdo, $id, 'directory');
    }
    if (isset($m['departments']) && is_array($m['departments'])) {
        mirror_apply_member_departments($pdo, $id, $m['departments']);
    }
}

/**
 * The Sales CRM roles the kernel says a member holds (claims.roles, feed access[].roles — kernel db/145).
 * Only roles Sales CRM offers are kept; the rights they give are Sales CRM's own (crm_role_rights, db/005).
 */
function mirror_apply_roles(PDO $pdo, int $memberId, array $roles): void
{
    $known = $pdo->query('SELECT role_key FROM crm_roles')->fetchAll(PDO::FETCH_COLUMN);
    $keep = array_values(array_intersect(array_unique(array_map('strval', $roles)), $known));
    $pdo->prepare('UPDATE members SET roles = CAST(:roles AS text[]), synced_at = now() WHERE id = :id')
        ->execute(['roles' => '{' . implode(',', $keep) . '}', 'id' => $memberId]);
}

/**
 * One access[] row of the change feed (kernel db/141, db/145): the member's whole holding on Sales CRM. It
 * replaces their capability and roles; a member left holding nothing loses Sales CRM and every session here.
 * A member the mirror does not know is ignored — the directory rows introduce people.
 */
function mirror_apply_access(PDO $pdo, array $a): bool
{
    $id = (int) ($a['member_id'] ?? 0);
    if ($id < 1 || find_member_by_id($pdo, $id) === null) {
        return false;
    }
    $capability = in_array($a['capability'] ?? null, ['read', 'write', 'admin'], true) ? $a['capability'] : null;
    $pdo->prepare('UPDATE members SET capability = :cap, synced_at = now() WHERE id = :id')->execute(['cap' => $capability, 'id' => $id]);
    mirror_apply_roles($pdo, $id, $capability === null ? [] : (is_array($a['roles'] ?? null) ? $a['roles'] : []));
    if ($capability === null) {
        end_member_sessions($pdo, $id, 'directory');
    }
    return true;
}

/** The member's live departments, as a hand-off's claims or a member row list them: the whole set. */
function mirror_apply_member_departments(PDO $pdo, int $memberId, array $departments): void
{
    $keep = [];
    foreach ($departments as $d) {
        $did = (int) ($d['id'] ?? 0);
        if ($did < 1) {
            continue;
        }
        $keep[] = $did;
        $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')
            ->execute(['id' => $did, 'name' => (string) ($d['name'] ?? ('Department #' . $did))]);
        $pdo->prepare(<<<'SQL'
            INSERT INTO department_members (member_id, department_id, is_admin, is_primary, left_at, synced_at)
            VALUES (:m, :d, :a, :p, NULL, now())
            ON CONFLICT (member_id, department_id) DO UPDATE SET is_admin = EXCLUDED.is_admin, is_primary = EXCLUDED.is_primary, left_at = NULL, synced_at = now()
        SQL)->execute(['m' => $memberId, 'd' => $did, 'a' => !empty($d['is_admin']) ? 't' : 'f', 'p' => !empty($d['is_primary']) ? 't' : 'f']);
    }
    $in = $keep === [] ? 'NULL' : implode(',', array_map('intval', $keep));
    $pdo->prepare("UPDATE department_members SET left_at = now(), synced_at = now() WHERE member_id = :m AND left_at IS NULL AND department_id NOT IN ({$in})")
        ->execute(['m' => $memberId]);
}

function mirror_apply_department(PDO $pdo, array $d): void
{
    $id = (int) ($d['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $parent = isset($d['parent_id']) && $d['parent_id'] !== null ? (int) $d['parent_id'] : null;
    if ($parent !== null) {
        $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')->execute(['id' => $parent, 'name' => 'Department #' . $parent]);
    }
    $manager = isset($d['manager_member_id']) && $d['manager_member_id'] !== null ? (int) $d['manager_member_id'] : null;
    if ($manager !== null && find_member_by_id($pdo, $manager) === null) {
        $manager = null;                                   // the member row arrives in the same feed; the next pass names them
    }
    $pdo->prepare(<<<'SQL'
        INSERT INTO departments (id, name, description, parent_id, manager_member_id, is_system, system_key, archived_at, directory_updated_at, synced_at)
        VALUES (:id, :name, :desc, :parent, :manager, :sys, :key, :arch, :dupd, now())
        ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, parent_id = EXCLUDED.parent_id,
            manager_member_id = EXCLUDED.manager_member_id, is_system = EXCLUDED.is_system, system_key = EXCLUDED.system_key,
            archived_at = EXCLUDED.archived_at, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
    SQL)->execute([
        'id' => $id, 'name' => (string) ($d['name'] ?? ('Department #' . $id)),
        'desc' => ($d['description'] ?? '') !== '' ? (string) $d['description'] : null,
        'parent' => $parent === $id ? null : $parent, 'manager' => $manager,
        'sys' => !empty($d['is_system']) ? 't' : 'f', 'key' => $d['system_key'] ?? null,
        'arch' => $d['archived_at'] ?? null, 'dupd' => $d['updated_at'] ?? null,
    ]);
}

function mirror_apply_membership(PDO $pdo, array $dm): void
{
    $m = (int) ($dm['member_id'] ?? 0);
    $d = (int) ($dm['department_id'] ?? 0);
    if ($m < 1 || $d < 1 || find_member_by_id($pdo, $m) === null) {
        return;
    }
    $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')->execute(['id' => $d, 'name' => 'Department #' . $d]);
    $pdo->prepare(<<<'SQL'
        INSERT INTO department_members (member_id, department_id, is_admin, is_primary, joined_at, left_at, synced_at)
        VALUES (:m, :d, :a, :p, :j, :l, now())
        ON CONFLICT (member_id, department_id) DO UPDATE SET is_admin = EXCLUDED.is_admin, is_primary = EXCLUDED.is_primary,
            joined_at = COALESCE(EXCLUDED.joined_at, department_members.joined_at), left_at = EXCLUDED.left_at, synced_at = now()
    SQL)->execute(['m' => $m, 'd' => $d, 'a' => !empty($dm['is_admin']) ? 't' : 'f', 'p' => !empty($dm['is_primary']) ? 't' : 'f',
                   'j' => $dm['joined_at'] ?? null, 'l' => $dm['left_at'] ?? null]);
}

/**
 * Apply one feed document (os.directory-changes/1 or the plain lists). Members first (departments
 * name managers, memberships name members), then departments twice (parents and managers may
 * arrive in any order), then memberships. Returns the counts.
 */
function mirror_apply_feed(PDO $pdo, array $doc): array
{
    $counts = ['members' => 0, 'departments' => 0, 'memberships' => 0];
    foreach ($doc['members'] ?? [] as $m) {
        $known = find_member_by_id($pdo, (int) ($m['id'] ?? 0));
        $capability = $known['capability'] ?? null;
        // A brand-new member has no grant until a hand-off says so (NULL); a known one keeps theirs.
        mirror_apply_member($pdo, array_diff_key($m, ['departments' => true]), $capability, $known === null);
        $counts['members']++;
    }
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($doc['departments'] ?? [] as $d) {
            mirror_apply_department($pdo, $d);
        }
    }
    $counts['departments'] = count($doc['departments'] ?? []);
    if (!empty($doc['full'])) {
        // A full document lists every LIVE membership: what it does not list has left.
        $live = [];
        foreach ($doc['memberships'] ?? [] as $dm) {
            $live[] = (int) $dm['member_id'] . ':' . (int) $dm['department_id'];
        }
        $st = $pdo->query('SELECT member_id, department_id FROM department_members WHERE left_at IS NULL');
        foreach ($st->fetchAll() as $row) {
            if (!in_array($row['member_id'] . ':' . $row['department_id'], $live, true)) {
                $pdo->prepare('UPDATE department_members SET left_at = now(), synced_at = now() WHERE member_id = :m AND department_id = :d')
                    ->execute(['m' => $row['member_id'], 'd' => $row['department_id']]);
            }
        }
    }
    foreach ($doc['memberships'] ?? [] as $dm) {
        mirror_apply_membership($pdo, $dm);
        $counts['memberships']++;
    }
    // What each affected member now holds on Sales CRM — capability and roles (kernel db/141, db/145).
    $counts['access'] = 0;
    foreach ($doc['access'] ?? [] as $a) {
        $counts['access'] += mirror_apply_access($pdo, $a) ? 1 : 0;
    }
    return $counts;
}
