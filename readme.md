# Guía de Despliegue en Servidor Web (Producción)

Esta guía explica paso a paso cómo preparar, subir y configurar la API de **skytrip_api** en un servidor web real (como cPanel, Hostinger, AWS, DigitalOcean o cualquier Cloud con panel de control basado en Apache/Nginx).

---

## 🚀 1. Preparación Local

Antes de subir nada, asegúrate de que el proyecto funciona en tu máquina (XAMPP/Localhost) y de instalar las dependencias de Composer:

```bash
composer install --no-dev --optimize-autoloader
```
*Este comando instalará las librerías necesarias de forma ligera y lista para producción (como `vlucas/phpdotenv` y `firebase/php-jwt`).*

---

## 📂 2. Subir Archivos al Servidor

Utiliza el Administrador de Archivos de tu Hosting, o conéctate mediante FTP (FileZilla) hacia la carpeta `public_html` (o `htdocs`, según tu proveedor) y sube todos los archivos de este directorio **CON EXCEPCIÓN DE**:

- `node_modules/` (si los tienes)
- `.git/`
- `.env` (¡El archivo local `.env` NO debe subirse para no sobrescribir las variables!)

Tu estructura en el servidor debería quedar similar a esta:
```text
/public_html
  /tu_carpeta_api (ej: /rms_api)
    /.env.example
    /.gitignore
    /composer.json
    /composer.lock
    /config.php
    /public (esta carpeta tiene el index.php y .htaccess)
    /src
    /vendor
```

---

## 🔐 3. Configurar Entorno (.env)

El archivo mágico de la seguridad de la API es el `.env`. En tu panel de hosting:

1. Duplica el archivo `.env.example` o cambia su nombre para que se llame simplemente `.env`.
2. Edita el archivo `.env` recién creado en el servidor para colocar tus claves reales de la base de datos de producción y tu ruta pública final:

```ini
DB_HOST="localhost"
DB_DATABASE="nombre_de_la_bdd_del_servidor"
DB_USERNAME="usuario_bdd_del_servidor"
DB_PASSWORD="ContraseñaSuperSecretaBaseDatos!"
DB_CHARSET="utf8mb4"

JWT_SECRET="CLAVE_SUPER_SECRETA_2026_MUY_EXTENSA_Y_COMPLEJA" 
## -> (Puedes cambiar esta cadena local por otra nueva en el servidor)

# Configuración de Producción
APP_ENV="production"
APP_URL="https://tu-dominio.com/rms_api/public"
```


## 🛠 4. Base de Datos
No olvides exportar tu base de datos desde tu *phpMyAdmin* local, y ejecutar el bloque SQL importándolo en el *phpMyAdmin* del panel de control de tu servidor contratado. 
Recuerda que ahora tenemos las nuevas tablas de RBAC (`as_menus` y `as_role_menus`).

---

## 🛑 5. Revisión y Reglas de Seguridad (Apache)

Nuestra API se sirve exclusivamente a través de la carpeta `public/` (y su archivo principal `index.php`).
Verifica a través del Administrador de Archivos de cPanel que el archivo oculto `/public/.htaccess` exista y se haya subido correctamente.
Debe tener este contenido:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```
*💡 Esto garantiza que los Request HTTP (desde tu app de Dart) sigan siendo redirigidos hacia el código PHP central, limpiando los links (URL amigables).*

---

## ✅ 6. Pruebas Finales

Para verificar que la API está corriendo feliz en sus nuevas tierras, utiliza Postman apuntando directamente a tu dominio HTTPS. 

**Ejemplo de prueba Login:**
- **URL**: `POST https://tu-dominio.com/tu_carpeta/public/login`
- **Body JSON**: `{"username": "admin", "password": "mipassword123"}`

Si todo ha ido bien, recibirás de vuelta en JSON la cadena JWT de autorización, y con ese código podrás pegarlo en el *Authorization* *Bearer Token* del EndPoint `/me`.

