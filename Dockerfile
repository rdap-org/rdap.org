FROM gbxyz/openswoole:php85-resolute

RUN apt -qqq install php8.5-redis

WORKDIR /app

COPY . .

RUN composer --quiet install

RUN rm -rf composer.json composer.lock
