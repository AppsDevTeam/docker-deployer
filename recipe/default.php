<?php

// Shared Deployer 8 recipe of AppsDevTeam projects built from the sandbox template
// (project/ + docker/ + config/ in the repository, ~/project + ~/docker on the server).
//
// Usage in the project's deploy.php:
//
//     require __DIR__ . '/project/vendor/adt/docker-deployer/recipe/default.php';
//
// and run it through the project's makefile (`make deploy -- <stage>`), which passes
// `-o stage=<stage>`. See README.md for the contract with the project (env variables,
// makefile targets).

namespace Deployer;

use Deployer\Host\Host;

require 'recipe/common.php';

// Value of `-o <name>=<value>` from the command line. Read from argv directly, because it is
// needed while this file is being loaded (stage, hosts), before Deployer parses the options.
// Worker processes (Deployer 8 runs every task on every host in a separate process that loads
// deploy.php again) get the options as `--option <name>=<value>`, so all forms are accepted.
function cliOption(string $name): ?string
{
	$argv = $_SERVER['argv'];
	foreach ($argv as $i => $arg) {
		if ($arg === '-o' || $arg === '--option') {
			$arg = $argv[$i + 1] ?? '';
		} elseif (str_starts_with($arg, '--option=')) {
			$arg = substr($arg, strlen('--option='));
		} elseif (str_starts_with($arg, '-o')) {
			$arg = substr($arg, 2);
		} else {
			continue;
		}
		if (str_starts_with($arg, "$name=")) {
			return substr($arg, strlen($name) + 1);
		}
	}
	return null;
}

// The stage is passed as `-o stage=<stage>`, not as a host selector: the selector is not passed
// to the worker processes, options are. So every process knows the stage, loads its env and
// defines the same hosts. One run always holds just the hosts of one stage, hence `all`.
// Without a stage (`dep list`, `dep tree deploy` - they do not accept -o) only the tasks are
// defined, there is no host to run them on.
set('default_selector', 'all');
$stage = cliOption('stage');
if ($stage !== null && $stage !== '') {
	defineStage($stage);
}

function defineStage(string $stage): void
{
	// config/docker/.env.<stage> holds BRANCH in projects created from the sandbox
	foreach (['config/.env.common', "config/.env.$stage", "config/docker/.env.$stage"] as $envFile) {
		if (!file_exists($envFile)) {
			if ($envFile === "config/.env.$stage") {
				throw new \RuntimeException("Unknown stage $stage: $envFile does not exist");
			}
			continue;
		}
		foreach (explode("\n", file_get_contents($envFile)) as $keyValue) {
			$keyValue = trim($keyValue);
			// Comments may contain "=" too (e.g. "MAILAPI_KEY = ...") and would end up as a bogus key
			if ($keyValue === '' || str_starts_with($keyValue, '#') || !str_contains($keyValue, '=')) {
				continue;
			}
			[$key, $value] = explode('=', $keyValue, 2);
			$_ENV[$key] = $value;
			putenv("$key=$value");
		}
	}

	foreach (['PROJECT_NAME', 'STAGE', 'BRANCH', 'DEPLOY_HOSTS'] as $required) {
		if (empty($_ENV[$required])) {
			throw new \RuntimeException("$required is not set in config/.env.common, config/.env.$stage or config/docker/.env.$stage");
		}
	}

	// Repository = the remote tracked by the deployed branch (BRANCH), falling back to origin. In
	// a project created from the sandbox it is origin, in the sandbox repository itself the remote
	// `sandbox`. There is no git in the Deployer container, so .git/config is read directly.
	$gitConfig = file_get_contents('.git/config');
	$remote = preg_match('/\[branch "' . preg_quote($_ENV['BRANCH'], '/') . '"\][^\[]*?remote = (\S+)/', $gitConfig, $matches)
		? $matches[1]
		: 'origin';
	if (!preg_match('/\[remote "' . preg_quote($remote, '/') . '"\][^\[]*?url = (.+)/', $gitConfig, $matches)) {
		// die('…') with a string exits 0 - the makefile would consider the deploy successful
		fwrite(STDERR, "Repository not found: remote \"$remote\" is missing in .git/config\n");
		exit(1);
	}
	set('repository', trim($matches[1]));

	host(...explode(',', $_ENV['DEPLOY_HOSTS']))
		->setRemoteUser($_ENV['PROJECT_NAME'])
		->setLabels(['stage' => $_ENV['STAGE']])
		// The server clones the repository with the key from the ssh agent (the dep wrapper mounts
		// SSH_AUTH_SOCK). Deployer 8 forwards the agent only with this setting.
		->setForwardAgent(true)
		// The default ControlPath is in ~/.ssh, which is mounted from the host into the Deployer
		// container - a unix socket cannot always be created there (Docker Desktop on macOS).
		// %C is a hash of the connection (host, port, user).
		->setSshControlPath('/tmp/dep-%C')
		->set('stage', $_ENV['STAGE'])
		->set('branch', $_ENV['BRANCH'])
		// Root of the storage of shared_storage_dirs: GlusterFS shared by all hosts of the stage, or
		// the deployer's shared dir. Host config, because tasks run in worker processes.
		->set('shared_storage_root', !empty($_ENV['GLUSTERFS_APP_ROOT']) ? $_ENV['GLUSTERFS_APP_ROOT'] : '{{deploy_path}}/shared');
}

