# Tickex — pendientes priorizados

Actualizado: 8 de septiembre de 2026.

## P0 — antes de incorporar clientes pagos

- [x] Completar “Continuar con Google”: código base, configuración Google Cloud y publicación externa.
- [ ] Desplegar y probar el acceso único local ya terminado: comprador, administrador, staff y superadmin desde `login.php`.
- [ ] Cerrar inmediatamente la superficie técnica pública del VPS: SQLite, pruebas, migraciones, herramientas y diagnósticos. La corrección ya está preparada y probada en local; falta el bloqueo efectivo en Apache y su verificación externa.
- [ ] Rotar contraseñas/sesiones y revisar accesos después de cerrar la descarga pública de la base; conservar una copia forense y no distribuirla.
- [ ] Ejecutar una compra mínima real de Mercado Pago con comprador, organizador y Tickex separados.
- [ ] Verificar en las tres cuentas el precio nominal, costo al comprador, comisión de Mercado Pago y fee Tickex.
- [ ] Validar webhooks reales: aprobado, pendiente, rechazado, reembolso y contracargo, sin duplicar QR.
- [ ] Ensayar restauración completa de código, SQLite y secretos desde un backup del VPS.
- [ ] Corregir `zip.so` y calendarizar la actualización de PHP 7.4, Apache y SQLite.
- [ ] Completar textos legales y fiscales con revisión profesional: términos, privacidad, arrepentimiento, tratamiento de datos y responsabilidades de cada organizador.
- [ ] Revisión externa de seguridad del dominio productivo, cabeceras, permisos y flujos de pago.
- [ ] Inventariar y retirar páginas históricas duplicadas una vez confirmado que no reciben tráfico legítimo.

## P1 — operación comercial confiable

- [ ] Activar límites reales de planes: QR mensuales, comisión por plan, excepciones, cambios y vencimientos.
- [ ] Incorporar cobro recurrente de suscripciones y gestión de mora/cancelación.
- [ ] Conciliación diaria de órdenes Tickex contra Mercado Pago y TotalCoin, con alertas por diferencias.
- [ ] Panel de devoluciones, cancelaciones y contracargos con trazabilidad y permisos.
- [ ] Monitoreo y alertas: pagos, webhooks, cola de correo, base bloqueada, espacio en disco y certificados.
- [ ] Probar recuperación ante caída de Mercado Pago/TotalCoin sin perder órdenes ni duplicar cobros.
- [ ] Centralizar logs con retención y limpieza automática; evitar que crezcan indefinidamente.
- [ ] Sacar la base de datos y archivos operativos del directorio público, aunque Apache ya los bloquee.
- [ ] Definir soporte operativo: severidades, tiempos de respuesta, responsables y comunicación de incidentes.

## P1 — correo y comunicación a escala

- [ ] Confirmar SPF, DKIM y DMARC del dominio remitente.
- [ ] Medir límites reales del proveedor y definir lotes, pausas y horarios de envío.
- [ ] Procesar rebotes, quejas y desuscripciones automáticamente.
- [ ] Auditar el caso de newsletters repetidos y probar idempotencia con concurrencia real.
- [ ] Separar correo transaccional (entradas, pagos, acceso) del marketing masivo.
- [ ] Evaluar proveedor transaccional dedicado antes de escalar la cantidad de clientes.
- [ ] Definir dominios/subdominios por organizador y reputación de remitente sin ocultar que el servicio opera sobre Tickex.

## P2 — producto y experiencia

- [ ] Completar revisión responsive en celulares reales de comprador, administrador, puerta y superadmin.
- [x] Unificar en local los accesos de comprador y administrador sin mezclar identidades ni permisos.
- [ ] Validar visualmente y desplegar el acceso unificado en producción.
- [x] Reemplazar los enlaces de correo de la landing por un formulario interno y acceso directo a WhatsApp.
- [ ] Desplegar y probar el formulario público y la bandeja comercial de superadmin.
- [ ] Terminar el diseño del superadmin: clientes, operaciones, planes, incidencias, salud y auditoría.
- [ ] Ampliar panel de puerta: ventas de puerta, caja, check-ins, incidencias y cierre de turno.
- [ ] Mejorar el perfil del comprador, historial, facturas, devoluciones y preferencias de comunicación.
- [ ] Completar guías contextuales, onboarding y ayuda dentro de cada módulo.
- [ ] Preparar la aplicación móvil; hasta entonces comunicar claramente que la aplicación web ya está disponible.
- [ ] Incorporar analítica de conversión: visita, inicio de checkout, pago y abandono.
- [ ] Accesibilidad: teclado, contraste, foco, lectores de pantalla y tamaños táctiles.
- [ ] Revisión visual final, ruta por ruta, en escritorio y móvil; registrar capturas de comprador, organizador, staff/puerta y superadmin.

## P2 — URLs, marca y dominios

- [ ] Definir URLs amigables y estables para eventos y páginas públicas.
- [ ] Usar identificadores públicos aleatorios cuando un ID correlativo exponga información innecesaria.
- [ ] Mantener autorización por servidor en todas las pantallas: ocultar o “hashear” una URL no reemplaza el control de permisos.
- [ ] Completar alta y verificación automática de dominios personalizados, TLS y estado DNS.
- [ ] Definir kit de marca y personalización permitida por plan.

## P3 — evolución técnica

- [ ] Migrar gradualmente SQLite a una base preparada para concurrencia antes de un volumen alto de ventas.
- [ ] Separar procesos web, workers de email y webhooks.
- [ ] Automatizar despliegues, migraciones, rollback, smoke tests y backups verificados.
- [ ] Crear un entorno de staging parecido al VPS para probar integraciones externas sin tocar producción.
- [ ] Medir rendimiento y fijar objetivos de disponibilidad, latencia y recuperación.
- [ ] Documentar arquitectura, modelo de datos, secretos, accesos y procedimientos de emergencia.

## Integraciones siguientes sugeridas

1. Google Identity — acceso y alta de compradores; vinculación segura de administradores existentes.
2. Mercado Pago productivo — cierre del split y ciclo completo del pago.
3. Proveedor de correo transaccional — entregabilidad, eventos de rebote y volumen.
4. Monitoreo/alertas — visibilidad antes de sumar clientes.
5. Facturación y suscripciones — monetización recurrente y límites automáticos.
6. Dominios/DNS — personalización comercial cuando la operación base esté estable.

## Auditoría integral iniciada el 8 de septiembre de 2026

- [x] Comprobar en producción la exposición de archivos sensibles sin descargar su contenido.
- [x] Proteger en local el visor histórico de usuarios y las utilidades de diagnóstico/importación para uso exclusivo por consola.
- [x] Agregar reglas de denegación para bases, logs, pruebas, migraciones, herramientas y parches.
- [x] Incorporar verificación CSRF a operaciones antiguas de SenForms/Bridge y asignaciones artísticas.
- [x] Ejecutar pruebas de seguridad, aislamiento, Google Identity y contacto público en local.
- [ ] Aplicar las reglas equivalentes en el VirtualHost activo, porque producción actualmente usa `AllowOverride None`.
- [ ] Verificar desde Internet que los recursos sensibles devuelvan 403/404 y que compra, login, webhooks y paneles sigan operativos.
- [ ] Auditar dependencias, versiones, permisos del filesystem, cabeceras y configuración TLS.
- [ ] Completar revisión visual y de accesibilidad con sesiones reales de los cuatro perfiles.
