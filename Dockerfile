FROM gbxyz/ubuntu-latest-php-82-openswoole:main

WORKDIR /app

ADD https://api.github.com/repos/gbxyz/rdap-bootstrap-server/commits?per_page=1 /tmp/commits.js

RUN git clone --depth 1 --branch main --single-branch "https://github.com/gbxyz/rdap-bootstrap-server.git" .