set('keep_releases', 3);

// The release contains just the content of project/, so the deploy path is one level below the
// home directory and docker/ (with docker/.env holding infrastructure passwords) does not get
// into the containers, which mount {{deploy_path}}.
set('project_root', '~');
set('deploy_path', '{{project_root}}/project');
set('deploy_path_temp', '{{project_root}}/release_{{release_name}}_temp');
set('project_folder', 'project'); // copied into the release
set('docker_folder', 'docker'); // copied next to the release, into project_root

// The default `archive` extracts the code without .git; app:prepare reads git log/rev-parse/describe
// from the temporary clone
set('update_code_strategy', 'clone');

// No sudo at the beginning of the command: Deployer 8 asks for a password when a command starting
// with `sudo` fails. A variable in run() is expanded only after that check.
set('bin/console', 'sudo -E php bin/console');

// The code is mounted read-only into PHP-FPM on the server. It writes only into shared/, reached
// from the release by symlinks:
// - log, data (incl. data/locks of command-lock) and www/data are shared by all releases
// - temp is per release (app:release_temp) - the DI and Latte cache belong to the code of one
//   release, a new release must not rewrite the cache of the one still running on `current`
// The symlinks must be relative: inside the container the deploy root is /var/www/html.
set('shared_dirs', ['log', 'data', 'www/data']);
set('use_relative_symlink', true);
set('release_temp_root', '{{deploy_path}}/shared/temp/releases');

// Shared dirs with uploaded files, on GlusterFS when the stage has it (shared_storage_root)
set('shared_storage_dirs', ['data', 'www/data']);

// `make deploy` runs dep without a TTY (with one dep exits 157), so nobody would answer "Are the
// migrations ok?". The makefile asks instead - once for all stages, between deploy:build and
// deploy:finish - and passes `-o migrations_auto_confirm=true`. Only `dep deploy` run directly
// from a terminal asks.
set('migrations_auto_confirm', cliOption('migrations_auto_confirm') === 'true');

// Tasks

// The deploy is split in two, so that `make deploy` can show the migrations of all stages between
// them and confirm them with one question: deploy:build prepares the release and prints the plan
// (app:migrations_plan), deploy:finish runs the migrations and ends the deploy (deploy:pause).
// `dep deploy` run directly runs both.
desc('Begin deploy');
task('deploy', [
	'deploy:build',
	'deploy:finish',
]);

desc('Prepare release (without migrations)');
task('deploy:build', [
	'deploy:v6_compat',
	'deploy:info',
	'deploy:setup',
	'app:unlock_own',
	'deploy:lock',
	'deploy:release_check',
	'deploy:release',
	'deploy:temp_release_clone',
	'app:shared_storage',
	'deploy:shared',
	'deploy:writable',
	'deploy:clear_paths',
	'app:release_temp',
	'app:prepare',
	'app:migrations_plan',
	'app:built',
]);

desc('Run migrations and finish deploy');
task('deploy:finish', [
	'app:check_built',
	'app:migrations',
	'deploy:pause',
]);

