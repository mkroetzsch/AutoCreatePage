ARG MW_VERSION=1.35
FROM gesinn/docker-mediawiki-sqlite:${MW_VERSION}

ENV EXTENSION=AutoCreatePage
COPY composer*.json /var/www/html/extensions/$EXTENSION/

RUN cd extensions/$EXTENSION && \
    composer update

COPY . /var/www/html/extensions/$EXTENSION

RUN sed -i s/80/8080/g /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && \
    echo \
        "wfLoadExtension( '$EXTENSION' );\n" \
    >> LocalSettings.php
