# 1. Base PHP con Apache
FROM php:8.2-apache

# 2. Instala extensiones necesarias
RUN apt-get update \
 && apt-get install -y \
    libonig-dev \
    libzip-dev \
    zip \
    unzip \
    git \
 && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    bcmath

# 3. Habilita mod_rewrite de Apache
RUN a2enmod rewrite

# 4. Copia composer y dependencias
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5. Copia todo el código Laravel
WORKDIR /var/www/html
COPY . /var/www/html

# 6. Instala dependencias de PHP
RUN composer install --no-interaction --optimize-autoloader --no-dev

# 7. Ajuste de permisos
RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

# 8. Expone puerto 80
EXPOSE 80

# 9. Arranca Apache en foreground
CMD ["apache2-foreground"]
