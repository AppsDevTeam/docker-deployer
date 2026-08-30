FROM php:8.2-cli

LABEL maintainer="herloct <herloct@gmail.com>"

# Build the Deployer 7 image with: --build-arg DEPLOYER_VERSION=7.5.12
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
