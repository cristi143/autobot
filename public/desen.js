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

  var activeSalvate = [];      // triunghiurile din baza de date
  var modDesen = false;
  var puncte = [];             // punctele clicului curent, {ms, pret}
  var indiciu = null;          // punctul de sub cursor, pentru previzualizare
  var inAsteptare = null;      // triunghiul desenat, încă nesalvat

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
    sus:       "#26a69a",
    jos:       "#ef5350",
    schita:    "#8b949e",
    manere:    "#e6edf3"
  };

  function traseazaLinie(l, culoare, punctat) {
    var x1 = xDinMs(l.t1), y1 = yDinPret(l.p1);
    var x2 = xDinMs(l.t2), y2 = yDinPret(l.p2);
    if (x1 == null || x2 == null || y1 == null || y2 == null) return;

    var lat = latimeUtila();

    // Linia se prelungește până la marginea din dreapta. Calculul se face în
    // pixeli, ceea ce e corect pentru că ambele axe sunt liniare: o dreaptă în
    // (timp, preț) rămâne o dreaptă pe ecran.
    var xSfarsit = Math.max(lat, x1, x2);
    var ySfarsit = (x2 === x1)
      ? y2
      : y1 + ((y2 - y1) / (x2 - x1)) * (xSfarsit - x1);

    ctx.save();
    ctx.beginPath();
    ctx.rect(0, 0, lat, gazda.clientHeight);
    ctx.clip();

    ctx.strokeStyle = culoare;
    ctx.lineWidth = 1.5;
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
      ctx.arc(p[0], p[1], 3.5, 0, Math.PI * 2);
      ctx.fill();
    });
    ctx.restore();
  }

  function redeseneaza() {
    ctx.clearRect(0, 0, gazda.clientWidth, gazda.clientHeight);

    activeSalvate.forEach(function (t) {
      if (t.linii.sus) traseazaLinie(t.linii.sus, CULORI.sus, false);
      if (t.linii.jos) traseazaLinie(t.linii.jos, CULORI.jos, false);
    });

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

  function incarcaTriunghiuri() {
    return fetch("api/triunghiuri.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.eroare || "răspuns nevalid");
        activeSalvate = (d.triunghiuri || []).filter(function (t) { return t.stare === "activ"; });
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
      var d = new Date(t.desenat_la);
      var e = document.createElement("span");
      e.textContent = "#" + t.id + " · " + d.toISOString().slice(0, 16).replace("T", " ");
      var s = document.createElement("button");
      s.className = "mic-buton";
      s.textContent = "șterge";
      s.addEventListener("click", function () { sterge(t.id); });
      r.appendChild(e); r.appendChild(s);
      lista.appendChild(r);
    });
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
  document.addEventListener("autobot:pret", redeseneaza);

  dimensioneaza();
  redeseneaza();
  incarcaTriunghiuri();
})();
