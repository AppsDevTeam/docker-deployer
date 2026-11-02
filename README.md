## Usage in a project

```sh
composer require adt/docker-deployer
```

That provides `vendor/bin/dep`, a wrapper running Deployer from the image
against the current directory (it mounts the project, `~/.ssh`, `~/.vault` and
the ssh agent socket).

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

Always through the project's makefile with `DEPLOYER_VERSION=8.0.5`:

```sh
dep deploy:build -o stage=main     # code, `make _prepare`, plan of migrations
dep deploy:finish -o stage=main    # migrations once per stage, deploy:pause
dep deploy:publish_check -o stage=main
dep deploy:publish -o stage=main   # symlink, crontab, reload-consumers, cleanup
```

The stage is an option, not a host selector - Deployer 8 passes options to its
worker processes, the selector it does not. Without `-o stage` (`dep list`,
`dep tree deploy`, which reject `-o`) only the tasks are defined.
`-o migrations_auto_confirm=true` runs migrations without asking (the makefile
asks itself, using the `MIGRATIONS_PLAN>` lines of `deploy:build`).

### What the project provides

Env (`config/.env.common`, `config/.env.<stage>`, `config/docker/.env.<stage>`):

| Variable | |
|---|---|
| `PROJECT_NAME` | remote user on the hosts |
| `STAGE` | stage label |
| `BRANCH` | deployed branch; its tracked remote in `.git/config` is the repository (fallback `origin`) |
| `DEPLOY_HOSTS` | comma-separated hosts of the stage, migrations run on the first one |
| `GLUSTERFS_APP_ROOT` | optional root of `data/` and `www/data/` shared by all hosts; empty = `shared/` on each host |

Makefile targets:

| Target | Where | |
|---|---|---|
| `secrets-decrypt SECRETS_STAGE=<stage>`, `_env STAGE=<stage>` | root | decrypt and assemble `.env` files in the temp clone |
| `_prepare` | `project/` | `checkout` without migrations (clear, install, build) |
| `migrations` | `project/` | `MIGRATIONS_DRY_RUN=1` prints the plan and a `MIGRATIONS_PENDING` line, `MIGRATIONS_AUTO_CONFIRM=1` runs them; empty target without a DB |
| `reload-consumers` | `project/` | after publish; empty target without consumers |

Files and servers:

- `project/crontab.<stage>` - installed on **all** hosts after publish; cronie
  cluster mode (`cron-cluster` in server-management-scripts) runs it only on
  the leader, so it must be deployed on the hosts first.
- `shared_dirs` = `log`, `data`, `www/data`; the compose override mounts
  `${GLUSTERFS_APP_ROOT:-${PROJECT_ROOT}/project/shared}/{data,www/data}`.
- `temp/` is per release under `shared/temp/releases/<N>`.
- A deployment created by Deployer 6 is converted by `deploy:v6_compat`
  automatically.
- Long commands (`make _prepare`, migrations) have no timeout.

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
