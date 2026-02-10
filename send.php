<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * send.php – Kontaktformular via SMTP (IONOS) mit PHPMailer
 * - POST-only
 * - Config aus config/smtp-config.php (mit Fallback-Pfaden)
 * - Validierung + Header-Injection-Checks
 * - Spam-Schutz: Rate-Limit (Datei), Honeypot, Mindest-Submit-Zeit
 * - Bei Fehlern: freundliche Nutzerseite mit Hinweis auf info@grazing-tables-saar.de
 * - Interne Fehler: Log nach mail-error.log (im gleichen Ordner wie send.php)
 */

// ====== User-friendly fallback message (shown on any failure) ======
const CONTACT_FALLBACK_EMAIL = 'info@grazing-tables-saar.de';
const USER_FALLBACK_MESSAGE =
  'Leider konnte deine Anfrage gerade nicht automatisch versendet werden.<br>' .
  'Bitte schreibe uns direkt eine E-Mail an ' .
  '<a href="mailto:' . CONTACT_FALLBACK_EMAIL . '">' . CONTACT_FALLBACK_EMAIL . '</a>.<br>' .
  'Vielen Dank für dein Verständnis!';

// ====== Error logging (server-side) ======
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'mail-error.log');
// ini_set('display_errors', '1'); // nur kurzzeitig aktivieren, falls du es direkt sehen willst

function render_user_error_page(): void {
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Nachricht nicht gesendet</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#fafafa; padding:2rem; }
    .box { max-width:720px; margin:auto; background:#fff; padding:2rem; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,.08); }
    h1 { font-size:1.4rem; margin:0 0 1rem 0; }
    p { line-height:1.55; margin:0.25rem 0; }
    a { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="box">
    <h1>Etwas ist schiefgelaufen</h1>
    <p>' . USER_FALLBACK_MESSAGE . '</p>
  </div>
</body>
</html>';
}

function fail(int $code, string $logMessage): never {
  http_response_code($code);
  error_log('[send.php] ' . $code . ' ' . $logMessage);
  render_user_error_page();
  exit;
}

// -------------------------
// Only accept POST
// -------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
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
// Load SMTP config
// -------------------------
$configPaths = [
  // wenn send.php im Webroot liegt
  __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',

  // wenn send.php in /public oder /api liegt
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',

  // fallback: eine Ebene höher ohne /config
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'smtp-config.php',
];

$config = null;
$loadedPath = null;

foreach ($configPaths as $path) {
  if (is_file($path) && is_readable($path)) {
    $tmp = require $path;
    if (is_array($tmp)) {
      $config = $tmp;
      $loadedPath = $path;
      break;
    }
  }
}

if (!is_array($config)) {
  fail(500, 'Config missing or invalid. Checked: ' . implode(' | ', $configPaths));
}

$smtpHost   = (string)($config['smtp_host']   ?? 'smtp.ionos.de');
$smtpPort   = (int)   ($config['smtp_port']   ?? 587);
$smtpSecure = strtolower((string)($config['smtp_secure'] ?? 'tls')); // tls|ssl
$smtpUser   = (string)($config['smtp_user']   ?? '');
$smtpPass   = (string)($config['smtp_pass']   ?? '');
$mailFrom   = (string)($config['mail_from']   ?? '');
$mailTo     = (string)($config['mail_to']     ?? '');

if ($smtpUser === '' || $smtpPass === '' || $mailFrom === '' || $mailTo === '') {
  fail(500, 'Config incomplete (smtp_user/smtp_pass/mail_from/mail_to missing). Loaded from: ' . (string)$loadedPath);
}

// -------------------------
// Spam protection: rate limit (IP)
// -------------------------
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gts_ratelimit';

if (!is_dir($rateDir) && !mkdir($rateDir, 0755, true) && !is_dir($rateDir)) {
  fail(500, 'Cannot create rate limit dir: ' . $rateDir);
}

$rateFile = $rateDir . DIRECTORY_SEPARATOR . 'ip_' . hash('sha256', $ip);
$now = time();
$limit = 5;
$window = 3600;

$hits = [];
if (is_file($rateFile)) {
  $lines = @file($rateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (is_array($lines)) {
    foreach ($lines as $line) {
      $t = (int)$line;
      if ($t > ($now - $window)) $hits[] = $t;
    }
  }
}

if (count($hits) >= $limit) {
  // hier bewusst KEINE Nutzer-Fehlerseite, sondern klare Rate-Limit-Meldung:
  http_response_code(429);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Zu viele Anfragen. Bitte später erneut versuchen.');
}

$hits[] = $now;
if (file_put_contents($rateFile, implode("\n", $hits), LOCK_EX) === false) {
  fail(500, 'Cannot write rate limit file: ' . $rateFile);
}

// -------------------------
// Spam protection: honeypot
// -------------------------
if (post_field('website') !== '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige Anfrage.');
}

// -------------------------
// Spam protection: minimum submit time
// -------------------------
$ts = (int)post_field('form_ts');
if ($ts <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige Anfrage.');
}
if (($now - $ts) < 3) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Formular zu schnell abgesendet.');
}

// -------------------------
// Validation
// -------------------------
$eventDatum   = post_field('event_datum');
$eventUhrzeit = post_field('event_uhrzeit');
$eventOrt     = post_field('event_ort');
$eventAnlass  = post_field('event_anlass');
$personenzahl = post_field('personenzahl');
$email        = post_field('email');
$telefon      = post_field('telefon');
$nachricht    = post_field('nachricht');

if ($eventDatum === '' || $eventUhrzeit === '' || $eventOrt === '' || $eventAnlass === '' || $personenzahl === '' || $email === '' || $nachricht === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Bitte alle Pflichtfelder ausfuellen.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige E-Mail-Adresse.');
}

if (is_header_injection($email) || is_header_injection($telefon)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige Eingabe.');
}

if (!ctype_digit($personenzahl)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige Personenzahl.');
}

$pn = (int)$personenzahl;
if ($pn < 1 || $pn > 50) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Ungueltige Personenzahl.');
}

