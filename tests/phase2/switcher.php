<?php
/**
 * Proof: the application switcher as the shell carries it (K31; design-system "Header: the application switcher"; the integration plugin's
 * sign-on-and-directory.md §8): the header asks the (fake) kernel's GET /api/v1/apps/mine.php as the signed-in person and renders the Helpdesk
 * button and the menu of their applications, Sales CRM marked current; with no answer it shows neither; the partial itself from a fixture.
 * Run through tests/phase2/run.sh (needs the servers it starts).
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);
$feed = ['schema' => 'os.my-applications/1', 'launcher_url' => 'https://app.example.test/', 'os_url' => 'https://os.example.test', 'applications' => [
    ['id' => 57, 'key' => 'helpdesk', 'name' => 'Help Desk', 'icon' => 'feather-life-buoy', 'current' => false, 'launch_path' => '/launch/57', 'scopes' => []],
    ['id' => 75, 'key' => 'sales_crm', 'name' => 'Sales CRM', 'icon' => 'feather-target', 'current' => true, 'launch_path' => '/launch/75', 'scopes' => []],
    ['id' => 56, 'key' => 'txtschedules', 'name' => 'txtSchedules', 'icon' => 'feather-calendar', 'current' => false, 'launch_path' => '/launch/56',
     'scopes' => [['id' => 3, 'name' => 'Downtown', 'role' => 'Manager', 'launch_path' => '/launch/56?scope=3']]],
]];
echo "1. The shell, through the kernel\n";
kernel_state(function ($s) { unset($s['apps']); return $s; });
[$j, ] = sign_on(26);
$b = page($j, '/')['body'];
ok(!str_contains($b, 'header-apps-toggle') && !str_contains($b, 'header-helpdesk-btn'), 'with no answer from the kernel, the header shows neither the button nor the menu');
kernel_state(function ($s) use ($feed) { $s['apps'] = $feed; return $s; });
@unlink(need('FAKE_KERNEL_STATE') . '.apps');
[$j, ] = sign_on(26);                                                   // a fresh session asks afresh
$b = page($j, '/')['body'];
ok(str_contains($b, 'id="header-helpdesk-btn"') && str_contains($b, 'href="https://app.example.invalid/launch/57"'), 'the Helpdesk button links to the launcher\'s /launch/57 (OS_LAUNCHER_URL)');
ok(str_contains($b, 'id="header-apps-toggle"') && str_contains($b, '<span class="dropdown-item active" id="header-apps-item-75"'), 'the applications menu marks Sales CRM current, not linked');
$asked = array_filter(array_map('trim', file(need('FAKE_KERNEL_STATE') . '.apps') ?: []));
ok($asked !== [] && array_unique($asked) === ['26'], 'the kernel was asked as the acting person (26)');
page($j, '/');
page($j, '/search');
ok(count(array_filter(array_map('trim', file(need('FAKE_KERNEL_STATE') . '.apps') ?: []))) === count($asked), 'the answer is cached in the session — three pages, one question');
kernel_state(function ($s) { unset($s['apps']); return $s; });
ok(str_contains(file_get_contents($root . '/app/views/layout.php'), "view('shared/app-switcher.php')") && str_contains(file_get_contents($root . '/app/bootstrap.php'), "require_once __DIR__ . '/switcher.php';"), 'the layout renders shared/app-switcher.php and the bootstrap requires app/switcher.php');
ok(str_contains(file_get_contents($root . '/html/assets/css/app-overrides.css'), 'APPLICATION SWITCHER'), 'app-overrides.css has the APPLICATION SWITCHER section');
echo "2. The partial, from a fixture\n";
$render = static function (array $feed) use ($root): string {
    $f = tempnam(sys_get_temp_dir(), 'sw') . '.php';
    file_put_contents($f, '<?php putenv("OS_LAUNCHER_URL=https://app.example.test/"); putenv("OS_APPLICATION_TOKEN=osapp_fixture"); require ' . var_export($root . '/app/bootstrap.php', true) . '; echo view("shared/app-switcher.php", ["feed" => json_decode(' . var_export(json_encode($feed), true) . ', true)]);');
    $out = (string) shell_exec('php ' . escapeshellarg($f) . ' 2>&1'); unlink($f); return $out;
};
$h = $render($feed);
ok(str_contains($h, 'id="header-apps-item-56-3"') && str_contains($h, 'https://app.example.test/launch/56?scope=3') && str_contains($h, 'Downtown'), 'a scoped application has one row per scope with ?scope=');
ok(str_contains($h, 'id="header-apps-launcher"') && str_contains($h, 'id="header-apps-os"'), 'the foot links to the launcher and, for a super-admin, the operating system');
ok(!preg_match('/hx-(get|post)=/', $h), 'no link is an HTMX swap');
$asHd = $feed; foreach ($asHd['applications'] as $i => $a) { $asHd['applications'][$i]['current'] = $a['key'] === 'helpdesk'; }
ok(!str_contains($render($asHd), 'header-helpdesk-btn'), 'inside the Help Desk the button is not shown');
ok(trim($render(['applications' => []])) === '', 'an empty feed renders nothing');
finish();
