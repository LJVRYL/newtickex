<?php
// inc/db.php (PHP5-safe)
function db(){
    static $pdo = null;
    if ($pdo) return $pdo;

    $dbFile = __DIR__ . '/../save_the_rave.sqlite';
    if (!file_exists($dbFile)) {
        die("Base no encontrada: ".$dbFile);
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Evita errores intermitentes "database is locked" en escrituras concurrentes.
        // WAL mejora la convivencia de lecturas vs escrituras.
        // Todo esto es best-effort (no rompe si alguna PRAGMA no existe).
        try {
            $pdo->exec('PRAGMA busy_timeout = 15000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } catch (Exception $e) {
            // ignore
        }

        // Crear tabla de notificaciones si no existe
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS notificaciones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                mensaje TEXT NOT NULL,
                tipo TEXT DEFAULT "info",
                extra TEXT,
                created_at DATETIME DEFAULT (datetime("now")),
                leida INTEGER DEFAULT 0
            )');
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Ensure latest columns exist (idempotent)
        try {
            $cols = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
            $hasApellido = false;
            $hasEmailConfirmado = false;
            $hasApodo = false;
            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'apellido') { $hasApellido = true; break; }
            }
            if (!$hasApellido) {
                $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN apellido TEXT");
            }

            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'apodo') { $hasApodo = true; break; }
            }
            if (!$hasApodo) {
                $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN apodo TEXT");
            }

            // Tickex ID (apodo) en admins: best-effort único (case-insensitive)
            try {
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_admin_apodo_unique ON usuarios_admin(lower(apodo))");
            } catch (Exception $e) {
                // ignore (puede fallar si hay duplicados existentes)
            }

            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'email_confirmado') { $hasEmailConfirmado = true; break; }
            }
            if (!$hasEmailConfirmado) {
                // En muchos esquemas iniciales 'usuarios' era una VIEW con email_confirmado fijo en 1.
                // Agregamos una columna real para poder auditar/editar validación.
                $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN email_confirmado INTEGER NOT NULL DEFAULT 1");
            }

            // Si usuarios es VIEW, recrearla para exponer email_confirmado real
            try {
                $row = $pdo->query("SELECT type FROM sqlite_master WHERE name='usuarios' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['type']) && strtolower($row['type']) === 'view') {
                    $pdo->exec("DROP VIEW IF EXISTS usuarios");
                                        $sqlUsuariosView = <<<'SQL'
CREATE VIEW usuarios AS
SELECT
    id,
    COALESCE(NULLIF(nombre, ''), username)      AS nombre,
    COALESCE(apellido, '')                      AS apellido,
    email,
    password,
    '$2y$10$aC0hWyiirkltF/gE8wp9Q.LutJ3iHmlHTrK65msZv25OUdofF4H32' AS password_hash,
    rol,
    email_confirmado                            AS email_confirmado,
    NULL                                        AS token_confirmacion,
    datetime('now')                             AS creado_en,
    datetime('now')                             AS fecha_registro,
    NULL                                        AS token_verificacion,
    1                                           AS verificado,
    tipo_global,
    dni,
    cbu,
    avatar_filename,
    creado_por_admin_id,
    evento_id
FROM usuarios_admin
WHERE activo = 1
SQL;
                                        $pdo->exec($sqlUsuariosView);
                }
            } catch (Exception $e) {
                // ignore view recreation errors
            }

            // email_logs: observabilidad de envíos de correo
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              resend_of_id INTEGER,
              context TEXT,
              related_table TEXT,
              related_id INTEGER,
              to_email TEXT NOT NULL,
              from_email TEXT,
              from_name TEXT,
              reply_to TEXT,
              subject TEXT,
              body TEXT,
              headers TEXT,
              extra_params TEXT,
              mail_ok INTEGER NOT NULL DEFAULT 0,
              error_text TEXT
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_created_at ON email_logs(created_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_to ON email_logs(to_email)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_context ON email_logs(context)");

                        // email_templates: plantillas editables para envíos del sistema
                        $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            context TEXT NOT NULL,
                            name TEXT,
                            enabled INTEGER NOT NULL DEFAULT 1,
                            is_html INTEGER NOT NULL DEFAULT 0,
                            from_email TEXT,
                            from_name TEXT,
                            reply_to TEXT,
                            extra_params TEXT,
                            subject TEXT NOT NULL,
                            body TEXT NOT NULL,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_email_templates_context ON email_templates(context)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_templates_enabled ON email_templates(enabled)");

                        // Seed de plantillas por defecto (no pisa si ya existen)
                        try {
                                $pdo->exec("INSERT OR IGNORE INTO email_templates (context,name,enabled,is_html,from_email,from_name,reply_to,extra_params,subject,body)
                                VALUES (
                                    'password_reset',
                                    'Recupero de contraseña',
                                    1,0,
                                    'no-reply@tickex.com.ar','Tickex','no-reply@tickex.com.ar','-f no-reply@tickex.com.ar',
                                    'Recuperá tu contraseña de Tickex',
                                    'Hola,\n\nRecibimos tu solicitud para restablecer la contraseña.\nHacé clic en el siguiente enlace para crear una nueva contraseña:\n{{link}}\n\nSi no solicitaste este cambio, ignorá este mensaje.\n\nTickex\n'
                                )");

                                $pdo->exec("INSERT OR IGNORE INTO email_templates (context,name,enabled,is_html,from_email,from_name,reply_to,extra_params,subject,body)
                                VALUES (
                                    'registro_step1',
                                    'Registro (confirmación de email)',
                                    1,0,
                                    'no-reply@tickex.com.ar','Tickex','no-reply@tickex.com.ar','-f no-reply@tickex.com.ar',
                                    'Confirmá tu email en Tickex',
                                    'Hola,\n\nPara continuar tu registro en Tickex, hacé clic en este enlace:\n{{link}}\n\nSi no fuiste vos, podés ignorar este mensaje.\n\nTickex\n'
                                )");

                                $pdo->exec("INSERT OR IGNORE INTO email_templates (context,name,enabled,is_html,from_email,from_name,reply_to,extra_params,subject,body)
                                VALUES (
                                    'registro_pendiente_step1',
                                    'Registro pendiente (reenvío confirmación)',
                                    1,0,
                                    'no-reply@tickex.com.ar','Tickex','no-reply@tickex.com.ar','-f no-reply@tickex.com.ar',
                                    'Confirmá tu email en Tickex',
                                    'Hola,\n\nPara continuar tu registro en Tickex, hacé clic en este enlace:\n{{link}}\n\nSi no fuiste vos, podés ignorar este mensaje.\n\nTickex\n'
                                )");

                                $pdo->exec("INSERT OR IGNORE INTO email_templates (context,name,enabled,is_html,from_email,from_name,reply_to,extra_params,subject,body)
                                VALUES (
                                    'entrada_registro',
                                    'Entrada STR (registro)',
                                    1,0,
                                    'no-reply@tickex.com.ar','Save The Rave','no-reply@tickex.com.ar','-f no-reply@tickex.com.ar',
                                    'Tu entrada #{{id}} para Save The Rave',
                                    'Hola {{nombre}},\n\n¡Gracias por registrarte en SAVE THE RAVE!\n\nDatos de tu entrada:\n  - Número de entrada: #{{id}}\n  - Nombre / alias: {{nombre}}\n  - Email registrado: {{email}}\n  - Tipo: {{tipo}}\n  - Fecha de registro: {{fecha_registro}}\n\nPara ver tu QR de acceso, abrí este link:\n{{ticket_url}}\n\nEn la puerta vamos a escanear ese QR para validar tu entrada.\nGuardá este mensaje hasta la fecha del evento.\n\nSave The Rave\ntickex.com.ar\n'
                                )");

                                $pdo->exec("INSERT OR IGNORE INTO email_templates (context,name,enabled,is_html,from_email,from_name,reply_to,extra_params,subject,body)
                                VALUES (
                                    'tickex_cortesia',
                                    'Cortesía (entrada emitida)',
                                    1,0,
                                    'info@tickex.com.ar','Tickex','info@tickex.com.ar','-f info@tickex.com.ar',
                                    '¡Recibiste una entrada de cortesía!',
                                    'Hola {{nombre}},\n\nTe han asignado una entrada de cortesía para el evento.\n\nCódigo de entrada: {{codigo}}\nTipo: {{tipo}}\nFecha de registro: {{fecha}}\n\nMostrá este código en la puerta del evento para ingresar.\n\nSi tenés dudas, respondé este email.\n\n¡Nos vemos!\nEquipo Tickex'
                                )");
                        } catch (Exception $e) {
                                // ignore seed errors
                        }

                        // registro_pendientes: fuente de datos de clientes (asegurar columnas usadas por revendedores)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            email TEXT NOT NULL,
                            token TEXT NOT NULL,
                            nombre TEXT,
                            apellido TEXT,
                            apodo TEXT,
                            dni TEXT,
                            cbu TEXT,
                            genero TEXT,
                            foto_path TEXT,
                            next_url TEXT,
                            creado_en TEXT,
                            completado_en TEXT,
                            password_hash TEXT
                        )");

                        // Tickex ID (apodo) en clientes: best-effort único (case-insensitive)
                        try {
                            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_registro_pendientes_apodo_unique_ci ON registro_pendientes(lower(apodo))");
                        } catch (Exception $e) {
                            // ignore (puede fallar si hay duplicados existentes)
                        }

                        try {
                            $colsRp = $pdo->query("PRAGMA table_info(registro_pendientes)")->fetchAll(PDO::FETCH_ASSOC);
                            $hasCbu = false;
                            $hasPass = false;
                            foreach ($colsRp as $c) {
                                if (isset($c['name']) && $c['name'] === 'cbu') $hasCbu = true;
                                if (isset($c['name']) && $c['name'] === 'password_hash') $hasPass = true;
                            }
                            if (!$hasCbu) {
                                $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN cbu TEXT");
                            }
                            if (!$hasPass) {
                                $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT");
                            }
                        } catch (Exception $e) {
                            // ignore
                        }

            // staff_eventos: asignación múltiple de staff a eventos
            $pdo->exec("CREATE TABLE IF NOT EXISTS staff_eventos (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              staff_id INTEGER NOT NULL,
              evento_id INTEGER NOT NULL,
              UNIQUE(staff_id, evento_id)
            )");

                        // staff_admins: relación cliente -> admin (rol staff adicional, sin perder rol cliente)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_admins (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            owner_admin_id INTEGER NOT NULL,
                            cliente_id INTEGER NOT NULL,
                            rol_staff TEXT,
                            activo INTEGER NOT NULL DEFAULT 1,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            UNIQUE(owner_admin_id, cliente_id)
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_admins_owner ON staff_admins(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_admins_cliente ON staff_admins(cliente_id)");

                        // staff_admin_invitaciones: invitación por email con aceptación obligatoria
                        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_admin_invitaciones (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            owner_admin_id INTEGER NOT NULL,
                            email TEXT NOT NULL,
                            token TEXT,
                            mensaje TEXT,
                            rol_staff TEXT,
                            estado TEXT NOT NULL DEFAULT 'pending',
                            cliente_id INTEGER,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_owner ON staff_admin_invitaciones(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_email ON staff_admin_invitaciones(email)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_estado ON staff_admin_invitaciones(estado)");

                        // revendedores: entidad separada vinculada a usuarios_admin (si aplica)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS revendedores (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            usuario_admin_id INTEGER,
                            owner_admin_id INTEGER,
                            cliente_id INTEGER,
                            codigo TEXT,
                            nombre TEXT,
                            comision_percent REAL NOT NULL DEFAULT 0,
                            activo INTEGER NOT NULL DEFAULT 1,
                            created_at TEXT NOT NULL DEFAULT (datetime('now'))
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_revendedores_codigo ON revendedores(codigo)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revendedores_usuario ON revendedores(usuario_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revendedores_owner ON revendedores(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revendedores_cliente ON revendedores(cliente_id)");

                        // Backfill columnas en bases viejas
                        try {
                            $colsRev = $pdo->query("PRAGMA table_info(revendedores)")->fetchAll(PDO::FETCH_ASSOC);
                            $hasOwner = false; $hasCliente = false;
                            foreach ($colsRev as $c) {
                                if (isset($c['name']) && $c['name'] === 'owner_admin_id') $hasOwner = true;
                                if (isset($c['name']) && $c['name'] === 'cliente_id') $hasCliente = true;
                            }
                            if (!$hasOwner) {
                                $pdo->exec("ALTER TABLE revendedores ADD COLUMN owner_admin_id INTEGER");
                            }
                            if (!$hasCliente) {
                                $pdo->exec("ALTER TABLE revendedores ADD COLUMN cliente_id INTEGER");
                            }
                        } catch (Exception $e) {
                            // ignore
                        }

                        // Solicitudes de revendedor (clientes -> admin/evento)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS revendedor_solicitudes (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            cliente_id INTEGER NOT NULL,
                            cliente_email TEXT,
                            evento_id INTEGER,
                            owner_admin_id INTEGER,
                            mensaje TEXT,
                            estado TEXT NOT NULL DEFAULT 'pending',
                            revendedor_id INTEGER,
                                                        direction TEXT NOT NULL DEFAULT 'client_to_admin',
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_estado ON revendedor_solicitudes(estado)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_owner ON revendedor_solicitudes(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_cliente ON revendedor_solicitudes(cliente_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_evento ON revendedor_solicitudes(evento_id)");
                                                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_direction ON revendedor_solicitudes(direction)");

                                                // Backfill direction si la tabla existía sin esa columna
                                                try {
                                                    $colsSol = $pdo->query("PRAGMA table_info(revendedor_solicitudes)")->fetchAll(PDO::FETCH_ASSOC);
                                                    $hasDir = false;
                                                    foreach ($colsSol as $c) {
                                                        if (isset($c['name']) && $c['name'] === 'direction') { $hasDir = true; break; }
                                                    }
                                                    if (!$hasDir) {
                                                        $pdo->exec("ALTER TABLE revendedor_solicitudes ADD COLUMN direction TEXT NOT NULL DEFAULT 'client_to_admin'");
                                                    }
                                                    // Normalizar nulos/vacíos
                                                    $pdo->exec("UPDATE revendedor_solicitudes SET direction='client_to_admin' WHERE direction IS NULL OR direction='' ");
                                                } catch (Exception $e) {
                                                    // ignore
                                                }

                        // Retiros solicitados por revendedores
                        $pdo->exec("CREATE TABLE IF NOT EXISTS revendedor_retiros (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            revendedor_id INTEGER NOT NULL,
                            owner_admin_id INTEGER,
                            cliente_id INTEGER,
                            amount REAL NOT NULL,
                            cbu TEXT,
                            estado TEXT NOT NULL DEFAULT 'pending',
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_retiros_estado ON revendedor_retiros(estado)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_retiros_owner ON revendedor_retiros(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_retiros_rev ON revendedor_retiros(revendedor_id)");

                        // tc_orders: registro mínimo de órdenes/checkout TotalCoin (para auditoría y atribución)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS tc_orders (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            request_id TEXT,
                            state TEXT,
                            evento_id INTEGER,
                            ref TEXT,
                            concept TEXT,
                            amount REAL,
                            buyer_dni TEXT,
                            buyer_last TEXT,
                            buyer_first TEXT,
                            buyer_email TEXT,
                            revendedor_id INTEGER,
                            selected_tickets_json TEXT,
                            payment_url TEXT,
                            ip TEXT,
                            user_agent TEXT,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT,
                            processed_at TEXT
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tc_orders_request_id ON tc_orders(request_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tc_orders_revendedor ON tc_orders(revendedor_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tc_orders_evento ON tc_orders(evento_id)");
                        $colsTcOrders = $pdo->query("PRAGMA table_info(tc_orders)")->fetchAll(PDO::FETCH_ASSOC);
                        $hasProcessedAt = false;
                        foreach ($colsTcOrders as $c) {
                            if (isset($c['name']) && $c['name'] === 'processed_at') {
                                $hasProcessedAt = true;
                                break;
                            }
                        }
                        if (!$hasProcessedAt) {
                            $pdo->exec("ALTER TABLE tc_orders ADD COLUMN processed_at TEXT");
                        }

                        $pdo->exec("CREATE TABLE IF NOT EXISTS order_events (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            tc_order_id INTEGER,
                            request_id TEXT,
                            event_type TEXT NOT NULL,
                            payload_json TEXT,
                            created_at TEXT NOT NULL DEFAULT (datetime('now'))
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_events_tc_order_id ON order_events(tc_order_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_events_request_id ON order_events(request_id)");

                        $colsEntradas = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
                        $hasOrderRequestId = false;
                        foreach ($colsEntradas as $c) {
                            if (isset($c['name']) && $c['name'] === 'tc_order_request_id') {
                                $hasOrderRequestId = true;
                                break;
                            }
                        }
                        if (!empty($colsEntradas) && !$hasOrderRequestId) {
                            $pdo->exec("ALTER TABLE entradas ADD COLUMN tc_order_request_id TEXT");
                            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_entradas_tc_order_request_id ON entradas(tc_order_request_id)");
                        }

            // tipos_entrada: visibilidad y fecha de corte
            $colsTe = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
            $hasVisTe = false; $hasVentaHastaTe = false;
            foreach ($colsTe as $c) {
                if (isset($c['name']) && $c['name'] === 'visible_publico') { $hasVisTe = true; }
                if (isset($c['name']) && $c['name'] === 'venta_hasta') { $hasVentaHastaTe = true; }
            }
            if (!$hasVisTe) {
                $pdo->exec("ALTER TABLE tipos_entrada ADD COLUMN visible_publico INTEGER DEFAULT 1");
            }
            if (!$hasVentaHastaTe) {
                $pdo->exec("ALTER TABLE tipos_entrada ADD COLUMN venta_hasta TEXT");
            }

            // plantillas_entrada: visibilidad y fecha de corte
            $colsPe = $pdo->query("PRAGMA table_info(plantillas_entrada)")->fetchAll(PDO::FETCH_ASSOC);
            $hasVisPe = false; $hasVentaHastaPe = false;
            foreach ($colsPe as $c) {
                if (isset($c['name']) && $c['name'] === 'visible_publico') { $hasVisPe = true; }
                if (isset($c['name']) && $c['name'] === 'venta_hasta') { $hasVentaHastaPe = true; }
            }
            if (!$hasVisPe) {
                $pdo->exec("ALTER TABLE plantillas_entrada ADD COLUMN visible_publico INTEGER DEFAULT 1");
            }
            if (!$hasVentaHastaPe) {
                $pdo->exec("ALTER TABLE plantillas_entrada ADD COLUMN venta_hasta TEXT");
            }
        } catch (Exception $e) {
            // ignore schema checks errors
        }
    } catch (Exception $ex) {
        die("Error DB: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    return $pdo;
}
