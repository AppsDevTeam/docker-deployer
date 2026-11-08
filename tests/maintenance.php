<?php

// php tests/maintenance.php - migrationsMaintenanceReason() nad vzorky dry-run plánů

require __DIR__ . '/../recipe/maintenance.php';

use function Deployer\migrationsMaintenanceReason;

$block = fn (string $version, string ...$sql) => implode("\n", array_merge(
	["[notice] Migrating (dry-run) up to Migrations\\$version", "[info] ++ migrating Migrations\\$version (Popis)"],
	array_map(fn ($s) => "[debug] $s", $sql),
	["[info] Migration Migrations\\$version migrated (took 0.3ms, used 18M memory)", '[notice] finished in 1.7ms', ' [OK] Successfully migrated', 'MIGRATIONS_PENDING: project/test'],
));

$cases = [
	'jen data' => [$block('V1', "UPDATE task SET x = 1 WHERE y = ''", "INSERT INTO a (b) VALUES ('c')", 'DELETE FROM x'), false],
	'DO bez sleep' => [$block('V1', 'DO 0'), false],
	'DO SLEEP = test' => [$block('V1', 'DO SLEEP(60)'), true],
	'CREATE TABLE bez FK' => [$block('V1', 'CREATE TABLE department (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) ENGINE = InnoDB'), false],
	'CREATE TABLE s FK' => [$block('V1', 'CREATE TABLE x (id INT, user_id INT, CONSTRAINT FK_1 FOREIGN KEY (user_id) REFERENCES user (id))'), true],
	'ALTER ADD COLUMN' => [$block('V1', 'ALTER TABLE task ADD due_time TIME DEFAULT NULL'), true],
	'ADD FK' => [$block('V1', 'CREATE TABLE note (id INT)', 'ALTER TABLE note ADD CONSTRAINT FK_X FOREIGN KEY (a) REFERENCES b (id)'), true],
	'CREATE INDEX' => [$block('V1', 'CREATE INDEX IDX_1 ON user_param_set (department_id)'), true],
	'migrace bez SQL' => [$block('V1'), true],
	'pending bez bloku' => ["== migrations: projekt x ==\nMIGRATIONS_PENDING: project/x", true],
	'nic nečeká' => ["[OK] Already at the latest version", false],
	'zalomené REFERENCES' => ["[info] ++ migrating Migrations\\V1 (x)\n[debug] CREATE TABLE x (id INT, a INT, CONSTRAINT F FOREIGN KEY (a) REFERE\nNCES user (id))\n[info] Migration Migrations\\V1 migrated", true],
	'zalomené dlouhé UPDATE' => ["[info] ++ migrating Migrations\\V1 (x)\n[debug] UPDATE task SET notificationData = 'N;' WHERE\nnotificationData = ''\n[info] Migration Migrations\\V1 migrated", false],
	'víc klinik, jedna DDL' => [$block('V1', 'UPDATE a SET b = 1') . "\n" . $block('V2', 'ALTER TABLE a ADD c INT'), true],
];

$failed = 0;
foreach ($cases as $name => [$plan, $expected]) {
	$reason = migrationsMaintenanceReason($plan);
	$ok = ($reason !== null) === $expected;
	$failed += $ok ? 0 : 1;
	printf("%s %-24s %s\n", $ok ? 'OK  ' : 'FAIL', $name, $reason ?? '(bez údržby)');
}
exit($failed ? 1 : 0);
