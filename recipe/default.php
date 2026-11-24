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

use Deployer\Exception\GracefulShutdownException;
use Deployer\Exception\RunException;
use Deployer\Host\Host;

require 'recipe/common.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/smoke_check.php';

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

// A flag from env/-o: only 1/true/yes/on (case-insensitive, quotes stripped) switch it on - with
// !empty() also `false`, `no`, `off` and `"0"` (the env parser keeps quotes) would turn it on.
function flagEnabled(?string $value): bool
{
	return in_array(strtolower(trim((string) $value, " \t\"'")), ['1', 'true', 'yes', 'on'], true);
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
	foreach (['config/.env.skeleton', 'config/.env.common', "config/.env.$stage", "config/docker/.env.$stage"] as $envFile) {
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
			$key = trim((string) preg_replace('~^export\s+~', '', $key));
			$value = trim($value);
			// the same as the .env parsers of the containers and make: DEPLOY_HOSTS="a,b" is a,b, not "a
			if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
				$value = substr($value, 1, -1);
			}
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

	// The server clones the repository with the key of its own account - a read-only deploy key of
	// the repository (app-ci.sh of server-management-scripts). The agent of the developer is not
	// forwarded: anyone with root or the account on the host could sign with it for the whole
	// deploy, wherever the developer's key leads. DEPLOY_FORWARD_AGENT=1 (config/.env.<stage>)
	// only for a stage whose account has no deploy key yet. Off = `-o ForwardAgent=no`, not just
	// no `-A`: `ForwardAgent yes` in the developer's ~/.ssh/config (mounted by bin/dep) would
	// forward it anyway, the command line wins over the config file.
	$forwardAgent = flagEnabled($_ENV['DEPLOY_FORWARD_AGENT'] ?? null);
	host(...explode(',', $_ENV['DEPLOY_HOSTS']))
		->setRemoteUser($_ENV['PROJECT_NAME'])
		->setLabels(['stage' => $_ENV['STAGE']])
		->setForwardAgent($forwardAgent)
		->setSshArguments($forwardAgent ? [] : ['-o ForwardAgent=no'])
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
set('migrations_auto_confirm', flagEnabled(cliOption('migrations_auto_confirm')));
// A lock of a finished deploy (deploy.paused) without activity for this long (minutes) belongs to
// a release nobody published - app:unlock_own removes it. 0 = never (`make deploy-unlock` only).
set('lock_stale_minutes', 60);

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
	'app:build_images',
	'app:prepare',
	'app:preprod_up',
	'app:check_extensions_preprod',
	'app:migrations_plan',
	'app:built',
]);

desc('Run migrations and finish deploy');
task('deploy:finish', [
	'app:check_built',
	'app:migrations',
	'app:smoke_check',
	'deploy:pause',
]);

// Switching the `current` symlink is enough - nginx resolves the path through $realpath_root, so
// every release has its own opcache keys and nothing has to be restarted.
desc('Publish deploy');
task('deploy:publish', [
	'app:publish_start',
	// Cron jobs paused and the running ones waited for, resumed after deploy:unlock (app:cron_resume).
	// Before app:containers_up: `dupd up` recreates a service whose image changed and would kill the
	// jobs running in it - and a publish that gives up waiting must not leave the previous release
	// on the new image.
	'app:cron_pause',
	'app:cron_wait',
	'app:containers_up',
	'app:check_extensions',
	'deploy:symlink',
	'app:publish_done',
	'app:crontab',
	'app:reload_consumers',
	'app:migrations_contract',
	// After the contract migrations: they run under the deploy lock like app:migrations (another
	// deploy would remove their maintenance file as stale), and the cron jobs are resumed
	// (after deploy:unlock) only with the crontab of the new release installed
	'deploy:unlock',
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
	// docker/.make (adt/make downloaded by that root `make docker-upd`) is not in git and belongs
	// to root - --delete could not remove it and the deploy failed with rsync exit code 23
	run('rsync -a --delete --exclude=/{{docker_folder}}/.make {{docker_folder}} {{project_root}}/');

	// composer.json and composer.lock for php/Dockerfile (install-composer-extensions). From the
	// release being deployed, not from `current` - the image can be built for an unpublished release.
	run("cp $releasePathOriginal/composer.json $releasePathOriginal/composer.lock {{project_root}}/{{docker_folder}}/php/");

	set('release_path', $releasePathOriginal);
});

// Images of the services with `build:` - dupd (adt/docker-binaries, root-owned, allowed in sudoers
// without a password) validates the compose configuration and builds from a tar made under the
// application account. Detected through sudo (`sudo -l <cmd>` = the account may run it):
// /usr/local/bin/dupd is a symlink into /root, so `command -v` fails for the account. The running
// containers are not touched, they get the new image in deploy:publish (app:containers_up). A host
// without dupd keeps the manual `make docker-upd` by root.
// After app:shared_storage, deploy:shared and app:release_temp: the validation refuses a bind
// mount whose source does not exist (docker would create it as root), and on the first deploy of
// a stage those tasks are what creates shared/{log,data,www/data,temp} and the storage dirs.
desc('Build images');
task('app:build_images', function () {
	runVisible('if sudo -n -l /usr/local/bin/dupd >/dev/null 2>&1; then sudo -n dupd build; fi');
});

