FROM php:8.2-apache

#RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mysqli

RUN { \
      echo 'upload_max_filesize=6M'; \
      echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

COPY index.php /var/www/html/index.php

EXPOSE 80
