# SAVE THE RAVE Newsletter - Integracion Tickex

Archivo base HTML:
- str/templates/newsletters/save-the-rave_master_newsletter.html

Contenido ejemplo:
- str/templates/newsletters/save-the-rave_master_content_example.json

## Mapeo recomendado para modulo Comunicacion (actual)

El modulo actual valida variables conocidas en minuscula. Para usar esta plantilla sin tocar codigo, se recomienda este mapeo minimo al momento de renderizar:

- {{EVENTO}} -> {{evento}}
- {{EDICION}} -> {{evento}}
- {{FECHA}} -> {{fecha}}
- {{LUGAR}} -> {{organizador}}
- {{TICKETS_URL}} -> {{ticket_url}}

Variables como {{ARTISTA_1}} o {{MANIFIESTO}} no existen en el catalogo actual.
Si queres resolverlo de forma nativa, ampliar `communication_variables_catalog()` en `str/inc/communication_variables.php`.

Tambien se agregaron placeholders multimedia para la version editorial:
- {{IMAGE_1_URL}}
- {{IMAGE_2_URL}}
- {{MODEL_PREVIEW_URL}}
- {{MODEL_URL}}

## Uso rapido

1. Copiar el HTML base en una nueva plantilla de Comunicacion.
2. Reemplazar placeholders con datos de la edicion.
3. Si no hay imagen principal, eliminar el bloque de la fila `<img src="{{HERO_IMAGE_URL}}" ...>`.
4. Si no usas assets extra/model, eliminar los bloques Visual Assets y Model.
4. Enviar prueba a Gmail, Outlook y Apple Mail antes del envio final.
