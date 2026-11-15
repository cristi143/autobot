# Preînregistrare — bot doar pe ZEC: baza și cinci îmbunătățiri

Scrisă pe 15.11.2026, **înainte de orice rulare**. Cerută de Cristi: 50/50 și 30/70,
testele 2, 4, 5, 6 și Fear & Greed.

## Date și reguli comune

- ZECUSDT și BTCUSDT, lumânări de 1h de la Binance (`/api/v3/klines`), 01.07.2019 –
  15.11.2026. Orele lipsă (mentenanță) se completează cu ultima închidere, volum 0.
- Fear & Greed: alternative.me, zilnic. Valoarea zilei D se folosește de la D 01:00 UTC.
- Cost pe parte: comision 0,075% + execuție 0,05% (măsurată live) = **0,125%**.
- Fără levier, doar long. Capital 1000. Decizia la închiderea orei, la prețul ei.
- Încălzire: primele 745 de ore nu se tranzacționează.
- **Antrenare:** 01.08.2019 – 31.12.2022. **Test:** 01.01.2023 – 15.11.2026. Fiecare
  perioadă pornește de la 1000, separat.
- Măsuri: randament anualizat (CAGR), cădere maximă, Calmar = CAGR / cădere.
- Semnalul de panică, identic cu botul: abaterea std (populație) a 24 de randamente
  log orare, rang (parte strict mai mică) față de cele 720 de valori dinainte ≥ 0,8,
  ȘI prețul sub cel de acum 24h.

## Baza

Pondere w în ZEC (w = 0,50 și w = 0,30), restul USDC. Reechilibrare la țintă când
abaterea depășește 5 pp din capital. Referințe: ZEC ținut simplu, și USDC.

## Testul 2 — lățimea benzii

- benzi fixe: 2,5 / 5 / 10 / 15 pp
- bandă după volatilitate: 5 pp × (σ24 de acum / mediana σ24 pe cele 720 de ore
  dinainte), limitată la [2,5; 15] pp

Trece: Calmar peste bandă 5 pp în ambele perioade, la ambele w. Altfel rămâne 5 pp.
Mă aștept la diferențe mici.

## Testul 4 — excepția după panică, pe ZEC

La semnal, ținta urcă la w + 0,25 pentru 24h, apoi revine la w. Un semnal în timpul
unei excepții active sau în ora expirării e ignorat. La pornire și la expirare se
tranzacționează până la țintă; în rest banda de 5 pp.

Control (placebo): 200 de rulări cu același număr de excepții, pornite la ore
aleatoare, aceeași mecanică.

Trece: capital final peste 95% din placebo pe test ȘI peste mediana placebo pe
antrenare, la ambele w. Raportez și Calmar față de bază și față de o bază cu aceeași
expunere medie.

## Testul 5 — BTC ca semnal de risc pentru ZEC

a) Studiu de eveniment: randamentul ZEC pe următoarele 24h după orele cu semnal de
   panică pe BTC, față de media tuturor orelor din același an. t pe medii zilnice.
   Separat: ore cu semnal pe BTC dar fără semnal pe ZEC.
b) Strategie: ca testul 4, dar excepția pornește la semnalul BTC. Același placebo,
   același criteriu.

Dacă (a) iese semnificativ negativ (t < −2 în ambele perioade), varianta „reduce
expunerea” devine ipoteză pentru un test viitor — nu se rulează acum ca strategie
câștigătoare.

## Testul 6 — volumul ca filtru al panicii

Volum = suma pe 24h a volumului în USDT; rang față de cele 720 de valori dinainte.
Semnalele de panică ZEC se împart în „volum mare” (rang ≥ 0,8) și „volum normal”.

a) Studiu de eveniment: randament 24h după semnal, pe cele două grupe; diferența cu t.
b) Strategie: excepția din testul 4 doar la volum mare; și doar la volum normal.

Trece: diferența dintre grupe are același semn în ambele perioade și |t| > 2 pe
total, iar strategia filtrată bate excepția nefiltrată (capital final) în ambele
perioade, la ambele w.

## Testul 7 — Fear & Greed

a) Studiu de eveniment: randamentul ZEC pe următoarele 7 zile, pe grupe: ≤ 25,
   26–45, 46–54, 55–74, ≥ 75.
b) Strategii, ținta se schimbă odată cu indicele (verificat la 01:00 UTC):
   - F1: w + 0,25 cât indicele ≤ 25
   - F2: F1, plus w − 0,25 (minim 0) cât indicele ≥ 75

Control: 200 de rulări cu seria indicelui decalată circular cu un număr aleator de
zile (cel puțin 90).

Trece: capital final peste 95% din placebo pe test și peste mediană pe antrenare,
la ambele w.

## Ce nu se face

- Nu se aleg praguri după rezultat. Dacă o variantă nepreînregistrată pare bună, e
  ipoteză pentru altă dată, cu altă perioadă de test (adică înainte, pe bot).
- ZEC a urcat de ~20 de ori din 2023. Profitul absolut nu e criteriu; se judecă
  față de bază, față de placebo și față de aceeași expunere medie.
- Sunt 5 teste × 2 ponderi: un „trece” izolat, la limită, se tratează ca posibil noroc.

## Adaos, 15.11.2026, înainte de rulare: aceleași teste pe BTC

Cerut de Cristi („ZEC e cumva atipic”). Identic, cu BTCUSDT în locul ZECUSDT: aceleași
perioade, cost, ponderi, praguri, placebo și criterii. La testul 5 semnalul extern e
panica pe ETHUSDT (perechea mare vecină), în locul celei pe BTC. Nimic altceva schimbat.
BTC nu e pereche-sursă mai „curată”: și el a fost în explorarea inițială a ipotezei.
