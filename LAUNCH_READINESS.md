# Tickex — estado de preparación para lanzamiento

Última auditoría local: 7 de septiembre de 2026.

## Validado localmente

- Sintaxis válida en todos los archivos PHP versionados.
- Suite integral ejecutada sobre datos aislados, sin correo ni cobros reales.
- Integridad de la copia SQLite confirmada.
- Aislamiento de administradores, eventos, inventario, staff y comunicaciones.
- Emisión simple y por paquetes, stock, reintentos e idempotencia.
- Cálculos económicos de TotalCoin y Mercado Pago.
- OAuth, firma de webhooks, atribución y validación de importes simulados.
- Recuperación de contraseña, soporte, planes, facturación y portal de compradores.
- Credenciales fuera del repositorio y archivos sensibles bloqueados por el servidor web.

## Riesgos cerrados en esta auditoría

- Los paneles administrativos legacy ya no exponen datos ni borrados globales.
- El login administrativo incorpora CSRF, límite de intentos y migración automática de contraseñas antiguas a hashes seguros.
- Una cuenta pendiente sin contraseña ya no puede apropiarse desde el formulario de login.
- Las imágenes de perfil se validan por contenido real, no por extensión.
- Las escrituras administrativas restantes incorporan protección CSRF.
- La eliminación de plantillas dejó de ejecutarse mediante enlaces GET.
- Las pruebas de flujo ya no mezclan correos históricos con los del caso actual.

## Verificaciones obligatorias antes de habilitar ventas reales

1. Ejecutar una compra real de importe mínimo con comprador, organizador y cuenta Tickex separados.
2. Confirmar en Mercado Pago el importe acreditado al organizador, la comisión de Mercado Pago y el fee acreditado a Tickex.
3. Confirmar que el webhook real emite una sola vez, entrega todos los QR y tolera reintentos.
4. Probar rechazo, pago pendiente, devolución y contracargo sin emitir entradas incorrectas.
5. Ensayar restauración completa de código, base y secretos desde un backup del VPS.
6. Corregir la extensión ZIP de PHP y planificar actualización de PHP/Apache/SQLite en el VPS.
7. Verificar colas de email, SPF, DKIM, DMARC, rebotes y límites reales del proveedor.
8. Completar textos legales, política de privacidad, términos, datos fiscales y procedimiento de incidentes.
9. Realizar una revisión externa de seguridad sobre el dominio productivo y sus cabeceras.

La suite local reduce el riesgo de regresiones, pero no reemplaza las pruebas reales de Mercado Pago ni la revisión de infraestructura productiva.
