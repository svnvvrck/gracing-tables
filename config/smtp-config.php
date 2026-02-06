<?php
declare(strict_types=1);

// SMTP settings for IONOS (stored outside web root).
return [
  "smtp_host" => "smtp.ionos.de",
  "smtp_port" => 587,
  "smtp_secure" => "tls", // tls (587) or ssl (465)
  "smtp_user" => "anfrage@domain.de",
  "smtp_pass" => "CHANGE_ME",
  "mail_from" => "anfrage@domain.de",
  "mail_to" => "info@domain.de",
];
