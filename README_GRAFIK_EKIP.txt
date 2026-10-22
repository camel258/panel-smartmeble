PATCH – Grafik testowy (ekipy) + Ekipy
Data: 16.10.2026 (na bazie backupu "16 10 2026 BACKUP")

CO DOCHODZI
- Menu: "Grafik testowy (ekipy)" i "Ekipy" (widoczne tylko dla Admin / Super Admin).
- Ekipy: dodawanie, edycja, wyłączanie ekip. Skład = pracownicy z panelu
  i/lub osoby bez konta (wpisane tekstem), samochód (podpowiedzi z GPS), kolor.
  Przycisk "Wczytaj 7 ekip testowych" zakłada ekipy z listy:
  Alex/Dominik, Piotr Tułaza/Sławek, Tomasz Jaworski/Aleksander P., Tomasz Kudłaty/Kacper,
  Wojtek/Artur, Jakub/Michał, Karol/Mateusz.
- Grafik testowy: miesiąc × dni, wiersz "Warsztat" + zamówienia z panelu
  (ta sama logika miesięcy co "Zamówienia aktualne"). Wybierasz ekipę u góry
  i klikasz w dzień -> krzyżyk z numerem ekipy. Ponowne kliknięcie zdejmuje.
  Na dole "Obsada ekip" (kto gdzie danego dnia) i "Wolne ekipy".
  Czerwona obwódka = ta sama ekipa w 2 miejscach tego samego dnia.

CO SIĘ NIE ZMIENIA
- Obecny "Grafik" (per monter), ikonki W/M na liście zamówień i "Zestawienie pracy"
  działają jak dotąd. Grafik testowy ma osobne tabele i niczego w nich nie nadpisuje.

PLIKI DO WGRANIA
NOWE:
- /backend/lib/crews_lib.php
- /backend/api/crews.php
- /backend/api/crew_schedule.php
- /frontend/grafik_ekip.html
- /frontend/ekipy.html
PODMIANA (jedna zmiana: 2 nowe pozycje w menu):
- /frontend/assets/js/menu.js

BAZA DANYCH
- Nic nie trzeba robić. Tabele crews, crew_members, crew_schedule zakładają się same
  przy pierwszym wejściu w "Ekipy" albo "Grafik testowy".
- Gdyby wyskoczył błąd o braku uprawnień do CREATE: wklej w phpMyAdmin -> SQL
  zawartość /database/patch_grafik_ekip.sql (tego pliku NIE wgrywaj na serwer).

KOLEJNOŚĆ
1. Wgraj pliki przez FTP (nowe + menu.js).
2. Ctrl+F5 w przeglądarce.
3. Menu -> Ekipy -> "Wczytaj 7 ekip testowych" -> popraw składy (zaznacz monterów
   z panelu, jeśli mają konta) i samochody.
4. Menu -> Grafik testowy (ekipy).

COFNIĘCIE
- Przywróć stary /frontend/assets/js/menu.js z backupu i usuń 5 nowych plików.
  Tabele w bazie można zostawić (nic z nich nie korzysta) albo usunąć:
  DROP TABLE crew_schedule, crew_members, crews;
