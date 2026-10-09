<?php
/**
 * Router for `php -S 127.0.0.1:8801 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-sales_crm.conf; the routing form of
 * docs/phase1-consistency.md decision 11), so the proofs run without Apache: /sso, /sso/logout, /api/v1/health, the ICS feed and the records API;
 * the board's family /pipeline/{pipeline}/board|list|forecast|columns (a pipeline by id or key) → pipeline/{page}.php?pipeline=; an email
 * /email/{in|out}/{id} → email/view.php; the unsubscribe door /u/{token}; the attachment gate /attachments/{id}; a stage form under its pipeline;
 * the mailbox webhook; the picker /pick/; a page of a record (/deals/12/won) and the canonical URLs (/x/new, /x/{id}/edit, /x/{id}, /x → x.php or
 * x/index.php). A rewrite to a file its slice has not built yet answers 404, as Apache does. Anything that is a real file is served as it is.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
$target = null;
if (str_starts_with($path, '/api/')) {
    if (preg_match('#^/api/v1/health/?$#', $path)) { $target = '/api/v1/health.php'; }
    elseif ($path === '/api/v1/calendar.ics') { $target = '/api/v1/calendar_ics.php'; }
    elseif (preg_match('#^/api/v1/(leads|accounts|contacts|deals|activities)/?$#', $path, $m)) { $target = '/api/v1/' . $m[1] . '.php'; }
    elseif (preg_match('#^/api/v1/(leads|accounts|contacts|deals|activities)/([0-9]+)/?$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/api/v1/' . $m[1] . '.php'; }
    else { http_response_code(404); echo 'Not found'; return true; }
}
$rel = rtrim($path, '/') ?: '/';
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/channels/malumail/mail' => '/channels/malumail/mail.php'];
if ($target !== null) {}
elseif (preg_match('#^/pipeline/([A-Za-z0-9_-]+)/(board|list|forecast|columns)$#', $rel, $m)) { $set(['pipeline' => $m[1]]); $target = '/pipeline/' . $m[2] . '.php'; }
elseif (preg_match('#^/email/(in|out)/([0-9]+)$#', $rel, $m)) { $set(['direction' => $m[1], 'id' => $m[2]]); $target = '/email/view.php'; }
elseif (preg_match('#^/u/([A-Za-z0-9_-]{16,128})$#', $rel, $m)) { $set(['token' => $m[1]]); $target = '/u/index.php'; }
elseif (preg_match('#^/attachments/([0-9]+)$#', $rel, $m)) { $set(['id' => $m[1]]); $target = '/attachments/download.php'; }
elseif (preg_match('#^/admin/pipelines/([0-9]+)/stages/new$#', $rel, $m)) { $set(['pipeline' => $m[1]]); $target = '/admin/stages/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/([a-z_-]+)$#', $rel, $m) && is_file($root . '/' . $m[1] . '/' . $m[3] . '.php')) { $target = '/' . $m[1] . '/' . $m[3] . '.php'; $set(['id' => $m[2]]); }
elseif (isset($map[$rel])) { $target = $map[$rel]; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (preg_match('#^/(.+)/new$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/edit$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/(.+)/([0-9]+)$#', $rel, $m)) { $target = '/' . $m[1] . '/view.php'; $set(['id' => $m[2]]); }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }     // a rewrite to a file its slice has not built yet: 404, as Apache answers
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
