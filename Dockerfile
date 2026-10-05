FROM php:8.4-apache

RUN docker-php-ext-install opcache \
    && a2enmod rewrite headers expires

WORKDIR /var/www/html
COPY . /var/www/html/

# Keep a pristine copy of existing persistent data so the first Fly.io volume boot
# can seed the volume without deleting or overwriting existing data on later boots.
RUN mkdir -p /opt/united-seed \
    && cp /var/www/html/users.json /opt/united-seed/users.json \
    && cp /var/www/html/posts.json /opt/united-seed/posts.json \
    && cp -a /var/www/html/storage /opt/united-seed/storage \
    && mkdir -p /var/www/html/uploads && cp -a /var/www/html/uploads /opt/united-seed/uploads

COPY fly-entrypoint.sh /usr/local/bin/fly-entrypoint.sh
RUN chmod +x /usr/local/bin/fly-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

ENV APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/fly-entrypoint.sh"]
