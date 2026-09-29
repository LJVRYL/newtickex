<?php
require_once __DIR__ . '/../inc/communication_contacts.php';

function contact_segmentation_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
    echo "PASS: " . $message . PHP_EOL;
}

$rows = array(
    array(
        'email' => 'comprador@example.com', 'nombre' => 'Comprador', 'rol' => 'VIP',
        'registrado' => 'Si', 'bloqueado' => 0, 'tickets_count' => 4,
        'paid_entries_count' => 1, 'paid_amount' => 40000, 'event_ids' => array(15),
        'fuentes' => array('entradas' => true, 'usuarios' => true),
    ),
    array(
        'email' => 'invitado@example.com', 'nombre' => 'Invitado', 'rol' => 'Prensa',
        'registrado' => 'No', 'bloqueado' => 0, 'tickets_count' => 1,
        'paid_entries_count' => 0, 'paid_amount' => 0, 'event_ids' => array(15, 18),
        'fuentes' => array('entradas' => true),
    ),
    array(
        'email' => 'prospecto@example.com', 'nombre' => 'Prospecto', 'rol' => 'Prensa',
        'registrado' => 'No', 'bloqueado' => 0, 'tickets_count' => 0,
        'paid_entries_count' => 0, 'paid_amount' => 0, 'event_ids' => array(),
        'fuentes' => array('import_csv' => true),
    ),
);

$buyers = communication_contacts_apply_filters($rows, array('f_contact_type' => 'buyer'));
contact_segmentation_assert(count($buyers) === 1 && $buyers[0]['email'] === 'comprador@example.com', 'paid contacts are classified as buyers');

$guests = communication_contacts_apply_filters($rows, array('contact_type' => 'guest'));
contact_segmentation_assert(count($guests) === 1 && $guests[0]['email'] === 'invitado@example.com', 'unpaid ticket holders are classified as guests');

$eventContacts = communication_contacts_apply_filters($rows, array('f_event_id' => 18));
contact_segmentation_assert(count($eventContacts) === 1 && $eventContacts[0]['email'] === 'invitado@example.com', 'event filter only returns contacts from the selected event');

$roleContacts = communication_contacts_apply_filters($rows, array('f_role' => 'Prensa'));
contact_segmentation_assert(count($roleContacts) === 2, 'role filter is reusable for contact organization');

$prospects = communication_contacts_apply_filters($rows, array('contact_type' => 'prospect'));
contact_segmentation_assert(count($prospects) === 1 && $prospects[0]['email'] === 'prospecto@example.com', 'contacts without tickets remain available as prospects');

$imported = communication_contacts_apply_filters($rows, array('contact_type' => 'imported', 'role' => 'Prensa'));
contact_segmentation_assert(count($imported) === 1 && $imported[0]['email'] === 'prospecto@example.com', 'contact criteria can be combined for future audiences');

echo "ALL COMMUNICATION CONTACT SEGMENTATION TESTS PASSED" . PHP_EOL;
