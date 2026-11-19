## Usage in a project

```sh
composer require adt/docker-deployer
```

That provides `vendor/bin/dep`, a wrapper running Deployer from the image
against the current directory. It mounts the project, the ssh agent socket
(on macOS `/run/host-services/ssh-auth.sock` of Docker Desktop) and only
`~/.ssh/config` and `~/.ssh/known_hosts`, read-only. Private keys stay out of
the container (it runs `deploy.php` and code from the project's `vendor/` as
root) as long as the agent holds a key - add it once:

```sh
ssh-add --apple-use-keychain ~/.ssh/id_ed25519   # macOS
```

To keep it in the agent after a restart, add to `~/.ssh/config` (`IgnoreUnknown` is needed:
the config is mounted into the Linux container, whose OpenSSH rejects `UseKeychain`):

```
Host *
    IgnoreUnknown UseKeychain
    UseKeychain yes
    AddKeysToAgent yes
```

Without a key in the agent the wrapper mounts **one** key file, read-only, and warns:
`DEP_SSH_KEY` if set, otherwise `~/.ssh/id_ed25519` (where adt/ci writes the deploy key). This
happens in CI (`CI=true`) or when `DEP_SSH_KEY` is set explicitly; anywhere else `dep` fails and
asks to add the key to the agent - before 1.19 it mounted every `~/.ssh/id_*` into the container
running as root. A key with another name than the OpenSSH defaults (`id_ed25519`, `id_rsa`, ...)
also needs `IdentityFile ~/.ssh/<name>` in `~/.ssh/config` (mounted, `~` = `/root` in the container).

The agent is **not forwarded** to the servers: the server clones the repository with the key of
its own account (`~/.ssh/id_ed25519` of the project user), which must be a read-only deploy key of
the repository - `app-ci.sh` of server-management-scripts adds it. With a forwarded agent anyone
with root or the project account on the host could sign with the developer's key for the whole
deploy. Off means `ssh -o ForwardAgent=no`, so `ForwardAgent yes` in the developer's
`~/.ssh/config` (mounted into the container) does not forward it either - before 1.20 the recipe
only left out `-A` and such a config still forwarded the agent. A stage whose account has no
deploy key yet sets `DEPLOY_FORWARD_AGENT=1` in `config/.env.<stage>` until it gets one. Only `1`, `true`, `yes`, `on` (case-insensitive, quotes
stripped) turn it on - before 1.19 any non-empty value did, `false` and `0` included. The same
parsing applies to `-o migrations_auto_confirm=`.

Deployer 6 is used by default. To deploy with a newer one, set the image tag in
the project's `.env` - rewrite `deploy.php` for that generation first, neither
7.x nor 8.x is backwards compatible with 6.x:

```
DEPLOYER_VERSION=8
```

## Shared recipe (Deployer 8)

`recipe/default.php` is the deploy of AppsDevTeam projects created from the
sandbox (Docker, read-only code on the server, two-step deploy with preprod).
A project's `deploy.php` just requires it and adds its own settings and tasks
after it:

```php
<?php
namespace Deployer;

require __DIR__ . '/project/vendor/adt/docker-deployer/recipe/default.php';

set('shared_dirs', [...get('shared_dirs'), 'www/upload']);
```

Another company with a different server layout forks the recipe rather than
configuring it.

### Running

Always through the make targets of `make/deploy.mk`, which the project's root
makefile includes (`-include`, before `composer install` there is no vendor):

```make
-include project/vendor/adt/docker-deployer/make/deploy.mk
```

| Target | |
|---|---|
| `make deploy -- <stage> [<stage> ...]` | `deploy:build` of all stages in parallel (logs in `log/deploy/`), the pending migrations of all stages, one `[y/N]` (`DEP_YES=1` skips it), `deploy:finish` |
| `make deploy-publish -- <stage> ...` | `deploy:publish_check` of all stages, then `deploy:publish` - nothing is published unless every stage is ready |
| `make deploy-unlock -- <stage> ...` | unlock after a failed deploy |

`make deploy` keeps the lock until `make deploy-publish` (the release waits on
port 4434). The next `make deploy` releases its own finished lock, and a lock of
somebody else only when that deploy finished (`deploy.paused`) and has been idle
for `lock_stale_minutes` (default 60, `0` = never) - a release nobody published.
The idle time counts from the last activity: long commands (build, migrations)
renew the lock every minute and `deploy:pause` once more, so migrations running
for hours or a deploy waiting for `[y/N]` keep their lock (before 1.18 a second
deploy removed it after an hour, then the maintenance file in the middle of the
DDL). The lock of a running or killed deploy stays - `make deploy-unlock`.

