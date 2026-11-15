# Plan — băncile pe ZEC singur (D, E, F)

Scris pe 15.11.2026. **Stare: codat și probat local pe 15.11.2026; pornește la prima oră de după migrație și deploy.** Continuă `plan-bot.md`,
care rămâne sursa de adevăr pentru băncile A, B, C și pentru mecanica motorului.

## 1. Ce se adaugă și ce nu se atinge

Trei bănci noi, doar pe **ZECUSDC**, câte **1000 USDC** fictivi, pornite în aceeași oră:

| Banca | Regula | De ce există |
|---|---|---|
| **D** | 50% ZEC, 50% USDC. Reechilibrare la abatere > 5 pp din capital. | Baza, la riscul ales până acum |
| **E** | 30% ZEC, 70% USDC. Aceeași bandă. | Varianta prudentă |
| **F** | Ca E, plus: la semnalul de panică pe ZEC ținta urcă la **55%** timp de **24 h**, apoi revine la 30%. | Singurul adaos care a trecut testul — experiment |

**A, B și C rămân neatinse** și rulează mai departe: sunt un test preînregistrat, iar
o schimbare le-ar reporni numărătoarea (`plan-bot.md` §9).

## 2. De unde vin regulile

Preînregistrare, cod și rezultate: `cercetare/PREINREGISTRARE_zec_singur.md`,
`zec_singur.py`, `zec_singur_ZEC.out`, `zec_singur_BTC.out`. Ore Binance 07.2019 –
11.2026, cost 0,125% pe parte, antrenare 2019-08..2022-12, test 2023-01..2026-11.

**Baza** (randament anual / cea mai mare scădere):

| | ZEC 2019–22 | ZEC 2023–26 | BTC 2019–22 | BTC 2023–26 |
|---|---|---|---|---|
| moneda ținută simplu | −15,8% / 91% | +127,6% / 73% | +15,7% / 77% | +42,5% / 54% |
| 50/50 | +12,7% / 60% | +76,9% / 44% | +16,2% / 49% | +23,2% / 31% |
| 30/70 | +11,7% / 41% | +45,7% / 27,5% | +11,5% / 33% | +14,0% / 20% |

Reechilibrarea taie căderea cam la jumătate pe ambele monede, în ambele perioade.
Asta e partea robustă a planului.

**Cele cinci îmbunătățiri încercate:**

| Test | ZEC | BTC |
|---|---|---|
| Lățimea benzii (2,5–15 pp, sau după volatilitate) | pică | pică |
| Excepția după panică (+25 pp, 24 h) | **trece** (peste 96–98% din placebo pe antrenare, 100% pe test) | pică (sub bază pe antrenare) |
| Panica altei monede ca semnal (BTC pentru ZEC, ETH pentru BTC) | pică | pică |
| Volumul ca filtru al panicii | pică ca strategie | pică, cu semn invers |
| Fear & Greed | pică | pică |

**Rezervele despre excepție (banca F):**
- trece doar pe ZEC, care e și una dintre monedele pe care a fost găsită ipoteza;
- pe BTC evenimentul există (exces +0,5%, t ≈ 3–4), dar brutul e ~+0,3% pe 24 h,
  sub costul dus-întors; pe ZEC brutul pe eveniment e de ~4 ori mai mare (+1,1…1,2%);
- pe ZEC a fost sub bază în 2019, 2020 și 2026; câștigul vine din 2021, 2024, 2025;
- ~150 de tranzacții pe an, față de ~30 la bază.

De aceea F e o bancă de test, nu varianta principală.

**Alegerea ZEC** e făcută după rezultat: ZEC a urcat de ~20 de ori din 2023. Pe un
an întreg (11.2025 – 11.2026) 50/50 a dat +44,9% pe ZEC, −14,8% pe BTC, −21,0% pe ETH.

## 3. Regulile, exact

Aceleași ca în `plan-bot.md` §4, cu o singură monedă:

- lumânări de 1h închise, ZECUSDC; decizia la închiderea orei;
- semnalul de panică: neschimbat (`b_evalueaza`) — abaterea std a 24 de randamente
  log orare, rang ≥ 0,8 față de cele 720 de ore dinainte, ȘI preț sub cel de acum 24 h;
- ținte: D 0,50 · E 0,30 · F 0,30, respectiv 0,55 cât e activă excepția;
- F: un semnal în timpul unei excepții active sau în chiar ora expirării e ignorat;
- tranzacție: la pornirea și la expirarea excepției, până la țintă, oricât de mică;
  altfel doar la abatere > 5 pp din capital;
- fără levier: o cumpărare nu cheltuie mai mult USDC decât există;
- cumpără la ask, vinde la bid, comision 0,075% pe parte; se scrie și închiderea orei;
- ore pierdute: se judecă și se scriu, fără tranzacții.

## 4. Cum s-a implementat

Hotărât cu Cristi pe 15.11.2026 și făcut așa:

- **Regulile:** `z_pasOra` în `motor/bot-reguli.php`, funcție separată de `b_pasOra`;
  funcțiile băncilor A, B, C nu au fost atinse. Semnalul e același (`b_evalueaza`).
- **Baza:** `migratii/2026-11-15-banci-zec.sql` — coloana `banca` în `b_exceptii`
  (rândurile vechi devin ale lui B). Se rulează **înaintea** deploy-ului.
- **Motorul:** D, E, F pornesc la prima rulare în care nu există în `b_banci`.
- **Probele:** `motor/probe/zec-matematica.php` (33) și `zec-echivalenta.php`
  (0 diferențe în 63 921 de semnale; băncile identice cu simularea pe ambele perioade).
- **Pagina:** două grupuri de bănci; excepțiile au coloana „Banca”.

Întrebările de la care s-a pornit:

1. **Regulile pure.** `bot-reguli.php` are coșul și ponderile ca constante
   (`B_MONEDE`, `B_PROPORTIE`, `B_PONDERE_EXC`). Băncile noi cer aceste valori pe
   bancă, fără ca A, B, C să-și schimbe vreun rezultat — proba de echivalență
   existentă trebuie să treacă neschimbată.
2. **Baza de date.** Tabelele `b_*` au deja coloana de bancă; de verificat dacă
   `b_exceptii` și `b_detineri` duc o bancă cu o singură monedă fără migrație.
3. **Proba de echivalență pentru D, E, F**: funcțiile PHP față de `zec_singur.py`,
   pe aceleași ore, înainte de pornirea cronului.
4. **Pagina:** șase bănci în loc de trei; `VERSIUNE` din `bot.js` se schimbă.
5. **Ora de pornire** comună pentru D, E, F.

## 5. Cum se citesc rezultatele (preînregistrat acum)

- la **3 luni**: doar mecanica — echivalență, cost de execuție, ore pierdute;
- la **12 luni**: E față de D (cât randament s-a dat pe cât risc) și **F față de E**
  — randament, cea mai mare scădere, media brută a excepțiilor cu marja ei.
  Se raportează oricum ar ieși;
- un an nu poate confirma excepția (aceeași socoteală ca în `plan-bot.md` §9), dar
  o poate infirma dacă F rămâne clar sub E;
- regulile nu se schimbă pe parcurs. Altă regulă = altă bancă.

## 6. Ce nu se face

- Fără chei API, fără ordine reale. Trecerea pe bani reali e proiect separat, cu
  confirmare explicită, și redeschide discuția despre parolă.
- Nu se readuc: banda dinamică, BTC ca semnal, filtrul de volum, Fear & Greed,
  ținta de volatilitate. Testate, picate.