// Applies the docker configuration deployed to {{project_root}}/docker: `docker compose up` through
// dupd recreates only the services whose image or configuration changed, the others keep running.
// In deploy:publish right before deploy:symlink (only app:check_extensions in between), so that
// a changed compose (e.g. a new mount) never runs over the old release. First deploy: app:prepare starts the containers. Removes the
// preprod copy of app:preprod_up too (`--remove-orphans`).
desc('Apply docker configuration');
task('app:containers_up', function () {
	runVisible('if sudo -n -l /usr/local/bin/dupd >/dev/null 2>&1; then sudo -n dupd up; fi');
});

// Preprod on the new image: dupd starts <preprod_service>-next from the image app:build_images just
// built, the live service keeps the previous one until app:containers_up. The nginx of the project
// sends X-Forwarded-Port 4434 there (include/root of the sandbox) and the console commands of the
// release run in it (preprodPhp()), so a release needing a new extension or PHP version is migrated
// and smoke-checked on the runtime it goes public with - before, the dry run of the migrations ran
// in the old container and failed (could not find driver) without a way out but root.
// After app:prepare: its `dupd up` of the first deploy would remove the copy as an orphan.
// dupd without preprod (docker-binaries < 3.22) keeps the previous behaviour. false = off.
set('preprod_service', 'php');
task('app:preprod_up', function () {
	$service = get('preprod_service');
	if (!$service) {
		return;
	}
	if (!preprodSupported()) {
		if (test('sudo -n -l /usr/local/bin/dupd >/dev/null 2>&1')) {
			warning('dupd on {{alias}} without preprod (docker-binaries < 3.22): preprod and migrations on the live service');
		}
		return;
	}
	runVisible('sudo -n dupd preprod start ' . escapeshellarg($service));
})->hidden();

// The extensions of the release in its runtime already in deploy:build: a missing ext-* fails here
// with a clear message instead of "could not find driver" in the dry run of the migrations or a 500
// of the smoke check. adt/make < 1.9.5 has no CHECK_EXTENSIONS_PHP, its check would run in the live
// container with the previous image - only app:check_extensions in the publish then.
task('app:check_extensions_preprod', function () {
	$php = preprodPhp();
	if ($php === null) {
		return;
	}
	cd('{{release_path}}');
	if (!test('make -n check-extensions CHECK_EXTENSIONS_PHP=__probe__ 2>/dev/null | grep -q __probe__')) {
		return;
	}
	run('make check-extensions CHECK_EXTENSIONS_PHP=' . escapeshellarg($php));
})->hidden();

// A failure must not stop the other deploy:failed tasks (unlock)
task('app:preprod_down', function () {
	if (!get('lock_held')) {
		return;
	}
	try {
		if (preprodSupported()) {
			run('cd ~ && sudo -n dupd preprod stop');
		}
	} catch (\Throwable $e) {
		warning('dupd preprod stop failed on {{alias}}: ' . $e->getMessage());
	}
})->hidden();

function preprodSupported(): bool
{
	return get('preprod_service')
		&& test('sudo -n -l /usr/local/bin/dupd >/dev/null 2>&1 && sudo -n dupd rules | grep -q "^PREPROD"');
}

// php of the release being deployed: the preprod copy, null where there is none (preprod off, dupd
// without preprod - the live service as before). A copy that should run but does not (crashed, host
// restarted while the deploy paused - it has restart: no) is an error: falling back to the live
// container would run the migrations on the previous image, the very thing preprod is there to avoid.
function preprodPhp(): ?string
{
	if (!preprodSupported()) {
		return null;
	}
	$copy = get('preprod_service') . '-next';
	if (!test('[ "$(sudo container-running ' . escapeshellarg($copy) . ')" = true ]')) {
		throw error("Preprod $copy is not running on {{alias}} - see `sudo dupd logs $copy`, then `make deploy` again (it starts the copy anew).");
	}
	return "sudo -E de $copy php";
}

// The runtime has every ext-* of composer.json and composer.lock (adt/make check-extensions,
// `php` of the running container - after app:containers_up that is the image built for this
// release). Before deploy:symlink: a release whose extension is missing never goes public.
// Checked in the release being published (the `release` symlink), not in `current`.
task('app:check_extensions', function () {
	run('cd {{deploy_path}}/release && make check-extensions');
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

	// First deploy: starts the containers through dupd; without dupd waits until somebody
	// starts them - without a time limit
	run('if [ "$(sudo container-running php)" != "true" ]; then'
		. ' if sudo -n -l /usr/local/bin/dupd >/dev/null 2>&1; then sudo -n dupd up; else echo "Run the containers to continue."; fi;'
		. ' while [ "$(sudo container-running php)" != "true" ]; do sleep 1; done; sleep 10;'
		. ' fi', timeout: 0, forceOutput: true);

	// `make checkout` without migrations - app:migrations runs them once per stage. No time limit:
	// composer install + yarn build of a bigger project take longer than the default 300 s, and
	// the deploy is always watched by somebody who can interrupt it. `timeout: 0`, not null - run()
	// replaces null with default_timeout (despite its docblock), Symfony Process takes 0 as no limit.
	// Without the extension check (adt/make CHECK_EXTENSIONS): it runs `php` in the running
	// container, which is still the previous image - the one just built by dupd starts in
	// deploy:publish (app:containers_up). app:check_extensions checks right after that.
	runVisible('make _prepare CHECK_EXTENSIONS=0');

	writeTracySystemInfo();

	run('rm -rf {{deploy_path_temp}}');
});

