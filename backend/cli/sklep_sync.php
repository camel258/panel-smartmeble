<?php
// Synchronizacja zamówień sklepu z panelem (cron, użytkownik www-data):
//   php backend/cli/sklep_sync.php frontypax          – zmienione od ostatniej synchronizacji (co 10 min)
//   php backend/cli/sklep_sync.php frontypax --pelna  – wszystkie + usunięcie skasowanych w sklepie (raz na dobę)
// Katalog backend/cli nie jest dostępny z internetu (nginx wykonuje tylko /index.php i /backend/api/*.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';
$kod = (string)($argv[1] ?? '');
if (!sk_sklep($kod)) { fwrite(STDERR, "Użycie: php sklep_sync.php <sklep> [--pelna]\n"); exit(2); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  [$pobrano, $zmian] = sk_synchronizuj($pdo, $kod, in_array('--pelna', $argv, true));
  echo date('Y-m-d H:i:s') . " $kod: pobrano $pobrano, zmienionych $zmian\n";
} catch (Throwable $e) {
  fwrite(STDERR, date('Y-m-d H:i:s') . " $kod: BŁĄD " . $e->getMessage() . "\n");
  exit(1);
}
