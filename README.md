# autobot

**Unealtă de tranzacționare manuală** pe Binance, ZECUSDC.
Web: [autobot.dunitru.ro](https://autobot.dunitru.ro)

Tu pui nivelurile, sistemul doar execută. Nu decide nimic singur, nu caută
semnale, nu desenează. Fără o setare scrisă de om, nu face nimic.

Deosebirea față de un ordin obișnuit: **nu se intră la atingerea nivelului, ci
la revenirea la el.** Un ordin limită la 800 s-ar executa pe drumul în jos; aici
prețul trebuie să treacă dincolo și să se întoarcă.

Bani fictivi.

- Regulile, întregi: **[docs/plan-unealta.md](docs/plan-unealta.md)**
- Hosting și deploy: **[DEPLOY.md](DEPLOY.md)**
- Ce ajunge pe web: [`public/`](public/) · motorul, din cron: [`motor/`](motor/)

## Probe

```bash
php motor/probe/unealta-matematica.php    # 88 de probe, fără bază de date
```

## Dezvoltare locală

```bash
cd public && python3 -m http.server 8000
# http://localhost:8000  (API-ul cere PHP și baza de date, deci doar pagina)
```
