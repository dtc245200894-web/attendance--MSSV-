FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public

RUN docker-php-ext-install pdo_mysql \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && a2enmod rewrite

COPY . /var/www/app/

WORKDIR /var/www/app

RUN printf 'expose_php=Off\n' > /usr/local/etc/php/conf.d/security.ini