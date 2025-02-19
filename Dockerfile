# Use composer stage for dependencies
FROM composer:2.2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./

# Instalar dependencias ignorando requisitos de plataforma temporalmente
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-scripts \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=php

# Final image
FROM php:8.2-apache

# Install all dependencies in a single layer
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    zip \
    gd \
    && a2enmod rewrite \
    && a2enmod headers \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/www/html/storage/framework/{sessions,views,cache} \
    && mkdir -p /var/www/html/storage/app/google \
    && mkdir -p /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/html/storage/framework/views/cache

# Configure Apache
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Copy vendor from composer stage
COPY --from=composer /app/vendor /var/www/html/vendor

# Prepare storage directory and set initial permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

# Copy application files
COPY . /var/www/html/

# Create and set permissions for Google Cloud key
RUN mkdir -p /var/www/html/storage/app/google \
    && if [ -f "/var/www/html/storage/app/google-cloud-key.json" ]; then \
    chmod 644 /var/www/html/storage/app/google/google-cloud-key.json; \
    elif [ -f "/workspace/storage/app/google/google-cloud-key.json" ]; then \
    cp /workspace/storage/app/google/google-cloud-key.json /var/www/html/storage/app/google/google-cloud-key.json; \
    chmod 644 /var/www/html/storage/app/google/google-cloud-key.json; \
    fi

# Set final permissions
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage/app/google

CMD ["apache2-foreground"]