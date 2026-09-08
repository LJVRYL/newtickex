<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/notificaciones.php';
require_once __DIR__ . '/inc/secure_links.php';
require_once __DIR__ . '/inc/customer_portal.php';
require_once __DIR__ . '/inc/mail.php';

require_login();
$cu = current_user();
$usuarioId = isset($_SESSION['auth_context'], $_SESSION['usuario_id']) && $_SESSION['auth_context'] === 'user'
    ? (int)$_SESSION['usuario_id'] : 0;
if ($usuarioId <= 0) {
    header('Location: ' . tickex_route('login', array()) . '?next=' . urlencode(tickex_route('customer_home', array())));
    exit;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
tickex_customer_portal_ensure_schema($pdo);
$user = tickex_customer_portal_user($pdo, $usuarioId);
if (!$user) {
    tickex_clear_identity_session();
    header('Location: ' . tickex_route('login', array()));
    exit;
}

$email = (string)$user['email'];
$csrf = tickex_csrf_token();
$allowedFilters = array('vigentes', 'utilizadas', 'vencidas', 'canceladas', 'ocultas', 'todas');
$filter = isset($_GET['estado']) && in_array((string)$_GET['estado'], $allowedFilters, true)
    ? (string)$_GET['estado'] : 'vigentes';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $returnFilter = isset($_POST['estado']) && in_array((string)$_POST['estado'], $allowedFilters, true)
        ? (string)$_POST['estado'] : 'vigentes';
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        $flashError = 'La sesión venció. Actualizá la página e intentá nuevamente.';
    } else {
        $ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        if ($action === 'hide_ticket' || $action === 'restore_ticket') {
            $ok = tickex_customer_portal_set_hidden($pdo, $email, $ticketId, $action === 'hide_ticket');
            if ($ok) {
                flash('ok', $action === 'hide_ticket' ? 'Entrada archivada.' : 'Entrada restaurada.');
                header('Location: ' . tickex_route('customer_home', array()) . '?estado=' . urlencode($returnFilter));
                exit;
            }
            $flashError = 'No encontramos esa entrada dentro de tu cuenta.';
        } elseif ($action === 'resend_ticket') {
            $own = $pdo->prepare('SELECT id,codigo FROM entradas WHERE id=:id AND lower(trim(email))=lower(trim(:email)) LIMIT 1');
            $own->execute(array(':id' => $ticketId, ':email' => $email));
            $entry = $own->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                $flashError = 'No encontramos esa entrada dentro de tu cuenta.';
            } else {
                $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'str.tickex.com.ar';
                $ticketUrl = tickex_secure_ticket_url($pdo, $scheme . '://' . $host, (int)$entry['id'], (string)$entry['codigo']);
                $sender = function ($ticket, $url) {
                    $subject = 'Tu entrada para ' . $ticket['evento_nombre'];
                    $body = "Hola " . $ticket['nombre'] . ",\n\nTe reenviamos tu entrada para " . $ticket['evento_nombre'] . ".\n\n";
                    $body .= "Abrila desde este enlace seguro:\n" . $url . "\n\nTickex\n";
                    return tickex_send_mail_template($ticket['email'], 'entrada_registro', array(
                        'id' => $ticket['id'], 'nombre' => $ticket['nombre'], 'email' => $ticket['email'],
                        'tipo' => $ticket['tipo'], 'fecha_registro' => $ticket['fecha_registro'],
                        'ticket_url' => $url, 'codigo' => $ticket['codigo'],
                    ), array(
                        'context' => 'customer_ticket_resend', 'related_table' => 'entradas', 'related_id' => (int)$ticket['id'],
                    ), array(
                        'subject' => $subject, 'body' => $body, 'from_email' => 'servicio@tickex.com.ar',
                        'from_name' => 'Tickex', 'reply_to' => 'servicio@tickex.com.ar',
                        'extra_params' => '-f servicio@tickex.com.ar', 'is_html' => 0,
                    ));
                };
                list($ok, $message) = tickex_customer_portal_resend($pdo, $usuarioId, $email, $ticketId, $ticketUrl, $sender);
                if ($ok) {
                    flash('ok', $message);
                    header('Location: ' . tickex_route('customer_home', array()) . '?estado=' . urlencode($returnFilter));
                    exit;
                }
                $flashError = $message;
            }
        }
    }
}

