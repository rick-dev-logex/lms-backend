# Usa PHP con Apache
FROM php:8.2-apache AS base

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y \
    zip unzip git curl libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite en Apache
RUN a2enmod rewrite && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Copiar todos los archivos del proyecto
COPY . .

# Instalar dependencias de Laravel con Artisan ya disponible
RUN composer install --no-dev --optimize-autoloader && \
    chmod -R 775 storage bootstrap/cache

# Exponer puerto 80 para Cloud Run
EXPOSE 80

# Iniciar Apache
CMD ["apache2-foreground"]
