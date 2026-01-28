<?php
// inc/auth.php — utilidades mínimas de autenticación y sesión

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
	}
}

// Devuelve un array con los datos presentes en sesión
if (!function_exists('current_user')) {
	function current_user()
	{
		$u = array();

		// IDs conocidos
		if (isset($_SESSION['usuario_id'])) {
			$u['usuario_id'] = (int) $_SESSION['usuario_id'];
			$u['id'] = (int) $_SESSION['usuario_id'];
		}
		if (isset($_SESSION['user_id'])) {
			$u['user_id'] = (int) $_SESSION['user_id'];
			if (!isset($u['id'])) {
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

