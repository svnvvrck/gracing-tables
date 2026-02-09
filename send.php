<?php
declare(strict_types=1);

/**
 * send.php – Kontaktformular via SMTP (IONOS) mit PHPMailer
 * - POST-only
 * - Config server-side (config/smtp-config.php oder Alternativen)
 * - Input-Validierung + Header-Injection-Checks
 * - Honeypot + Mindestzeit + IP-Rate-Limit (Datei-basiert, mit Lock)
 */

use PHPMailer\PHPMailer\PHPMailer;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

// -------------------------
// Load SMTP config
// -------------------------
$configPaths = [
  // typisch: /config/smtp-config.php (wenn send.php z.B. in /public liegt)
  __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',

  // typisch: ../config/smtp-config.php (wenn send.php z.B. in /public oder /api liegt)
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',

  // fallback: ../smtp-config.php (falls du es eine Ebene höher ohne /config abgelegt hast)
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'smtp-config.php',
];

$config = null;
foreach ($configPaths as $path) {
  if (is_file($path)) {
    $tmp = require $path;
    if (is_array($tmp)) {
      $config = $tmp;
      break;
    }
  }
}

if (!is_array($config)) {
  http_response_code(500);
  exit('Server configuration missing.');
}

$smtpHost   = (string)($config['smtp_host']   ?? 'smtp.ionos.de');
$smtpPort   = (int)   ($config['smtp_port']   ?? 587);
$smtpSecure = (string)($config['smtp_secure'] ?? 'tls'); // "tls" (587) or "ssl" (465)
$smtpUser   = (string)($config['smtp_user']   ?? '');
$smtpPass   = (string)($config['smtp_pass']   ?? '');
$mailFrom   = (string)($config['mail_from']   ?? '');
$mailTo     = (string)($config['mail_to']     ?? '');

if ($smtpUser === '' || $smtpPass === '' || $mailFrom === '' || $mailTo === '') {
  http_response_code(500);
  exit('Server configuration incomplete.');
}

// -------------------------
// Helpers
// -------------------------
function post_field(string $key): string {
  return trim((string)($_POST[$key] ?? ''));
}

function is_header_injection(string $value): bool {
  return (bool)preg_match("/[\r\n]/", $value);
}

// -------------------------
// Spam protection: rate limit (IP)
// -------------------------
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gts_ratelimit';

if (!is_dir($rateDir)) {
  if (!mkdir($rateDir, 0755, true) && !is_dir($rateDir)) {
    http_response_code(500);
    exit('Server storage unavailable.');
  }
}

$rateFile = $rateDir . DIRECTORY_SEPARATOR . 'ip_' . hash('sha256', $ip);
$now = time();
$limit = 5;
$window = 3600;

$hits = [];
if (is_file($rateFile)) {
  $lines = @file($rateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMP_
