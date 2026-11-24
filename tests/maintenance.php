<?php

// php tests/maintenance.php - migrationsMaintenanceReason() nad vzorky dry-run plánů

require __DIR__ . '/../recipe/maintenance.php';

use function Deployer\migrationsMaintenanceReason;
use function Deployer\migrationsPhasePending;

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

// Expand fáze (foreignKeysOnly): okno jen pro cizí klíč a testovací migraci
$expandCases = [
	'expand: jen data' => [$block('V1', 'UPDATE a SET b = 1'), false],
	'expand: ADD COLUMN' => [$block('V1', 'ALTER TABLE task ADD due_time TIME DEFAULT NULL'), false],
	'expand: CREATE INDEX' => [$block('V1', 'CREATE INDEX IDX_1 ON user_param_set (department_id)'), false],
	'expand: ADD FK' => [$block('V1', 'ALTER TABLE note ADD CONSTRAINT FK_X FOREIGN KEY (a) REFERENCES b (id)'), true],
	'expand: CREATE TABLE s FK' => [$block('V1', 'CREATE TABLE x (id INT, user_id INT, CONSTRAINT FK_1 FOREIGN KEY (user_id) REFERENCES user (id))'), true],
	'expand: zalomené REFERENCES' => ["[info] ++ migrating Migrations\\V1 (x)\n[debug] ALTER TABLE x ADD CONSTRAINT F FOREIGN KEY (a) REFERE\nNCES user (id)\n[info] Migration Migrations\\V1 migrated", true],
	'expand: FK v datech' => [$block('V1', "UPDATE a SET b = 'REFERENCES x'"), false],
	'expand: migrace bez SQL' => [$block('V1'), false],
	'expand: DO SLEEP = test' => [$block('V1', 'DO SLEEP(60)'), true],
];

$failed = 0;
foreach ([[$cases, false], [$expandCases, true]] as [$set, $foreignKeysOnly]) {
	foreach ($set as $name => [$plan, $expected]) {
		$reason = migrationsMaintenanceReason($plan, $foreignKeysOnly);
		$ok = ($reason !== null) === $expected;
		$failed += $ok ? 0 : 1;
		printf("%s %-28s %s\n", $ok ? 'OK  ' : 'FAIL', $name, $reason ?? '(bez údržby)');
	}
}

// migrationsPhasePending(): dry-run fáze (adt/doctrine-components), bloky SymfonyStyle zalomené na 80 znaků
$pendingCases = [
	'fáze: migrace v plánu' => [$block('V1', 'UPDATE a SET b = 1'), true],
	'fáze: nic nečeká' => [' [OK] Already at the latest version ("Migrations\\V1") - phase contract', false],
	'fáze: zalomené nic nečeká' => [" [OK] Already at the latest\n      version (\"Migrations\\V1\") - phase contract", false],
	'fáze: bez migrací' => [" [WARNING] The version \"latest\" couldn't be reached, there are no registered migrations.", false],
	'fáze: zalomené bez migrací' => [" ! [WARNING] The version \"latest\" couldn't be reached, there are no registered\n !           migrations.", false],
	'fáze: neznámý výstup' => ['Something else', true],
	'fáze: prázdný výstup' => ['', true],
];
foreach ($pendingCases as $name => [$dryRun, $expected]) {
	$ok = migrationsPhasePending($dryRun) === $expected;
	$failed += $ok ? 0 : 1;
	printf("%s %-28s %s\n", $ok ? 'OK  ' : 'FAIL', $name, $expected ? 'čeká' : 'nic');
}
exit($failed ? 1 : 0);
