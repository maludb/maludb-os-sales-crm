<?php
declare(strict_types=1);

/**
 * Sales CRM — application bootstrap. Every web endpoint requires this first.
 *   - loads config/.env into the environment (the proofs point CRM_DEV_ENV at a scratch file instead)
 *   - loads the helpers (db, http, activity, auth, directory, mail)
 *   - starts a hardened session, or acts for one request under the kernel's action token
 *   - re-checks the mirror row on every signed-in request (status, capability, the session list)
 *
 * There is no login form and no password here: the kernel signs people in (html/sso.php).
 * Endpoints under html/ reach this via: require_once dirname(__DIR__, N) . '/app/bootstrap.php';
 */

define('APP_ROOT', dirname(__DIR__));                 // /srv/apps/sales_crm
define('APP_START', microtime(true));

(function (): void {
    // The proofs run against a scratch environment: CRM_DEV_ENV names it (tests/setup_dev.sh writes it) and config/.env is
    // then never read — the installed application is untouched. Never honoured in production.
    $devFile = (string) getenv('CRM_DEV_ENV');
    $envFile = $devFile !== '' && getenv('APP_ENV') !== 'prod' && is_readable($devFile) ? $devFile : APP_ROOT . '/config/.env';
    if (!is_readable($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
})();

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function app_is_prod(): bool
{
    return env('APP_ENV', 'dev') === 'prod';
}

function app_url(string $path = ''): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

function app_name(): string
{
    return (string) env('APP_NAME', 'Sales CRM');
}

/** This application's catalog key — the audience of every token it accepts. */
function app_key(): string
{
    return (string) env('APP_KEY', 'sales_crm');
}

function launcher_url(string $query = ''): string
{
    $base = rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/launcher';
    return $query === '' ? $base : $base . '?' . $query;
}

error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/handler.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/directory.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/api/kernel.php';
// The shell's navigation (app/features/shell/nav.php) is Phase 2's; the kit stands without it.

// A JSON caller (the kernel's actions server, an approval replay) never receives PHP's own
// error output — a 500 it can parse instead of an HTML fragment.
if (PHP_SAPI !== 'cli' && wants_json()) {
    json_mode_begin();
    set_exception_handler(static function (Throwable $e): void {
        error_log('json error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            json_error('server_error', 'Something went wrong.', 500);
        }
        exit;
    });
}

if (PHP_SAPI !== 'cli') {
    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,          // this exact host, over TLS when TLS terminates in front
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('INVSID');
    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // The kernel's actions server (X-Action-Token + X-Action-Relay) or an approval replay
    // (X-Action-Token + X-Approval-Replay): act as the member the token names for ONE request.
    if (!is_logged_in()) {
        $actionToken = (string) ($_SERVER['HTTP_X_ACTION_TOKEN'] ?? '');
        if ($actionToken !== '') {
            $mid = verify_action_token($actionToken, (string) ($_SERVER['HTTP_X_ACTION_RELAY'] ?? ''));
            if ($mid === null && isset($_SERVER['HTTP_X_APPROVAL_REPLAY'])) {
                // A replay carries the requester's 120 s token without the relay; the replay
                // signature (verified by the handler) is what makes it trustworthy.
                $signed = verify_token_signature($actionToken);
                $mid = $signed === null ? null : $signed[0];
            }
            if ($mid !== null) {
                $m = find_member_by_id(db(), $mid);
                // An agent's grant is not in the change feed: the first time a known, active agent
                // arrives with a run token, the kernel's run-facts call vouches for it (valid, an
                // agent, endpoints of THIS application) and the mirror records its admission.
                if ($m !== null && $m['member_kind'] === 'agent' && $m['status'] === 'active' && $m['capability'] === null
                    && action_token_run_id($actionToken) !== null) {
                    $facts = run_facts($actionToken);
                    $GLOBALS['__run_facts'] = $facts;                 // an evaluation run writes nothing (is_eval)
                    if ($facts !== null && !empty($facts['valid']) && !empty($facts['is_agent'])
                        && (int) ($facts['member_id'] ?? 0) === $mid && !empty($facts['endpoints'])) {
                        db()->prepare("UPDATE members SET capability = 'write', synced_at = now() WHERE id = :id")->execute(['id' => $mid]);
                        $m['capability'] = 'write';
                    }
                }
                // An id the mirror does not know is refused, never created (sign-on-and-directory.md §3).
                if ($m !== null && $m['status'] === 'active' && $m['capability'] !== null) {
                    $_SESSION['member_id'] = (int) $m['id'];
                    $GLOBALS['__action_authed'] = true;
                    $GLOBALS['__agent_run_id'] = action_token_run_id($actionToken);
                    $GLOBALS['__action_token'] = $actionToken;
                    header_remove('Set-Cookie');
                    register_shutdown_function(static function (): void {
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            $_SESSION = [];
                            session_destroy();
                        }
                    });
                    db_apply_context(db());
                    require_once __DIR__ . '/partial_update.php';
                    partial_update_prefill(db());
                } else {
                    log_activity(db(), 'member.refused', 'member', $mid,
                        ['actor_member_id' => null, 'source' => 'agent', 'after' => ['reason' => 'unknown or inactive member']]);
                }
            }
        }
    } elseif (empty($GLOBALS['__action_authed'])) {
        // A browser session: still alive only while the mirror says so and the kernel has not
        // signed the member out (member_sessions). Checked on every request — "if the kernel
        // deactivated this person a minute ago, is every door here already shut?"
        $m = current_member();
        $alive = $m !== null && $m['status'] === 'active' && $m['capability'] !== null
            && session_is_listed(db(), session_id());
        if (!$alive) {
            end_session(db(), 'directory');
            if (wants_json()) {
                json_error('unauthorized', 'Sign in required.', 401);
            }
            redirect(launcher_url('app=' . rawurlencode(app_key())));
        }
        session_touch(db(), session_id());
    }
}
