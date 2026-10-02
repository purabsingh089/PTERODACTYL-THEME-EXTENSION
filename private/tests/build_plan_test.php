<?php

/**
 * Unit tests for PlanValidator + ConflictDetector + TemplateCatalog
 * (AI Server Builder orchestration logic).
 * Run: php private/tests/build_plan_test.php (after identifier rewrite).
 */

$fail = 0;
$pass = 0;

function expect_true($cond, string $name): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "PASS: {$name}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$name}\n";
}

function expect_eq($got, $want, string $name): void
{
    expect_true($got === $want, $name . ' (got ' . var_export($got, true) . ')');
}

$base = dirname(__DIR__);
$files = [
    'PlanValidator.php' => $base . '/Services/PlanValidator.php',
    'ConflictDetector.php' => $base . '/Services/ConflictDetector.php',
    'TemplateCatalog.php' => $base . '/Services/TemplateCatalog.php',
];

foreach ($files as $name => $path) {
    expect_true(is_file($path), "{$name} exists");
}
if ($fail > 0) {
    echo "{$pass} passed, {$fail} failed\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/primus_buildplan_test_' . getmypid();
@mkdir($tmp);
foreach ($files as $name => $src) {
    $code = str_replace('{identifier}', 'primus', (string) file_get_contents($src));
    file_put_contents($tmp . '/' . $name, $code);
    require $tmp . '/' . $name;
}

use Pterodactyl\BlueprintFramework\Extensions\primus\Services\ConflictDetector;
use Pterodactyl\BlueprintFramework\Extensions\primus\Services\PlanValidator;
use Pterodactyl\BlueprintFramework\Extensions\primus\Services\TemplateCatalog;

/* ── PlanValidator ────────────────────────────────────────────── */

$validPlan = [
    'summary' => 'Medieval survival server',
    'software' => ['name' => 'Paper', 'reason' => 'plugin support'],
    'minecraft_version' => '1.21.x',
    'plugins' => [
        ['name' => 'EssentialsX', 'action' => 'install', 'provider' => 'modrinth', 'project' => 'essx', 'version' => 'v1', 'reason' => 'economy'],
        ['name' => 'LuckPerms', 'action' => 'keep', 'reason' => 'already installed'],
    ],
    'properties' => ['max-players' => 30, 'difficulty' => 'normal', 'pvp' => true],
    'motd' => 'Medieval Lifestyle SMP',
    'commands' => ['say Welcome'],
    'restart' => true,
    'risks' => ['none'],
    'health' => ['check TPS'],
];

$v = PlanValidator::validate($validPlan);
expect_true($v['ok'], 'valid plan passes');
expect_eq(count($v['plan']['plugins'] ?? []), 2, 'valid plan keeps plugins');
expect_eq($v['plan']['properties']['max-players'] ?? 0, 30, 'valid plan keeps allowed property');
expect_eq($v['plan']['motd'] ?? '', 'Medieval Lifestyle SMP', 'valid plan keeps motd');

/* unknown property keys dropped */
$bad = $validPlan;
$bad['properties']['evil-key'] = 'x';
$bad['properties']['view-distance'] = 99; /* out of range [2,32] */
$v = PlanValidator::validate($bad);
expect_true($v['ok'], 'plan with bad properties still structurally ok');
expect_true(!isset($v['plan']['properties']['evil-key']), 'unknown property key dropped');
expect_true(!isset($v['plan']['properties']['view-distance']), 'out-of-range int dropped');

/* motd too long -> dropped */
$bad = $validPlan;
$bad['motd'] = str_repeat('a', 80);
$v = PlanValidator::validate($bad);
expect_true(!isset($v['plan']['motd']) || $v['plan']['motd'] === '', 'over-long motd dropped');

/* motd with control chars -> dropped */
$bad = $validPlan;
$bad['motd'] = "bad\n\x01motd";
$v = PlanValidator::validate($bad);
expect_true(!isset($v['plan']['motd']) || $v['plan']['motd'] === '', 'control-char motd dropped');

/* commands: only whitelisted prefixes survive; stop/rm never allowed */
$bad = $validPlan;
$bad['commands'] = ['say hi', 'stop', 'rm -rf /', 'whitelist add Steve', 'op Admin'];
$v = PlanValidator::validate($bad);
expect_eq($v['plan']['commands'] ?? null, ['say hi', 'whitelist add Steve', 'op Admin'], 'dangerous commands dropped, whitelisted kept');

/* install plugin without project/version -> invalid */
$bad = $validPlan;
$bad['plugins'][0]['project'] = '';
$bad['plugins'][0]['version'] = '';
$v = PlanValidator::validate($bad);
expect_true(!$v['ok'], 'install without project/version rejected');

/* unknown action -> entry dropped */
$bad = $validPlan;
$bad['plugins'][1]['action'] = 'explode';
$v = PlanValidator::validate($bad);
expect_eq(count($v['plan']['plugins']), 1, 'unknown plugin action dropped');

/* plugin name charset guard */
$bad = $validPlan;
$bad['plugins'][0]['name'] = "../evil";
$v = PlanValidator::validate($bad);
expect_true(!$v['ok'], 'path-like plugin name rejected');

/* empty/garbage plans */
expect_true(!PlanValidator::validate(null)['ok'], 'null plan rejected');
expect_true(!PlanValidator::validate([])['ok'], 'empty plan rejected');
expect_true(!PlanValidator::validate(['summary' => 'x'])['ok'], 'plan without plugins/props rejected');

/* integer coercion for properties */
$p = PlanValidator::validate($validPlan)['plan']['properties'];
expect_true($p['pvp'] === 'true' || $p['pvp'] === true, 'boolean property preserved');

/* ── ConflictDetector ─────────────────────────────────────────── */

$installed = ['EssentialsX-2.20.jar', 'luckperms.jar', 'Vault.jar'];
$cat = ConflictDetector::category('EssentialsX');
expect_eq($cat, 'economy', 'EssentialsX categorized as economy');
$cat = ConflictDetector::category('jobs-reborn');
expect_eq($cat, 'jobs', 'jobs plugin categorized');
$cat = ConflictDetector::category('PlaceholderAPI');
expect_eq($cat, null, 'unknown plugin has no category');

/* plan installs a plugin genuinely absent and in a fresh category */
$conflicts = ConflictDetector::detect(
    [['name' => 'Jobs', 'action' => 'install', 'project' => 'jobs', 'version' => 'v1']],
    $installed
);
expect_eq($conflicts, [], 'installing absent plugin in fresh category has no conflict');

/* exact-name duplicate (name is prefix of installed jar stem) flagged */
$conflicts = ConflictDetector::detect(
    [['name' => 'EssentialsX', 'action' => 'install', 'project' => 'essx', 'version' => 'v1']],
    $installed
);
expect_eq(count($conflicts), 1, 'already-installed plugin install flagged');

$conflicts = ConflictDetector::detect(
    [['name' => 'XConomy', 'action' => 'install', 'project' => 'xconomy', 'version' => 'v1']],
    ['EssentialsX-2.20.jar']
);
expect_eq(count($conflicts), 1, 'duplicate economy flagged');
expect_eq($conflicts[0]['category'] ?? '', 'economy', 'conflict category is economy');
expect_eq($conflicts[0]['existing'] ?? '', 'EssentialsX-2.20.jar', 'conflict names existing jar');

/* keep action never conflicts */
$conflicts = ConflictDetector::detect(
    [['name' => 'Vault', 'action' => 'keep', 'project' => '', 'version' => '']],
    $installed
);
expect_eq($conflicts, [], 'keep action never conflicts');

/* ── TemplateCatalog ──────────────────────────────────────────── */

$templates = TemplateCatalog::all();
expect_true(count($templates) >= 10, 'catalog has at least 10 templates');
expect_true(isset($templates['lifestyle-survival']), 'lifestyle-survival template exists');
expect_true(isset($templates['skyblock']), 'skyblock template exists');

$t = TemplateCatalog::find('lifesteal');
expect_true(is_array($t) && ($t['label'] ?? '') !== '', 'find returns template with label');
expect_true(($t['prompt'] ?? '') !== '' && strlen($t['prompt']) >= 20, 'template prompt non-trivial');
expect_true(TemplateCatalog::find('nope') === null, 'find unknown returns null');

$slugs = TemplateCatalog::slugs();
expect_true(in_array('economy-smp', $slugs, true), 'slugs list includes economy-smp');

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