// Switching the `current` symlink is enough - nginx resolves the path through $realpath_root, so
// every release has its own opcache keys and nothing has to be restarted.
desc('Publish deploy');
task('deploy:publish', [
	'deploy:symlink',
	'app:publish_done',
	'deploy:unlock',
	'app:crontab',
	'app:reload_consumers',
	'deploy:cleanup',
	'app:release_temp_cleanup',
	'deploy:success',
]);

// Deployer 6 kept the releases in .dep/releases (CSV `date,number`), Deployer 8 keeps the counter
// in .dep/latest_release and the list in .dep/releases_log (JSON). Without them the first deploy
// would start from release 1 (colliding with the existing directory) and the cleanup would never
// delete the old releases. They are converted once. Must run before deploy:info, which computes
// (and remembers) release_name first.
task('deploy:v6_compat', function () {
	// No cd: on a new server deploy_path does not exist yet (created by deploy:setup)
	if (!test('[ -f {{deploy_path}}/.dep/releases ] && [ ! -f {{deploy_path}}/.dep/latest_release ]')) {
		return;
	}

	cd('{{deploy_path}}');

	$existing = array_filter(explode("\n", run('ls -1 releases 2>/dev/null || true')), 'ctype_digit');

	// Repeated numbers (Deployer 6 recycled releases) - the last record wins
	$created = [];
	foreach (explode("\n", run('cat .dep/releases')) as $line) {
		$parts = explode(',', trim($line));
		if (count($parts) === 2 && ctype_digit($parts[1])) {
			unset($created[$parts[1]]);
			$created[$parts[1]] = $parts[0];
		}
	}

	foreach ($created as $name => $date) {
		$createdAt = \DateTime::createFromFormat('YmdHis', $date) ?: new \DateTime();
		$json = json_encode([
			'created_at' => $createdAt->format(\DateTimeInterface::ISO8601),
			'release_name' => (string) $name,
			'user' => 'deployer6',
			'target' => '',
		]);
		run('echo ' . quote($json) . ' >> .dep/releases_log');
	}

	$latest = max([0, ...array_map('intval', $existing), ...array_map('intval', array_keys($created))]);
	run("echo $latest > .dep/latest_release");
	info("Converted Deployer 6 metadata, latest release: $latest");
})->hidden();

desc('Clone git into a temp directory');
task('deploy:temp_release_clone', function () {
	// Leftover of a failed deploy with the same number
	run('rm -rf {{deploy_path_temp}}');

	// First deploy: `current` and current/www must exist, so that the containers can start
	run('if [ ! -L {{deploy_path}}/current ]; then {{bin/symlink}} {{release_path}} {{deploy_path}}/current; fi');
	run('if [ ! -d {{deploy_path}}/current/www ]; then mkdir {{deploy_path}}/current/www; fi');

	run('mkdir -p {{deploy_path_temp}}');

	// Cloned into a temporary directory, the release gets just the content of project/
	$releasePathOriginal = get('release_path');
	set('release_path', '{{deploy_path_temp}}');

	invoke('deploy:update_code');

	cd('{{deploy_path_temp}}');

	// SECRETS_STAGE limits decryption to the deployed stage - a broken or undecryptable .env
	// of an unrelated stage must not fail the deploy
	run('make secrets-decrypt SECRETS_STAGE={{stage}} SUDO="sudo -E"');

	// Assembles docker/.env and project/.env from the templates in config/
	run('make _env STAGE={{stage}} SUDO="sudo -E"');

	run("rsync -a --delete {{project_folder}}/ $releasePathOriginal/");

	// The docker configuration lives outside the release and outside deploy_path, so that it
	// survives the symlink switch and does not get into the containers. It is applied by hand:
	// cd {{project_root}}/docker && make docker-upd (root)
	run('rsync -a --delete {{docker_folder}} {{project_root}}/');

	// composer.json and composer.lock for php/Dockerfile (install-composer-extensions). From the
	// release being deployed, not from `current` - the image can be built for an unpublished release.
	run("cp $releasePathOriginal/composer.json $releasePathOriginal/composer.lock {{project_root}}/{{docker_folder}}/php/");

	set('release_path', $releasePathOriginal);
});

