FROM gbxyz/openswoole:php84-noble

RUN apt install php-redis

WORKDIR /app

COPY . .

RUN composer --quiet install

RUN rm -rf composer.json composer.lock
