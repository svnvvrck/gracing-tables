<?php
declare(strict_types=1);

// Only accept POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  exit("Method Not Allowed");
}

// --- SMTP config (server-side only) ---
// Config is stored outside web root (one level up).
$configPaths = [
  __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "smtp-config.php",
];

$config = null;
foreach ($configPaths as $path) {
  if (is_file($path)) {
    $config = require $path;
    break;
  }
}

if (!is_array($config)) {
  http_response_code(500);
  exit("Server configuration missing.");
}

$smtpHost = (string)($config["smtp_host"] ?? "smtp.ionos.de");
$smtpPort = (int)($config["smtp_port"] ?? 587);
$smtpSecure = (string)($config["smtp_secure"] ?? "tls"); // tls (587) or ssl (465)
$smtpUser = (string)($config["smtp_user"] ?? "");
$smtpPass = (string)($config["smtp_pass"] ?? "");
$mailFrom = (string)($config["mail_from"] ?? "");
$mailTo = (string)($config["mail_to"] ?? "");

if ($smtpUser === "" || $smtpPass === "" || $mailFrom === "" || $mailTo === "") {
  http_response_code(500);
  exit("Server configuration incomplete.");
}

// --- helpers ---
function post_field(string $key): string {
  return trim((string)($_POST[$key] ?? ""));
}

function is_header_injection(string $value): bool {
  return (bool)preg_match("/[\r\n]/", $value);
}

// --- spam protection: rate limit ---
$ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gts_ratelimit";
if (!is_dir($rateDir)) {
  if (!mkdir($rateDir, 0755, true) && !is_dir($rateDir)) {
    http_response_code(500);
    exit("Server configuration missing.");
  }
}
$rateFile = $rateDir . DIRECTORY_SEPARATOR . "ip_" . hash("sha256", $ip);
$now = time();
$limit = 5;
$window = 3600;

$hits = [];
if (is_file($rateFile)) {
  $lines = explode("\n", trim((string)file_get_contents($rateFile)));
  foreach ($lines as $line) {
    $t = (int)$line;
    if ($t > ($now - $window)) $hits[] = $t;
  }
}
if (count($hits) >= $limit) {
  http_response_code(429);
  exit("Too many requests. Please try again later.");
}
$hits[] = $now;
if (file_put_contents($rateFile, implode("\n", $hits)) === false) {
  http_response_code(500);
  exit("Server configuration missing.");
}

// --- spam protection: honeypot ---
if (post_field("website") !== "") {
  http_response_code(400);
  exit("Invalid submission.");
}

// --- spam protection: minimum submit time ---
$ts = (int)post_field("form_ts");
if ($ts <= 0) {
  http_response_code(400);
  exit("Invalid submission.");
}
if (($now - $ts) < 3) {
  http_response_code(400);
  exit("Form submitted too quickly.");
}

// --- validation ---
$eventDatum = post_field("event_datum");
$eventUhrzeit = post_field("event_uhrzeit");
$eventOrt = post_field("event_ort");
$eventAnlass = post_field("event_anlass");
$personenzahl = post_field("personenzahl");
$email = post_field("email");
$telefon = post_field("telefon");
$nachricht = post_field("nachricht");

if (!$eventDatum || !$eventUhrzeit || !$eventOrt || !$eventAnlass || !$personenzahl || !$email || !$nachricht) {
  http_response_code(400);
  exit("Bitte alle Pflichtfelder ausfuellen.");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  exit("Ungueltige E-Mail-Adresse.");
}
if (is_header_injection($email) || is_header_injection($telefon)) {
  http_response_code(400);
  exit("Ungueltige Eingabe.");
}
if (!ctype_digit($personenzahl) || (int)$personenzahl < 1 || (int)$personenzahl > 50) {
  http_response_code(400);
  exit("Ungueltige Personenzahl.");
}
if (strlen($eventOrt) > 100 || strlen($eventAnlass) > 100 || strlen($telefon) > 50) {
  http_response_code(400);
  exit("Eingaben zu lang.");
}
if (strlen($nachricht) > 2000) {
  http_response_code(400);
  exit("Nachricht zu lang.");
}

// --- message ---
$subject = "Neue Anfrage ueber die Website";
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

// --- PHPMailer ---
require __DIR__ . "/phpmailer/src/PHPMailer.php";
require __DIR__ . "/phpmailer/src/SMTP.php";
require __DIR__ . "/phpmailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;

try {
  $mail = new PHPMailer(true);
  $mail->CharSet = "UTF-8";
  $mail->isSMTP();
  $mail->Host = $smtpHost;
  $mail->SMTPAuth = true;
  $mail->Username = $smtpUser;
  $mail->Password = $smtpPass;
  $mail->SMTPSecure = $smtpSecure;
  $mail->Port = $smtpPort;

  $mail->setFrom($mailFrom, "Grazing Tables Saar");
  $mail->addAddress($mailTo);
  $mail->addReplyTo($email);

  $mail->Subject = $subject;
  $mail->Body = $body;

  $mail->send();
  header("Location: kontakt.html?sent=1");
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  exit("Mailversand fehlgeschlagen.");
}
