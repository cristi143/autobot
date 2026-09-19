# autobot.dunitru.ro — ghid de deploy

Sistem identic cu `dunitru.ro` și `marcel-parcel.ro`: GitHub → cPanel Git Version
Control → Deploy. Domeniul găzduiește **un singur lucru**: unealta de
tranzacționare manuală, la rădăcină.

---

## 1. Unde trăiește

- **Cod sursă:** GitHub `cristi143/autobot`
- **Producție:** ClausWeb, cont cPanel `marcelpa` (autobot.dunitru.ro e **subdomeniu**)
  - document root: `/home/marcelpa/autobot.dunitru.ro/`, sau
    `.../public_html/` — **deploy-ul îl detectează singur**
  - clona Git a serverului: `/home/marcelpa/repositories/autobot`
  - motorul, în afara webului: `/home/marcelpa/autobot-motor/`
  - configurarea, cu un nivel deasupra a tot: `/home/marcelpa/autobot-config.php`

### ⚠ De ce document root separat

Deploy-ul lui `dunitru.ro` **golește complet** `/home/marcelpa/dunitru.ro/public_html`.
Dacă subdomeniul ar avea document root-ul înăuntru (ce propune cPanel implicit),
s-ar șterge la fiecare deploy de dunitru.ro. De aceea arborele separat.

---

## 2. Flux de lucru

1. Modificare → commit + push pe `main`
2. cPanel → Git™ Version Control → `autobot` → Manage → **Deploy HEAD Commit**
3. Verifici https://autobot.dunitru.ro (Ctrl+F5)

Log: `/home/marcelpa/deploy-autobot.log`

### ⚠ „Update from Remote" e stricat (18.09.2026)

Butonul **răspunde „The repository is up-to-date" fără să tragă nimic** — clona
rămâne pe commitul vechi, iar „Deploy HEAD Commit" publică liniștit versiunea
veche. Descoperit pe `marcel-parcel`, **același cont cPanel**.

Nu e greșeală de configurare: s-au verificat `.git/config`, fișierele `.lock`,
ștergerea și recrearea repo-ului, tokenul GitHub, cheia SSH și rețeaua spre
github.com — toate în regulă. Povestea completă:
`../../02_Marcel/marcel-parcel/DEPLOY.md`, secțiunea 4.1.

**Verificarea care nu minte:** în *Basic Information*, **HEAD Commit** trebuie să
arate ce ai împins tu. Sau, din File Manager, data lui
`/home/marcelpa/repositories/autobot/.git/FETCH_HEAD` — git o rescrie doar după
un fetch reușit.

**Ocolirea, deja făcută:** un Cron Job la 5 minute care rulează ce refuză butonul.
Deploy-ul rămâne manual; cronul aduce doar codul.

### Cele două cronuri

| Cron | Ce face | Cât de des |
|---|---|---|
| `unealta.php` | motorul uneltei | la un minut |
| `git fetch` | aduce codul de pe GitHub | la 5 minute |

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/marcelpa/autobot-motor/unealta.php >/dev/null 2>&1
```
```
cd /home/marcelpa/repositories/autobot; { date; git fetch origin; git merge --ff-only origin/main; } > /home/marcelpa/git-pull-autobot.log 2>&1
```

> **⚠** Lista de Cron Jobs e comună pe tot contul, deci acolo stau și cronurile
> altor site-uri. Pe 19.09.2026 s-a șters din greșeală un motor în loc de
> altceva și a stat 11 ore oprit, fără ca nimic să se plângă în afară de banda
> din pagină. **Citește comanda întreagă înainte de a apăsa Delete.**

---

## 2.1 Parola — AMÂNATĂ DELIBERAT (19.09.2026)

**Site-ul rămâne deschis, și e o decizie, nu o scăpare.** Cât timp băncile sunt
simulate, cel mai rău lucru pe care îl poate face un străin care nimerește
adresa e să strice o simulare.

**De reluat subiectul** la bani reali, chei API pe server, date care nu sunt
fictive, sau dacă adresa ajunge la altcineva. Utilizatorul a cerut explicit să
fie întrebat atunci.

⚠ **Când se face, capcana e aici, și șterge parola tăcut.** cPanel → *Directory
Privacy* scrie liniile de autentificare în `.htaccess`-ul din document root —
**exact fișierul pe care deploy-ul îl suprascrie** cu `public/.htaccess` din
repo. Configurată doar din cPanel, parola dispare la primul deploy și nimic nu
anunță.

Deci: o configurezi din cPanel, apoi copiezi din File Manager liniile `AuthType`
/ `AuthName` / `AuthUserFile` / `Require valid-user` și le pui permanent în
`public/.htaccess`, în git. Fișierul `.htpasswd` rămâne unde l-a pus cPanel, în
afara document root-ului, deci supraviețuiește.

---

## 3. Capcane cPanel (aflate pe pielea noastră)

- **Câmpul „Document Root" e RELATIV la home.** Dacă scrii `/home/marcelpa/x`,
  cPanel îl transformă în `/home/marcelpa/marcelpa/x`. Scrie doar `x`.
- **cPanel adaugă uneori un `public_html` înăuntru, alteori nu.** De aceea
  `.cpanel.yml` își detectează singur document root-ul.
- **Golirea document root-ului păstrează fișierele cPanel-ului:** `.well-known`,
  `cgi-bin`, `.user.ini`, `php.ini`. `.well-known` e critic — acolo pune AutoSSL
  dovada de validare. **Nu scoate excepțiile din `.cpanel.yml`.**
- **cPanel oprește deploy-ul la prima comandă cu exit ≠ 0.**
- **Fiecare task din `.cpanel.yml` rulează în shell separat** — variabilele nu
  supraviețuiesc de la un rând la altul.
- **Deploy-ul copiază motorul, nu golește folderul lui.** De aceea `.cpanel.yml`
  șterge explicit fișierele rămase din versiuni vechi.

---

## 4. Structura repo-ului

```
autobot/
├── .cpanel.yml          rețeta de deploy
├── CLAUDE.md · DEPLOY.md · README.md
├── docs/
│   └── plan-unealta.md      sursa de adevăr pentru reguli
├── baza-de-date/
│   ├── migratii/2026-09-19-unealta.sql   cele 6 tabele u_*
│   ├── config.exemplu.php
│   └── README.md            cum se pregătește baza
├── motor/               → /home/marcelpa/autobot-motor/ (ÎN AFARA webului)
│   ├── unealta.php          motorul, din cron la un minut
│   ├── unealta-reguli.php   regulile: funcții pure, cerute și de API
│   └── probe/unealta-matematica.php   88 de probe, fără bază de date
└── public/              → document root
    ├── index.html · unealta.js · unealta.css
    ├── api/             _comun.php · unealta.php
    └── favicon.svg, robots.txt, .htaccess
```

**Motorul nu ajunge în document root.** Deploy-ul îl copiază separat, unde nu e
accesibil prin web. Cronul îl cheamă de acolo.

---

## 5. Migrațiile

**Sunt manuale și nu rulează la deploy.** Se scriu în `baza-de-date/migratii/`,
se rulează din cPanel → phpMyAdmin (selectează întâi baza în panoul din stânga,
altfel dă `#1046 - No database selected`), **înaintea** deploy-ului codului care
le cere. Altfel motorul pică la prima rulare, pe o coloană care nu există.
