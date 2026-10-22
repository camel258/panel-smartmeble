PATCH – Punkt 2: Monterzy (backend + instrukcja)

Cel:
- w karcie zamówienia monterzy mają pokazywać TYLKO przypisanych
- ma dać się zmieniać przypisanych monterów i zapisać

Wgraj i NADPISZ:

BACKEND:
- /backend/api/order_get.php
- /backend/api/order_update.php

FRONTEND:
Jeżeli używasz order_details.html z select#f_mounters (multiple) oraz select#f_scheduler,
to wklej zawartość pliku:
- /frontend/order_details_mounters_patch_snippet.html
na końcu order_details.html (przed </body>).

Ctrl+F5 po wgraniu.
