# Sitemap del dominio principal de Tickex

URL pública: https://www.tickex.com.ar/sitemap.xml. Robots referencia esa URL.

`tools/build_sitemap.py` usa Python 3.6+ sin dependencias. Se ejecuta desde CLI
privada después de cambios en páginas públicas o publicación de eventos:

```
python3 build_sitemap.py --docroot /opt/ferozo3/web/str --output /ruta/privada/sitemap.xml --report /ruta/privada/report.json
```

Consulta SQLite con `mode=ro` y `query_only`, sin bootstrap ni migraciones.
Revisa páginas comerciales/editoriales conocidas y sitios visibles con eventos
publicados no borrados. Sólo admite HTTP200, sin redirección, permitido por robots,
sin noindex, con un único canonical HTTPS idéntico y host www.tickex.com.ar.
No incorpora checkout, admin, entradas, clientes privados, tracking ni filtros.
No publica dominios de organizadores dentro del sitemap www. Una página sin
canonical confirmado queda en el informe de exclusiones, no en el XML.

Cobertura validada 2026-10-09: portada, contacto, demo y ayuda. Legal, manual,
arrepentimiento y rutas de organizador/eventos carecen de canonical confirmado.
La propuesta tiene noindex. No hay productos públicos separados en el dominio.
Resolver canonical/host de organizadores y detalle válido antes de ampliar.

No se inventa lastmod: las fechas de despliegue y calendario de evento no son
prueba de modificación del contenido. Sin lastmod hasta disponer de esa señal.
Se comprueban 50000 URLs y 50MiB; fallar antes de superarlos obliga a dividir
en sitemaps e índice. Con cuatro URLs no hace falta índice.

La salida se prepara fuera del docroot. Revisar informe, hacer backup del XML
vigente, comprobar hash contra drift, instalar con rename atómico y comprobar
HTTP/Content-Type/XML/URLs en producción. El generador no publica por sí solo.
Conservarlo en /root/tickex-seo-tools/build_sitemap.py; no exponerlo por HTTP.
No hay cron instalado: la regeneración es parte del release de contenido público.

Rollback: comprobar hash del XML publicado y restaurar únicamente sitemap.xml
desde backup verificado mediante archivo temporal y rename. Robots, código de
negocio, base y vhosts no cambian. El envío Search Console existente se conserva;
enviado no significa leído ni indexado. Google vuelve a leer la misma URL.
