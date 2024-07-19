FROM gbxyz/openswoole:php82

WORKDIR /app

ADD https://api.github.com/repos/rdap-org/rdap.org/commits?per_page=1 /tmp/commits.js

RUN git clone --depth 1 --branch main --single-branch "https://github.com/rdap-org/rdap.org.git" .
