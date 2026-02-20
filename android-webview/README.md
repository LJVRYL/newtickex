# Tickex STR – Android WebView (instalación manual)

Este directorio trae los archivos clave para un wrapper Android **WebView** que carga:

- `https://str.tickex.com.ar/login.php?app=1`

> Nota: No incluye un proyecto Android completo (Gradle wrapper, etc.). La forma más rápida/segura es crear un proyecto nuevo en Android Studio y **reemplazar** los archivos por los de acá.

## 1) Crear proyecto en Android Studio

1. Android Studio → **New Project** → **Empty Views Activity** (Kotlin)
2. Nombre sugerido: `Tickex STR`
3. Package name sugerido: `com.tickex.strwebview`
4. Minimum SDK: 23 (Android 6) o el que prefieras

## 2) Reemplazar archivos

Con el proyecto creado, copiá estos archivos a las rutas equivalentes del proyecto Android:

- `app/src/main/AndroidManifest.xml`  
	(copiar desde `android-webview/AndroidManifest.xml`)
- `app/src/main/java/com/tickex/strwebview/MainActivity.kt`  
	(copiar desde `android-webview/MainActivity.kt`)
- `app/src/main/res/layout/activity_main.xml`  
	(copiar desde `android-webview/activity_main.xml`)

Si tu package name es distinto, ajustá el `package` en `MainActivity.kt` y el `android:name` del manifest.

Tip: la ruta `app/src/main/java/com/tickex/strwebview/` debe existir (o crearla) para que coincida con el package.

## 3) Cambiar URL (si hace falta)

En `MainActivity.kt` buscá la constante:

- `private const val WEB_URL = "https://str.tickex.com.ar/login.php?app=1"`

y reemplazala.

## 4) Build APK (para instalar a mano)

- Android Studio → **Build** → **Build APK(s)**
- Te va a dar una ruta tipo: `app/build/outputs/apk/debug/app-debug.apk`

En el celular:
- Activá instalación de “apps desconocidas” para tu explorador/Drive/WhatsApp según corresponda
- Abrí el APK e instalá

## Comportamiento incluido

- WebView con JS + DOM storage habilitado (para sesiones, etc.)
- Cookies habilitadas (incluye third-party cookies para flujos de pago si el proveedor las usa)
- Back button: vuelve atrás dentro del WebView
- Links `tel:`, `mailto:` e `intent:` se abren con apps del sistema
- File upload (`<input type="file">`) soportado via selector
