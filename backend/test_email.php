<?php
require_once __DIR__ . '/config/config.php'; $me=auth_user(); if(!$me){ http_response_code(401); die('Brak autoryzacji.'); }
$pdo = db(); require_once __DIR__ . '/lib/mail.php'; $tpl=file_get_contents(__DIR__.'/lib/mail_template.html');
$html = render_template($tpl, ['content_html'=>"<h2>Wiadomość testowa</h2><div>To jest wiadomość testowa z systemu Smart Meble Panel v1.4.</div><div>Miniatura zamówienia: Kuchnia + garderoba</div>", 'cta_url'=>"https://panel.smartmeble.pl/frontend/orders.html"]);
$to = $me['email'] ?? 'kamil@smartmeble.pl'; $row=$pdo->query("SELECT mail_from FROM email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC); $from = ($row && $row['mail_from']) ? $row['mail_from'] : 'powiadomienia@smartmeble.pl';
echo send_html_mail($to,$from,"[Smart Meble] Test wiadomości",$html) ? "Wysłano test na: ".htmlspecialchars($to) : "Nie udało się wysłać wiadomości.";