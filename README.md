<span style="display: flex; flex-align: center; justify-items: center; place-self: center; height:auto; width: 300px">
<img src="https://www.logex.com.ec/wp-content/uploads/2024/05/cropped-logoweb.png" alt="LogeX Logo" />
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
   composer install --no-dev
```

3. Crear el archivo .env en la raíz del directorio basado en el archivo .env.example:

```
    cp .env.example .env

```

4. Configurar el archivo .env con las credenciales de MySQL:

```
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=lms_backend
    DB_USERNAME=root
    DB_PASSWORD=


```

<small>Nota: Estas son las credenciales sugeridas únicamente para facilidad en localhost.</small>

5. Generar la clave de la aplicación:

```
    php artisan key:generate

```

# Base de datos

Crear una base de datos llamada lms_backend en phpMyAdmin u otra herramienta de administración de MySQL.

Ejecutar las migraciones para crear las tablas:

```
    php artisan migrate
```

Agregar los seeders necesarios. Asegúrate de tener los archivos JSON en la raíz del proyecto si vas a generar seeders automáticamente con el script de generate-seeder.php.

Cargar los datos en la base de datos con los seeders. Por ejemplo, para PersonalSeeder, que ya está generado:

```
    php artisan db:seed --class=PersonalSeeder
```

Para otros seeders, cambia PersonalSeeder por el nombre del seeder correspondiente.

# Servidor de desarrollo

Levantar el servidor de desarrollo con el siguiente comando:

```
    php artisan serve

```

Esto iniciará un servidor local que estará disponible en http://127.0.0.1:8000.

## Notas importantes

Este proyecto utiliza Laravel Sanctum para la autenticación API. Asegúrate de revisar la [documentación oficial de Sanctum](https://laravel.com/docs/11.x/sanctum) si necesitas personalizar esta funcionalidad.
Si necesitas ayuda para crear seeders adicionales, el script incluido en el proyecto te permite generar seeders automáticamente desde archivos JSON.

Si encuentras algún problema, no dudes en contactar al equipo. 😊
