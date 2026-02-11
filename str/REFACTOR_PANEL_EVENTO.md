# Refactor panel_evento.php - Unificación de Entradas

## Cambios Realizados

### 1. Creación de helpers para entradas unificadas
**Archivo:** `inc/unified_tickets.php`

Nuevas funciones:
- `detect_table_columns($pdo, $table_name)` - Detecta qué columnas existen en una tabla
- `get_checkin_column($pdo)` - Identifica la columna de check-in (checkin o checked_in)
- `get_unified_entries($pdo, $evento_id, $filters)` - Obtiene entradas de ambas fuentes (STR + Tickex)
- `count_unified_entries($entries)` - Calcula estadísticas unificadas

**Características:**
- Lee entradas de la tabla `entradas` (STR)
- Busca entradas de `v_senforms_bridge_status` (vista) o `senforms_bridge_tickets` (tabla)
- Filtra automáticamente por entradas pagas (is_paid=1 o status SUCCESS)
- Normaliza todas las entradas a un formato común
- Maneja fallbacks de columnas para máxima compatibilidad
- Ordena por fecha descendente

### 2. Creación de sistema de ingresos manuales
**Archivo:** `inc/manual_income.php`

Nuevas funciones:
- `ensure_manual_income_table($pdo)` - Crea tabla si no existe
- `add_manual_income(...)` - Agrega nuevo ingreso manual
- `get_manual_incomes($pdo, $evento_id)` - Lista ingresos por evento
- `get_total_manual_income($pdo, $evento_id)` - Suma total
- `delete_manual_income($pdo, $income_id)` - Elimina un ingreso

**Tabla creada:** `manual_income`
```
Columnas:
- id (PK)
- evento_id (FK)
- concepto (TEXT)
- monto (REAL)
- descripcion (TEXT)
- creado_por (user_id)
- created_at (timestamp)
- updated_at (timestamp)
```

### 3. Endpoints API para ingresos
**Archivo:** `add_manual_income.php`
- POST `/add_manual_income.php` - Agrega ingreso
- Valida permisos (admin_evento, super_admin)
- Responde en JSON

**Archivo:** `delete_manual_income.php`
- POST `/delete_manual_income.php` - Elimina ingreso
- Responde en JSON

### 4. Refactor de panel_evento.php

**Cambios principales:**

a) Importación de nuevos helpers:
```php
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';
```

b) Unificación de entradas:
- Reemplaza query directo a `entradas`
- Usa `get_unified_entries()` que incluye Tickex
- Mantiene los mismos filtros (q, tipo, estado)

c) Tabla de entradas actualizada:
- Nuevo campo "Origen" (STR o TICKEX)
- Nuevo campo "Pago" (✓ o ✗)
- Actualizado campo "Acciones" (maneja ambas fuentes)
- Colores diferenciados por origen

d) Nueva sección: Ingresos manuales
- Formulario para agregar ingresos
- Lista editable con eliminación
- Total acumulado
- AJAX para add/delete sin recargar

## Compatibilidad

✅ **Mantiene:**
- Estructura visual del panel existente
- Sistema de permisos (admin_evento, super_admin)
- Check-in existente para entradas STR
- Filtros y búsqueda
- Estadísticas globales del evento

✅ **Compatible con:**
- Nombres de columnas variables (fallbacks automáticos)
- Tablas/vistas que no existan (panel sigue funcionando)
- Usuarios sin cambios en la BD (perfil existente)

## Base de Datos Local

**BD:** `save_the_rave.sqlite` (no se commitea)

**Tablas nuevas creadas automáticamente:**
- `manual_income` (si no existe)

**Índices creados:**
- `idx_manual_income_evento` en tabla `manual_income`

## Testing Local

```bash
# Iniciar servidor
php -S 127.0.0.1:8080 -t str

# Acceder al panel
http://127.0.0.1:8080/panel_evento.php?evento_id=1
```

## Características de Detectación

El código detecta automáticamente:

**Tabla `entradas`:**
- Columna de check-in: `checkin` o `checked_in`

**Bridge Tickex/SenForms:**
- Vista: `v_senforms_bridge_status` (preferida)
- Tabla: `senforms_bridge_tickets` (fallback)

**Filtros de pago:**
- `is_paid` = 1
- O `pago_status` / `status` IN ('SUCCESS', 'APROBADO')

**Mapeo de columnas de evento:**
- `evento_id`, `event_id`, `id_evento`

## Notas Importantes

1. **No se toca producción** - Solo afecta a entorno local
2. **DB no se commitea** - .gitignore ignora `save_the_rave.sqlite`
3. **Cambios mínimos** - Se reutiliza estructura existente
4. **Fallbacks robustos** - Panel funciona aunque falten tablas/vistas

## Próximos Pasos (Opcionales)

- Agregar permisos granulares (quién puede ver qué ingresos)
- Reportes de ingresos por concepto
- Integración con contabilidad
- Auditoría de cambios en ingresos
- Exportación a CSV/Excel
