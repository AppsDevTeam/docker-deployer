<?php

// php tests/smoke_check.php - smokeCheckFailure() over http codes and curl exit codes

require __DIR__ . '/../recipe/smoke_check.php';

use function Deployer\smokeCheckFailure;

$cases = [
	'200' => ['200', '0', null],
	'204' => ['204', '0', null],
	'301' => ['301', '0', null],
	'302' => ['302', '0', null],
	'304' => ['304', '0', null],
	'401' => ['401', '0', null],
	'403' => ['403', '0', null],
	'400' => ['400', '0', 'status'],
	'404' => ['404', '0', 'status'],
	'405' => ['405', '0', 'status'],
	'429' => ['429', '0', 'status'],
	'500' => ['500', '0', 'status'],
	'502' => ['502', '0', 'status'],
	'503' => ['503', '0', 'status'],
	'100' => ['100', '0', 'status'],
	'000 exit 0' => ['000', '0', 'status'],
	'empty code' => ['', '0', 'status'],
	'refused (7)' => ['000', '7', 'curl'],
	'timeout (28)' => ['000', '28', 'curl'],
	'empty reply (52)' => ['000', '52', 'curl'],
	'reset (56)' => ['000', '56', 'curl'],
	'200 but curl failed' => ['200', '18', 'curl'],
	'missing exit' => ['200', '', 'curl'],
];

$failed = 0;
foreach ($cases as $name => [$code, $exit, $expected]) {
	$result = smokeCheckFailure($code, $exit);
	$ok = $result === $expected;
	$failed += $ok ? 0 : 1;
	printf("%s %-22s %s\n", $ok ? 'OK  ' : 'FAIL', $name, $result ?? '(passes)');
}
exit($failed ? 1 : 0);
