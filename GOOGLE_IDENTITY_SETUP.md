# Configuración de acceso con Google

El código queda desactivado mientras no existan credenciales. No reemplaza el login por contraseña y no convierte compradores en administradores.

## Google Cloud

1. Crear o elegir un proyecto en Google Cloud Console.
2. Configurar la pantalla de consentimiento OAuth con nombre `Tickex`, dominio `tickex.com.ar`, página principal y enlaces legales.
3. Crear un cliente OAuth de tipo **Aplicación web**.
4. Registrar estas URLs de redirección:
   - Producción: `https://str.tickex.com.ar/google_oauth_callback.php`
   - Desarrollo: `http://127.0.0.1:8088/google_oauth_callback.php`
5. Conservar Client ID y Client Secret fuera de Git.

## Archivo privado del servidor

Crear `/opt/ferozo3/web/.secrets/google_identity.php`, propietario `root:webusers` y permisos `0640`:

```php
<?php
return array(
    'client_id' => 'CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'CLIENT_SECRET',
    'redirect_uri' => 'https://str.tickex.com.ar/google_oauth_callback.php',
);
```

En desarrollo puede usarse `str/.secrets/google_identity.php` con la URL local. Ambos directorios están excluidos de Git.

## Comportamiento

- Comprador: busca el email confirmado; si no existe, crea un perfil de comprador vinculado al `sub` estable de Google.
- Administrador: solo entra si ya existe un administrador activo con ese email. Google nunca concede roles.
- Staff: conserva sus eventos y redirige a Puerta.
- Un mismo email puede tener contexto de comprador y administrador, pero cada acceso queda vinculado por separado.
- Se solicitan únicamente `openid`, `email` y `profile`; no se guardan access tokens ni refresh tokens.

## Validación antes de producción

1. Ejecutar la migración sobre una copia y luego sobre la base productiva.
2. Probar alta de comprador nuevo y acceso de comprador existente.
3. Probar administrador existente, staff y rechazo de un email sin permisos.
4. Confirmar retorno seguro al checkout mediante `next`.
5. Revocar el acceso desde Google y confirmar que la contraseña de Tickex sigue funcionando.