// Output of a long command printed as it runs, without a time limit. Blank lines are dropped:
// Deployer 8 Logger::print() stops at the first empty line of a chunk (`return` instead of
// `continue`), so everything after it was lost - a failed Vite build printed only „Build failed
// with 1 error:“ and not the error itself, not even into the log. A line "0" is empty() in PHP
// too, hence the space. pipefail keeps the exit code of the command.
function runVisible(string $command): string
{
	return run(withLockHeartbeat("set -o pipefail; { $command; } 2>&1 | sed -e '/^[[:space:]]*$/d' -e 's/^0$/ 0/'"), timeout: 0, forceOutput: true);
}

// The age of the lock is the time since the last activity of the deploy, not since its start:
// a long command (migrations with timeout 0, a build) renews it every minute. touch -c does not
// recreate a lock somebody removed meanwhile. The loop ends with the shell that started it, so
// a killed deploy does not keep its lock alive - an orphaned command still running does, rightly.
function withLockHeartbeat(string $command): string
{
	return '( while kill -0 $$ 2>/dev/null; do touch -c {{deploy_path}}/.dep/deploy.lock; sleep 60; done ) </dev/null >/dev/null 2>&1 & '
		. "$command";
}

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
//
// Pending migrations run in a maintenance window (see maintenanceOn()): DDL on a Galera cluster
// conflicts with the metadata locks of the running application - an ALTER TABLE ... ADD FOREIGN
// KEY on a table in use crashed a node (mysql-wsrep 8.0.43, wsrep_handle_mdl_conflict).
//
// No cd() in this task: on() in maintenanceOn()/maintenanceOff() reloads the host config from the
// main process (Configuration::load()) and drops working_path set by cd() in the worker - the
// migrations then ran in the home directory (`No rule to make target 'migrations'`). Every
// command therefore changes the directory itself.
desc('Run database migrations');
task('app:migrations', function () {
	// Nobody else deploys the stage (deploy:lock), so the file is a leftover of a killed deploy -
	// the application would stay unavailable
	$stale = false;
	on(selectedHosts(), function () use (&$stale) {
		$stale = $stale || test('[ -f {{maintenance_file}} ]');
	});
	if ($stale) {
		warning('Stale maintenance file {{maintenance_file}} (killed deploy?) - removing it on all hosts');
		maintenanceOff();
	}

	// In the preprod copy of php (the image of the release), see app:preprod_up
	$php = preprodPhp();
	runPendingMigrations('{{release_path}}', migrationsPhase('{{release_path}}', 'expand', $php), $php);
})->once();

// Expand and Contract pattern (adt/doctrine-components): a project that replaced `migrations:migrate`
// with the command of the package has two phases of migrations. Expand ones are backward compatible
// and run in deploy:finish, while the previous release is still live; contract ones remove what the
// previous release needed and run in deploy:publish, after the switch of `current`
// (app:migrations_contract). Any other project has no phases (null) - everything runs in
// deploy:finish through its makefile, as before.
//
// Detected by the `--phase` option of the command, not by the package in vendor: the package is
// installed in every project, the command is replaced only where the project's configuration says
// so (`doctrineComponents: expandContract: true` or the service registered by hand). Asked once per
// host and release - every question boots the console.
//
// $php: php of the release being deployed (preprodPhp()), null = the live service ({{bin/console}}) -
// the published release (`current`) and hosts without preprod.
function migrationsPhase(string $path, string $phase, ?string $php): ?string
{
	static $phased = [];

	$key = currentHost()->getAlias() . ':' . parse($path);
	$phased[$key] ??= migrationsPhased($path, $php);

	return $phased[$key] ? $phase : null;
}

// Phases only when the help of the command shows --phase. The console says "no phases" with the
// help (its `Usage:`) without --phase, or with the two messages of Symfony Console for a command
// that does not exist (a project without a database). When it cannot tell - it fails for another
// reason, or ends with 0 without printing the help - the migrations run the old way, without
// phases (everything through `make migrations` in deploy:finish), with a warning and the output
// of the console. Retried first: right after `dupd up` the php container may still be starting.
function migrationsPhased(string $path, ?string $php): bool
{
	if (!test("[ -f $path/bin/console ]")) {
		return false;
	}

	$command = "cd $path && out=\$(MAINTENANCE_BYPASS=1 " . migrationsConsole($php) . " migrations:migrate --help 2>&1); code=\$?; "
		. "if echo \"\$out\" | grep -q -e '--phase'; then echo PHASES_ON; "
		. "elif [ \$code -eq 0 ] && echo \"\$out\" | grep -q -e 'Usage:'; then echo PHASES_OFF; "
		. "elif echo \"\$out\" | grep -q -F -e 'Command \"migrations:migrate\" is not defined' -e 'There are no commands defined in the \"migrations\" namespace'; then echo PHASES_OFF; "
		. "else echo \"\$out\" | grep -v '^[[:space:]]*\$' | tail -n 20; echo PHASES_UNKNOWN; fi";

	$output = '';
	for ($attempt = 1; $attempt <= 3; $attempt++) {
		$output = run($command);
		if (str_ends_with($output, 'PHASES_ON')) {
			return true;
		}
		if (str_ends_with($output, 'PHASES_OFF')) {
			return false;
		}
		if ($attempt < 3) {
			sleep(5);
		}
	}

	// output() + escape(): the console output may contain {{...}} or <...>, see runPendingMigrations()
	output()->writeln(\Symfony\Component\Console\Formatter\OutputFormatter::escape($output));
	warning("Cannot tell whether the migrations of {{alias}} have the expand/contract phases: `migrations:migrate --help` in $path failed (output above) - running them the old way, without phases.");

	return false;
}

