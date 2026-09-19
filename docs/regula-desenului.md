# Cum trage Cristi triunghiurile — prima formulare în cuvinte

Scris pe 20 septembrie 2026, prin interviu. Planul spunea, din septembrie, că
„utilizatorul nu poate explica verbal cum trage liniile". S-a dovedit că poate
parțial — dar numai întrebat despre **ce îl face să NU tragă**, nu despre ce îl
face să tragă.

**Statutul acestui fișier:** ipoteză de testat, nu adevăr. E ce a spus el despre
propriul comportament, iar oamenii se înșală sistematic când își descriu
propriile reguli. `desen-orb` (vezi mai jos) există tocmai ca să-l confirme sau
să-l contrazică.

---

## Regula, așa cum a ieșit

### 1. Capetele liniilor stau pe extremele reale

Pe vârfurile wick-urilor, nu pe corpuri și nu „aproximativ, ca să arate bine".
Maximul atins contează chiar dacă a fost atins o clipă.

*(Coincide cu alegerea făcută independent la unealta manuală, unde extremele se
iau tot din wick-uri. Aceeași intuiție, în două locuri diferite.)*

### 2. Convergența — o singură mărime, cu prag jos și prag sus

**Descoperirea principală a interviului.** Cele două obiecții pe care le-a
formulat inițial ca fiind separate — „e prea strâns" și „unghiul dintre laturi e
prea mare" — sunt **capetele aceleiași mărimi**: cât de repede se închid laturile
una spre alta.

| Convergență | Unghi la vârf | Cum arată | Verdict |
|---|---|---|---|
| lentă | → 0°, ascuțit | fâșie lungă și subțire | **nu-l trage** |
| medie | ~30-90° | triunghi ca-n manual | **îl trage** |
| rapidă | → 180°, bont | două linii izbite frontal | **nu-l trage** |

**Pragurile, în cifre:** cu ~200 de lumânări pe ecran, trage triunghiul dacă
vârful e la **între 10 și 50 de lumânări** în față. Sub ~5 e prea bont, peste
~100 e prea ascuțit.

Normalizat, ca să nu depindă de zoom: **vârful trebuie să cadă între 5% și 25%
din lățimea ferestrei vizibile**, socotit de la marginea din dreapta.

