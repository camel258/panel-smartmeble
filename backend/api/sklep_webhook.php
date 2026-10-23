<?php
// Powiadomienie ze sklepu WooCommerce (webhook „Zamówienie utworzone / zaktualizowane”) → zapis zamówienia w panelu.
// Adres: /backend/api/sklep_webhook.php?sklep=frontypax. Bez logowania – podpis HMAC-SHA256 (nagłówek
// X-WC-Webhook-Signature) sekretem SKLEP_<KOD>_WEBHOOK_SECRET z config/.env. Gdy powiadomienie zaginie,
// zamówienie i tak dociągnie synchronizacja co 10 min (backend/cli/sklep_sync.php).
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';
header('Content-Type: application/json; charset=utf-8');

$kod = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['sklep'] ?? ''));
$s = sk_sklep($kod);
if (!$s || $s['secret'] === '') json_err('not_found', 404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('method_not_allowed', 405);

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 5 * 1024 * 1024) json_err('too_large', 413);
$podpis = (string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '');
// ping przy zakładaniu webhooka („webhook_id=…”, bez podpisu) – tylko potwierdzenie, nic nie zapisujemy
if ($podpis === '' && preg_match('/^webhook_id=\d+$/', $raw)) json_ok(['ok' => true, 'ping' => true]);
if ($podpis === '' || !hash_equals(base64_encode(hash_hmac('sha256', $raw, $s['secret'], true)), $podpis)) json_err('forbidden', 403);

$o = json_decode($raw, true);
if (!is_array($o) || empty($o['id'])) json_ok(['ok' => true, 'ping' => true]);   // ping przy zakładaniu webhooka

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
sk_schemat($pdo);
$zasob = (string)($_SERVER['HTTP_X_WC_WEBHOOK_RESOURCE'] ?? 'order');
$zdarzenie = (string)($_SERVER['HTTP_X_WC_WEBHOOK_EVENT'] ?? '');
if ($zasob !== 'order') json_ok(['ok' => true, 'pominieto' => $zasob]);
if ($zdarzenie === 'deleted') {
  // kosz w sklepie (albo skasowanie) → zakładka „Kosz”; skasowane na stałe usunie nocna pełna synchronizacja
  $pdo->prepare("UPDATE sklep_zamowienia SET status_przed_koszem=IF(status='trash', status_przed_koszem, status), status='trash', zsynchronizowano=NOW() WHERE sklep=? AND wc_id=?")->execute([$kod, (int)$o['id']]);
  json_ok(['ok' => true, 'kosz' => (int)$o['id']]);
}
sk_zapisz($pdo, $kod, $o);
json_ok(['ok' => true, 'id' => (int)$o['id']]);
