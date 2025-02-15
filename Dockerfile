# Etapa base
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

# Verificar que el archivo está presente
RUN ls -la app/Http/Middleware && cat app/Http/Middleware/VerifyJWTToken.php || echo "No se encontró el archivo"


# Instalar dependencias de Laravel
RUN composer install --no-dev --optimize-autoloader \
    && composer dump-autoload -o

# Crear directorios necesarios y establecer permisos
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && chmod -R 775 storage \
    && chmod -R 775 bootstrap/cache \
    && chown -R www-data:www-data storage \
    && chown -R www-data:www-data bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Configurar Apache para escuchar en el puerto definido por Cloud Run
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Asegurarnos que el DocumentRoot es correcto
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Comando de inicio
CMD ["apache2-foreground"]