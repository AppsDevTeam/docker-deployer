[![license](https://img.shields.io/github/license/herloct/docker-deployer.svg)]()
[![Build Status](https://travis-ci.org/herloct/docker-deployer.svg?branch=master)](https://travis-ci.org/herloct/docker-deployer)

## Usage in a project

```sh
composer require adt/docker-deployer
```

That provides `vendor/bin/dep`, a wrapper running Deployer from the image
against the current directory (it mounts the project, `~/.ssh`, `~/.vault` and
the ssh agent socket).

Deployer 6 is used by default. To deploy with Deployer 7, set the image tag in
the project's `.env` - `deploy.php` has to be rewritten for 7.x first, its API
is not backwards compatible with 6.x:

```
DEPLOYER_VERSION=7
```

## AppsDevTeam images

Published as `appsdevteam/deployer` for `linux/amd64` and `linux/arm64`:

| Tag | Deployer | Base |
|---|---|---|
| `latest`, `6.9.0` | 6.9.0 (the last 6.x) | `php:8.2-cli` |
| `7`, `7.5.12` | 7.5.12 | `php:8.2-cli` |

The Deployer version is a build argument, so both images come from this one
Dockerfile:

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
    -t appsdevteam/deployer:latest -t appsdevteam/deployer:6.9.0 --push .

docker buildx build --platform linux/amd64,linux/arm64 \
    --build-arg DEPLOYER_VERSION=7.5.12 \
    -t appsdevteam/deployer:7 -t appsdevteam/deployer:7.5.12 --push .
```

Note that Deployer 7 is not backwards compatible with 6.x deploy scripts, so
projects opt in by switching to the `7` tag.

## Upstream: supported tags and respective `Dockerfile` links

* [`6.3.0`, `latest`](https://github.com/herloct/docker-deployer/blob/6.3.0/Dockerfile)
* [`5.0.3`](https://github.com/herloct/docker-deployer/blob/5.0.3/Dockerfile)
* [`5.0.2`](https://github.com/herloct/docker-deployer/blob/5.0.2/Dockerfile)
* [`5.0.1`](https://github.com/herloct/docker-deployer/blob/5.0.1/Dockerfile)
* [`5.0.0`](https://github.com/herloct/docker-deployer/blob/5.0.0/Dockerfile)
* [`4.3.0`](https://github.com/herloct/docker-deployer/blob/4.3.0/Dockerfile)
* [`4.2.1`](https://github.com/herloct/docker-deployer/blob/4.2.1/Dockerfile)

> Version 6.3.0 and up are based on PHP 7.2 image

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
