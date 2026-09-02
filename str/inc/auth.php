<?php
// inc/auth.php — utilidades mínimas de autenticación y sesión

if (!function_exists('tickex_clear_identity_session')) {
	function tickex_clear_identity_session()
	{
		$keys = array(
			'auth_context', 'usuario_id', 'user_id', 'admin_id', 'es_admin', 'is_admin',
			'usuario', 'username', 'usuario_email', 'email', 'usuario_nombre', 'nombre',
			'first_name', 'last_name', 'apellido', 'dni', 'usuario_rol', 'rol',
			'tipo_global', 'rol_evento', 'evento_id'
		);
		foreach ($keys as $key) unset($_SESSION[$key]);
	}
}

if (!function_exists('tickex_normalize_session_identity')) {
	function tickex_normalize_session_identity()
	{
		$role = isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '';
		$isAdminRole = in_array($role, array('super_admin', 'superadmin', 'admin_evento', 'staff_evento'), true);

		if ($isAdminRole && !empty($_SESSION['user_id'])) {
			// user_id es el identificador canónico de usuarios_admin. Corrige sesiones
			// antiguas que conservaron un admin_id de otra cuenta.
			$_SESSION['admin_id'] = (int)$_SESSION['user_id'];
			$_SESSION['es_admin'] = true;
			$_SESSION['is_admin'] = true;
			$_SESSION['auth_context'] = 'admin';
			return;
		}

		if (!empty($_SESSION['usuario_id'])) {
			// Una sesión de comprador nunca debe heredar privilegios administrativos.
			unset($_SESSION['admin_id'], $_SESSION['user_id'], $_SESSION['es_admin'], $_SESSION['is_admin'], $_SESSION['evento_id'], $_SESSION['rol_evento']);
			$_SESSION['auth_context'] = 'user';
		}
	}
}

if (!function_exists('tickex_is_email_blocked')) {
	function tickex_is_email_blocked($pdo, $email)
	{
		$email = trim((string)$email);
		if ($email === '') return false;

		try {
			$st = $pdo->prepare("SELECT 1 FROM user_blocks WHERE active = 1 AND lower(email) = lower(:e) LIMIT 1");
			$st->execute(array(':e' => $email));
			return (bool)$st->fetchColumn();
		} catch (Exception $e) {
			return false;
		}
	}
}

if (!function_exists('tickex_force_logout_if_blocked')) {
	function tickex_force_logout_if_blocked()
	{
		$email = '';
		if (!empty($_SESSION['usuario_email'])) {
			$email = (string)$_SESSION['usuario_email'];
		} elseif (!empty($_SESSION['email'])) {
			$email = (string)$_SESSION['email'];
		}

		if ($email === '') return;

		try {
			$pdo = db();
			if (!tickex_is_email_blocked($pdo, $email)) return;
		} catch (Exception $e) {
			return;
		}

		$_SESSION = array();
		if (session_id() !== '') {
			@session_destroy();
		}
		header('Location: login.php?blocked=1');
		exit;
	}
}

// Redirige a login si no hay usuario (común o admin) en sesión
if (!function_exists('require_login')) {
	function require_login()
	{
		$hasUser  = !empty($_SESSION['usuario_id']);
		$hasAdmin = !empty($_SESSION['es_admin']) || !empty($_SESSION['admin_id']) || !empty($_SESSION['user_id']);

		if (!$hasUser && !$hasAdmin) {
			header('Location: login.php');
			exit;
		}

		tickex_force_logout_if_blocked();
	}
}

// Devuelve un array con los datos presentes en sesión
if (!function_exists('current_user')) {
	function current_user()
	{
		$u = array();
		$isAdminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';

		// IDs conocidos
		if (isset($_SESSION['usuario_id'])) {
			$u['usuario_id'] = (int) $_SESSION['usuario_id'];
			if (!$isAdminContext) $u['id'] = (int) $_SESSION['usuario_id'];
		}
		if (isset($_SESSION['user_id'])) {
			$u['user_id'] = (int) $_SESSION['user_id'];
			if ($isAdminContext || !isset($u['id'])) {
				$u['id'] = (int) $_SESSION['user_id'];
			}
		}
		if (isset($_SESSION['admin_id'])) {
			$u['admin_id'] = (int) $_SESSION['admin_id'];
			if (!isset($u['id'])) {
				$u['id'] = (int) $_SESSION['admin_id'];
			}
		}

		// Flags
		$u['es_admin'] = !empty($_SESSION['es_admin']);
		$u['is_admin'] = $u['es_admin'];

		// Nombre de usuario / nombre completo
		if (isset($_SESSION['usuario'])) {
			$u['username'] = $_SESSION['usuario'];
		}
		if (isset($_SESSION['username'])) {
			$u['username'] = $_SESSION['username'];
		}
		if (isset($_SESSION['usuario_nombre'])) {
			$u['nombre'] = $_SESSION['usuario_nombre'];
		}
		if (isset($_SESSION['nombre'])) {
			$u['nombre'] = $_SESSION['nombre'];
		}

		// Email
		if (isset($_SESSION['usuario_email'])) {
			$u['email'] = $_SESSION['usuario_email'];
		} elseif (isset($_SESSION['email'])) {
			$u['email'] = $_SESSION['email'];
		}

		// Roles
		if (isset($_SESSION['rol'])) {
			$u['rol'] = $_SESSION['rol'];
		} elseif (isset($_SESSION['usuario_rol'])) {
			$u['rol'] = $_SESSION['usuario_rol'];
		}
		if (isset($_SESSION['tipo_global'])) {
			$u['tipo_global'] = $_SESSION['tipo_global'];
		}
		if (isset($_SESSION['rol_evento'])) {
			$u['rol_evento'] = $_SESSION['rol_evento'];
		}

		// Nombre a mostrar
		if (!isset($u['display_name'])) {
			if (!empty($u['nombre'])) {
				$u['display_name'] = $u['nombre'];
			} elseif (!empty($u['username'])) {
				$u['display_name'] = $u['username'];
			} elseif (!empty($u['email'])) {
				$u['display_name'] = $u['email'];
			}
		}

		return $u;
	}
}

// Helper por compatibilidad
if (!function_exists('is_admin')) {
	function is_admin()
	{
		return !empty($_SESSION['es_admin']) || !empty($_SESSION['admin_id']) || !empty($_SESSION['user_id']);
	}
}

// También sanea las sesiones ya abiertas antes de desplegar esta corrección.
tickex_normalize_session_identity();

