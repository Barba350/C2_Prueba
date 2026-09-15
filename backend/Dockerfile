FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mysqli

# Permite subir imágenes hasta 5MB (por defecto PHP limita a 2MB)
RUN { \
      echo 'upload_max_filesize=6M'; \
      echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Instala Composer (para el AWS SDK for PHP)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Instala dependencias primero (aprovecha la cache de Docker si el código cambia pero no composer.json)
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

COPY index.php /var/www/html/index.php

EXPOSE 80
