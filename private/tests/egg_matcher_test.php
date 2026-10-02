<?php

/**
 * Unit tests for EggMatcher + ServerSpecNormalizer.
 * Run: php private/tests/egg_matcher_test.php (after identifier rewrite).
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
$matcherFile = $base . '/Services/EggMatcher.php';
$normFile = $base . '/Services/ServerSpecNormalizer.php';

expect_true(is_file($matcherFile), 'EggMatcher.php exists');
expect_true(is_file($normFile), 'ServerSpecNormalizer.php exists');

if (!is_file($matcherFile) || !is_file($normFile)) {
    echo "{$pass} passed, {$fail} failed\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/primus_builder_test_' . getmypid();
@mkdir($tmp);
foreach (['EggMatcher.php' => $matcherFile, 'ServerSpecNormalizer.php' => $normFile] as $name => $src) {
    $code = str_replace('{identifier}', 'primus', (string) file_get_contents($src));
    file_put_contents($tmp . '/' . $name, $code);
    require $tmp . '/' . $name;
}

use Pterodactyl\BlueprintFramework\Extensions\primus\Services\EggMatcher;
use Pterodactyl\BlueprintFramework\Extensions\primus\Services\ServerSpecNormalizer;

$eggs = [
    ['id' => 1, 'nest_id' => 1, 'name' => 'Paper (Preview)', 'nest' => 'Minecraft', 'description' => 'High performance Paper Minecraft server'],
    ['id' => 2, 'nest_id' => 1, 'name' => 'Vanilla Minecraft', 'nest' => 'Minecraft', 'description' => 'Official vanilla jar'],
    ['id' => 3, 'nest_id' => 2, 'name' => 'Rust', 'nest' => 'Rust', 'description' => 'Rust dedicated server'],
];

$paper = EggMatcher::match('paper', $eggs);
expect_true(is_array($paper) && ($paper['egg_id'] ?? null) === 1, 'paper matches Paper egg');

$mc = EggMatcher::match('minecraft', $eggs);
expect_true(is_array($mc) && in_array($mc['egg_id'], [1, 2], true), 'minecraft matches a Minecraft egg');

$van = EggMatcher::match('vanilla', $eggs);
expect_true(is_array($van) && ($van['egg_id'] ?? null) === 2, 'vanilla matches Vanilla egg');

$rust = EggMatcher::match('rust dedicated', $eggs);
expect_true(is_array($rust) && ($rust['egg_id'] ?? null) === 3, 'rust dedicated matches Rust egg');

$case = EggMatcher::match('PAPER', $eggs);
expect_true(is_array($case) && ($case['egg_id'] ?? null) === 1, 'match is case-insensitive');

$prompt = EggMatcher::match('small minecraft paper smp', $eggs);
expect_true(is_array($prompt) && ($prompt['egg_id'] ?? null) === 1, 'extra words still match paper');

expect_true(EggMatcher::match('', $eggs) === null, 'empty game returns null');
expect_true(EggMatcher::match('factorio', $eggs) === null, 'unknown game returns null');
expect_true(EggMatcher::match('paper', []) === null, 'empty catalog returns null');

$n = ServerSpecNormalizer::normalize([
    'name' => 'My SMP',
    'memory' => 2048,
    'disk' => 4096,
    'cpu' => 100,
    'game' => 'paper',
]);
expect_eq($n['name'], 'My SMP', 'keeps name');
expect_eq($n['memory'], 2048, 'keeps memory MB');
expect_eq($n['disk'], 4096, 'keeps disk MB');
expect_eq($n['cpu'], 100, 'keeps cpu');
expect_eq($n['swap'], 0, 'default swap 0');
expect_eq($n['io'], 500, 'default io 500');
expect_eq($n['start_on_completion'], false, 'default start_on_completion false');
expect_eq($n['database_limit'], 0, 'default databases 0');
expect_eq($n['backup_limit'], 0, 'default backups 0');
expect_eq($n['allocation_limit'], 1, 'default allocations 1');

$gb = ServerSpecNormalizer::normalize(['memory' => '2GB', 'disk' => '4G', 'name' => 'x']);
expect_eq($gb['memory'], 2048, 'parses 2GB as 2048 MB');
expect_eq($gb['disk'], 4096, 'parses 4G as 4096 MB');

$small = ServerSpecNormalizer::normalize(['memory' => 2, 'disk' => 4, 'name' => 'x']);
expect_eq($small['memory'], 2048, 'small integer memory treated as GB');
expect_eq($small['disk'], 4096, 'small integer disk treated as GB');

$clamp = ServerSpecNormalizer::normalize(['memory' => 10, 'disk' => 1, 'cpu' => -5, 'name' => 'x']);
expect_true($clamp['memory'] >= 128, 'memory clamped to minimum 128');
expect_true($clamp['disk'] >= 256, 'disk clamped to minimum 256');
expect_eq($clamp['cpu'], 0, 'negative cpu clamped to 0');

$hi = ServerSpecNormalizer::normalize(['memory' => 999999, 'disk' => 999999, 'cpu' => 9999, 'name' => 'x']);
expect_eq($hi['memory'], 8192, 'memory clamped to 8192');
expect_eq($hi['disk'], 32768, 'disk clamped to 32768');
expect_eq($hi['cpu'], 400, 'cpu clamped to 400');

$empty = ServerSpecNormalizer::normalize(['game' => 'paper']);
expect_true($empty['name'] !== '', 'generates a name when missing');
expect_true(strlen($empty['name']) <= 191, 'name max 191');

$long = ServerSpecNormalizer::normalize(['name' => str_repeat('A', 300)]);
expect_eq(strlen($long['name']), 191, 'truncates long name to 191');

$bool = ServerSpecNormalizer::normalize(['name' => 'x', 'start_on_completion' => 'true']);
expect_eq($bool['start_on_completion'], true, 'parses start_on_completion true');

echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
