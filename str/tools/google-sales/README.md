# Graphex y Tickex: medición de ventas

Estado al 9 de octubre de 2026, 21:05 ART. Esta entrega amplía la medición de visitas ya verificada.

## Instalado y verificado

- Una credencial Measurement Protocol por marca, creada con autorización. Se guardan sólo en archivos privados del VPS, con propietario root y permisos 600. La transferencia fue cifrada; se eliminaron el material local y la clave temporal.
- Aviso y políticas actualizados para compras futuras. El permiso previo para visitas no autoriza ventas: se requiere aceptar el nuevo aviso. La aceptación y la retirada se verificaron en ambos sitios y en los registros privados del servidor.
- Contexto de pedidos separado de las bases financieras. Acceso HTTP bloqueado, incluso en el alias str.tickex.com.ar. Las claves de Google permanecen fuera de la web.
- Procesamiento automático cada minuto. Lee la confirmación existente de pago; no inicia cobros, no llama a Mercado Pago y no importa pedidos históricos.
- Una compra por pedido, sin duplicar por recargas o avisos repetidos. Los pedidos sin consentimiento, sin importe positivo o sin pago confirmado no se exportan.
- Google validó ambos formatos de compra sin errores mediante su endpoint de diagnóstico, que no incorpora las pruebas a los informes. Pasaron 30 comprobaciones aisladas de datos, duplicados, consentimiento y retirada, más las pruebas del navegador. No se crearon pedidos ni pagos reales.

## Qué significa el importe

Graphex registra el valor de los productos después de descuentos, sin impuestos ni envío. Tickex registra el valor de las entradas, sin el costo de servicio: **no representa el ingreso de Tickex por comisiones**. La comisión permanece en la contabilidad existente. No se exportan nombre, DNI, correo, archivos, QR, URLs privadas ni datos de tarjetas.

Sólo se atribuyen pedidos web con contexto válido del comprador. Las ventas telefónicas, por WhatsApp o creadas por un operador sin ese contexto no se inventan como conversiones atribuidas. La asociación con campañas depende también del identificador y la sesión realmente recibidos por GA4.

## Pendientes reales

- Observar la primera compra real consentida en los informes: todavía no hay una venta de este tipo verificada. La validación del formato y un acuse HTTP no prueban por sí solos la aparición de una compra en los informes.
- Reembolsos posteriores al envío: aún no se sincronizan automáticamente. Un reembolso ya registrado antes del envío en Graphex evita exportar esa compra. No usar el informe como contabilidad neta de devoluciones.
- Integración de Tickex en main: su rama incluye diferencias ajenas a este paquete; sigue pendiente reconciliarlas sin incorporar cambios independientes. El cambio desplegado sobre su checkout actual fue una sola inserción opcional de contexto.
- Search Console: Graphex tiene 223 URLs revisadas técnicamente; la lectura de algunos sitemaps secundarios y la indexación siguen pendientes de Google. Tickex tiene seis URLs públicas en el sitemap; la lectura de la nueva versión y su indexación no están confirmadas. Enviar un sitemap no significa que todas sus páginas estén indexadas.
- Horario de Graphex en Maps: la solicitud de lunes a viernes de 10 a 18 estaba pendiente de revisión de Google en la última comprobación.

La base técnica de SEO y la medición de visitas están desplegadas. La medición de compras web futuras queda activa con el alcance anterior. SEO requiere además revisar búsquedas reales, contenido comercial e indexación; no es un trabajo que se agote con conectar Analytics.

## Operación y reversión

Proyecto: Graphex / Tickex. Recurso: VPS Ferozo. Integración: Google Analytics 4. Referencias de credenciales: `ge-ga4-graphex-mp` y `ge-ga4-tickex-mp`; sus valores no se guardan en documentación ni repositorios.

Proceso privado: `/root/ge-private/ga4-sales/dispatch.php`. Calendario del servicio: `/etc/cron.d/ge-consented-sales`. Registro privado: `/root/ge-private/ga4-sales/dispatch.log`, sólo contadores y errores técnicos, sin datos de compradores.

Respaldo inicial verificado: `/root/ge-backups/consented-sales-20261009T235908Z`. Respaldo del ajuste de almacenamiento: `/root/ge-backups/sales-private-storage-20261010T000321Z`. La copia consistente de SQLite pasó `integrity_check`; permanece en el VPS.

Para detener exportaciones, retirar exclusivamente el calendario `ge-consented-sales`. Mantener la regla restrictiva `/opt/ferozo/conf/vhosts.d/998-ge-sales-private.conf` mientras existan contextos privados. Restaurar sólo los archivos anteriores del paquete, después de comprobar que no fueron modificados por otro trabajo. Para Tickex, retirar únicamente la inserción `tickex_sales_capture_order` de su checkout actual, preservando otros cambios. No restaurar toda una base financiera: podría perder ventas posteriores. Restaurar la política de Graphex desde su JSON respaldado, o publicar la versión anterior de privacidad de Tickex, sólo después de detener el proceso. No retirar la protección HTTP antes de archivar de forma privada los contextos.

Fuentes técnicas: [Measurement Protocol](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference) y [validación sin incorporación a informes](https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events).
