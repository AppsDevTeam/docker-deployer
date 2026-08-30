# Each Deployer generation needs a PHP of its own era - 8.x requires PHP >= 8.3,
# while 6.x and 7.x are from the PHP 8.0-8.2 days. See README for the tag matrix.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

LABEL maintainer="Apps Dev Team <hello@appsdevteam.com>"

ARG DEPLOYER_VERSION=6.9.0
ENV DEPLOYER_VERSION=$DEPLOYER_VERSION

RUN apt-get update && apt-get install -y openssh-client rsync \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fL https://deployer.org/releases/v$DEPLOYER_VERSION/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

VOLUME ["/project", "/root/.ssh"]
WORKDIR /project

COPY docker-entrypoint.sh /bin/docker-entrypoint.sh
RUN chmod +x /bin/docker-entrypoint.sh
ENTRYPOINT ["/bin/docker-entrypoint.sh"]
CMD ["--version"]
