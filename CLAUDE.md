# autobot.dunitru.ro — context de lucru

Proiect nou: platformă de tranzacționare automată pe Binance.
Stadiu actual: **doar pagina „în construcție"**, plus lanțul de deploy.

## La începutul fiecărei sesiuni
Citește **`DEPLOY.md`** — hosting, deploy, și împărțirea arhitecturii
(ce rulează pe ClausWeb vs. ce trebuie să ruleze pe VPS).

## Reguli de lucru
- Tot ce ajunge pe web stă în **`public/`**. Deploy-ul nu copiază nimic altceva.
- Se lucrează pe `main`: commit + push. Înainte de push, **arată-i utilizatorului
  ce s-a modificat** ca să confirme.
- Deploy-ul îl face utilizatorul manual din cPanel (Update from Remote →
  Deploy HEAD Commit).
- Mesajele de commit au diacritice → fișier cu `-F`, nu `-m`.
- Se comite cu identitate explicită dacă repo-ul n-o are:
  `git -c user.name="Cristi Iorga" -c user.email="cristi.s.iorga@gmail.com"`
- În commit nu se pune identificatorul de model.

## Reguli specifice botului
- **Cheile API Binance nu ajung NICIODATĂ în git și nici pe ClausWeb.** Stau în
  `.env` pe mașina care rulează motorul. `.gitignore` le blochează — nu-l slăbi.
- **Datele istorice nu intră în git** (`../historical_data/`, ~7.5 GB).
- Când se scrie cod care trimite ordine reale, se cere confirmare explicită și se
  implementează întâi pe **testnet Binance** / mod paper-trading.
- Nu se propune rularea motorului pe shared hosting — nu funcționează, vezi DEPLOY.md §3.

## Context vecin
Același cont cPanel (`marcelpa`) găzduiește și `marcel-parcel.ro` și `dunitru.ro`.
Sunt proiecte complet separate, cu repo-uri separate — nu se modifică nimic acolo
din sesiunile de autobot. **Atenție:** deploy-ul lui dunitru.ro golește
`/home/marcelpa/dunitru.ro/public_html`, de aceea autobot are arbore separat.
