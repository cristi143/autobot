/* Desenarea triunghiurilor peste grafic.
 *
 * Un triunghi = două linii convergente, una deasupra prețului și una dedesubt.
 * Se desenează din patru clicuri: două puncte pentru fiecare linie.
 *
 * Rolul fiecărei linii NU se alege manual: se deduce din poziția prețului curent
 * față de linie, în momentul desenării, și se fixează atunci. Liniile fiind
 * convergente, se vor intersecta cândva, iar un rol dedus la fiecare afișare
 * s-ar inversa singur.
 *
 * Desenăm pe o pânză proprie, așezată peste grafic, în coordonate LOGICE (indicele
 * lumânării), nu în timp: liniile trebuie prelungite dincolo de ultima lumânare,
 * iar acolo timpul nu mai are coordonată pe axă.
 */

(function () {
  "use strict";

  /* Marcaj de versiune. Serverul îl citește din fișierul de pe disc, browserul
     îl are din fișierul pe care chiar l-a încărcat. Dacă cele două diferă,
     browserul rulează cod vechi — și atunci o spune, în loc să ne întrebăm de
     ce o schimbare „nu a avut efect". Se schimbă la fiecare modificare a
     fișierelor din public/. */
  var VERSIUNE = "2026-09-02-e";
  window.AUTOBOT_VERSIUNE = VERSIUNE;

  var A = window.Autobot;
  if (!A) return;

  var gazda   = document.getElementById("grafic");
  var CHEIE   = "autobot_cheie_api";

  /* ---------- pânza de deasupra ---------- */

  var panza = document.createElement("canvas");
  panza.className = "panza-desen";
  gazda.appendChild(panza);
  var ctx = panza.getContext("2d");

  function dimensioneaza() {
    var r = Math.max(1, window.devicePixelRatio || 1);
    var w = gazda.clientWidth, h = gazda.clientHeight;
    panza.width = Math.round(w * r);
    panza.height = Math.round(h * r);
    panza.style.width = w + "px";
    panza.style.height = h + "px";
    ctx.setTransform(r, 0, 0, r, 0, 0);
  }

  /* ---------- starea ---------- */

  var activeSalvate = [];      // triunghiurile încă armate
  var consumate = [];          // cele care au tras — istoric, desenat în gri
  var vizibile = {};           // id -> arătat pe grafic (ales de utilizator)
  var CHEIE_VIZ = "autobot_triunghiuri_vizibile";
  var modDesen = false;
  var puncte = [];             // punctele clicului curent, {ms, pret}
  var indiciu = null;          // punctul de sub cursor, pentru previzualizare
  var inAsteptare = null;      // triunghiul desenat, încă nesalvat
  var pozitie = null;          // {tip, linie, tp} cât timp e o poziție deschisă

  /* ---------- conversii ---------- */

  function xDinMs(ms) {
    var idx = A.msLaIndice(ms);
    if (idx == null) return null;
    return A.chart.timeScale().logicalToCoordinate(idx);
  }
  function yDinPret(p) { return A.serie.priceToCoordinate(p); }

  function msDinX(x) {
    var idx = A.chart.timeScale().coordinateToLogical(x);
    return idx == null ? null : A.indiceLaMs(idx);
  }
  function pretDinY(y) { return A.serie.coordinateToPrice(y); }

  /** Lățimea zonei de desen: tot, mai puțin scala de preț din dreapta. */
  function latimeUtila() {
    var scala = 0;
    try { scala = A.chart.priceScale("right").width() || 0; } catch (e) {}
    return Math.max(0, gazda.clientWidth - scala);
  }

  /* ---------- desenare ---------- */

  var CULORI = {
    sus:    "#26a69a",
    jos:    "#ef5350",
    schita: "#8b949e",
    vechi:  "#7c8794",   // triunghiurile consumate: prezente, dar retrase
    sl:     "#ef5350",   // linia de intrare devine prag de ieșire
    tp:     "#26a69a"
  };

  /** Unde s-a terminat tranzacția: TP sau SL. */
  function traseazaIesire(iesire) {
    var x = xDinMs(iesire.ora), y = yDinPret(iesire.pret);
    if (x == null || y == null || x < 0 || x > latimeUtila()) return;

    var peTP = iesire.motiv === "tp";
    var culoare = peTP ? CULORI.sus : CULORI.jos;

    ctx.save();
    ctx.strokeStyle = culoare;
    ctx.fillStyle = culoare;
    ctx.lineWidth = 1.8;

    if (peTP) {
      ctx.beginPath();
      ctx.arc(x, y, 4, 0, Math.PI * 2);
      ctx.fill();
    } else {
      var d = 4.5;
      ctx.beginPath();
      ctx.moveTo(x - d, y - d); ctx.lineTo(x + d, y + d);
      ctx.moveTo(x + d, y - d); ctx.lineTo(x - d, y + d);
      ctx.stroke();
    }
    ctx.restore();
  }

  /** Locul unde triunghiul a tras: lumânarea și prețul de închidere. */
  function traseazaSemnal(semnal) {
    var x = xDinMs(semnal.ora), y = yDinPret(semnal.pret);
    if (x == null || y == null) return;
    if (x < 0 || x > latimeUtila()) return;

    var lung = semnal.tip === "long";
    var culoare = lung ? CULORI.sus : CULORI.jos;

    ctx.save();
    ctx.fillStyle = culoare;
    ctx.strokeStyle = culoare;
    ctx.lineWidth = 1.5;

    // Săgeata care arată încotro s-a intrat. Stă la distanță de lumânare:
    // lipită de ea, se pierdea printre corpuri și umbre.
    var LATIME = 7;    // jumătate din baza săgeții
    var DISTANTA = 16; // de la prețul semnalului până la baza săgeții
    var INALTIME = 13;

    var sus = lung ? -1 : 1;
    var baza = y + sus * DISTANTA;
    var varf = y + sus * (DISTANTA + INALTIME);

    ctx.beginPath();
    ctx.moveTo(x, varf);
    ctx.lineTo(x - LATIME, baza);
    ctx.lineTo(x + LATIME, baza);
    ctx.closePath();
    ctx.fill();

    // coada, ca să lege săgeata de punctul pe care îl marchează
    ctx.beginPath();
    ctx.moveTo(x, baza);
    ctx.lineTo(x, y + sus * 5);
    ctx.stroke();

    // punctul exact al închiderii care a dat semnalul
    ctx.beginPath();
    ctx.arc(x, y, 3, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
  }

  /** Prag orizontal, de la marginea stângă la dreapta — TP-ul e un preț fix. */
  function traseazaPrag(pretPrag, culoare, eticheta) {
    var y = yDinPret(pretPrag);
    if (y == null) return;
    var lat = latimeUtila();

    ctx.save();
    ctx.strokeStyle = culoare;
    ctx.lineWidth = 1;
    ctx.setLineDash([2, 4]);
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(lat, y);
    ctx.stroke();

    ctx.setLineDash([]);
    ctx.font = "11px ui-monospace, SFMono-Regular, Menlo, monospace";
    ctx.fillStyle = culoare;
    ctx.textBaseline = "bottom";
    ctx.fillText(eticheta, 6, y - 3);
    ctx.restore();
  }

  /** Capetele unei linii în pixeli, sau null dacă nu se pot afla. */
  function capete(l) {
    var x1 = xDinMs(l.t1), y1 = yDinPret(l.p1);
    var x2 = xDinMs(l.t2), y2 = yDinPret(l.p2);
    if (x1 == null || x2 == null || y1 == null || y2 == null) return null;
    return { x1: x1, y1: y1, x2: x2, y2: y2 };
  }

  /**
   * Intersecția a două linii, calculată ÎN PIXELI.
   *
   * Nu în timp: `logicalToCoordinate` întoarce 0 pentru indici logici din afara
   * datelor, deci un vârf aflat în viitor nu poate fi transformat în coordonată.
   * În pixeli n-are importanță — o transformare liniară păstrează intersecțiile,
   * iar capetele liniilor sunt oricum deja pe ecran.
   */
  function intersectie(a, b) {
    var dax = a.x2 - a.x1, day = a.y2 - a.y1;
    var dbx = b.x2 - b.x1, dby = b.y2 - b.y1;
    var numitor = dax * dby - day * dbx;
    if (Math.abs(numitor) < 1e-9) return null;      // paralele
    var t = ((b.x1 - a.x1) * dby - (b.y1 - a.y1) * dbx) / numitor;
    return { x: a.x1 + t * dax, y: a.y1 + t * day };
  }

  /**
   * @param limitaX  dacă e dat, linia se oprește la coordonata asta în loc să se
   *                 prelungească până la marginea din dreapta.
   */
  function traseazaLinie(l, culoare, punctat, subtire, limitaX) {
    var x1 = xDinMs(l.t1), y1 = yDinPret(l.p1);
    var x2 = xDinMs(l.t2), y2 = yDinPret(l.p2);
    if (x1 == null || x2 == null || y1 == null || y2 == null) return;

    var lat = latimeUtila();

    // Linia se prelungește până la marginea din dreapta. Calculul se face în
    // pixeli, ceea ce e corect pentru că ambele axe sunt liniare: o dreaptă în
    // (timp, preț) rămâne o dreaptă pe ecran.
    var xSfarsit = (limitaX != null)
      ? Math.max(limitaX, x1, x2)
      : Math.max(lat, x1, x2);
    var ySfarsit = (x2 === x1)
      ? y2
      : y1 + ((y2 - y1) / (x2 - x1)) * (xSfarsit - x1);

    ctx.save();
    ctx.beginPath();
    ctx.rect(0, 0, lat, gazda.clientHeight);
    ctx.clip();

    ctx.strokeStyle = culoare;
    ctx.lineWidth = subtire ? 1 : 1.5;
    ctx.globalAlpha = subtire ? 0.75 : 1;
    ctx.setLineDash(punctat ? [5, 4] : []);
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(xSfarsit, ySfarsit);
    ctx.stroke();

    // capetele fixate de utilizator
    ctx.setLineDash([]);
    ctx.fillStyle = culoare;
    [[x1, y1], [x2, y2]].forEach(function (p) {
      ctx.beginPath();
      ctx.arc(p[0], p[1], subtire ? 2.5 : 3.5, 0, Math.PI * 2);
      ctx.fill();
    });
    ctx.restore();
  }

  function redeseneaza() {
    ctx.clearRect(0, 0, gazda.clientWidth, gazda.clientHeight);

    // Întâi istoricul, ca să stea în spatele celor active.
    consumate.forEach(function (t) {
      if (!vizibile[t.id]) return;

      // Până la vârf: acolo se închide triunghiul pe care l-a desenat
      // utilizatorul. Liniile converg, deci desenul se mărginește singur — spre
      // deosebire de prelungirea la infinit, care trecea peste tot graficul.
      //
      // Poziția poate trăi mai mult decât vârful: expirarea la vârf privește
      // doar triunghiurile încă armate, nu și un stop loss deja în lucru. Când
      // se întâmplă, semnul ieșirii cade în dreapta triunghiului închis. E
      // corect așa — vârful e un fapt geometric, ieșirea unul de tranzacție.
      var limita = null;
      var cSus = t.linii.sus ? capete(t.linii.sus) : null;
      var cJos = t.linii.jos ? capete(t.linii.jos) : null;
      if (cSus && cJos) {
        var v = intersectie(cSus, cJos);
        // Doar dacă vârful e înainte: în urmă înseamnă laturi care se depărtează,
        // iar atunci n-avem ce închide.
        if (v && v.x > Math.max(cSus.x2, cJos.x2)) limita = v.x;
      }

      if (t.linii.sus) traseazaLinie(t.linii.sus, CULORI.vechi, false, true, limita);
      if (t.linii.jos) traseazaLinie(t.linii.jos, CULORI.vechi, false, true, limita);
      if (t.semnal) traseazaSemnal(t.semnal);
      if (t.iesire) traseazaIesire(t.iesire);
    });

    activeSalvate.forEach(function (t) {
      if (t.linii.sus) traseazaLinie(t.linii.sus, CULORI.sus, false);
      if (t.linii.jos) traseazaLinie(t.linii.jos, CULORI.jos, false);
    });

    // Poziția deschisă: linia de intrare e stop loss-ul, iar TP-ul un preț fix.
    if (pozitie && pozitie.linie) {
      traseazaLinie(pozitie.linie, CULORI.sl, true);
      if (pozitie.tp) traseazaPrag(pozitie.tp, CULORI.tp, "TP " + pozitie.tp.toFixed(2));
    }

    // linia terminată din desenul curent
    if (puncte.length >= 2) {
      traseazaLinie({ t1: puncte[0].ms, p1: puncte[0].pret,
                      t2: puncte[1].ms, p2: puncte[1].pret }, CULORI.schita, true);
    }
    if (puncte.length === 4) {
      traseazaLinie({ t1: puncte[2].ms, p1: puncte[2].pret,
                      t2: puncte[3].ms, p2: puncte[3].pret }, CULORI.schita, true);
    }

    // firul care urmărește cursorul până la al doilea clic
    var n = puncte.length;
    if (modDesen && indiciu && (n === 1 || n === 3)) {
      traseazaLinie({ t1: puncte[n - 1].ms, p1: puncte[n - 1].pret,
                      t2: indiciu.ms, p2: indiciu.pret }, CULORI.schita, true);
    }
  }

  /* ---------- rolul liniei, dedus la desenare ---------- */

  function rolulLiniei(l) {
    var pretAcum = A.pretCurent();
    if (pretAcum == null) return null;
    var lumanari = A.lumanari();
    if (!lumanari.length) return null;
    var acum = lumanari[lumanari.length - 1].time * 1000;

    var panta = (l.p2 - l.p1) / (l.t2 - l.t1);
    var pretLinie = l.p1 + panta * (acum - l.t1);

    // prețul e sub linie -> e linie de sus (spargere în sus = long)
    return pretAcum < pretLinie ? "sus" : "jos";
  }

  /* ---------- interacțiune ---------- */

  function pozitieEveniment(ev) {
    var r = panza.getBoundingClientRect();
    var x = ev.clientX - r.left, y = ev.clientY - r.top;
    if (x > latimeUtila()) return null;
    var ms = msDinX(x), pret = pretDinY(y);
    return (ms == null || pret == null) ? null : { ms: Math.round(ms), pret: pret };
  }

  panza.addEventListener("mousemove", function (ev) {
    if (!modDesen) return;
    indiciu = pozitieEveniment(ev);
    redeseneaza();
  });

  panza.addEventListener("click", function (ev) {
    if (!modDesen) return;
    var p = pozitieEveniment(ev);
    if (!p) return;
    puncte.push(p);
    redeseneaza();
    if (puncte.length === 4) incheieDesenul();
    actualizeazaBara();
  });

  /* ---------- încheierea desenului ---------- */

  function incheieDesenul() {
    var a = { t1: puncte[0].ms, p1: puncte[0].pret, t2: puncte[1].ms, p2: puncte[1].pret };
    var b = { t1: puncte[2].ms, p1: puncte[2].pret, t2: puncte[3].ms, p2: puncte[3].pret };

    var ra = rolulLiniei(a), rb = rolulLiniei(b);

    if (ra === rb) {
      spune("Ambele linii au ieșit „" + ra + "\". Un triunghi are nevoie de una " +
            "deasupra prețului și una dedesubt.", true);
      reseteazaDesenul();
      return;
    }

    inAsteptare = {};
    inAsteptare[ra] = a;
    inAsteptare[rb] = b;
    modDesen = false;
    indiciu = null;
    actualizeazaBara();
  }

  function reseteazaDesenul() {
    puncte = [];
    indiciu = null;
    inAsteptare = null;
    redeseneaza();
    actualizeazaBara();
  }

  /* ---------- vorbitul cu serverul ---------- */

  function cheia() {
    var c = localStorage.getItem(CHEIE);
    if (!c) {
      c = prompt("Cheia de API (o dată, se ține minte în acest browser):");
      if (c) localStorage.setItem(CHEIE, c.trim());
    }
    return c;
  }

  function citesteVizibile() {
    try { return JSON.parse(localStorage.getItem(CHEIE_VIZ)) || {}; }
    catch (e) { return {}; }
  }
  function scrieVizibile() {
    try { localStorage.setItem(CHEIE_VIZ, JSON.stringify(vizibile)); } catch (e) {}
  }

  function incarcaTriunghiuri() {
    return fetch("api/triunghiuri.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.eroare || "răspuns nevalid");
        var toate = d.triunghiuri || [];
        activeSalvate = toate.filter(function (t) { return t.stare === "activ"; });

        // Consumate, cele mai recente întâi.
        consumate = toate.filter(function (t) { return t.stare === "consumat"; })
                         .sort(function (a, b) { return (b.consumat_la || 0) - (a.consumat_la || 0); })
                         .slice(0, 10);

        vizibile = citesteVizibile();
        // Ultimul care a tras se arată din start: e cel pe care vrei să-l vezi
        // după ce ai ieșit din poziție. Restul, la cerere.
        if (consumate.length && vizibile[consumate[0].id] === undefined) {
          vizibile[consumate[0].id] = true;
          scrieVizibile();
        }
        redeseneaza();
        actualizeazaBara();
        document.dispatchEvent(new CustomEvent("autobot:triunghiuri-schimbate"));
      })
      .catch(function (e) { spune("Nu pot citi triunghiurile: " + e.message, true); });
  }

  function salveaza() {
    if (!inAsteptare) return;
    var c = cheia();
    if (!c) return;

    fetch("api/triunghiuri.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Autobot-Cheie": c },
      body: JSON.stringify(inAsteptare)
    })
      .then(function (r) { return r.json().then(function (d) { return { s: r.status, d: d }; }); })
      .then(function (x) {
        if (!x.d.ok) {
          if (x.s === 401) localStorage.removeItem(CHEIE);
          throw new Error(x.d.eroare || "eroare");
        }
        reseteazaDesenul();
        spune("Triunghi salvat.", false);
        return incarcaTriunghiuri();
      })
      .catch(function (e) { spune("Nu am putut salva: " + e.message, true); });
  }

  function sterge(id) {
    var c = cheia();
    if (!c) return;
    fetch("api/triunghiuri.php?id=" + id, {
      method: "DELETE",
      headers: { "X-Autobot-Cheie": c }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.eroare || "eroare");
        return incarcaTriunghiuri();
      })
      .catch(function (e) { spune("Nu am putut șterge: " + e.message, true); });
  }

  /* ---------- bara de unelte ---------- */

  var bara = document.getElementById("unelte");
  var mesaj = document.getElementById("desen-mesaj");

  function candScurt(ms) {
    return ms ? new Date(ms).toISOString().slice(5, 16).replace("T", " ") : "—";
  }

  function spune(text, e) {
    mesaj.textContent = text;
    mesaj.classList.toggle("rau", !!e);
    if (!e) setTimeout(function () {
      if (mesaj.textContent === text) mesaj.textContent = "";
    }, 4000);
  }

  function actualizeazaBara() {
    var b = document.getElementById("btn-deseneaza");
    var conf = document.getElementById("confirmare");
    var lista = document.getElementById("lista-triunghiuri");

    b.textContent = modDesen ? "Renunță" : "Desenează triunghi";
    b.classList.toggle("activ", modDesen);
    panza.style.pointerEvents = modDesen ? "auto" : "none";

    if (modDesen) {
      var pasi = ["primul punct al primei linii", "al doilea punct al primei linii",
                  "primul punct al liniei a doua", "al doilea punct al liniei a doua"];
      spune("Clic: " + pasi[puncte.length], false);
    }

    conf.hidden = !inAsteptare;
    if (inAsteptare) {
      document.getElementById("conf-detaliu").textContent =
        "Linie de sus (long la spargere) și linie de jos (short la spargere), " +
        "deduse din poziția prețului.";
    }

    lista.textContent = "";

    if (!activeSalvate.length) {
      var g = document.createElement("div");
      g.className = "gol";
      g.textContent = "Niciun triunghi activ.";
      lista.appendChild(g);
    }

    activeSalvate.forEach(function (t) {
      var r = document.createElement("div");
      r.className = "rand-triunghi";

      var e = document.createElement("span");
      e.textContent = "#" + t.id + " · " + candScurt(t.desenat_la);

      var b = document.createElement("button");
      b.className = "mic-buton";
      b.textContent = "șterge";
      b.addEventListener("click", function () { sterge(t.id); });

      r.appendChild(e); r.appendChild(b);
      lista.appendChild(r);
    });

    if (consumate.length) {
      var cap = document.createElement("div");
      cap.className = "cap-istoric";
      cap.textContent = "au tras — bifează ca să le vezi";
      lista.appendChild(cap);

      // Lista are înălțime mărginită și derulează dincolo de ea: istoricul
      // crește la nesfârșit, iar panoul are alte lucruri de arătat sub el.
      var cutie = document.createElement("div");
      cutie.className = "istoric-lista";
      lista.appendChild(cutie);

      consumate.forEach(function (t) {
        var r = document.createElement("label");
        r.className = "rand-triunghi vechi";

        var bifa = document.createElement("input");
        bifa.type = "checkbox";
        bifa.checked = !!vizibile[t.id];
        bifa.addEventListener("change", function () {
          vizibile[t.id] = bifa.checked;
          scrieVizibile();
          redeseneaza();
        });

        var e = document.createElement("span");
        e.className = "et-triunghi";
        e.textContent = "#" + t.id + " · " + candScurt(t.consumat_la || t.desenat_la);

        var sm = document.createElement("span");
        if (t.semnal) {
          sm.className = "semn " + t.semnal.tip;
          sm.textContent = t.semnal.tip === "long" ? "LONG" : "SHORT";
        }

        r.appendChild(bifa); r.appendChild(e); r.appendChild(sm);
        cutie.appendChild(r);
      });
    }
  }

  document.getElementById("btn-deseneaza").addEventListener("click", function () {
    if (modDesen || inAsteptare) { modDesen = false; reseteazaDesenul(); spune("", false); }
    else { modDesen = true; puncte = []; actualizeazaBara(); }
  });
  document.getElementById("btn-salveaza").addEventListener("click", salveaza);
  document.getElementById("btn-renunta").addEventListener("click", function () {
    reseteazaDesenul();
  });

  /* ---------- sincronizare cu graficul ---------- */

  A.chart.timeScale().subscribeVisibleLogicalRangeChange(redeseneaza);
  window.addEventListener("resize", function () { dimensioneaza(); redeseneaza(); });

  /* Plasă de siguranță pentru scala de preț.
     Biblioteca anunță schimbările pe axa timpului, dar nu și pe cea verticală.
     În mod normal nu contează: fiecare tic de preț declanșează oricum o
     redesenare. Rămâne însă cazul în care graficul își reîncadrează singur
     prețurile fără să vină un tic — de exemplu după sosirea datelor din punte,
     sau cu WebSocket-ul căzut. Atunci liniile ar rămâne la înălțimea veche.

     Verificăm unde ajunge un preț de referință și redesenăm doar când chiar
     s-a mutat ceva: un apel de funcție la fiecare jumătate de secundă. */
  var ultimulY = null, ultimaLatime = null;
  setInterval(function () {
    var lum = A.lumanari();
    if (!lum.length) return;
    var y = A.serie.priceToCoordinate(lum[lum.length - 1].close);
    var lat = latimeUtila();
    if (y !== ultimulY || lat !== ultimaLatime) {
      ultimulY = y;
      ultimaLatime = lat;
      redeseneaza();
    }
  }, 500);
  document.addEventListener("autobot:pret", redeseneaza);
  document.addEventListener("autobot:pozitie", function (ev) {
    pozitie = ev.detail;
    redeseneaza();
  });

  dimensioneaza();
  redeseneaza();
  incarcaTriunghiuri();
})();