if (mb_strlen($eventOrt) > 100 || mb_strlen($eventAnlass) > 100 || mb_strlen($telefon) > 50) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Eingaben zu lang.');
}

if (mb_strlen($nachricht) > 2000) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Nachricht zu lang.');
}

// -------------------------
// Build message
// -------------------------
$subject = 'Neue Anfrage ueber die Website';
$body =
  "Neue Anfrage:\n\n" .
  "Datum: {$eventDatum}\n" .
  "Uhrzeit: {$eventUhrzeit}\n" .
  "Ort: {$eventOrt}\n" .
  "Anlass: {$eventAnlass}\n" .
  "Personenzahl: {$personenzahl}\n" .
  "E-Mail: {$email}\n" .
  "Telefon: {$telefon}\n\n" .
  "Nachricht:\n{$nachricht}\n";

// -------------------------
// PHPMailer includes (no composer)
// -------------------------
$phpMailerBase = __DIR__ . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'src';
$phpMailerFiles = [
  $phpMailerBase . DIRECTORY_SEPARATOR . 'PHPMailer.php',
  $phpMailerBase . DIRECTORY_SEPARATOR . 'SMTP.php',
  $phpMailerBase . DIRECTORY_SEPARATOR . 'Exception.php',
];

foreach ($phpMailerFiles as $f) {
  if (!is_file($f)) {
    fail(500, 'PHPMailer file missing: ' . $f);
  }
  require_once $f;
}

try {
  $mail = new PHPMailer(true);
  $mail->CharSet = 'UTF-8';
  $mail->isSMTP();
  $mail->Host = $smtpHost;
  $mail->SMTPAuth = true;
  $mail->Username = $smtpUser;
  $mail->Password = $smtpPass;
  $mail->Port = $smtpPort;

  // Verschlüsselung konsistent setzen
  if ($smtpSecure === 'ssl' || $smtpPort === 465) {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  } else {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  }

  // Optional: Debug in Logfile (aktivieren wenn nötig)
  // $mail->SMTPDebug = 2;
  // $mail->Debugoutput = function ($str, $level) {
  //   error_log('[SMTP ' . $level . '] ' . $str);
  // };

  // IONOS: setFrom sollte idealerweise zur SMTP-Mailbox passen / aus derselben Domain stammen
  $mail->setFrom($mailFrom, 'Grazing Tables Saar');
  $mail->addAddress($mailTo);
  $mail->addReplyTo($email);

  $mail->Subject = $subject;
  $mail->Body = $body;
  $mail->isHTML(false);

  $mail->send();

  header('Location: kontakt.html?sent=1');
  exit;
} catch (Throwable $e) {
  error_log('[send.php] Exception: ' . $e->getMessage());
  fail(500, 'Mailer exception thrown.');
}
