<span style="display: flex; flex-align: center; justify-items: center; place-self: center; height:auto; width: 300px">
<img src="https://www.logex.com.ec/wp-content/uploads/2024/05/cropped-logoweb.png" alt="LogeX Logo" style="max-width: 100%;display: grid;place-self: center;" />
</span>
<br/>
<br/>

# Back End de LMS (LogeX Management System)

Este proyecto está desarrollado en Laravel 11 y sirve como backend para la aplicación de LMS, misma que está basada en APIs. A continuación, se describen los pasos necesarios para configurar el entorno de desarrollo.

## Requisitos previos

1. **PHP**: Versión 8.2.12 o superior.
2. **Composer**: Gestor de dependencias para PHP en su versión 2.8.4 o superior.
3. **MySQL**: Para gestionar la base de datos. (Recomiendo utilizar XAMPP)
4. **Laravel 11**: Framework utilizado para este proyecto en su versión 11.31 o superior.

## Instalación y configuración inicial

1. Clonar este repositorio en tu máquina local:

```
   git clone https://github.com/rick-dev-logex/lms-backend.git
   cd LMS-Backend
```

2. Instalar las dependencias del proyecto:

```
   composer install
```

3. Crear el archivo .env en la raíz del directorio basado en el archivo .env.example:

```
    cp .env.example .env
```

4. Configurar el archivo .env con las credenciales de MySQL:

```
    DB_CONNECTION=mysql
    DB_DATABASE=lms_backend
    DB_USERNAME=root
    DB_PASSWORD=

    ONIX_DB_HOST=sgt_de_logex
    ONIX_DB_PORT=3306
    ONIX_DB_DATABASE=sistema_onix
    ONIX_DB_USERNAME=usuario_de_onix
    ONIX_DB_PASSWORD=contraseña_de_onix

    TMS_DB_HOST=sgt_de_logex
    TMS_DB_PORT=3306
    TMS_DB_DATABASE=tms
    TMS_DB_USERNAME=usuario_de_tms
    TMS_DB_PASSWORD=contraseña_de_tms

    FRONTEND_URL=url_de_la_app_en_produccion

    SESSION_DRIVER=cookie
    SANCTUM_STATEFUL_DOMAINS=
    SESSION_DOMAIN=localhost
    CORS_ALLOWED_ORIGINS=url_de_la_app_en_produccion

    #IMPORTANTE PARA AUTH CON TOKEN. Utilizar php artisan jwt:secret
    JWT_SECRET=secreto_generado_con_el_comando
    JWT_ALGO=HS256

    SESSION_SECURE_COOKIE=false
    SESSION_HTTP_ONLY=false

    # Configuración del correo

    MAIL_MAILER=smtp
    MAIL_HOST=smtp.sendgrid.net
    MAIL_PORT=587
    MAIL_USERNAME=apikey
    SENDGRID_API_KEY=clave_de_sendgrid
    MAIL_ENCRYPTION=tls
    MAIL_FROM_NAME="LMS | LogeX"
    MAIL_FROM_ADDRESS='notificaciones-lms@logex.ec'

```

<small style="font-size:.75rem; font-style: italic;">Nota: Estas son las credenciales sugeridas únicamente para facilidad en localhost.</small>

5. Generar la clave de la aplicación:

```
    php artisan key:generate
```

6. Configuración de almacenamiento público:

```
    php artisan storage:link
```

7. Generar token con el secreto de JWT:

```
    php artisan jwt:secret
```

# Base de datos

Crear una base de datos llamada lms_backend en phpMyAdmin u otra herramienta de administración de MySQL.

Ejecutar las migraciones para crear las tablas y cargar los seeders:

```
    php artisan migrate --seed
```

# Servidor de desarrollo

Levantar el servidor de desarrollo con el siguiente comando:

```
    php artisan serve
```

Esto iniciará un servidor local que estará disponible en http://127.0.0.1:8000.

## Notas importantes

Este proyecto utiliza Laravel Sanctum para la autenticación API. Asegúrate de revisar la [documentación oficial de Sanctum](https://laravel.com/docs/11.x/sanctum) si necesitas personalizar esta funcionalidad.

Si encuentras algún problema, no dudes en contactar al equipo de desarrollo. 😊