// The command running the pending migrations of the release in $path. A phase goes to the console
// command of adt/doctrine-components directly - the makefile contract has no place for it, so what
// the project's `migrations` target does on top (more databases, own options) is not run.
// --allow-no-migration: a project without any migration yet is not an error, as in the makefiles.
function migrationsCommand(string $path, ?string $phase, ?string $php): string
{
	return $phase === null
		? "cd $path && make migrations MIGRATIONS_AUTO_CONFIRM=1 MAINTENANCE_BYPASS=1" . migrationsApp($php)
		: "cd $path && MAINTENANCE_BYPASS=1 " . migrationsConsole($php) . " migrations:migrate --no-interaction --allow-no-migration -vv --phase=$phase";
}

// The console of the release: in the preprod copy of php when there is one, otherwise the live service
function migrationsConsole(?string $php): string
{
	return $php === null ? '{{bin/console}}' : "$php bin/console";
}

// The same as APP of adt/make for the targets of the makefile. A make argument, so it wins over
// APP of the project makefile.
function migrationsApp(?string $php): string
{
	return $php === null ? '' : ' APP=' . escapeshellarg("$php bin/console");
}

// Shows the plan of the pending migrations of the release in $path, asks for confirmation and runs
// them - in the maintenance window when they touch the structure of existing tables.
function runPendingMigrations(string $path, ?string $phase, ?string $php): void
{
	$plan = migrationsPlan($path, $phase, $php);
	if (!output()->isVerbose()) {
		writeln($plan);
	}
	if (str_contains($plan, 'MIGRATIONS_PENDING')) {
		if (!get('migrations_auto_confirm') && !askConfirmation('Are the migrations ok?')) {
			// The contract phase runs in deploy:publish, with the release already live - `make deploy`
			// would not run it
			throw error($phase === 'contract'
				? 'Contract migrations not confirmed - the release is published without them. Run them: dep app:migrations_contract -o stage={{stage}} -o migrations_auto_confirm=true'
				: 'Migrations not confirmed. Without asking: make deploy -- <stage> DEP_ARGS="-o migrations_auto_confirm=true"');
		}
		// Data migrations (DML, CREATE TABLE without a foreign key) do not touch the structure of
		// existing tables - no metadata lock conflict, so no maintenance window (recipe/maintenance.php).
		// Expand migrations are backward compatible and run under the live previous release, a window
		// for every new column would make the pattern pointless - but compatible is not the same as
		// free of the metadata lock conflict, so a foreign key (the statement that crashed a node)
		// still gets the window.
		$expand = $phase === 'expand';
		$reason = migrationsMaintenanceReason($plan, foreignKeysOnly: $expand);
		if ($reason === null) {
			info($expand ? 'expand migrations without a foreign key - without the maintenance window' : 'migrations change data only - without the maintenance window');
			run(withLockHeartbeat(migrationsCommand($path, $phase, $php)), timeout: 0);
			return;
		}

		// output() + escape(): the reason contains SQL - writeln()/info() would take {{...}} for
		// a variable, the formatter `<...>` for a style tag
		output()->writeln('<info>maintenance window</info>: ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($reason));
		// maintenanceOn() inside: a failure while turning it on (reload-consumers) must not leave the
		// maintenance file behind
		try {
			maintenanceOn();
			if (test("cd $path && make -n migrations-kill-sessions >/dev/null 2>&1")) {
				run(withLockHeartbeat("cd $path && make migrations-kill-sessions MAINTENANCE_BYPASS=1 MIGRATIONS_KILL_WAIT={{migrations_kill_wait}}" . migrationsApp($php)), timeout: 0);
			}
			run(withLockHeartbeat(migrationsCommand($path, $phase, $php)), timeout: 0);
		} finally {
			maintenanceOff();
		}
	}
}

// Contract phase (see migrationsPhase()): after the switch of `current` the code that no longer
// uses the old schema runs on all hosts, so the contract migrations of the live release may run.
// Also available on its own, e.g. after a failed attempt:
// `dep app:migrations_contract -o stage=<stage>`.
//
// A project without the phases has no contract phase - all its migrations ran in
// app:migrations - and the task does nothing, not even the dry-run.
desc('Run contract migrations of the published release');
task('app:migrations_contract', function () {
	// php: null - app:containers_up replaced the preprod copy, the live service runs the new image
	$phase = migrationsPhase('{{current_path}}', 'contract', null);
	if ($phase === null) {
		return;
	}

	runPendingMigrations('{{current_path}}', $phase, null);
})->once();

// Maintenance flag of the stage, one per host. Outside the releases: the migrations run from the
// new release, the web and consumers still from `current`, both must see the same file. Inside the
// containers it is /var/www/html/maintenance (the deploy root is mounted there).
set('maintenance_file', '{{deploy_path}}/maintenance');

// How long `make migrations-kill-sessions` waits for the sessions of the application to become
// idle (a consumer finishing its job) before it kills them - passed as MIGRATIONS_KILL_WAIT
set('migrations_kill_wait', 60);

// The application has to check the file itself (README, Maintenance during migrations): the web
// answers 503, console commands end right away and consumers wait until the file is gone.
// Running consumers are restarted (reload-consumers), so that they start waiting. The commands of
// the deploy pass MAINTENANCE_BYPASS=1 - as a make argument, a variable from the included .env
// would override the environment.
function maintenanceOn(): void
{
	on(selectedHosts(), function () {
		run('date -Iseconds > {{maintenance_file}}');
		info('maintenance ON on {{alias}}');
	});
	on(selectedHosts(), function () {
		// `current` of the first deploy of a project moved to the recipe predates adt/make: no such
		// target (and no consumers the target would know)
		if (test('cd {{current_path}} && make -n reload-consumers >/dev/null 2>&1')) {
			run('cd {{current_path}} && make reload-consumers MAINTENANCE_BYPASS=1');
		}
	});
}

function maintenanceOff(): void
{
	on(selectedHosts(), function () {
		run('rm -f {{maintenance_file}}');
		info('maintenance OFF on {{alias}}');
	});
}

// A deploy killed in the middle of the migrations (Ctrl+C, lost connection) leaves the file and
// the application unavailable - `make deploy-unlock`, which is run after a killed deploy anyway,
// removes it. On all hosts, not once.
task('app:maintenance_off', function () {
	run('rm -f {{maintenance_file}}');
})->hidden();
after('deploy:unlock', 'app:maintenance_off');

// Plan of the migrations for `make deploy`: every line prefixed MIGRATIONS_PLAN>, the makefile
// extracts them from the log and asks for the stages with a MIGRATIONS_PENDING line.
task('app:migrations_plan', function () {
	// output() instead of writeln(): writeln() passes the text through parse() and would take
	// {{...}} in SQL for a variable
	// Both phases, so that the confirmation of the deploy covers what deploy:publish will run too.
	// In the preprod copy of php (app:preprod_up), like the migrations themselves.
	$php = preprodPhp();
	$plan = migrationsPlan('{{release_path}}', migrationsPhase('{{release_path}}', 'expand', $php), $php);
	if (migrationsPhase('{{release_path}}', 'contract', $php) !== null) {
		$plan .= "\n== contract migrations, run by deploy:publish ==\n" . migrationsPlan('{{release_path}}', 'contract', $php);
	}
	foreach (explode("\n", $plan) as $line) {
		output()->writeln('[' . currentHost() . '] MIGRATIONS_PLAN> ' . $line);
	}
})->once()->hidden();

// Output of run() is printed only with -v and more (make deploy uses -vvv). Empty lines are
// dropped: Deployer 8 (Logger::print) discards the rest of a received output block at the first
// empty line - and Doctrine output starts with one. pipefail, so that a make error is not lost
// in grep; `|| true` of grep, so that an empty plan is not an error. MAINTENANCE_BYPASS, so that
// a maintenance file left by a killed deploy does not end the commands and hide the migrations.
// Changes the directory itself, see app:migrations.
// With a phase the dry-run of the console command is the plan and the MIGRATIONS_PENDING line is
// added here, the way the project's makefile does it (migrationsPhasePending(), recipe/maintenance.php).
function migrationsPlan(string $path, ?string $phase, ?string $php): string
{
	$dryRun = $phase === null
		? "cd $path && make --no-print-directory migrations MIGRATIONS_DRY_RUN=1 MAINTENANCE_BYPASS=1" . migrationsApp($php)
		: migrationsCommand($path, $phase, $php) . ' --dry-run 2>&1';
	$plan = run('set -o pipefail; ' . $dryRun . ' | { grep -v "^[[:space:]]*$" || true; }', timeout: 0);

	if ($phase !== null && migrationsPhasePending($plan)) {
		$plan .= "\nMIGRATIONS_PENDING";
	}

	return $plan;
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

// A release failing every request (missing vendor, broken .env, an error in Bootstrap) fails before
// Tracy runs: nothing dedupes the error and it would show only after publish. One request to the
// preprod of every host (the running nginx serves release/www for X-Forwarded-Port: 4434, through
// the preprod copy of app:preprod_up when the nginx config of the project routes it there), after the
// migrations the new code may need; a failure stops the deploy before deploy:pause, so make
// deploy-publish refuses the release. Passes on 2xx, 3xx, 401 and 403 (smokeCheckFailure()).
// false = off, otherwise the path requested - a project whose `/` is not public and answers
// something else than 401/403 points it at a public page.
//
// Every curl error fails, "connection refused" included: the containers run since app:prepare,
// which starts them on the first deploy (dupd up) or waits until somebody does - so nothing
// listening means a wrong NGINX_PORT or a dead nginx container, never "first deploy".
set('smoke_check_path', '/');
task('app:smoke_check', function () {
	$path = get('smoke_check_path');
	if ($path === false || $path === 'false' || empty($_ENV['NGINX_PORT']) || empty($_ENV['PROJECT_URL'])) {
		warning('Smoke check of preprod skipped (smoke_check_path false, or NGINX_PORT / PROJECT_URL not set)');
		return;
	}
	$url = 'http://127.0.0.1:' . $_ENV['NGINX_PORT'] . $path;
	$host = smokeCheckHost($_ENV['PROJECT_URL']);
	// `; echo` - run() throws on a non-zero exit and the exit code of curl is what tells the cases apart
	$result = trim(run('curl -s -o /dev/null -w "%{http_code}" --connect-timeout 10 --max-time 60'
		. ' -H ' . escapeshellarg('Host: ' . $host)
		. ' -H "X-Forwarded-Port: 4434" -H "X-Forwarded-Proto: https"'
		. ' ' . escapeshellarg($url) . '; echo " $?"'));
	[$code, $exit] = array_pad(preg_split('/\s+/', $result), 2, '');
	$skip = 'Skip the check: DEP_ARGS="-o smoke_check_path=false"';
	$failure = smokeCheckFailure($code, $exit);
	if ($failure === 'curl' && $exit === '7') {
		throw error("Nothing listens on $url of {{alias}} - wrong NGINX_PORT, or the nginx container"
			. " {$_ENV['PROJECT_NAME']}_nginx is not running (sudo dupd up). $skip");
	}
	if ($failure === 'curl') {
		throw error("Smoke check of preprod on {{alias}} failed: curl exit $exit (28 = timeout) on $url - see `sudo dupd logs nginx` / `sudo dupd logs php-next` (preprod) or `php` without preprod");
	}
	if ($failure === 'status') {
		throw error("Preprod of {{alias}} answered $code on $path (Host: $host; expected 2xx, 3xx, 401 or 403) - the release is broken,"
			. " see ~/project/shared/log and `sudo dupd logs php-next` (preprod; `php` without preprod). A path that is not public:"
			. " set('smoke_check_path', '/<public page>') in deploy.php. $skip");
	}
	info("preprod $path: $code");
})->hidden();

task('deploy:pause', function () {
	// Mark for app:unlock_own and deploy:publish_check - the deploy finished and keeps the lock
	// only until deploy:publish
	run('rm -f {{deploy_path}}/.dep/deploy.built');
	run('touch {{deploy_path}}/.dep/deploy.paused');
	// the stale time of a paused deploy counts from here
	run('touch -c {{deploy_path}}/.dep/deploy.lock');
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

// A publish in progress: until deploy:symlink the lock still has its deploy.paused mark and
// app:unlock_own of another `make deploy` would take it for a finished deploy and release it - while
// the publish waits for the cron jobs (up to cron_wait_timeout) the `release` symlink would get
// repointed to an unbuilt release and published. Removed with the lock.
//
// The mark protects only together with the lock (app:unlock_own drops the marks when nobody holds
// it), and a publish repeated after a failed one has none - deploy:failed released it. So the
// publish takes the lock when it is free; a lock that exists belongs to the deploy being published
// (deploy.paused is there - a deploy that started meanwhile removed it). Checked once more here, in
// one command with taking the lock: deploy:publish_check ran in another process, a deploy may have
// started since. GracefulShutdownException: deploy:failed must not run and unlock that deploy.
task('app:publish_start', function () {
	$dep = '{{deploy_path}}/.dep';
	$state = run("if [ -f $dep/deploy.paused ] && [ -L {{deploy_path}}/release ]; then "
		. "[ -f $dep/deploy.lock ] || echo " . quote(get('user')) . " > $dep/deploy.lock; "
		. "touch $dep/deploy.publishing; touch -c $dep/deploy.lock; echo +ready; else echo +unfinished; fi");
	if ($state !== '+ready') {
		throw new GracefulShutdownException(parse('Deploy of stage {{stage}} on {{alias}} did not finish (.dep/deploy.paused or the release symlink is missing) - run `make deploy` again.'));
	}
	set('lock_held', true);
})->hidden();

task('app:publish_end', function () {
	run('rm -f {{deploy_path}}/.dep/deploy.publishing');
})->hidden();
after('deploy:unlock', 'app:publish_end');

// `make deploy` ends with deploy:pause and the lock is released by deploy:publish. A repeated
// deploy without publish (a fix before publishing, more stages at once, unconfirmed migrations)
// would fail on "Deploy locked". So the own lock (same `user`) is released, but only when the
// deploy holding it reached the end of deploy:build (deploy.built) or deploy:pause
// (deploy.paused) and is not being published (deploy.publishing). The lock of a running or
// interrupted deploy - even an own one, e.g. from another terminal - and somebody else's lock stay. The marks are deleted so that the next
// running deploy does not inherit them - but only when nobody holds the lock: a locked deploy
// fails on deploy:lock anyway and the marks belong to the lock holder.
//
// A lock of somebody else is removed only when its deploy finished (deploy.paused), is not being
// published (deploy.publishing - a publish killed in the middle needs `make deploy-unlock`) and has
// been idle for lock_stale_minutes - a release nobody published. The age is the time since the last
// activity (withLockHeartbeat, deploy:pause), not since the start: before 1.18 the lock of a
// deploy running migrations or waiting for [y/N] longer than an hour was removed, and the second
// deploy deleted the maintenance file in the middle of the DDL and replaced the `release` symlink.
// The lock of a killed or running deploy stays, `make deploy-unlock` removes it.
task('app:unlock_own', function () {
	$lockFile = '{{deploy_path}}/.dep/deploy.lock';
	$builtFile = '{{deploy_path}}/.dep/deploy.built';
	$pausedFile = '{{deploy_path}}/.dep/deploy.paused';
	$publishingFile = '{{deploy_path}}/.dep/deploy.publishing';
	if (test("[ -f $lockFile ] && [ ! -f $publishingFile ] && { [ -f $builtFile ] || [ -f $pausedFile ]; } && [ \"\$(cat $lockFile)\" = " . quote(get('user')) . " ]")) {
		run("rm -f $lockFile");
		info('removed own deploy lock');
	}
	$staleMinutes = (int) get('lock_stale_minutes');
	if ($staleMinutes > 0 && test("[ -f $lockFile ] && [ -f $pausedFile ] && [ ! -f $publishingFile ] && [ -n \"\$(find $lockFile -mmin +$staleMinutes)\" ]")) {
		$holder = run("cat $lockFile");
		$age = run("echo \$(( (\$(date +%s) - \$(stat -c %Y $lockFile)) / 60 ))");
		run("rm -f $lockFile");
		warning("removed deploy lock of $holder - deploy finished but not published, idle for $age min (lock_stale_minutes: $staleMinutes)");
	}
	if (test("[ -f $lockFile ]")) {
		return;
	}
	run("rm -f $builtFile $pausedFile $publishingFile");
})->hidden();

// Installed on all hosts. Jobs run only on one of them: cronie in cluster mode (crond -c, service
// cron-cluster of server-management-scripts) runs user crontabs only on the leader, so a failover
// takes the jobs over. After deploy:symlink the `release` symlink no longer exists and Deployer 8
// throws on {{release_path}}, publish tasks therefore use {{current_path}}.
// Jobs that must run on every node (local logs, local data cleanup...) go to crontab-all.<stage>
// in the same format: `crontab-all` (adt/docker-binaries, sudoers without a password) writes it to
// /etc/cron.d, which cronie runs on all nodes. The file removed from the project = removed from the
// host. `sudo -n` not at the start of the command: Deployer 8 would ask for a password on failure.
desc('Apply crontab');
task('app:crontab', function () {
	cd('{{current_path}}');

	run('crontab crontab.{{stage}}');
	if (test('[ -f crontab-all.{{stage}} ]')) {
		run('command -v crontab-all >/dev/null 2>&1 || { echo "crontab-all missing on the host - update docker-binaries (server-management-scripts --docker-binaries)" >&2; exit 1; }; sudo -n crontab-all crontab-all.{{stage}}');
	} elseif (test('[ -f /etc/cron.d/crontab-all-$(id -un) ]')) {
		run('sudo -n crontab-all -r');
	}
});

// Cron jobs must not run across the switch of the `current` symlink - a job would start in one
// release and finish in another (README, Cron jobs during publish). It needs a wrapper the jobs of
// the crontab are started through; a project whose installed crontab does not use it is left
// alone - nothing is paused, checked or waited for.

// How long the publish waits for the running cron jobs to finish
set('cron_wait_timeout', 1800);

// The crontab is installed on all hosts and only cronie in cluster mode runs the jobs once.
// `-o cron_cluster_check=false` skips the check (a stage with a single host).
set('cron_cluster_check', true);

// The wrapper, as it appears in the installed crontab
set('cron_wrapper', 'bin/cron');

// Before the switch (in deploy:publish before app:containers_up and deploy:symlink) the jobs are paused
// (.dep/cron.pause, the wrapper waits) and the running ones are waited for (shared lock
// .dep/cron.lock). The crontab is installed on all hosts, so both run everywhere.

// The file holds the budget of the pause in seconds and is refreshed while waiting. When it is
// exceeded (a killed publish), the wrapper resumes the jobs itself.
task('app:cron_pause', function () {
	cronPause((int) get('cron_wait_timeout'), strict: true);
})->hidden();

// The wrapper holds the lock shared while a job runs, the exclusive one is free once all finished.
// Only on a host paused by app:cron_pause.
task('app:cron_wait', function () {
	cronWait((int) get('cron_wait_timeout'), strict: true);
})->hidden();

// The wrapper is looked for in the installed crontab of the account and in its crontab-all file in
// /etc/cron.d (see app:crontab), commented lines aside. Not strict (rollback): what would stop
// a publish is only a warning.
function cronPause(int $timeout, bool $strict): void
{
	$wrapper = quote(get('cron_wrapper') . ' ');
	$state = explode("\n", run("crontab -l 2>/dev/null | grep -v '^[[:space:]]*#' | grep -q $wrapper && echo wrapper; "
		. "grep -v '^[[:space:]]*#' /etc/cron.d/crontab-all-\$(id -un) 2>/dev/null | grep -q $wrapper && echo wrapper-all; "
		. "hash flock crontab 2>/dev/null && echo tools-ok; crontab -c 2>/dev/null | grep -q . && echo cluster; true"));
	if (!in_array('wrapper', $state, true) && !in_array('wrapper-all', $state, true)) {
		return;
	}

	if (!in_array('tools-ok', $state, true)) {
		if ($strict) {
			throw error('flock or crontab is not available on {{alias}}.');
		}
		warning('flock or crontab is not available on {{alias}} - cron jobs are not paused');

		return;
	}

	// Only the crontab of the account depends on the cluster mode, crontab-all jobs run on every
	// host on purpose
	if (in_array('wrapper', $state, true) && !in_array('cluster', $state, true) && filter_var(get('cron_cluster_check'), FILTER_VALIDATE_BOOLEAN)) {
		$message = 'cronie cluster mode is not reported by `crontab -c` on {{alias}}, with the crontab installed on every host the jobs would run more than once.';
		if ($strict) {
			throw error("$message Use -o cron_cluster_check=false to skip the check.");
		}
		warning($message);
	}

	run('echo ' . ($timeout + 600) . ' > {{deploy_path}}/.dep/cron.pause');
}

function cronWait(int $timeout, bool $strict): void
{
	if (!test('[ -f {{deploy_path}}/.dep/cron.pause ]')) {
		return;
	}

	writeln("Waiting up to $timeout s for running cron jobs to finish.");
	try {
		// The heartbeat: the wait may be longer than lock_stale_minutes
		run(withLockHeartbeat("touch {{deploy_path}}/.dep/cron.pause && flock -E 124 -x -w $timeout {{deploy_path}}/.dep/cron.lock touch {{deploy_path}}/.dep/cron.pause"), timeout: $timeout + 60);
	} catch (RunException $e) {
		if ($e->getExitCode() !== 124) {
			throw $e;
		}
		if ($strict) {
			throw error("Cron jobs did not finish within $timeout seconds.");
		}
		warning("Cron jobs on {{alias}} did not finish within $timeout seconds - going on, they finish in the release they did not start in");
	}
}

desc('Resume cron jobs paused by a publish');
task('app:cron_resume', function () {
	run('rm -f {{deploy_path}}/.dep/cron.pause');
});

// The pause ends with the release of the deploy lock: at the end of deploy:publish
// (after app:crontab, so that the resumed jobs start from the crontab of the new release), after
// a failed publish (deploy:failed -> app:unlock_failed) and with `make deploy-unlock`. Not through
// fail() of the publish - another fail() would replace it.
after('deploy:unlock', 'app:cron_resume');

// A rollback switches `current` the same way as a publish and needs the crontab of the release it
// switched to. It is run when the live release is broken, so the cron jobs must not stop it: they
// are waited for only shortly and a job that does not finish (maybe hung because of the release),
// a missing tool or the cluster check are a warning, not an error.
set('cron_rollback_wait_timeout', 60);

// Any failure, not only the expected ones: a leftover cron.pause on a host without flock, a lock
// file that cannot be opened, a crontab that cannot be read ...
task('app:cron_pause_rollback', function () {
	try {
		cronPause((int) get('cron_rollback_wait_timeout'), strict: false);
	} catch (\Throwable $e) {
		warning('Cron jobs on {{alias}} not paused, the rollback goes on: ' . $e->getMessage());
	}
})->hidden();

task('app:cron_wait_rollback', function () {
	try {
		cronWait((int) get('cron_rollback_wait_timeout'), strict: false);
	} catch (\Throwable $e) {
		warning('Cron jobs on {{alias}} not waited for, the rollback goes on: ' . $e->getMessage());
	}
})->hidden();

task('app:cron_quiesce_rollback', ['app:cron_pause_rollback', 'app:cron_wait_rollback'])->hidden();
before('rollback', 'app:cron_quiesce_rollback');
after('rollback', 'app:crontab');
after('rollback', 'app:cron_resume');
fail('rollback', 'app:cron_resume');

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
	if (!get('lock_held')) {
		return;
	}
	run('rm -rf {{project_root}}/release_*_temp');
})->hidden();

// Whether this run holds the deploy lock: set after deploy:lock and by app:publish_start (the host
// config is carried from task to task by the main process). A run that failed before it - an SSH
// error in deploy:setup, app:unlock_own - owns nothing on the host: the lock, the cron pause, the
// publishing mark, the maintenance file and the preprod copy there belong to somebody else's
// deploy or publish, maybe a running one, and deploy:failed must leave them alone.
set('lock_held', false);
task('app:lock_held', function () {
	set('lock_held', true);
})->hidden();
after('deploy:lock', 'app:lock_held');

// deploy:unlock with the tasks hooked after it (after('deploy:unlock', ...)) - invoke() does not
// run hooks, so they are listed here too
task('app:unlock_failed', function () {
	if (!get('lock_held')) {
		warning('{{alias}}: failed before taking the deploy lock - the lock on the host (if any) is not released');
		return;
	}
	invoke('deploy:unlock');
	invoke('app:maintenance_off');
	invoke('app:publish_end');
	invoke('app:cron_resume');
})->hidden();

// The fail hook is looked up by the name of the started task, `make deploy` runs
// deploy:build/deploy:finish. Not for deploy:finish: deploy:failed there would release the lock
// of a deploy that has not finished deploy:build (e.g. is running right now). The lock after
// failed migrations is released by the next deploy (deploy.built mark).
fail('deploy:build', 'deploy:failed');
fail('deploy:publish', 'deploy:failed');
after('deploy:failed', 'app:clean_temp');
after('deploy:failed', 'app:preprod_down');
after('deploy:failed', 'app:unlock_failed');