$tickets = tickex_customer_portal_load_tickets($pdo, $email);
$counts = tickex_customer_portal_counts($tickets);
$visibleTickets = tickex_customer_portal_filter_tickets($tickets, $filter);
$flashes = flash_get_all();
$staffActive = false;
$resellerActive = false;
$pendingInvitations = 0;
try { $st = $pdo->prepare('SELECT 1 FROM staff_admins WHERE cliente_id=:id AND activo=1 LIMIT 1'); $st->execute(array(':id' => $usuarioId)); $staffActive = (bool)$st->fetchColumn(); } catch (Exception $e) {}
try { $st = $pdo->prepare('SELECT 1 FROM revendedores WHERE cliente_id=:id AND activo=1 LIMIT 1'); $st->execute(array(':id' => $usuarioId)); $resellerActive = (bool)$st->fetchColumn(); } catch (Exception $e) {}
try { $st = $pdo->prepare("SELECT COUNT(*) FROM staff_admin_invitaciones WHERE estado='pending' AND (cliente_id=:id OR lower(email)=lower(:email))"); $st->execute(array(':id' => $usuarioId, ':email' => $email)); $pendingInvitations = (int)$st->fetchColumn(); } catch (Exception $e) {}

function tickex_customer_date_label($value)
{
    $value = trim((string)$value);
    if ($value === '') return 'Fecha a confirmar';
    $ts = strtotime($value);
    return $ts === false ? $value : date('d/m/Y', $ts);
}

