<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * send.php – Kontaktformular via SMTP (IONOS) mit PHPMailer
 * - POST-only
 * - Config aus config/smtp-config.php (mit Fallback-Pfaden)
 * - Validierung + Header-Injection-Checks
 * - Spam-Schutz: Rate-Limit (Datei), Honeypot, Mindest-Submit-Zeit
 * - Bei Fehlern: gestylte Fehlerseite im Design der Website
 * - Interne Fehler: Log nach mail-error.log (im gleichen Ordner wie send.php)
 */

// ====== Constants ======
const CONTACT_FALLBACK_EMAIL = 'info@grazing-tables-saar.de';

// ====== Error logging (server-side) ======
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'mail-error.log');

// ====== Styled error page (matches site design) ======
function render_error_page(string $title, string $message, bool $showRetry = true): void {
  $fallbackEmail = CONTACT_FALLBACK_EMAIL;
  $retryLink = $showRetry
    ? '<a class="btn btn-primary" href="kontakt.html">Zur&uuml;ck zum Formular</a>'
    : '';

  header('Content-Type: text/html; charset=UTF-8');
  echo <<<HTML
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{$title} – Grazing Tables Saar</title>
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="index.html">
        <img class="brand-logo" src="assets/img/logo_tranparent.png" alt="Grazing Tables Saar Logo">
        <span class="brand-name">Grazing Tables Saar</span>
      </a>
      <nav class="nav" aria-label="Hauptnavigation">
        <button class="nav-toggle" type="button" aria-label="Men&uuml; &ouml;ffnen" aria-expanded="false" aria-controls="navList">
          <span></span><span></span><span></span>
        </button>
        <ul class="nav-list" id="navList">
          <li><a href="index.html" class="nav-link">Start</a></li>
          <li><a href="angebot.html" class="nav-link">Angebot</a></li>
          <li><a href="anlaesse.html" class="nav-link">Anl&auml;sse</a></li>
          <li><a href="kontakt.html" class="nav-link">Anfrage</a></li>
        </ul>
      </nav>
      <div class="header-actions">
        <a class="icon-link" href="https://www.instagram.com/grazing_tables_saar/" target="_blank" rel="noopener noreferrer" aria-label="Instagram &ouml;ffnen">
          <img src="assets/img/insta.svg" alt="">
        </a>
      </div>
    </div>
  </header>

  <main class="section">
    <div class="container">
      <div class="contact-grid">
        <div class="contact-card" style="text-align:center; padding:2.5rem 2rem;">
          <h1 style="margin-bottom:1rem;">{$title}</h1>
          <p class="muted" style="margin-bottom:1.5rem;">{$message}</p>
          <div class="cta-row" style="justify-content:center;">
            {$retryLink}
            <a class="btn btn-ghost" href="index.html">Zur Startseite</a>
          </div>
          <p class="small muted" style="margin-top:1.5rem;">
            Alternativ erreichst du uns per E-Mail:
            <a class="underline" href="mailto:{$fallbackEmail}">{$fallbackEmail}</a>
          </p>
        </div>
      </div>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="small muted">&copy; <span id="year"></span> Grazing Tables Saar</div>
      <div class="footer-links">
        <a href="kontakt.html">Anfrage</a>
        <a href="impressum.html">Impressum</a>
        <a href="datenschutz.html">Datenschutz</a>
      </div>
      <a class="icon-link" href="https://www.instagram.com/grazing_tables_saar/" target="_blank" rel="noopener noreferrer" aria-label="Instagram &ouml;ffnen">
        <img src="assets/img/insta.svg" alt="">
      </a>
    </div>
  </footer>

  <script src="assets/js/main.js" defer></script>
</body>
</html>
HTML;
}

function fail(int $code, string $logMessage, string $userMessage = ''): never {
  http_response_code($code);
  error_log('[send.php] ' . $code . ' ' . $logMessage);

  if ($userMessage === '') {
    $userMessage = 'Leider konnte deine Anfrage gerade nicht versendet werden. Bitte versuche es sp&auml;ter erneut.';
  }

  render_error_page('Etwas ist schiefgelaufen', $userMessage);
  exit;
}

function fail_validation(string $userMessage): never {
  http_response_code(400);
  render_error_page('Eingabe fehlerhaft', $userMessage);
  exit;
}

// -------------------------
// Only accept POST
// -------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  render_error_page(
    'Seite nicht gefunden',
    'Diese Seite kann nicht direkt aufgerufen werden.',
    false
  );
  exit;
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
  __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp-config.php',
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
$smtpSecure = strtolower((string)($config['smtp_secure'] ?? 'tls'));
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
  http_response_code(429);
  render_error_page(
    'Zu viele Anfragen',
    'Du hast zu viele Anfragen in kurzer Zeit gesendet. Bitte versuche es sp&auml;ter erneut.'
  );
  exit;
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
  render_error_page('Ung&uuml;ltige Anfrage', 'Die Anfrage konnte nicht verarbeitet werden.', false);
  exit;
}

// -------------------------
// Spam protection: minimum submit time
// -------------------------
$ts = (int)post_field('form_ts');
if ($ts <= 0) {
  http_response_code(400);
  render_error_page('Ung&uuml;ltige Anfrage', 'Die Anfrage konnte nicht verarbeitet werden.', false);
  exit;
}
if (($now - $ts) < 3) {
  fail_validation('Das Formular wurde zu schnell abgesendet. Bitte f&uuml;lle es erneut aus.');
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
  fail_validation('Bitte f&uuml;lle alle Pflichtfelder aus.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  fail_validation('Bitte gib eine g&uuml;ltige E-Mail-Adresse ein.');
}

if (is_header_injection($email) || is_header_injection($telefon)) {
  fail_validation('Die Eingabe enth&auml;lt ung&uuml;ltige Zeichen.');
}

if (!ctype_digit($personenzahl)) {
  fail_validation('Bitte gib eine g&uuml;ltige Personenzahl ein.');
}

$pn = (int)$personenzahl;
if ($pn < 1 || $pn > 50) {
  fail_validation('Die Personenzahl muss zwischen 1 und 50 liegen.');
}

if (mb_strlen($eventOrt) > 100 || mb_strlen($eventAnlass) > 100 || mb_strlen($telefon) > 50) {
  fail_validation('Einige Eingaben sind zu lang. Bitte k&uuml;rze sie.');
}

if (mb_strlen($nachricht) > 2000) {
  fail_validation('Die Nachricht ist zu lang (maximal 2000 Zeichen).');
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

  if ($smtpSecure === 'ssl' || $smtpPort === 465) {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  } else {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  }

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
  fail(500, 'Mailer exception: ' . $e->getMessage());
}
