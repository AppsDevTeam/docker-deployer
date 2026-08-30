FROM php:8.5-cli

LABEL maintainer="herloct <herloct@gmail.com>"

ENV DEPLOYER_VERSION=6.8.0

RUN apt-get update && apt-get install -y openssh-client rsync \
    && rm -rf /var/lib/apt/lists/*

RUN curl -L https://deployer.org/releases/v$DEPLOYER_VERSION/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

VOLUME ["/project", "$HOME/.ssh"]
WORKDIR /project

COPY docker-entrypoint.sh /bin/docker-entrypoint.sh
RUN chmod +x /bin/docker-entrypoint.sh
ENTRYPOINT ["/bin/docker-entrypoint.sh"]
CMD ["--version"]