After the migrations `deploy:finish` requests the preprod of every host (`curl` on
`127.0.0.1:$NGINX_PORT` with `Host:` the first domain of `PROJECT_URL` (a comma-separated list of
several, as the TLS certificate of adt/make takes it - since 1.26, before the whole list went into the
header and a multi-tenant front controller answered 404) and `X-Forwarded-Port: 4434`, so the running
nginx serves `release/www`). It passes on 2xx, 3xx, 401 and 403 (a homepage behind a login or
an IP allowlist still proves the release boots); any other status - 404 included, since 1.20,
before only 5xx - fails the deploy before `deploy:pause`, so a release that fails every request
(missing vendor, broken `.env`, an error in Bootstrap - before Tracy, nothing dedupes it) cannot be
published (`recipe/smoke_check.php`, test `php tests/smoke_check.php`). Every curl error fails too: connection refused (wrong `NGINX_PORT`, nginx container
down), timeout (exit 28), empty reply, reset ... The containers always run by then - `app:prepare`
starts them on the first deploy (or waits until somebody does) - so "nothing listens" is never
expected; up to 1.19 a refused connection was meant to only warn on the first deploy, but the
`current` it checked for already existed (`deploy:temp_release_clone` creates it), so it never did.
`set('smoke_check_path', '/login');` in `deploy.php` changes the path (a project whose `/` answers
e.g. 404 points it at a public page), `false` turns it off; for a single deploy `DEP_ARGS="-o smoke_check_path=false"`.

