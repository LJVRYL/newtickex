    if (empty($errores)) {
        try {
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Generar hash de contraseña
            $passwordHash = password_hash($pass, PASSWORD_DEFAULT);

            // Generar token de confirmación
            if (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(16));
            } else {
                $token = sha1(uniqid(mt_rand(), true));
            }

            $ahora = date('Y-m-d H:i:s');

            $sql = "
                INSERT INTO usuarios
                    (nombre, apellido, email, dni, password_hash, rol, email_confirmado, token_confirmacion, creado_en)
                VALUES
                    (:nombre, :apellido, :email, :dni, :pass, 'cliente', 0, :token, :creado_en)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(
                ':nombre'    => $nombre,
                ':apellido'  => $apellido,
                ':email'     => $email,
                ':dni'       => $dni,
                ':pass'      => $passwordHash,
                ':token'     => $token,
                ':creado_en' => $ahora,
            ));

            $nombreCompleto = trim($nombre . ' ' . $apellido);
            $mailOk = enviar_mail_confirmacion($email, $nombreCompleto, $token);

            $mensajeOk = 'Te enviamos un email de confirmación a ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.';

        } catch (Exception $e) {
            $errores[] = 'Error al registrar el usuario: ' . $e->getMessage();
        }
    }
