FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    zip unzip git curl libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd

# Habilitar mod_rewrite para Laravel
RUN a2enmod rewrite

# Configuración adicional de Apache
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar archivos del backend
COPY . .

# Crear y configurar directorios de Laravel
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/framework/views \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && touch storage/framework/views/.gitkeep

# Asegurarse de que los directorios existan y tengan los permisos correctos
RUN chmod -R 775 storage/framework/views \
    && chown -R www-data:www-data storage/framework/views

# Verificar que el archivo está presente
RUN ls -la app/Http/Middleware && cat app/Http/Middleware/VerifyJWTToken.php || echo "No se encontró el archivo"

# Instalar dependencias de Laravel y forzar autoload
RUN composer install --no-dev --optimize-autoloader \
    && composer dump-autoload -o

# Ajustar Apache para Cloud Run
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Establecer permisos finales
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

CMD ["apache2-foreground"]