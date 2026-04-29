FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron default-mysql-client python3 \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers proxy proxy_http \
    && rm -rf /var/lib/apt/lists/*

COPY docker/app/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/app/entrypoint.sh /usr/local/bin/fuelau-entrypoint
COPY docker/cron.d/fuelau /etc/cron.d/fuelau

RUN chmod +x /usr/local/bin/fuelau-entrypoint \
    && chmod 0644 /etc/cron.d/fuelau \
    && mkdir -p /var/log/fuelapi /etc/fuelapi \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && touch /var/log/fuelapi/cron.log \
    && chown -R www-data:www-data /var/log/fuelapi

WORKDIR /var/www/html

COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["fuelau-entrypoint"]
CMD ["apache2-foreground"]
