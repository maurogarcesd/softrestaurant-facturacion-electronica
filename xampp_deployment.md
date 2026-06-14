# 🚀 Guía de Despliegue en XAMPP (Sin Docker)

Si prefieres no usar Docker y desplegar directamente sobre Windows utilizando **XAMPP**, debes tener en cuenta que el entorno requiere algunas configuraciones manuales (especialmente para conectar PHP con SQL Server y para mantener el demonio en segundo plano).

A continuación, los pasos detallados:

## 1. Preparación del Entorno (XAMPP)

### A. Versión de PHP
Asegúrate de tener instalado XAMPP con **PHP 8.1 o superior**.

### B. Instalar Controladores de SQL Server para PHP
XAMPP no trae los drivers para conectarse a SQL Server por defecto. 
1. Descarga los [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/es-es/sql/connect/php/download-drivers-php-sql-server).
2. Extrae los archivos `.dll` (ej. `php_sqlsrv_82_ts.dll` y `php_pdo_sqlsrv_82_ts.dll` dependiendo de tu versión de PHP).
3. Pega esos dos archivos en la carpeta `C:\xampp\php\ext\`.
4. Abre el archivo `C:\xampp\php\php.ini` y añade al final de las extensiones:
   ```ini
   extension=php_sqlsrv_82_ts
   extension=php_pdo_sqlsrv_82_ts
   ```
5. Reinicia Apache desde el panel de XAMPP.

## 2. Base de Datos (MySQL)
1. Abre **phpMyAdmin** (`http://localhost/phpmyadmin`).
2. Crea una base de datos llamada `integracion_factus`.
3. Importa el archivo `init.sql` que se encuentra en la carpeta del proyecto para crear la tabla de control.

## 3. Ubicación del Proyecto y Configuración
1. Copia toda la carpeta del proyecto (`SoftRestaurant`) dentro de `C:\xampp\htdocs\`. Debería quedar como `C:\xampp\htdocs\SoftRestaurant`.
2. Edita el archivo `.env` para poner tus credenciales de SQL Server, de MySQL (usualmente en XAMPP el usuario es `root` y sin contraseña) y las de producción de **Factus**.

## 4. Acceso al Panel Web (Dashboard)
Ya que el proyecto está en la carpeta de XAMPP, el panel web será accesible ingresando a:

👉 **`http://localhost/SoftRestaurant/public/index.html`**

## 5. Ejecución del Demonio en Segundo Plano (IMPORTANTE)
Como no estamos usando Docker, Apache solo sirve la página web, pero **NO** ejecuta automáticamente el proceso que lee las ventas e intenta mandarlas a Factus (el "Daemon").

Para que este demonio corra todo el tiempo en tu servidor Windows, la forma más fácil es usar un archivo `.bat` oculto o el Programador de Tareas de Windows.

### Opción A: Archivo Ejecutable (BAT)
Crea un archivo llamado `iniciar_facturacion.bat` en tu escritorio o dentro de la carpeta con este código:
```bat
@echo off
cd C:\xampp\htdocs\SoftRestaurant
C:\xampp\php\php.exe src\Daemon.php
pause
```
> **Nota:** Al hacerle doble clic, se abrirá una ventana negra que empezará a procesar las facturas. Esa ventana **debe mantenerse abierta** todo el día mientras el restaurante opere.

### Opción B: Ejecutar como Servicio Oculto de Windows (Recomendado)
Para evitar tener una ventana negra abierta que alguien pueda cerrar por error:
1. Abre el **Programador de tareas** de Windows.
2. Crea una **"Nueva Tarea Básica"** llamada "Demonio Factus".
3. En Desencadenador, selecciona **"Al iniciar el equipo"**.
4. En Acción, selecciona **"Iniciar un programa"**.
   - Programa/script: `C:\xampp\php\php.exe`
   - Agregar argumentos: `C:\xampp\htdocs\SoftRestaurant\src\Daemon.php`
5. En las propiedades de la tarea, marca la opción **"Ejecutar oculto"** y "Ejecutar con los privilegios más altos".

---

> [!WARNING]
> Recuerda que si modificaste algo de la estructura o borraste accidentalmente la carpeta `vendor`, deberás instalar **Composer** para Windows, abrir la consola en la carpeta del proyecto y ejecutar `composer install`. Si estás copiando la carpeta tal cual la tienes en tu entorno de desarrollo local, no es necesario.
