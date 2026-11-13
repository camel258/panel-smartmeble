# panel.smartmeble.pl – panel zamówień SMART MEBLE (PHP)

Sesja „PANEL SMARTMEBLE”. Zasady ogólne serwera: `~/.claude/CLAUDE.md`. Historia szczegółowa: `~/.claude/projects/-/memory/panel-smartmeble-vps.md` (czytaj przy większych zmianach).

## Gdzie co jest
- Kod (to): `~/migracja/panel-smartmeble/kod`, GitHub camel258/panel-smartmeble (klucz `~/.ssh/github_panel_smartmeble`, alias `github-panel-smartmeble`). Push po commitach.
- Strona działa na koncie `panel` (pula `/run/php/php8.4-fpm-panel.sock`), kod `panel:www-data` 2750/0640, `config/.env` 0600 panel. Przy wgrywaniu: `sudo chown -R panel:www-data …`.
- Baza lokalna `smartpanel` (MySQL). Pliki prywatne poza stroną.
- Zakładki sklepów: FRONTYPAX i ZBUDUJMEBEL (`backend/lib/sklepy.php`, API `sklep_zamowienia.php`, webhook `sklep_webhook.php`, cron `/etc/cron.d/panel-smartmeble-sklepy` co 10 min + pełna 03:20, log `/var/log/panel-smartmeble-sklepy.log`). Zmiana statusu w panelu = zmiana statusu w WooCommerce (maile do klienta jak w sklepie).
- Konfigurator smartmeble.pl wysyła wyceny do `www_wyceny_public.php`.

## Zasady
- Hasła i klucze tylko w `config/.env` (nigdy w repo, nigdy w rozmowie).
- Przed zmianą struktury/danych bazy – kopia z datą.
- Zmiana statusów zamówień z panelu wysyła maile klientom – testuj ostrożnie.
