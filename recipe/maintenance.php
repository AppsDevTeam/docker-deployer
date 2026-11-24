<?php

// Whether pending migrations need the maintenance window (README, Maintenance during migrations).
// Separate from default.php, so that it can be tested without Deployer (tests/maintenance.php).

namespace Deployer;

/**
 * Returns why the maintenance window is needed, or null when the plan changes data only.
 *
 * Every DDL takes an exclusive metadata lock, ALGORITHM=INSTANT included - on Galera it runs as
 * TOI and aborts the sessions holding the lock (wsrep_handle_mdl_conflict). Data migrations (DML)
 * and CREATE TABLE without a foreign key do not touch the structure of existing tables, they run
 * without the maintenance window. Conservative: a migration with no SQL in the plan (logic in PHP
 * calling the connection directly, the dry-run does not show it) or a plan without any migration
 * block needs it too. `DO SLEEP(...)` is a test migration of the maintenance window - it needs it.
 *
 * $foreignKeysOnly (expand migrations, which run under the live release on purpose): only a statement
 * with a foreign key needs the window - the one that crashed a node - and the test migration. Other
 * DDL and a migration without SQL run without it.
 *
 * Reads the Doctrine Migrations dry-run with -vv: `++ migrating <version>`, `[debug] <sql>` and
 * `Migration <version> migrated`. Long lines may be wrapped, continuation lines are joined.
 */
function migrationsMaintenanceReason(string $plan, bool $foreignKeysOnly = false): ?string
{
	$migrations = 0;
	$current = null;
	$statements = 0;
	$statement = null;
	$reason = null;

	$flush = function () use (&$statement, &$statements, &$reason, &$current, $foreignKeysOnly): void {
		if ($statement === null) {
			return;
		}
		$statements++;
		$reason ??= ddlReason($statement, (string) $current, $foreignKeysOnly);
		$statement = null;
	};

	foreach (explode("\n", $plan) as $line) {
		$trimmed = trim($line);
		if (preg_match('/\+\+ migrating (\S+)/', $trimmed, $m)) {
			$flush();
			$migrations++;
			$current = $m[1];
			$statements = 0;
		} elseif (preg_match('/^\[debug\]\s?(.*)$/', $trimmed, $m)) {
			$flush();
			$statement = [$m[1]];
		} elseif (preg_match('/Migration (\S+) migrated/', $trimmed)) {
			$flush();
			if ($statements === 0 && !$foreignKeysOnly) {
				$reason ??= "$current: no SQL in the plan (logic in PHP?)";
			}
			$current = null;
		} elseif ($statement !== null && $trimmed !== '' && !preg_match('/^(\[\w+\]|==|MIGRATIONS_)/', $trimmed)) {
			$statement[] = $trimmed;
		} else {
			$flush();
		}
	}
	$flush();

	if ($migrations === 0 && !$foreignKeysOnly && str_contains($plan, 'MIGRATIONS_PENDING')) {
		return 'pending migrations without SQL in the plan';
	}

	return $reason;
}

/**
 * Whether the dry-run of `migrations:migrate --phase=<phase>` (adt/doctrine-components) has anything
 * to run. A migration in the plan (`++ migrating`) says yes; no only the two messages of the command
 * for "nothing to do" - `Already at the latest version` (also of an empty phase, with ` - phase <phase>`
 * appended) and `there are no registered migrations`. The messages are printed in a block wrapped
 * to the width of the terminal (80 without one), each line prefixed, so they are matched with the
 * whitespace and the prefix of the block collapsed. Anything else counts as pending: an unknown
 * output must not skip the migrations silently.
 */
function migrationsPhasePending(string $dryRun): bool
{
	if (preg_match('/\+\+ migrating \S/', $dryRun)) {
		return true;
	}
	$text = preg_replace('/[\s!]+/', ' ', $dryRun);

	return !str_contains($text, 'Already at the latest version') && !str_contains($text, 'there are no registered migrations');
}

/**
 * @param list<string> $lines statement split by wrapping
 */
function ddlReason(array $lines, string $migration, bool $foreignKeysOnly = false): ?string
{
	// Joined with a space and without one - wrapping may split a word (REFERE|NCES)
	$spaced = strtoupper(preg_replace('/\s+/', ' ', implode(' ', $lines)));
	$glued = strtoupper(implode('', $lines));
	$short = substr(implode(' ', $lines), 0, 80);

	if (preg_match('/^DO\s+SLEEP\s*\(/', $spaced)) {
		return "$migration: $short (test migration of the maintenance window)";
	}
	if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|SELECT|SET|DO)\b/', $spaced)) {
		return null;
	}
	$foreignKey = preg_match('/\b(REFERENCES|FOREIGN\s+KEY)\b/', $spaced) || str_contains($glued, 'REFERENCES');
	if (!$foreignKey && ($foreignKeysOnly || preg_match('/^CREATE\s+TABLE\b/', $spaced))) {
		return null;
	}

	return "$migration: $short";
}