// The dirs of the shared storage must be created under the application account BEFORE the
// containers mount them - docker would create a missing bind source as root:root.
// The content of the dir in the release (from git, e.g. data/locks/.gitignore) is merged into the
// storage on every deploy - deploy:shared copies it only when it creates the shared dir, so a
// subdirectory added to git later would never appear. Files that lived in the current release
// before the dir became shared (current/<dir> is a real directory, not a symlink yet) are moved
// once. --ignore-existing never overwrites anything.
desc('Prepare shared storage');
task('app:shared_storage', function () {
	$root = get('shared_storage_root');
	foreach (get('shared_storage_dirs') as $dir) {
		run("mkdir -p $root/$dir");
		run("if [ -d {{deploy_path}}/current/$dir ] && [ ! -L {{deploy_path}}/current/$dir ]; then rsync -a --ignore-existing {{deploy_path}}/current/$dir/ $root/$dir/; fi");
		run("if [ -d {{release_path}}/$dir ] && [ ! -L {{release_path}}/$dir ]; then rsync -a --ignore-existing {{release_path}}/$dir/ $root/$dir/; fi");
	}
});

// The temp of a release lives in shared/temp/releases/<N> (a writable mount), the release has
// a symlink `temp` to it instead of temp/ from git (just .gitignore)
desc('Link per-release temp');
task('app:release_temp', function () {
	run('mkdir -p {{release_temp_root}}/{{release_name}}');
	run('rm -rf {{release_path}}/temp');
	run('{{bin/symlink}} {{release_temp_root}}/{{release_name}} {{release_path}}/temp');
});

// Deletes the temp of releases already removed by deploy:cleanup (keep_releases) and of failed
// deploys. A failure does not fail the publish - the release is already public at that point.
desc('Remove temp of deleted releases');
task('app:release_temp_cleanup', function () {
	try {
		// {{deploy_path}} starts with ~, which would not expand inside quotes - the test would then
		// claim no release exists and delete the temp of all releases including the published one
		run('cd {{release_temp_root}} && for dir in *; do [ -d "$dir" ] && [ ! -d {{deploy_path}}/releases/"$dir" ] && rm -rf "$dir"; done; true');
	} catch (\Throwable $e) {
		warning('Cleanup of the temp of old releases failed: ' . $e->getMessage());
	}
});

// Rollback switches `current` to the previous release with its own temp. The cache is deleted
// anyway: it was created before a newer release ran its migrations, Nette rebuilds it.
desc('Clear cache of the release after rollback');
task('app:rollback_temp', function () {
	run('rm -rf {{deploy_path}}/current/temp/cache');
});
after('rollback', 'app:rollback_temp');
after('rollback', 'app:release_temp_cleanup');

desc('Get the application ready');
task('app:prepare', function () {
	cd('{{release_path}}');

	// First deploy: waits until somebody starts the containers - without a time limit
	run('[ "$(sudo container-running php)" = "true" ] || (echo "Run the containers to continue." && while [ "$(sudo container-running php)" != "true" ]; do sleep 1; done; sleep 10)', timeout: 0, forceOutput: true);

	// `make checkout` without migrations - app:migrations runs them once per stage. No time limit:
	// composer install + yarn build of a bigger project take longer than the default 300 s, and
	// the deploy is always watched by somebody who can interrupt it. `timeout: 0`, not null - run()
	// replaces null with default_timeout (despite its docblock), Symfony Process takes 0 as no limit.
	run('make _prepare', timeout: 0, forceOutput: true);

	writeTracySystemInfo();

	run('rm -rf {{deploy_path_temp}}');
});

