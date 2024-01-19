FROM gbxyz/openswoole:php82

RUN apt-get -qqq update

RUN apt-get -qqq install cpanminus libxml2-dev libz-dev \
    libdatetime-perl liblwp-protocol-https-perl \
    libjson-perl libyaml-libyaml-perl libtext-csv-xs-perl \
    libxml-libxml-perl

RUN cpanm --quiet --notest Data::Mirror Object::Anon

WORKDIR /app

ADD https://api.github.com/repos/gbxyz/rdap-bootstrap-server/commits?per_page=1 /tmp/commits.js

RUN git clone --depth 1 --branch main --single-branch "https://github.com/gbxyz/rdap-bootstrap-server.git" .
