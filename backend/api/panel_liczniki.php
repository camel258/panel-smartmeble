<?php
// Liczniki do menu i dzwonka w pasku górnym (nowy wygląd panelu, 2026-11-27). Tylko odczyt.
// Zwraca tylko to, do czego użytkownik ma dostęp:
//   frontypax / zbudujmebel – zamówienia „W realizacji”, w których nie odhaczono jeszcze żadnego etapu (nowe do ruszenia)
//   reklamacje             – otwarte reklamacje (Administrator, Super Admin)
//   wyceny_www             – nowe wyceny ze strony (Super Admin)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$me = auth_user(); if (!$me) json_err('unauthorized', 401);
$pdo = db();
$rid = (int)($me['role_id'] ?? ($me['role'] ?? 0));
$out = [];
$licz = function (string $sql, array $par = []) use ($pdo): ?int {
  try { $st = $pdo->prepare($sql); $st->execute($par); return (int)$st->fetchColumn(); } catch (Throwable $e) { return null; }
};

foreach (['frontypax', 'zbudujmebel'] as $kod) {
  if (!sk_can_access($pdo, $me, $kod)) continue;
  $n = $licz("SELECT COUNT(*) FROM sklep_zamowienia z WHERE z.sklep=? AND z.status='processing'
    AND NOT EXISTS (SELECT 1 FROM sklep_etapy e WHERE e.sklep=z.sklep AND e.wc_id=z.wc_id)", [$kod]);
  $w = $licz("SELECT COUNT(*) FROM sklep_zamowienia WHERE sklep=? AND status='processing'", [$kod]);
  if ($n !== null) $out[$kod] = ['nowe' => $n, 'w_realizacji' => (int)$w];
}
if (in_array($rid, [1, 999], true)) {
  $n = $licz("SELECT COUNT(*) FROM orders WHERE COALESCE(complaint_active,0)=1 AND complaint_closed_at IS NULL AND COALESCE(is_deleted,0)=0");
  if ($n !== null) $out['reklamacje'] = $n;
}
if ($rid === 999) {
  $n = $licz("SELECT COUNT(*) FROM www_wyceny WHERE status='nowa'");
  if ($n !== null) $out['wyceny_www'] = $n;
}
json_ok(['ok' => true, 'liczniki' => $out]);
