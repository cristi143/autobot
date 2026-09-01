# Baza de date — cum se pregătește

Se face **o singură dată**, manual, din cPanel. Durează câteva minute.

## 1. Creezi baza și utilizatorul

cPanel → **MySQL® Databases**:

1. La *Create New Database*, nume: **`autobot`**
   cPanel îi pune singur prefixul contului, deci va deveni `marcelpa_autobot`.
2. La *MySQL Users → Add New User*, nume: **`autobot`** (devine `marcelpa_autobot`).
   Folosește butonul **Password Generator** și **copiază parola** — nu se mai poate
   citi după aceea.
3. La *Add User To Database*, alegi utilizatorul și baza, apoi bifezi
   **ALL PRIVILEGES**.

Reține exact cum arată numele cu prefix — le vei pune în configurare.

## 2. Rulezi schema

cPanel → **phpMyAdmin** → selectezi baza `marcelpa_autobot` în stânga →
tab **Import** → încarci `schema.sql` din acest folder → **Go**.

Ar trebui să raporteze 8 tabele create. Dacă dă vreo eroare, trimite-mi textul
exact — schema n-a putut fi rulată local, neexistând MySQL pe calculatorul de
dezvoltare, deci prima ei rulare adevărată e aceasta.

Tabelele: `lumanari_1h`, `triunghiuri`, `linii`, `semnale`, `pozitii`, `banci`,
`miscari`, `jurnal_cron`.

## 3. Pui configurarea în afara zonei publice

Copiezi `config.exemplu.php` în **`/home/marcelpa/autobot-config.php`**
(din File Manager: îl creezi acolo și lipești conținutul), apoi completezi
numele bazei, utilizatorul și parola.

**De ce acolo și nu lângă site:** dacă PHP-ul cade vreodată — o actualizare
stricată, o configurare greșită — Apache servește fișierele `.php` ca text
simplu. Un `config.php` în document root ar publica parola bazei. Unul cu un
nivel mai sus nu e accesibil prin web în niciun scenariu.

Generează și un `cheie_api` lung și aleatoriu. Nu e autentificare adevărată,
doar o piedică în calea scrierilor accidentale până la etapa 3.

## Două lucruri despre structură, ca să nu surprindă mai târziu

**Timpul e BIGINT peste tot** — milisecunde UTC, exact cum le trimite Binance.
Niciun `DATETIME` pentru momente de piață. Serverul are fusul Europe/Bucharest,
Binance lucrează în UTC, iar ora de vară mută diferența între 2 și 3 ore. Un
număr nu are fus și nu poate fi citit greșit. Conversia la text se face doar la
afișare.

**Ștergerea triunghiurilor e moale.** Baza refuză ștergerea unui triunghi care a
produs un semnal — istoricul tranzacțiilor n-are voie să rămână fără explicație.
Starea `sters` e pentru cele desenate din greșeală, înainte să tragă.
