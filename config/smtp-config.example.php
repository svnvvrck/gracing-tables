<?php
declare(strict_types=1);

// SMTP settings for IONOS.
// Copy this file to smtp-config.php and fill in your credentials.
return [
  "smtp_host" => "smtp.ionos.de",
  "smtp_port" => 587,
  "smtp_secure" => "tls", // tls (587) or ssl (465)
  "smtp_user" => "anfrage@grazing-tables-saar.de",
  "smtp_pass" => "",
  "mail_from" => "anfrage@grazing-tables-saar.de",
  "mail_to" => "info@grazing-tables-saar.de",
];
