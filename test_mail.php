<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/elearning_system/lib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

$sent = local_elearning_system_send_custom_email(
    'email_destinataire@test.com',   // à remplacer
    'Nom Test',
    'Test email plugin e-learning',
    '<h3>Test réussi</h3><p>PHPMailer est bien intégré dans local_elearning_system.</p>',
    'Test réussi - PHPMailer est bien intégré.'
);

echo $sent ? 'Email envoyé avec succès' : 'Échec de l’envoi';