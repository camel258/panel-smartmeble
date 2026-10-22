SMART MEBLE - patch modułu Monitoring GPS

1. Import SQL
- W phpMyAdmin wybierz obecną bazę SMART MEBLE.
- Zaimportuj plik: database/patch_v_gps_module.sql

2. Wgrywanie plików
- Wgraj folder backend/api/gps
- Wgraj plik frontend/gps.html
- Wgraj plik frontend/assets/js/gps_page.js
- Nadpisz pliki:
  backend/api/session_check.php
  backend/api/role_permissions_get.php
  frontend/assets/js/menu.js

3. Konfiguracja Traccara w patchu
Plik już gotowy:
backend/api/gps/config.php
Ustawione jest:
- base_url: http://smartmeble.dyndns.org:8082/api
- login: panel@smartmeble.pl
- hasło: wpisane tymczasowe

Gdy później zmienisz hasło w Traccar, edytuj tylko:
backend/api/gps/config.php
pole:
'password' => 'NOWE_HASLO'

4. Test ręczny po wdrożeniu
Uruchom w przeglądarce lub przez CLI:
/backend/api/gps/traccar_sync.php

Jeżeli wywołujesz z przeglądarki, musisz być zalogowany do panelu.
Jeżeli z CLI, auth nie jest wymagana.

5. Cron
Dodaj cron co 1 minutę:
* * * * * /usr/bin/php /PELNA_SCIEZKA_DO_PANELU/backend/api/gps/traccar_sync.php >/dev/null 2>&1

Przykład:
* * * * * /usr/bin/php /home/username/domains/smartmeble/public_html/backend/api/gps/traccar_sync.php >/dev/null 2>&1

6. Adres modułu
/frontend/gps.html

7. Uprawnienia
- Moduł GPS pojawi się w Uprawnieniach grup jako: Monitoring GPS
- Domyślnie dostęp otrzymają role 1, 999 i 3
- Potem możesz to zmienić w panelu Uprawnienia grup

8. Opcjonalnie webhook z Traccar
Możesz później dodać webhook:
http://TWOJ_PANEL/backend/api/gps/traccar_webhook.php?token=gps_smartmeble_2026_webhook_token_5802

Webhook nie jest wymagany do działania MVP.
Na start wystarczy cron.

9. Co pokazuje moduł
- mapa wszystkich pojazdów
- status online / postój / jazda
- prawa kolumna zdarzeń
- zdarzenia: zapłon ON/OFF, dłuższy postój, online/offline

10. Jeżeli mapa działa, ale brak zdarzeń
- uruchom ręcznie traccar_sync.php
- sprawdź czy urządzenia w Traccar mają świeże pozycje
- sprawdź dane logowania w backend/api/gps/config.php
- sprawdź czy serwer panelu ma dostęp do smartmeble.dyndns.org:8082
