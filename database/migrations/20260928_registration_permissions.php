<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../../common/db.php';
require_once __DIR__.'/../../../admin_registration_utsav_desk/private/bootstrap.php';
$registration = database(configuration());
$account = $registration->query('SELECT CURRENT_USER()')->fetchColumn();
$parts = explode('@', $account, 2);
if (count($parts) !== 2) throw new RuntimeException('Cannot identify registration database account.');
$db = databaseConnection();
$principal = $db->quote($parts[0]).'@'.$db->quote($parts[1]);
$schema = '`'.str_replace('`', '``', DB_NAME).'`';
$db->exec('GRANT SELECT (revoked_at), UPDATE (used_by) ON '.$schema.'.admin_registration_invites TO '.$principal);
registrationInvitesAvailable($registration);
findRegistrationInvite($registration, 'AAAAAAAAAAAAAAAA', 'verification-only');
$registration->exec('UPDATE admin_registration_invites SET used_by = NULL WHERE id = 0');
echo "Registration database permissions verified.\n";