$statusLabels = array('vigente' => 'Disponible', 'utilizada' => 'Utilizada', 'vencida' => 'Evento finalizado', 'cancelada' => 'Cancelada');
$filterLabels = array('vigentes' => 'Próximas', 'utilizadas' => 'Utilizadas', 'vencidas' => 'Anteriores', 'canceladas' => 'Canceladas', 'ocultas' => 'Archivadas', 'todas' => 'Todas');
$displayName = trim((string)$user['nombre'] . ' ' . (string)$user['apellido']);
if ($displayName === '') $displayName = $email;
$title = 'Mis entradas – Tickex';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.customer-hero-logo{display:block;width:148px;height:42px;object-fit:contain;object-position:left center;margin-bottom:12px}
.customer-brand{display:flex;align-items:center;gap:10px;margin-bottom:14px}.customer-brand .customer-hero-logo{width:46px;height:46px;margin:0}.customer-brand strong{font:800 16px/1 Manrope,Inter,sans-serif;letter-spacing:.12em}
.customer-portal{max-width:1120px;margin:0 auto;display:grid;gap:18px}.customer-hero{position:relative;overflow:hidden;padding:30px;background:radial-gradient(circle at 90% 10%,rgba(55,207,236,.14),transparent 30%),linear-gradient(135deg,rgba(25,29,57,.98),rgba(54,31,105,.96));border-color:rgba(139,92,246,.3)}.customer-hero h1{margin:7px 0 5px;font-size:clamp(30px,5vw,48px);letter-spacing:-.04em}.customer-hero p{margin:0;color:#b9bdd0}.customer-kicker{color:#57d9ed;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em}.customer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}.customer-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:24px}.customer-stat{padding:14px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:rgba(5,9,24,.32)}.customer-stat span{display:block;color:#aeb2c7;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.customer-stat strong{display:block;margin-top:3px;font:800 24px/1.1 Manrope,Inter,sans-serif}.customer-tabs{display:flex;gap:8px;overflow:auto;padding:4px}.customer-tab{display:flex;align-items:center;gap:7px;padding:10px 13px;border:1px solid var(--line);border-radius:12px;color:var(--muted);text-decoration:none;white-space:nowrap;font-weight:750}.customer-tab.active{color:#fff;border-color:#7f63ff;background:rgba(117,82,235,.2)}.customer-tab b{display:grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:rgba(255,255,255,.08);font-size:11px}.ticket-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.customer-ticket{display:grid;grid-template-columns:132px minmax(0,1fr);min-height:190px;overflow:hidden;padding:0}.ticket-art{background:linear-gradient(145deg,#151b32,#2f2261);min-height:190px;display:grid;place-items:center;overflow:hidden}.ticket-art img{width:100%;height:100%;object-fit:cover}.ticket-art span{font:900 26px Manrope;color:#8169ff}.ticket-content{padding:18px;display:flex;flex-direction:column;gap:11px;min-width:0}.ticket-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.ticket-top h2{margin:0;font-size:18px;line-height:1.25}.ticket-status{flex:0 0 auto;padding:5px 8px;border-radius:999px;border:1px solid var(--line);font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}.ticket-status.vigente{color:#65e6a5}.ticket-status.utilizada{color:#63c7ff}.ticket-status.vencida{color:#aeb2c7}.ticket-status.cancelada{color:#ff8e9d}.ticket-meta{display:grid;gap:4px;color:var(--muted);font-size:12px}.ticket-price{font:800 18px Manrope}.ticket-buttons{display:flex;gap:7px;flex-wrap:wrap;margin-top:auto}.ticket-buttons form{margin:0}.customer-empty{text-align:center;padding:42px 20px}.customer-empty h2{margin:0 0 7px}.customer-empty p{margin:0;color:var(--muted)}.customer-notice{display:flex;justify-content:space-between;gap:15px;align-items:center}.customer-notice p{margin:4px 0 0;color:var(--muted)}
@media(max-width:820px){.ticket-list{grid-template-columns:1fr}.customer-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.customer-portal{gap:12px}.customer-hero{padding:22px}.customer-actions .btn{flex:1;text-align:center}.customer-ticket{grid-template-columns:92px minmax(0,1fr);min-height:180px}.ticket-art{min-height:180px}.ticket-content{padding:14px}.ticket-top{display:grid}.ticket-status{width:max-content}.ticket-buttons .btn{padding:7px 9px;font-size:12px}.customer-notice{align-items:flex-start;display:grid}}
</style>
<main class="customer-portal">
  <section class="card customer-hero">
    <div class="customer-brand"><img class="customer-hero-logo" src="/tickex-isotipo.svg" alt=""><strong>TICKEX</strong></div><div class="customer-kicker">Tu cuenta</div><h1>Hola, <?php echo e($displayName); ?></h1>
    <p>Encontrá tus entradas, revisá su estado y abrí el QR cuando llegues al evento.</p>
    <div class="customer-actions"><a class="btn" href="<?php echo e(tickex_route('customer_profile', array())); ?>">Mi perfil</a><?php if ($staffActive): ?><a class="btn secondary" href="panel_staff.php">Panel de staff</a><?php endif; ?><?php if ($resellerActive): ?><a class="btn secondary" href="panel_revendedor.php">Panel de revendedor</a><?php endif; ?><a class="btn secondary" href="<?php echo e(tickex_route('logout', array())); ?>">Cerrar sesión</a></div>
    <div class="customer-summary"><div class="customer-stat"><span>Disponibles</span><strong><?php echo (int)$counts['vigentes']; ?></strong></div><div class="customer-stat"><span>Utilizadas</span><strong><?php echo (int)$counts['utilizadas']; ?></strong></div><div class="customer-stat"><span>Anteriores</span><strong><?php echo (int)$counts['vencidas']; ?></strong></div><div class="customer-stat"><span>Total</span><strong><?php echo (int)$counts['todas']; ?></strong></div></div>
  </section>
  <?php foreach ($flashes as $flash): ?><div class="flash <?php echo e($flash['type']); ?>"><?php echo e($flash['msg']); ?></div><?php endforeach; ?>
  <?php if ($flashError !== ''): ?><div class="flash err"><?php echo e($flashError); ?></div><?php endif; ?>
  <?php if ($pendingInvitations > 0): ?><section class="card customer-notice"><div><strong>Tenés <?php echo $pendingInvitations; ?> invitación<?php echo $pendingInvitations === 1 ? '' : 'es'; ?> de staff pendiente<?php echo $pendingInvitations === 1 ? '' : 's'; ?></strong><p>Podés revisarlas sin perder tu perfil de comprador.</p></div><a class="btn" href="<?php echo e(tickex_route('customer_profile', array())); ?>#invitaciones">Revisar</a></section><?php endif; ?>
  <nav class="card customer-tabs" aria-label="Filtrar entradas"><?php foreach ($filterLabels as $filterKey => $filterLabel): ?><a class="customer-tab<?php echo $filter === $filterKey ? ' active' : ''; ?>" href="<?php echo e(tickex_route('customer_home', array())); ?>?estado=<?php echo e($filterKey); ?>"><?php echo e($filterLabel); ?><b><?php echo (int)$counts[$filterKey]; ?></b></a><?php endforeach; ?></nav>
  <?php if (!$visibleTickets): ?><section class="card customer-empty"><h2>No hay entradas en esta sección</h2><p>Cuando tengas una entrada con este email, aparecerá automáticamente acá.</p></section><?php else: ?>
  <section class="ticket-list">
    <?php foreach ($visibleTickets as $ticket): $ticketUrl = tickex_secure_ticket_url($pdo, '', (int)$ticket['id'], (string)$ticket['codigo']); $flyer = trim((string)$ticket['flyer_filename']); $flyerExists = $flyer !== '' && is_file(__DIR__ . '/' . ltrim($flyer, '/\\')); $state = (string)$ticket['portal_state']; ?>
    <article class="card customer-ticket"><div class="ticket-art"><?php if ($flyerExists): ?><img src="<?php echo e($flyer); ?>" alt="Flyer de <?php echo e($ticket['evento_nombre']); ?>"><?php else: ?><span>TX</span><?php endif; ?></div><div class="ticket-content"><div class="ticket-top"><h2><?php echo e($ticket['evento_nombre']); ?></h2><span class="ticket-status <?php echo e($state); ?>"><?php echo e(isset($statusLabels[$state]) ? $statusLabels[$state] : $state); ?></span></div><div class="ticket-meta"><span><?php echo e($ticket['tipo']); ?></span><span><?php echo e(tickex_customer_date_label($ticket['fecha_desde'])); ?></span><span>Emitida <?php echo e(tickex_customer_date_label($ticket['fecha_registro'])); ?></span></div><div class="ticket-price"><?php echo (float)$ticket['monto_pagado'] > 0 ? '$' . number_format((float)$ticket['monto_pagado'], 2, ',', '.') : 'Sin cargo'; ?></div><div class="ticket-buttons"><?php if ($state !== 'cancelada'): ?><a class="btn" href="<?php echo e($ticketUrl); ?>" target="_blank" rel="noopener">Ver QR</a><?php endif; ?><?php if (empty($ticket['oculta_usuario']) && $state !== 'cancelada'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="resend_ticket"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>"><input type="hidden" name="estado" value="<?php echo e($filter); ?>"><button class="btn secondary" type="submit">Reenviar</button></form><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="<?php echo !empty($ticket['oculta_usuario']) ? 'restore_ticket' : 'hide_ticket'; ?>"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>"><input type="hidden" name="estado" value="<?php echo e($filter); ?>"><button class="btn secondary" type="submit"><?php echo !empty($ticket['oculta_usuario']) ? 'Restaurar' : 'Archivar'; ?></button></form></div></div></article>
    <?php endforeach; ?>
  </section><?php endif; ?>
</main>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
