# syntax=docker/dockerfile:1.4
##############################
# Etapa Base: Configuración común de PHP y Apache
##############################
FROM php:8.2-apache AS base

# Instalar dependencias del sistema y extensiones de PHP
RUN apt-get update && apt-get install -y \
    zip unzip git curl libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite y configurar Apache para Laravel
RUN a2enmod rewrite && \
    echo 'ServerName localhost' >> /etc/apache2/apache2.conf

##############################
# Etapa Builder: Instalación de dependencias de Composer y preparación de la aplicación
##############################
FROM base AS builder

# Instalar Composer (copiado desde la imagen oficial de Composer)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos de Composer para aprovechar la cache
COPY composer.json composer.lock ./

# Instalar dependencias de Composer sin ejecutar el comando de discovery
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copiar el resto del código de la aplicación
COPY . .

# Asegurarse de que el archivo 'artisan' está presente antes de ejecutar comandos
RUN ls -la /var/www/html && php artisan --version

# Crear y configurar directorios de Laravel y establecer permisos
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 775 storage /var/www/html/bootstrap/cache

# Ajustar configuración de Apache para Cloud Run (o el puerto deseado)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && \
    sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

##############################
# Etapa Final: Imagen de producción
##############################
FROM base

WORKDIR /var/www/html

# Copiar la aplicación ya preparada desde la etapa builder
COPY --from=builder /var/www/html /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
