FROM php:8.5-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libldap2-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    zlib1g-dev \
    libxml2-dev \
    libicu-dev \
    liboniguruma-dev \
    gettext \
    locales \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        ldap \
        gd \
        gettext \
        intl \
        zip \
        xml \
        mbstring \
        fileinfo \
    && docker-php-ext-enable opcache

COPY docker/php.ini /usr/local/etc/php/conf.d/syspass.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/syspass

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN chown -R www-data:www-data app/config app/cache app/temp app/backup \
    && chmod -R 750 app/config app/cache app/temp app/backup

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
