FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

WORKDIR /var/www/html/

RUN chown -R www-data:www-data /var/www/html