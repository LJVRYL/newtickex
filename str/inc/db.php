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

        // Bloqueos de acceso por email (suspension/ban de login)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS user_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                reason TEXT,
                active INTEGER NOT NULL DEFAULT 1,
                blocked_at TEXT NOT NULL DEFAULT (datetime("now")),
                blocked_by_admin_id INTEGER,
                unblocked_at TEXT,
                unblocked_by_admin_id INTEGER
            )');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_blocks_email_ci ON user_blocks(lower(email))");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_blocks_active ON user_blocks(active)");
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 1: audiencias reutilizables (definición de filtros, no miembros)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_audiences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                created_by_admin_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT,
                filters_json TEXT,
                status TEXT NOT NULL DEFAULT "active",
                last_used_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_aud_org_slug ON communication_audiences(organization_id, slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_aud_org_status ON communication_audiences(organization_id, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_aud_created_by ON communication_audiences(created_by_admin_id)");
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 1: plantillas reutilizables (solo administración)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                created_by_admin_id INTEGER,
                source_type TEXT NOT NULL DEFAULT "custom",
                parent_template_id INTEGER,
                is_system_locked INTEGER NOT NULL DEFAULT 0,
                template_type TEXT NOT NULL DEFAULT "general",
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT,
                subject_template TEXT NOT NULL,
                body_html_template TEXT,
                body_text_template TEXT,
                variables_schema_json TEXT,
                sample_data_json TEXT,
                status TEXT NOT NULL DEFAULT "active",
                last_used_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_tpl_org_slug ON communication_templates(organization_id, slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_tpl_org_status ON communication_templates(organization_id, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_tpl_type ON communication_templates(template_type)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_tpl_source ON communication_templates(source_type)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_tpl_created_by ON communication_templates(created_by_admin_id)");
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 1: campañas (objeto de negocio independiente del envío)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                created_by_admin_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT "draft",
                audience_id INTEGER,
                template_id INTEGER,
                subject_override TEXT,
                notes_internal TEXT,
                scheduled_for TEXT,
                scheduled_timezone TEXT,
                sending_started_at TEXT,
                sent_at TEXT,
                cancelled_at TEXT,
                failed_at TEXT,
                snapshot_subject TEXT,
                snapshot_body_html TEXT,
                snapshot_body_text TEXT,
                snapshot_taken_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_campaign_org_slug ON communication_campaigns(organization_id, slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_campaign_org_status ON communication_campaigns(organization_id, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_campaign_audience ON communication_campaigns(audience_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_campaign_template ON communication_campaigns(template_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_campaign_created_by ON communication_campaigns(created_by_admin_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_campaign_scheduled_for ON communication_campaigns(scheduled_for)");
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 2: configuración y logs de transporte
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_transport_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 0,
                channel TEXT NOT NULL DEFAULT "email",
                provider_name TEXT NOT NULL DEFAULT "legacy_mail_php",
                enabled INTEGER NOT NULL DEFAULT 1,
                config_json TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(organization_id, channel)
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_transport_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                organization_id INTEGER,
                campaign_id INTEGER,
                campaign_run_id INTEGER,
                recipient_fingerprint TEXT,
                provider_name TEXT,
                status TEXT,
                response_code TEXT,
                response_message TEXT,
                provider_message_id TEXT,
                latency_ms INTEGER,
                classification_reason TEXT
            )');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_transport_cfg_org_channel ON communication_transport_configs(organization_id, channel)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_transport_logs_run ON communication_transport_logs(campaign_run_id)");

            $stCfg = $pdo->prepare('INSERT OR IGNORE INTO communication_transport_configs (organization_id, channel, provider_name, enabled, config_json, created_at, updated_at) VALUES (0, :ch, :pn, 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $stCfg->execute(array(':ch' => 'email', ':pn' => 'legacy_mail_php'));
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 2: motor de ejecución (cola + runs + intentos)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_execution_commands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                campaign_id INTEGER NOT NULL,
                command_type TEXT NOT NULL DEFAULT "execute_campaign",
                request_key TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "queued",
                payload_json TEXT,
                scheduled_for TEXT,
                locked_by TEXT,
                lock_expires_at TEXT,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                result_json TEXT,
                error_text TEXT,
                created_by_admin_id INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(request_key)
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                campaign_id INTEGER NOT NULL,
                command_id INTEGER,
                status TEXT NOT NULL DEFAULT "requested",
                started_at TEXT,
                finished_at TEXT,
                snapshot_subject TEXT,
                snapshot_body_html TEXT,
                snapshot_body_text TEXT,
                snapshot_taken_at TEXT,
                audience_filters_json TEXT,
                resolved_recipients INTEGER NOT NULL DEFAULT 0,
                processed_count INTEGER NOT NULL DEFAULT 0,
                accepted_count INTEGER NOT NULL DEFAULT 0,
                rejected_count INTEGER NOT NULL DEFAULT 0,
                transient_error_count INTEGER NOT NULL DEFAULT 0,
                permanent_error_count INTEGER NOT NULL DEFAULT 0,
                skipped_duplicate_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_run_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                recipient_email TEXT,
                recipient_name TEXT,
                recipient_fingerprint TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "queued",
                attempt_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                last_response_code TEXT,
                last_response_message TEXT,
                provider_name TEXT,
                provider_message_id TEXT,
                locked_until TEXT,
                last_attempt_at TEXT,
                processed_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(run_id, recipient_fingerprint)
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_delivery_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                recipient_fingerprint TEXT NOT NULL,
                attempt_no INTEGER NOT NULL,
                provider_name TEXT,
                transport_status TEXT,
                response_code TEXT,
                response_message TEXT,
                provider_message_id TEXT,
                latency_ms INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_exec_cmd_status ON communication_execution_commands(status, scheduled_for)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_exec_cmd_campaign ON communication_execution_commands(campaign_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_runs_campaign ON communication_campaign_runs(campaign_id, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_run_rcpt_run_status ON communication_campaign_run_recipients(run_id, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_run_rcpt_campaign_fp ON communication_campaign_run_recipients(campaign_id, recipient_fingerprint, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_attempts_run ON communication_campaign_delivery_attempts(run_id, recipient_fingerprint)");
        } catch (Exception $e) {
            // no bloquear si falla
        }

        // Comunicación Fase 3: logging operativo unificado
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS communication_module_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                organization_id INTEGER NOT NULL DEFAULT 1,
                campaign_id INTEGER,
                run_id INTEGER,
                command_id INTEGER,
                component TEXT NOT NULL,
                level TEXT NOT NULL DEFAULT "info",
                event_name TEXT NOT NULL,
                message TEXT,
                context_json TEXT,
                event_key TEXT
            )');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_created ON communication_module_logs(created_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_component ON communication_module_logs(component, level)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_campaign ON communication_module_logs(campaign_id, run_id)");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_mod_logs_event_key ON communication_module_logs(event_key)");
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

                        // Access Links: emisión reutilizable por link configurable
                        $pdo->exec("CREATE TABLE IF NOT EXISTS access_links (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            uuid TEXT NOT NULL,
                            code TEXT NOT NULL,
                            label TEXT NOT NULL,
                            evento_id INTEGER NOT NULL,
                            access_type TEXT NOT NULL DEFAULT 'free',
                            status TEXT NOT NULL DEFAULT 'draft',
                            starts_at TEXT,
                            expires_at TEXT,
                            max_uses INTEGER,
                            captcha_required INTEGER NOT NULL DEFAULT 1,
                            unique_email INTEGER NOT NULL DEFAULT 1,
                            unique_dni INTEGER NOT NULL DEFAULT 1,
                            ip_limit_window_seconds INTEGER,
                            ip_limit_max_uses INTEGER,
                            rate_limit_window_seconds INTEGER,
                            rate_limit_max_requests INTEGER,
                            ticket_type_id INTEGER NOT NULL,
                            notes TEXT,
                            created_by_admin_id INTEGER,
                            updated_by_admin_id INTEGER,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_access_links_uuid ON access_links(uuid)");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_access_links_code ON access_links(code)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_links_evento ON access_links(evento_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_links_status ON access_links(status)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_links_expires ON access_links(expires_at)");

                        $pdo->exec("CREATE TABLE IF NOT EXISTS access_link_issues (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            access_link_id INTEGER NOT NULL,
                            evento_id INTEGER NOT NULL,
                            entrada_id INTEGER NOT NULL,
                            email_normalized TEXT,
                            dni_normalized TEXT,
                            ip_address TEXT,
                            user_agent TEXT,
                            issued_by TEXT,
                            created_at TEXT NOT NULL DEFAULT (datetime('now'))
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_issues_link ON access_link_issues(access_link_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_issues_evento ON access_link_issues(evento_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_issues_email ON access_link_issues(email_normalized)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_issues_dni ON access_link_issues(dni_normalized)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_issues_ip ON access_link_issues(ip_address)");

                        $pdo->exec("CREATE TABLE IF NOT EXISTS access_link_attempts (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            trace_id TEXT,
                            access_link_id INTEGER,
                            evento_id INTEGER,
                            ip_address TEXT,
                            email_normalized TEXT,
                            dni_normalized TEXT,
                            captcha_ok INTEGER NOT NULL DEFAULT 0,
                            result TEXT NOT NULL,
                            detail TEXT,
                            created_at TEXT NOT NULL DEFAULT (datetime('now'))
                        )");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_attempts_trace ON access_link_attempts(trace_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_attempts_link_time ON access_link_attempts(access_link_id, created_at)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_attempts_ip_time ON access_link_attempts(ip_address, created_at)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_link_attempts_result ON access_link_attempts(result)");

                        // Backfill columnas nuevas de entradas para trazabilidad de emisión por access link
                        $hasPaymentMethod = false;
                        $hasBuyerDni = false;
                        $hasBuyerPhone = false;
                        $hasAccessLinkId = false;
                        foreach ($colsEntradas as $c) {
                            if (!isset($c['name'])) continue;
                            if ($c['name'] === 'payment_method') $hasPaymentMethod = true;
                            if ($c['name'] === 'buyer_dni') $hasBuyerDni = true;
                            if ($c['name'] === 'buyer_phone') $hasBuyerPhone = true;
                            if ($c['name'] === 'access_link_id') $hasAccessLinkId = true;
                        }
                        if (!empty($colsEntradas) && !$hasPaymentMethod) {
                            $pdo->exec("ALTER TABLE entradas ADD COLUMN payment_method TEXT");
                        }
                        if (!empty($colsEntradas) && !$hasBuyerDni) {
                            $pdo->exec("ALTER TABLE entradas ADD COLUMN buyer_dni TEXT");
                        }
                        if (!empty($colsEntradas) && !$hasBuyerPhone) {
                            $pdo->exec("ALTER TABLE entradas ADD COLUMN buyer_phone TEXT");
                        }
                        if (!empty($colsEntradas) && !$hasAccessLinkId) {
                            $pdo->exec("ALTER TABLE entradas ADD COLUMN access_link_id INTEGER");
                            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_entradas_access_link_id ON entradas(access_link_id)");
                        }

                        // Checkout free simple por evento
                        $pdo->exec("CREATE TABLE IF NOT EXISTS event_free_checkout_configs (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            evento_id INTEGER NOT NULL UNIQUE,
                            enabled INTEGER NOT NULL DEFAULT 0,
                            ticket_type_id INTEGER NOT NULL,
                            max_uses INTEGER,
                            captcha_required INTEGER NOT NULL DEFAULT 1,
                            unique_email INTEGER NOT NULL DEFAULT 1,
                            created_by_admin_id INTEGER,
                            updated_by_admin_id INTEGER,
                            created_at TEXT NOT NULL DEFAULT (datetime('now')),
                            updated_at TEXT
                        )");
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_free_checkout_evento ON event_free_checkout_configs(evento_id)");

                        // Backfill de columnas en tablas access_links existentes
                        try {
                            $colsAl = $pdo->query("PRAGMA table_info(access_links)")->fetchAll(PDO::FETCH_ASSOC);
                            $has = array();
                            foreach ($colsAl as $c) {
                                if (isset($c['name'])) $has[$c['name']] = true;
                            }
                            if (!isset($has['uuid'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN uuid TEXT");
                            if (!isset($has['code'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN code TEXT");
                            if (!isset($has['label'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN label TEXT");
                            if (!isset($has['evento_id'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN evento_id INTEGER");
                            if (!isset($has['access_type'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN access_type TEXT NOT NULL DEFAULT 'free'");
                            if (!isset($has['status'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN status TEXT NOT NULL DEFAULT 'draft'");
                            if (!isset($has['starts_at'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN starts_at TEXT");
                            if (!isset($has['expires_at'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN expires_at TEXT");
                            if (!isset($has['max_uses'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN max_uses INTEGER");
                            if (!isset($has['captcha_required'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN captcha_required INTEGER NOT NULL DEFAULT 1");
                            if (!isset($has['unique_email'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN unique_email INTEGER NOT NULL DEFAULT 1");
                            if (!isset($has['unique_dni'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN unique_dni INTEGER NOT NULL DEFAULT 1");
                            if (!isset($has['ip_limit_window_seconds'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN ip_limit_window_seconds INTEGER");
                            if (!isset($has['ip_limit_max_uses'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN ip_limit_max_uses INTEGER");
                            if (!isset($has['rate_limit_window_seconds'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN rate_limit_window_seconds INTEGER");
                            if (!isset($has['rate_limit_max_requests'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN rate_limit_max_requests INTEGER");
                            if (!isset($has['ticket_type_id'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN ticket_type_id INTEGER");
                            if (!isset($has['notes'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN notes TEXT");
                            if (!isset($has['created_by_admin_id'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN created_by_admin_id INTEGER");
                            if (!isset($has['updated_by_admin_id'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN updated_by_admin_id INTEGER");
                            if (!isset($has['created_at'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN created_at TEXT");
                            if (!isset($has['updated_at'])) $pdo->exec("ALTER TABLE access_links ADD COLUMN updated_at TEXT");
                        } catch (Exception $e) {
                            // ignore
                        }

                        // Backfill de columnas nuevas en issues/attempts
                        try {
                            $colsAi = $pdo->query("PRAGMA table_info(access_link_issues)")->fetchAll(PDO::FETCH_ASSOC);
                            $hasIssuedBy = false;
                            foreach ($colsAi as $c) {
                                if (isset($c['name']) && $c['name'] === 'issued_by') { $hasIssuedBy = true; break; }
                            }
                            if (!$hasIssuedBy) $pdo->exec("ALTER TABLE access_link_issues ADD COLUMN issued_by TEXT");
                        } catch (Exception $e) {
                            // ignore
                        }
                        try {
                            $colsAa = $pdo->query("PRAGMA table_info(access_link_attempts)")->fetchAll(PDO::FETCH_ASSOC);
                            $hasTrace = false;
                            foreach ($colsAa as $c) {
                                if (isset($c['name']) && $c['name'] === 'trace_id') { $hasTrace = true; break; }
                            }
                            if (!$hasTrace) $pdo->exec("ALTER TABLE access_link_attempts ADD COLUMN trace_id TEXT");
                        } catch (Exception $e) {
                            // ignore
                        }
                        try {
                            $colsFq = $pdo->query("PRAGMA table_info(event_free_checkout_configs)")->fetchAll(PDO::FETCH_ASSOC);
                            $hasFq = array();
                            foreach ($colsFq as $c) {
                                if (isset($c['name'])) $hasFq[$c['name']] = true;
                            }
                            if (!isset($hasFq['enabled'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN enabled INTEGER NOT NULL DEFAULT 0");
                            if (!isset($hasFq['ticket_type_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN ticket_type_id INTEGER");
                            if (!isset($hasFq['max_uses'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN max_uses INTEGER");
                            if (!isset($hasFq['captcha_required'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN captcha_required INTEGER NOT NULL DEFAULT 1");
                            if (!isset($hasFq['unique_email'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN unique_email INTEGER NOT NULL DEFAULT 1");
                            if (!isset($hasFq['created_by_admin_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN created_by_admin_id INTEGER");
                            if (!isset($hasFq['updated_by_admin_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN updated_by_admin_id INTEGER");
                            if (!isset($hasFq['created_at'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN created_at TEXT");
                            if (!isset($hasFq['updated_at'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN updated_at TEXT");
                        } catch (Exception $e) {
                            // ignore
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
