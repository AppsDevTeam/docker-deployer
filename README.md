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
