<?php

function tickex_mobile_staff_invitations($pdo, $client)
{
    $clientId = (int)$client['id'];
    $email = trim((string)$client['email']);
    $stmt = $pdo->prepare("SELECT id, owner_admin_id, email, mensaje, rol_staff, created_at
        FROM staff_admin_invitaciones
        WHERE estado = 'pending' AND (cliente_id = :client OR (COALESCE(cliente_id, 0) = 0 AND lower(email) = lower(:email)))
        ORDER BY created_at DESC, id DESC LIMIT 100");
    $stmt->execute(array(':client' => $clientId, ':email' => $email));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = array();
    foreach ($rows as $row) {
        $result[] = array(
            'id' => (string)$row['id'],
            'ownerAdminId' => (string)$row['owner_admin_id'],
            'email' => (string)$row['email'],
            'message' => (string)$row['mensaje'],
            'roleCode' => (string)$row['rol_staff'],
            'createdAt' => (string)$row['created_at'],
        );
    }
    return $result;
}

function tickex_mobile_staff_invitation_decide($pdo, $client, $invitationId, $decision)
{
    $invitationId = (int)$invitationId;
    if ($invitationId <= 0 || !in_array($decision, array('accept', 'reject'), true)) return array('error' => 'invalid_decision');
    $clientId = (int)$client['id'];
    $email = strtolower(trim((string)$client['email']));
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, owner_admin_id, email, cliente_id, rol_staff, estado FROM staff_admin_invitaciones WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => $invitationId));
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invite || (string)$invite['estado'] !== 'pending') { $pdo->rollBack(); return array('error' => 'not_found'); }
        $boundClient = (int)$invite['cliente_id'];
        if (($boundClient > 0 && $boundClient !== $clientId) || ($boundClient <= 0 && strtolower(trim((string)$invite['email'])) !== $email)) {
            $pdo->rollBack(); return array('error' => 'forbidden');
        }
        $owner = (int)$invite['owner_admin_id'];
        $role = trim((string)$invite['rol_staff']);
        if ($owner <= 0) { $pdo->rollBack(); return array('error' => 'invalid_invitation'); }
        if ($decision === 'accept') {
            $insert = $pdo->prepare('INSERT OR IGNORE INTO staff_admins (owner_admin_id, cliente_id, rol_staff, activo) VALUES (:owner, :client, :role, 1)');
            $insert->execute(array(':owner' => $owner, ':client' => $clientId, ':role' => $role !== '' ? $role : null));
            $activate = $pdo->prepare("UPDATE staff_admins SET activo = 1, rol_staff = COALESCE(NULLIF(:role, ''), rol_staff) WHERE owner_admin_id = :owner AND cliente_id = :client");
            $activate->execute(array(':role' => $role, ':owner' => $owner, ':client' => $clientId));
            if ($activate->rowCount() < 1) { $pdo->rollBack(); return array('error' => 'staff_activation_failed'); }
        }
        $state = $decision === 'accept' ? 'accepted' : 'rejected';
        $update = $pdo->prepare('UPDATE staff_admin_invitaciones SET estado = :state, cliente_id = :client, updated_at = datetime(\'now\') WHERE id = :id AND estado = \'pending\'');
        $update->execute(array(':state' => $state, ':client' => $clientId, ':id' => $invitationId));
        if ($update->rowCount() !== 1) { $pdo->rollBack(); return array('error' => 'invitation_changed'); }
        $pdo->commit();
        return array('id' => (string)$invitationId, 'status' => $state, 'ownerAdminId' => (string)$owner, 'roleCode' => $role);
    } catch (Exception $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