// System info for the Tracy panel (adt/tracy-system-info), written by the deploy on the host -
// the console command tracy-system-info:add runs in the PHP container, where the code is
// read-only. Same format and keys as the command wrote (NEON, in which JSON strings are valid
// quoted strings).
function writeTracySystemInfo(): void
{
	$git = fn (string $command): string => trim(run("cd {{deploy_path_temp}} && $command"));

	$values = [
		'Commit' => $git('git log --format=%H -n 1'),
		'Branch' => $git('git rev-parse --abbrev-ref HEAD'),
		'Tag' => $git('git describe --tags --always'),
		'Message' => $git('git log -1 --pretty=%B'),
		// Same format and zone as --timestamp of the command
		'timestamp' => (new \DateTime('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'),
		'Instance' => currentHost()->getHostname(),
		'Release' => get('release_name'),
	];

	$neon = '';
	foreach ($values as $key => $value) {
		$neon .= $key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	// base64: the content (commit message) must pass neither the Deployer parser ({{...}}) nor the shell
	run('echo ' . base64_encode($neon) . ' | base64 -d > {{release_path}}/system-info.json');
}

// The database is shared by all hosts of the stage, so the migrations run only on the first one
// (once). Deployer starts the next task only after the previous one finished on all hosts, so
// app:prepare (local to each host) runs in parallel and the migrations come after it. run() has
// no terminal in Deployer 8, so `read` in `make migrations` could not ask - the plan is printed
// separately and confirmed with askConfirmation(), which Deployer forwards to the main process.
//
// Contract with project/makefile:
// - `make migrations MIGRATIONS_DRY_RUN=1` prints the plan and a line `MIGRATIONS_PENDING` when
//   there is anything to run
// - `make migrations MIGRATIONS_AUTO_CONFIRM=1` runs them without asking
// A project without a database has an empty `migrations` target.
desc('Run database migrations');
task('app:migrations', function () {
	cd('{{release_path}}');

	$plan = migrationsPlan();
	if (!output()->isVerbose()) {
		writeln($plan);
	}
	if (str_contains($plan, 'MIGRATIONS_PENDING')) {
		if (!get('migrations_auto_confirm') && !askConfirmation('Are the migrations ok?')) {
			throw error('Migrations not confirmed. Without asking: make deploy -- <stage> DEP_ARGS="-o migrations_auto_confirm=true"');
		}
		run('make migrations MIGRATIONS_AUTO_CONFIRM=1', timeout: 0);
	}
})->once();

// Plan of the migrations for `make deploy`: every line prefixed MIGRATIONS_PLAN>, the makefile
// extracts them from the log and asks for the stages with a MIGRATIONS_PENDING line.
task('app:migrations_plan', function () {
	cd('{{release_path}}');

	// output() instead of writeln(): writeln() passes the text through parse() and would take
	// {{...}} in SQL for a variable
	foreach (explode("\n", migrationsPlan()) as $line) {
		output()->writeln('[' . currentHost() . '] MIGRATIONS_PLAN> ' . $line);
	}
})->once()->hidden();

// Output of run() is printed only with -v and more (make deploy uses -vvv). Empty lines are
// dropped: Deployer 8 (Logger::print) discards the rest of a received output block at the first
// empty line - and Doctrine output starts with one. pipefail, so that a make error is not lost
// in grep; `|| true` of grep, so that an empty plan is not an error.
function migrationsPlan(): string
{
	return run('set -o pipefail; make --no-print-directory migrations MIGRATIONS_DRY_RUN=1 | { grep -v "^[[:space:]]*$" || true; }', timeout: 0);
}

// Marks that deploy:build finished. Without it deploy:finish does not run the migrations (the
// release would be unfinished) and app:unlock_own knows the own lock belongs to a finished build.
task('app:built', function () {
	run('touch {{deploy_path}}/.dep/deploy.built');
})->hidden();

task('app:check_built', function () {
	if (!test('[ -f {{deploy_path}}/.dep/deploy.built ] && [ -L {{deploy_path}}/release ]')) {
		throw error('deploy:build of stage {{stage}} on {{alias}} did not finish - run `make deploy` again.');
	}
})->hidden();

task('deploy:pause', function () {
	// Mark for app:unlock_own and deploy:publish_check - the deploy finished and keeps the lock
	// only until deploy:publish
	run('rm -f {{deploy_path}}/.dep/deploy.built');
	run('touch {{deploy_path}}/.dep/deploy.paused');
	writeln('After verifying on port 4434, continue with the task "deploy:publish".');
})->hidden();

// `make deploy-publish` runs it before deploy:publish for all given stages and publishes none
// when it fails for any of them. Guards against publishing a release whose deploy did not finish
// (the `release` symlink exists, deploy.paused does not). A separate task, not part of
// deploy:publish: its failure would trigger deploy:failed, which unlocks a running deploy.
desc('Check that deploy finished before publish');
task('deploy:publish_check', function () {
	if (!test('[ -f {{deploy_path}}/.dep/deploy.paused ] && [ -L {{deploy_path}}/release ]')) {
		throw error('Deploy of stage {{stage}} on {{alias}} did not finish (.dep/deploy.paused or the release symlink is missing) - run `make deploy` again.');
	}
});

// A published release does not need the deploy:pause mark - another publish passes only after
// a new deploy
task('app:publish_done', function () {
	run('rm -f {{deploy_path}}/.dep/deploy.paused');
})->hidden();

// `make deploy` ends with deploy:pause and the lock is released by deploy:publish. A repeated
// deploy without publish (a fix before publishing, more stages at once, unconfirmed migrations)
// would fail on "Deploy locked". So the own lock (same `user`) is released, but only when the
// deploy holding it reached the end of deploy:build (deploy.built) or deploy:pause
// (deploy.paused). The lock of a running or interrupted deploy - even an own one, e.g. from
// another terminal - and somebody else's lock stay. The marks are deleted so that the next
// running deploy does not inherit them - but only when nobody holds the lock: a locked deploy
// fails on deploy:lock anyway and the marks belong to the lock holder.
task('app:unlock_own', function () {
	$lockFile = '{{deploy_path}}/.dep/deploy.lock';
	$builtFile = '{{deploy_path}}/.dep/deploy.built';
	$pausedFile = '{{deploy_path}}/.dep/deploy.paused';
	if (test("[ -f $lockFile ] && { [ -f $builtFile ] || [ -f $pausedFile ]; } && [ \"\$(cat $lockFile)\" = " . quote(get('user')) . " ]")) {
		run("rm -f $lockFile");
		info('removed own deploy lock');
	}
	if (test("[ -f $lockFile ]")) {
		return;
	}
	run("rm -f $builtFile $pausedFile");
})->hidden();

// Installed on all hosts. Jobs run only on one of them: cronie in cluster mode (crond -c, service
// cron-cluster of server-management-scripts) runs user crontabs only on the leader, so a failover
// takes the jobs over. After deploy:symlink the `release` symlink no longer exists and Deployer 8
// throws on {{release_path}}, publish tasks therefore use {{current_path}}.
desc('Apply crontab');
task('app:crontab', function () {
	cd('{{current_path}}');

	run('crontab crontab.{{stage}}');
});

// Restarts the background-queue consumers so that they run the new code. A project without
// consumers has an empty `reload-consumers` target.
desc('Reload consumers');
task('app:reload_consumers', function () {
	cd('{{current_path}}');

	run('make reload-consumers');
});

// Deployer 8 computes the release number on every host separately from .dep/latest_release (+1).
// Checked before deploy:release, so that nothing is created on a mismatch.
desc('Check if all releases match');
task('deploy:release_check', function () {
	$latestReleases = [];
	on(selectedHosts(), function (Host $host) use (&$latestReleases) {
		$latestReleases[$host->getHostname()] = run('cat {{deploy_path}}/.dep/latest_release 2>/dev/null || echo 0');
	});

	if (count(array_unique($latestReleases)) !== 1) {
		$lines = [];
		foreach ($latestReleases as $hostname => $release) {
			$lines[] = "Host '$hostname': latest release '$release'";
		}
		throw error("Release number mismatch:\n" . implode("\n", $lines)
			. "\nAlign .dep/latest_release on the hosts, or deploy with -o release_name=<number>.");
	}
})->once();

// A failed deploy leaves ~/release_<N>_temp (deleted at the end of app:prepare). The next deploy
// has number N+1, so rm -rf {{deploy_path_temp}} would not delete it. A glob, because release_name
// of the failed attempt need not be known in this run; it cannot hit a concurrent deploy - with
// a locked deploy (GracefulShutdownException) Deployer 8 does not run deploy:failed.
task('app:clean_temp', function () {
	run('rm -rf {{project_root}}/release_*_temp');
})->hidden();

// The fail hook is looked up by the name of the started task, `make deploy` runs
// deploy:build/deploy:finish. Not for deploy:finish: deploy:failed there would release the lock
// of a deploy that has not finished deploy:build (e.g. is running right now). The lock after
// failed migrations is released by the next deploy (deploy.built mark).
fail('deploy:build', 'deploy:failed');
fail('deploy:publish', 'deploy:failed');
after('deploy:failed', 'app:clean_temp');
after('deploy:failed', 'deploy:unlock');
