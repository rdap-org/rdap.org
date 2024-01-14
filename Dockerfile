FROM gbxyz/openswoole:php82

RUN apt-get -qqq update
RUN apt-get -qqq install cpanminus libxml2-dev libz-dev
RUN cpanm --quiet --notest Data::Mirror DateTime Object::Anon LWP::Protocol::https JSON

WORKDIR /app

ADD https://api.github.com/repos/gbxyz/rdap-bootstrap-server/commits?per_page=1 /tmp/commits.js

RUN git clone --depth 1 --branch main --single-branch "https://github.com/gbxyz/rdap-bootstrap-server.git" .
