FROM gbxyz/openswoole:php84-noble

WORKDIR /app

COPY . .

RUN composer --quiet install

RUN rm -rf composer.json composer.lock