Options of `dep` go after the stages (`make deploy -- main --plan`), options
with a value through `DEP_ARGS="-o release_name=15"`. The file expects
`RUN_ARGS` (arguments after `--`) from [adt/make](https://github.com/AppsDevTeam/make)
and sets `DEPLOYER_VERSION ?= 8.0.5`. The plan of migrations is read from the
log with `LC_ALL=C`: Deployer splits the remote output in chunks, a multibyte
character can end up split between two lines and macOS sed in a UTF-8 locale
fails on it - before 1.5 the plan then looked empty and the migrations ran
without asking.

Under the hood (`DEPLOYER_VERSION=8.0.5`):

```sh
dep deploy:build -o stage=main     # code, `dupd build`, `make _prepare`, `dupd preprod start php`, plan of migrations
dep deploy:finish -o stage=main    # migrations once per stage, smoke check of preprod, deploy:pause
dep deploy:publish_check -o stage=main
dep deploy:publish -o stage=main   # `dupd up`, symlink, crontab, reload-consumers, cleanup
```

Containers: with `dupd` of [adt/docker-binaries](https://git.appsdevteam.com/adt/docker-binaries)
on the host (root-owned, in sudoers without a password), `deploy:build` builds
the images of the services with `build:` (`sudo dupd build` in `app:build_images`, after the
shared dirs exist - the validation refuses a missing bind source) and
`deploy:publish` applies the deployed `docker/` right before the symlink switch
(`sudo dupd up` - `docker compose up` that recreates only the services whose
image or configuration changed). The first deploy starts the containers in
`app:prepare`. dupd validates the compose configuration first (no privileged,
host namespaces, capabilities, foreign mounts, networks or image names; services
with bind mounts run under the UID of the account with no-new-privileges), so
nobody has to run `make docker-upd` as root anymore. A host without dupd keeps
the old behaviour: the containers are started and recreated by root by hand.

Preprod on the new image (since 1.21, dupd of docker-binaries >= 3.22): after
`app:prepare` the recipe starts `php-next`, a copy of the `php` service from the
image just built (`sudo dupd preprod start php`, `set('preprod_service', ...)`,
`false` = off), next to the live `php` that keeps the previous image until
`dupd up` in `deploy:publish` (it removes the copy). The console commands of the
release - the plan of migrations, the migrations, `migrations-kill-sessions` -
run in it (`APP="sudo -E de php-next php bin/console"` for make) and the nginx of
the project sends the preprod there (`include/root` of the sandbox: `$fpm` is
`php-next:9000` for `X-Forwarded-Port: 4434`), so the smoke check and the manual
check on port 4434 run on the runtime the release goes public with. Before, a
release needing a new extension or PHP version ran its migrations in the old
container (`could not find driver` on the first deploy over a stack started
before dupd) and only root could get it out. `deploy:failed` removes the copy,
an unpublished one is replaced by the next deploy. dupd without `preprod` keeps
the previous behaviour.

Since 1.22 the extensions of the release are checked in the copy right after it
starts (`make check-extensions CHECK_EXTENSIONS_PHP="sudo -E de php-next php"`,
adt/make >= 1.9.5; older adt/make only in the publish), and a copy that should
run but does not (crashed, host restarted during the pause - it has `restart: no`)
fails the deploy instead of running the migrations in the live container. Its
log: `sudo dupd logs php-next` (docker-binaries >= 3.22.1).

The stage is an option, not a host selector - Deployer 8 passes options to its
worker processes, the selector it does not. Without `-o stage` (`dep list`,
`dep tree deploy`, which reject `-o`) only the tasks are defined.
`-o migrations_auto_confirm=true` runs migrations without asking (the makefile
asks itself, using the `MIGRATIONS_PLAN>` lines of `deploy:build`).

### What the project provides

Env (`config/.env.skeleton`, `config/.env.common`, `config/.env.<stage>`, `config/docker/.env.<stage>`):

| Variable | |
|---|---|
| `PROJECT_NAME` | remote user on the hosts |
| `STAGE` | stage label |
| `BRANCH` | deployed branch; its tracked remote in `.git/config` is the repository (fallback `origin`) |
| `DEPLOY_HOSTS` | comma-separated hosts of the stage, migrations run on the first one |
| `DEPLOY_JUMP` | optional `ci-<repo>@<host>` for a stage behind a jump host (VMs behind a host); read by the CI component adt/ci >= 2.7, which writes `ProxyJump` for every `DEPLOY_HOSTS` entry into `~/.ssh/config` (`bin/dep` mounts it). Not used by the recipe itself |
| `GLUSTERFS_APP_ROOT` | optional root of `data/` and `www/data/` shared by all hosts; empty = `shared/` on each host |

Makefile targets:

| Target | Where | |
|---|---|---|
| `secrets-decrypt SECRETS_STAGE=<stage>`, `_env STAGE=<stage>` | root | decrypt and assemble `.env` files in the temp clone |
| `_prepare` | `project/` | `checkout` without migrations (clear, install, build); run with `CHECK_EXTENSIONS=0` |
| `check-extensions` | `project/` | in `deploy:build` in the preprod copy (`CHECK_EXTENSIONS_PHP`, adt/make >= 1.9.5) and in `deploy:publish` after `dupd up`, before the symlink: the new runtime has every `ext-*` of the release |
| `migrations` | `project/` | `MIGRATIONS_DRY_RUN=1` prints the plan and a `MIGRATIONS_PENDING` line, `MIGRATIONS_AUTO_CONFIRM=1` runs them; empty target without a DB |
| `reload-consumers` | `project/` | after publish and when the maintenance starts; empty target without consumers |
| `migrations-kill-sessions` | `project/` | optional, see below |

Files and servers:

- `project/crontab.<stage>` - installed on **all** hosts after publish; cronie
  cluster mode (`cron-cluster` in server-management-scripts) runs it only on
  the leader, so it must be deployed on the hosts first.
- `project/crontab-all.<stage>` (optional, same format) - jobs that must run on
  **every** host (local logs, local data cleanup): installed through
  `sudo crontab-all` of adt/docker-binaries into `/etc/cron.d`, which cronie
  runs on all nodes. Removing the file from the project removes it from the hosts.
- `shared_dirs` = `log`, `data`, `www/data`; the compose override mounts
  `${GLUSTERFS_APP_ROOT:-${PROJECT_ROOT}/project/shared}/{data,www/data}`.
- `temp/` is per release under `shared/temp/releases/<N>`.
- A deployment created by Deployer 6 is converted by `deploy:v6_compat`
  automatically.
- Long commands (`make _prepare`, migrations) have no timeout.

### Maintenance during migrations

DDL on a Galera cluster conflicts with the metadata locks of the running
application - even a plain `SELECT` in an open transaction holds one. An
`ALTER TABLE ... ADD FOREIGN KEY` on a table in use crashed a node
(mysql-wsrep 8.0.43, `wsrep_handle_mdl_conflict`). Every DDL takes the lock,
`ALGORITHM=INSTANT` included (it just holds it shortly), and the crash happened
while acquiring it.

The window is used only when the plan touches the structure of existing
tables (`recipe/maintenance.php`, test `php tests/maintenance.php`): pending
migrations made only of DML (`INSERT`, `UPDATE`, `DELETE`, `REPLACE`,
`SELECT`, `SET`, `DO`) and `CREATE TABLE` without a foreign key run without
it. Anything else needs it, and so does a migration with no SQL in the plan
(logic in PHP calling the connection directly - the dry-run does not show
it) or a plan without the migration blocks at all. `DO SLEEP(...)` is the
test migration of the window and always needs it. The plan is the
Doctrine Migrations dry-run with `-vv` (`++ migrating`, `[debug] <sql>`,
`Migration ... migrated`).

When the window is needed, `app:migrations`:

1. creates `~/project/maintenance` on **all** hosts of the stage (inside the
   containers `/var/www/html/maintenance`),
2. runs `make reload-consumers` on all hosts, so that the consumers restart
   and start waiting,
3. runs `make migrations-kill-sessions MIGRATIONS_KILL_WAIT=<migrations_kill_wait>`
   (default 60) when the project has the target,
4. runs the migrations,
5. removes the file on all hosts - also when the migrations fail.

All deploy commands get `MAINTENANCE_BYPASS=1` as a make argument (a variable
from the included `.env` would override the environment). A maintenance file
found at the start of `app:migrations` is a leftover of a killed deploy and
gets removed. When a deploy is killed in the middle, `make deploy-unlock`
removes it (`deploy:unlock` on all hosts).

What the project does with it (nothing happens in a project that ignores the
file) - implemented by `handleMaintenance()` of
[adt/configurator](https://github.com/AppsDevTeam/Configurator) and
`dbal:kill-sessions` of
[adt/doctrine-components](https://github.com/AppsDevTeam/doctrine-components):

- web: `503` with `Retry-After`
- console: ends right away with exit code 0 (cron jobs skip a run), except
  the consumers, which wait until the file is gone - supervisor would restart
  a consumer that exits in a loop
- `MAINTENANCE_BYPASS=1` skips the check; it has to be in the project's
  `.env` (empty), `de` passes only the variables listed there
- `migrations-kill-sessions` first waits up to `MIGRATIONS_KILL_WAIT` seconds
  until the sessions of the application's DB user are idle (a consumer
  finishing its job), then kills them (idle connections of the ProxySQL pool,
  a job that did not finish in time). `KILL` sent through ProxySQL does not
  reach the backend (ProxySQL takes it for its own session id), so the
  command must connect to the DB node directly - on Galera the writer is
  `@@wsrep_node_address` + `@@port`, asked through the proxy - and kill in
  rounds, ProxySQL retries a killed autocommit statement on a new connection
  (`mysql-query_retries_on_failure`). All of it is `dbal:kill-sessions --wait`
  of adt/doctrine-components.

Sessions of other DB users (people with their own accounts, backups) are not
covered.

### Expand and contract migrations

The maintenance window ends with the migrations; until `deploy:publish` (and
after a rollback) the old code of `current` keeps running on the migrated
schema. A migration that removes what the old code still uses (`DROP` of
a column or table, a rename, `NOT NULL`) therefore belongs to a contract
migration of adt/doctrine-components (`expandContract: true`, README
*Expand and contract migrations*): `migrations:migrate` defers it until its
code is the running release, i.e. to the next deploy. The plan is the
dry-run of the same command - it fails when an expand migration contains
such a change, so `deploy:build` stops with the reason before anything runs.
The recipe needs nothing for it.

## AppsDevTeam images

Published as `appsdevteam/deployer` for `linux/amd64` and `linux/arm64`. Each
Deployer generation gets the PHP its own era shipped with - 8.x requires PHP
>= 8.3, so one base does not fit all:

| Tag | Deployer | PHP |
|---|---|---|
| `latest`, `6`, `6.9.0` | 6.9.0 (the last 6.x) | 8.2 |
| `7`, `7.5.12` | 7.5.12 | 8.2 |
| `8`, `8.0.5` | 8.0.5 | 8.4 |

Both versions are build arguments, so all the images come from this one
Dockerfile:

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
    --build-arg DEPLOYER_VERSION=6.9.0 --build-arg PHP_VERSION=8.2 \
    -t appsdevteam/deployer:latest -t appsdevteam/deployer:6 \
    -t appsdevteam/deployer:6.9.0 --push .

docker buildx build --platform linux/amd64,linux/arm64 \
    --build-arg DEPLOYER_VERSION=7.5.12 --build-arg PHP_VERSION=8.2 \
    -t appsdevteam/deployer:7 -t appsdevteam/deployer:7.5.12 --push .

docker buildx build --platform linux/amd64,linux/arm64 \
    --build-arg DEPLOYER_VERSION=8.0.5 --build-arg PHP_VERSION=8.4 \
    -t appsdevteam/deployer:8 -t appsdevteam/deployer:8.0.5 --push .
```

`latest` stays on 6.x: neither 7.x nor 8.x is backwards compatible with a 6.x
`deploy.php`, so projects opt in per project (see above).

## What is Deployer?

Deployer is a deployment tool written in PHP.

> http://deployer.org/

## How to use this image

Basic usage.

```sh
docker run --rm \
    -v /local/path:/project \
    herloct/deployer[:tag] [<options>]
```

For example, to deploy to default server.

```sh
docker run --rm \
    -v /local/path:/project \
    herloct/deployer deploy
```

## Volumes

* `/project`: Your deployment scripts project.
* `/root/.ssh`: Your SSH file(s).

## Credits

Originally based on [herloct/docker-deployer](https://github.com/herloct/docker-deployer) (MIT).
