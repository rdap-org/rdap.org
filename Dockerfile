FROM gbxyz/openswoole:php85-noble

RUN apt -qqq --allow-releaseinfo-change update

RUN apt -qqq install php8.5-redis

WORKDIR /app

COPY . .

RUN composer --quiet install

RUN rm -rf composer.json composer.lock
