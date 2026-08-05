FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git unzip zip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV SESSION_DRIVER=database
ENV SESSION_CONNECTION=mysql
ENV SESSION_TABLE=sessions

COPY . .

RUN composer install --optimize-autoloader --no-interaction --no-scripts

EXPOSE 8080

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8080}