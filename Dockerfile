FROM php:8.2-apache

# System dependencies + PHP extensions
RUN apt-get update && apt-get install -y \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Composer (used to install the AWS SDK for Cloudflare R2 uploads)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP runtime overrides (upload size limits etc.)
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Apache virtual host — app is served from /inplace/ to match the
# existing hardcoded /inplace/... paths used throughout the codebase
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html/inplace

COPY . .

# Install PHP dependencies (AWS SDK only — PHPMailer is vendored separately)
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p assets/uploads/reports \
    && chown -R www-data:www-data assets/uploads

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