⚠ **Unghiul e o proprietate a ecranului, nu a datelor.** Zoom vertical diferit →
alt unghi, pe aceleași prețuri. De aceea regula nu se poate calcula din prețuri
singure, ci numai împreună cu fereastra vizibilă la desenare. Câmpurile
`fereastra_de_la` / `fereastra_pana_la` au fost puse în bază pe 2 septembrie din
alt motiv („vârful evident depinde de cât se vedea pe ecran"); se dovedesc a fi
exact ce face regula asta calculabilă.

### 3. Prospețime — de când n-a mai atins prețul o latură

Nu vechimea punctelor și nu cât mers lateral a fost, ci **de câte lumânări nu
s-a mai lipit prețul de vreuna dintre laturi.** Un triunghi pe care nu-l mai
confirmă nimeni s-a „răcit".

Reperele lui: **~4 lumânări = viu**, **~25 = răcit**. Pragul exact nu e stabilit.

### 4. Vârfurile — cât de proeminente

Un vârf e bun dacă „iese puțin din tipar": are de o parte și de alta lumânări
mai mici, cât să se vadă că e vârf. **Nu foarte proeminent** — cu un filtru prea
strict nu mai rămâne niciun semnal. Estimarea lui: măcar 3-4 lumânări de fiecare
parte.

**Un wick are voie să străpungă linia.** Linia nu trebuie să fie o barieră
perfectă.

⚠ Dar nota triunghiului #6, scrisă de el la desenare, spune altceva: *„linia de
jos trece printr-o lumânare, nu era intenția mea"*. Deci un wick care iese peste
linie e acceptabil, dar linia care taie printr-o lumânare e o greșeală. Ori
distincția e corp/wick, ori e o inconsecvență. **Neclarificat.**

### 5. Simetria nu e obligatorie

O latură are voie să fie orizontală. Deci triunghiurile ascendente și
descendente intră în regulă, nu doar cele simetrice.

### 6. Ce NU contează

- **Lățimea pe orizontală** (de la primul la ultimul punct). „Unghiul e tot."

### 7. Contextul de dinainte — corectare

Prima versiune a acestui fișier scria că nu contează contextul. **Greșit, și e o
deosebire importantă.** Ce a spus el de fapt:

> „cu siguranță contează, dar eu nu mă uit acolo pentru că nu știu să citesc
> datele."

Nu e un criteriu absent din regulă — e unul pe care **nu are unelte să-l
folosească**. Diferența contează: în primul caz l-am exclude din model, în al
doilea e o zonă în care modelul poate adăuga ceva ce el n-are.

De testat, ca trăsături măsurabile de context: mărimea și direcția mișcării
dinaintea strângerii, raportul dintre volatilitatea de acum și cea de dinainte,
poziția triunghiului față de maximele/minimele recente, cât de mult s-a îngustat
amplitudinea față de media ei.

---

## Ce spun cele 5 triunghiuri deja desenate

Măsurat pe 20.09.2026, direct din `triunghiuri.php` și din lumânările de 1h.
Eșantion mic — 5 triunghiuri, 18 capete de linie — deci orientativ, nu concludent.

### Capetele stau pe wick-uri: confirmat

**17 din 18** capete sunt mai aproape de extremul lumânării decât de corp.
Abaterea mediană de la extrem: **1,18 USDC** la un preț de 800-1500, adică sub
0,1%. Practic se lipesc exact de vârful wick-ului.

Triunghiurile #6 și #7 au **ambele** capete ale fiecărei linii pe extreme reale.
La #4 și #5 (primele desenate, în perioada de învățare a uneltei) doar primul
capăt e ancorat, al doilea e pus liber. Pare progres, nu regulă.

### Proeminența: estimarea lui de 3-4 lumânări e bună

Măsurată corect — adică numărând la dreapta **doar lumânările care existau deja
în clipa desenului**, nu tot ce a urmat după:

| Filtru | Câte capete ar trece |
|---|---|
| ≥1 lumânare mai mică de fiecare parte | 10/12 |
| **≥3 lumânări** | **8/12** |
| ≥5 lumânări | 8/12 |

Proeminența mediană a unui capăt: **11 lumânări** pe partea mai slabă. Pragul de
3 și cel de 5 dau același rezultat, deci distribuția e ruptă în două: ori
vârfuri clare (5-37 lumânări), ori vârfuri deloc (0-2).

**Cele care cad sunt, în majoritate, capătul cel mai recent al unei linii** —
care prin definiție n-are cum să fie un vârf confirmat, pentru că lumânările de
după el încă nu existau. Un detector de vârfuri nu poate cere confirmare
simetrică pentru punctul cel mai nou. Ori acceptă asimetria, ori tratează
ultimul capăt ca provizoriu.

### Unghiul: intervalul declarat e prea larg

A zis 5-25% din fereastră. Măsurat pe cele trei triunghiuri care au fereastra
salvată:

| Triunghi | Lumânări până la vârf | Fereastră | Raport |
|---|---|---|---|
| #6 | 27 | 244 | 10,9% |
| #7 | 10 | 109 | 9,1% |
| #8 | 16 | 246 | 6,3% |

Toate trei între **6% și 11%** — mult mai strâns decât a declarat.

**Și o ambiguitate care nu se poate rezolva cu 5 exemple.** Nota lui de la #7 se
plânge că vârful era prea aproape. Dar #7 nu e cel mai mic ca raport (9,1%, față
de 6,3% la #8) — e cel mai mic **ca număr absolut de lumânări** (10, față de 16).

Deci două citiri, amândouă compatibile cu datele:

- **unghi vizual** → contează raportul la fereastră (era zoomat mai tare la #7,
  deci vizual vârful părea departe, și totuși s-a plâns);
- **timp până la eveniment** → contează numărul absolut de lumânări, iar zoomul
  n-are nicio treabă.

Nota de la #7 înclină spre a doua. Desenul orb, cu zoom controlat, o poate
despărți definitiv: aceeași geometrie arătată la două zoomuri diferite.

### Un criteriu care nu apăruse deloc în interviu

Nota de la #6: *„am vrut să trag triunghiul mai devreme dar mi s-a părut că este
prea aproape de cel de dinainte și că nu are sens."*

Există deci și o **regulă de distanță față de triunghiul precedent** — un fel de
perioadă refractară. Nu a menționat-o când a fost întrebat direct; a ieșit din
ce scrisese la momentul faptei.

Asta e cel mai bun argument pentru câmpul `nota`: **notele scrise la desenare au
adus două criterii pe care interviul nu le-a scos.** Merită păstrat obiceiul.

---

## Gaura din regulă, și de ce e o veste bună

Regula de mai sus **filtrează, nu alege.**

Pe orice fereastră de grafic există zeci de vârfuri și de văi. Regula spune care
perechi de linii sunt acceptabile — dar nu spune pe care dintre ele le-ar desena
el. Actul propriu-zis al desenului, alegerea punctelor, rămâne neexplicat.

Asta împarte întrebarea în două, și **prima nu mai are nevoie de niciun desen:**

**Întrebarea 1 — are clasa asta de tipar vreun avantaj?**
Se enumeră mecanic TOATE triunghiurile care respectă regula (unghi în interval,
prospețime sub prag, capete pe wick-uri), pe cele ~30 de simboluri × 9 luni din
`../../historical_data/`. Se rulează prin regulile de ieșire existente. Se
compară cu etalonul deja măsurat — intrarea la întâmplare pe 6.856 de ore,
tabelul din `plan-tranzactionare.md`.
**Se poate face acum, într-o după-amiază.** Dacă răspunsul e nu, subiectul se
închide cu dovadă, fără ca el să deseneze nimic.

**Întrebarea 2 — alege el mai bine decât media?**
Abia dacă prima are răspuns pozitiv. Atunci desenul orb compară alegerea lui cu
**populația triunghiurilor care se calificau în aceeași fereastră** — nu cu
intrarea la întâmplare. Etalon mult mai strict, și care cere mult mai puține
exemple ca să spună ceva, fiindcă baza de comparație e deja foarte bună.

---

## Ce rămâne nespus

- **Care vârfuri.** Cea mai mare gaură. Nu s-a discutat ce face un vârf „demn de
  unit" — cât de proeminent, câte lumânări în jurul lui, dacă tolerează ca linia
  să fie străpunsă de un wick.
- **Simetria.** Trebuie ambele laturi înclinate (triunghi simetric), sau merge și
  una orizontală (triunghi ascendent/descendent)? Neîntrebat.
- **Pragul de prospețime**, undeva între 4 și 25 de lumânări.
- **Unde e prețul în interiorul triunghiului** la momentul desenului.

Toate patru se pot afla din desenul orb, măsurate în loc de declarate.
