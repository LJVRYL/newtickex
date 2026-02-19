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
        // Es un no-op si SQLite no soporta alguna PRAGMA.
        try {
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } catch (Exception $e) {
            // ignore
        }

        // Ensure latest columns exist (idempotent)
        try {
            $cols = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
            $hasApellido = false;
            $hasEmailConfirmado = false;
            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'apellido') { $hasApellido = true; break; }
            }
            if (!$hasApellido) {
                $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN apellido TEXT");
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

            // staff_eventos: asignación múltiple de staff a eventos
            $pdo->exec("CREATE TABLE IF NOT EXISTS staff_eventos (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              staff_id INTEGER NOT NULL,
              evento_id INTEGER NOT NULL,
              UNIQUE(staff_id, evento_id)
            )");

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
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_estado ON revendedor_solicitudes(estado)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_owner ON revendedor_solicitudes(owner_admin_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_cliente ON revendedor_solicitudes(cliente_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revsol_evento ON revendedor_solicitudes(evento_id)");

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
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tc_orders_request_id ON tc_orders(request_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tc_orders_revendedor ON tc_orders(revendedor_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tc_orders_evento ON tc_orders(evento_id)");

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
