FROM gbxyz/openswoole:php84-noble

RUN apt -qqq update

RUN apt -qqq install php8.4-redis

WORKDIR /app

COPY . .

RUN composer --quiet install

RUN rm -rf composer.json composer.lock
