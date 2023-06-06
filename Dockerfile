# syntax=docker/dockerfile:1.3-labs
# @see https://docs.docker.com/engine/reference/builder/

FROM ubuntu:latest

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV DEBIAN_FRONTEND=noninteractive

#
# install PHP runtime
#
RUN <<END

apt-get update -qqq
apt-get install -qqq software-properties-common
add-apt-repository ppa:ondrej/php
apt-get upgrade -qqq
apt-get install -qqq \
        php8.2 php8.2-opcache php-cli php-bcmath php-bz2 php-curl \
        php-dev php-intl php-mbstring php-memcache php-mysql \
		php-xml php-yaml php-pear php-gmp jq composer \
        libcurl4-openssl-dev

END

#
# install swoole
#
RUN <<END

(yes yes | head -5 ; echo no) | pecl install openswoole

echo "extension=openswoole.so" > \
	/etc/php/8.2/cli/conf.d/99-openswoole.ini

END

WORKDIR /app

ADD https://api.github.com/repos/gbxyz/rdap-bootstrap-server/commits?per_page=1 /tmp/commits.js

RUN git clone --depth 1 --branch main --single-branch "https://github.com/gbxyz/rdap-bootstrap-server.git" .

RUN <<END

apt-get clean
apt-get autoclean
find / -maxdepth 1 -type d -empty ! -name tmp -delete
unlink /tmp/commits.js

END
