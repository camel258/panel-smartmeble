# Panel zamówień Smart Meble – panel.smartmeble.pl

Wewnętrzny panel firmy SMART MEBLE Sp. z o.o. (Chwaszczyno) do obsługi zamówień mebli na wymiar:
zamówienia i ich pozycje, wpłaty i rozliczenia, grafik ekip montażowych, reklamacje, wyceny (także z formularza www),
dokumenty do druku, GPS samochodów (Traccar), uprawnienia ról oraz zamówienia sklepów internetowych
**frontypax.pl** i **zbudujmebel.pl**.

Technologia: PHP 8.4 (bez frameworka) + MySQL 8.4, frontend jako statyczne strony HTML/JS wołające API.
Serwer: VPS OVH (nginx), od 2026-10-22; wcześniej home.pl.

## Struktura

| Katalog | Co to |
|---|---|
| `backend/api/` | Endpointy API (JSON), logowanie podpisanym JWT; uprawnienia w `backend/lib/access.php` |
| `backend/lib/` | Biblioteki: dostęp do bazy, uprawnienia, poczta (PHPMailer), sklepy (`sklepy.php`) |
| `backend/cli/` | Zadania z crona, np. `sklep_sync.php` – synchronizacja zamówień sklepów (co 10 min, pełna o 3:20) |
| `backend/templates/` | Szablony dokumentów do zamówień (protokół odbioru, pokwitowanie) |
| `frontend/` | Strony panelu (HTML/JS/CSS) |
| `config/` | Konfiguracja wczytująca `.env` |
| `database/`, `migrations/` | Schemat bazy i poprawki SQL |
| `README*.txt` | Stare instrukcje wdrożeniowe poszczególnych modułów (z czasów home.pl) |

## Konfiguracja

Ustawienia są w pliku `.env` na serwerze (wzór: `.env.example`): baza, `APP_KEY` (min. 32 znaki, podpis tokenów),
SMTP, hasło modułu Wycena (`WYCENA_PASSWORD`), katalog plików prywatnych (`PRIVATE_FILES_DIR`) oraz dostęp do sklepów
(`SKLEP_FRONTYPAX_*`, `SKLEP_ZBUDUJMEBEL_*`: adres, klucze API WooCommerce, sekret webhooka).

**W repozytorium nie ma haseł, kluczy ani danych klientów** – `.env`, załączniki zamówień, logi i katalogi uploadu
są wyłączone w `.gitignore`.

## Na serwerze

- Kod: `/var/www/panel.smartmeble.pl/public_html` (do katalogu strony nie trafiają `install/`, `database/`,
  `migrations/`, `README*`, `test_email.php`).
- Załączniki zamówień: `/var/www/panel.smartmeble.pl/_private_files` (poza katalogiem strony, pobierane tylko przez API).
- nginx zastępuje `.htaccess`: PHP wykonywany tylko dla `/index.php` i `/backend/api/*.php`.
- Przerwa techniczna: plik `/var/www/panel.smartmeble.pl/PRZERWA` → strona 503.
- Sklepy: webhooki WooCommerce (podpis HMAC) → `backend/api/sklep_webhook.php`, cron → `backend/cli/sklep_sync.php`.

## Historia

Pierwszy commit to stan panelu przeniesiony z home.pl (2026-10-22). Kolejne: zabezpieczenia i przeniesienie na VPS,
poprawki uprawnień, zakładki sklepów FRONTYPAX i ZBUDUJMEBEL, wyceny www, eksport zamówień do XLSX.
