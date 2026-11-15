/**
 * Botul automat — pagina. Doar citește: botul nu primește comenzi de aici.
 *
 * Totul vine din `api/bot.php`, scris de motor o dată pe oră. Pagina nu
 * socotește nicio decizie — ar putea arăta ceva ce motorul n-a făcut.
 */
(function () {
  "use strict";

  /* Se schimbă LA FIECARE modificare din public/. `api/bot.php` o citește de pe
     disc, iar pagina compară: diferite = browserul rulează cod vechi din cache. */
  var VERSIUNE = "2026-11-15-zec-1";

  var REGULI = {
    A: "50% crypto (1/6 pe monedă), reechilibrare la abatere peste 5%",
    B: "ca A, plus excepția: după panică, moneda la 1/3 timp de 24 h",
    C: "pasivă: o treime în fiecare monedă la pornire, apoi nimic",
    D: "50% ZEC, 50% USDC, reechilibrare la abatere peste 5%",
    E: "30% ZEC, 70% USDC, reechilibrare la abatere peste 5%",
    F: "ca E, plus excepția: după panică pe ZEC, 55% timp de 24 h"
  };
  var MONEDE_GRUP = { cos: ["BTCUSDC", "ETHUSDC", "ZECUSDC", "USDC"], zec: ["ZECUSDC", "USDC"] };
  var NUME = { BTCUSDC: "BTC", ETHUSDC: "ETH", ZECUSDC: "ZEC", USDC: "USDC" };

  var date = null;
  function $(id) { return document.getElementById(id); }

  /* ---------------------------------------------------------- formatare */

  function nr(x, z) {
    if (x === null || x === undefined || isNaN(x)) return "—";
    return Number(x).toLocaleString("ro-RO", { minimumFractionDigits: z, maximumFractionDigits: z });
  }
  function proc(x, z) {
    if (x === null || x === undefined || isNaN(x)) return "—";
    return (x > 0 ? "+" : "") + nr(x * 100, z === undefined ? 2 : z) + "%";
  }
  function clasa(x) { return x > 0 ? "plus" : (x < 0 ? "minus" : ""); }
  function pretText(x) { return nr(x, x >= 1000 ? 2 : (x >= 10 ? 2 : 4)); }

  // Orele: UTC în date, afișate în ora României, cu ziua.
  function cand(ms) {
    if (!ms) return "—";
    return new Date(ms).toLocaleString("ro-RO", {
      timeZone: "Europe/Bucharest", day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit"
    });
  }
  // Ora judecată e DESCHIDEREA lumânării; decizia s-a luat la închiderea ei.
  function oraInchiderii(ms) { return ms ? cand(ms + 3600000) : "—"; }
  function deCand(ms) {
    var s = Math.round((Date.now() - ms) / 1000);
    if (s < 90) return s + " secunde";
    var m = Math.round(s / 60);
    if (m < 90) return m + " minute";
    return Math.round(m / 60) + " ore";
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  /* ---------------------------------------------------------- API */

  function ceruStarea() {
    fetch("/api/bot.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) throw new Error(d && d.eroare ? d.eroare : "răspuns neașteptat");
        date = d;
        deseneaza();
      })
      .catch(function (e) { arataBanda("Nu pot citi starea de pe server: " + e.message); });
  }

  function arataBanda(text) { var b = $("banda"); b.textContent = text; b.hidden = !text; }

  function bandaDeStare() {
    var p = [];
    if (date.versiune && date.versiune !== VERSIUNE) {
      p.push("Pagina din browser e mai veche decât cea de pe server (" + VERSIUNE + " față de "
           + date.versiune + "). Reîncarcă forțat: Ctrl+F5.");
    }
    var m = date.motor;
    if (!m) {
      p.push("Motorul n-a rulat niciodată — cronul orar e pornit?");
    } else if (Date.now() - m.ultima > 75 * 60 * 1000) {
      p.push("Motorul n-a mai rulat de " + deCand(m.ultima) + ". Cât timp nu rulează, orele se pierd.");
    } else if (m.rezultat === "eroare") {
      p.push("Ultima rulare a motorului s-a terminat cu eroare: "
           + (m.detaliu || "").split("\n").pop());
    }
    arataBanda(p.join(" · "));
  }

  /* ---------------------------------------------------------- desenarea */

  function deseneaza() {
    bandaDeStare();
    $("ultima-ora").textContent = oraInchiderii(date.ultima_ora);
    $("comision").textContent = nr(date.comision, 3);
    deseneazaBanci();
    deseneazaSemnal();
    deseneazaDecizii();
    deseneazaExceptii();
    var m = date.motor;
    $("motor-linie").textContent = m
      ? "Motorul: ultima rulare acum " + deCand(m.ultima) + " (" + m.rezultat + ")"
        + (date.ore_pierdute ? " · ore pierdute de la pornire: " + date.ore_pierdute : " · nicio oră pierdută")
      : "Motorul: n-a rulat încă.";
  }

  function deseneazaBanci() {
    deseneazaGrup("cos", $("banci"), "Băncile pornesc la prima rulare a motorului.");
    deseneazaGrup("zec", $("banci-zec"), "Băncile pe ZEC pornesc la prima rulare a motorului de după instalare.");
  }

  function deseneazaGrup(grup, c, textGol) {
    c.textContent = "";
    var banci = date.banci.filter(function (b) { return (b.grup || "cos") === grup; });
    if (!banci.length) {
      c.appendChild(el("p", "mic", textGol));
      return;
    }
    var activ = {};   // "B|ZECUSDC" -> excepție activă
    date.exceptii.active.forEach(function (x) { activ[(x.banca || "B") + "|" + x.simbol] = true; });

    banci.forEach(function (b) {
      var k = el("div", "banca");
      var cap = el("div", "banca-cap");
      cap.appendChild(el("span", "banca-nume", "Banca " + b.banca));
      var r = b.valoare === null ? null : b.valoare / b.initial - 1;
      cap.appendChild(el("span", "banca-rand " + clasa(r), proc(r)));
      k.appendChild(cap);
      k.appendChild(el("p", "banca-regula", REGULI[b.banca] || ""));
      k.appendChild(el("div", "banca-valoare", nr(b.valoare, 2) + " USDC"));
      k.appendChild(el("div", "banca-dd", "cea mai mare scădere: " + nr(b.scadere_max * 100, 1) + "%"
                                         + " · de la " + cand(b.pornita)));
      if (b.ponderi) {
        var p = el("div", "ponderi");
        MONEDE_GRUP[grup].forEach(function (s) {
          var w = b.ponderi[s] || 0;
          var rand = el("div", "pondere");
          rand.appendChild(el("span", "", NUME[s]));
          var bara = el("div", "bara-p" + (s === "USDC" ? " usdc" : "")
                               + (activ[b.banca + "|" + s] ? " exc" : ""));
          var u = el("span"); u.style.width = Math.max(0, Math.min(100, w * 100)) + "%";
          bara.appendChild(u);
          rand.appendChild(bara);
          rand.appendChild(el("span", "nr", nr(w * 100, 1) + "%"));
          p.appendChild(rand);
        });
        k.appendChild(p);
      }
      c.appendChild(k);
    });
  }

  function deseneazaSemnal() {
    var t = $("semnal");
    t.textContent = "";
    var chei = Object.keys(date.semnal);
    if (!chei.length) {
      var tr0 = el("tr"); var td0 = el("td", "gol", "—"); td0.colSpan = 5; tr0.appendChild(td0); t.appendChild(tr0);
      return;
    }
    ["BTCUSDC", "ETHUSDC", "ZECUSDC"].forEach(function (m) {
      var s = date.semnal[m];
      if (!s) return;
      var tr = el("tr");
      tr.appendChild(el("td", "", NUME[m]));
      tr.appendChild(el("td", "nr", pretText(s.pret)));
      var tdR = el("td");
      var rang = el("div", "rang");
      var bara = el("div", "bara-p" + (s.rang >= 0.8 ? " peste" : ""));
      var u = el("span"); u.style.width = (s.rang * 100) + "%";
      bara.appendChild(u);
      rang.appendChild(bara);
      rang.appendChild(el("span", "mic", nr(s.rang * 100, 0) + "%"));
      tdR.appendChild(rang);
      tr.appendChild(tdR);
      tr.appendChild(el("td", "nr " + clasa(s.r24), proc(s.r24, 1)));
      var stare;
      var exc = date.exceptii.active.filter(function (x) { return x.simbol === m; })
        .sort(function (a, b) { return (a.banca || "B") < (b.banca || "B") ? -1 : 1; })
        .map(function (x) { return (x.banca || "B") + " până la " + cand(x.expira + 3600000); });
      if (exc.length) stare = el("span", "stare-semnal", "excepție activă: " + exc.join(" · "));
      else if (s.semnal) stare = el("span", "stare-semnal", "SEMNAL");
      else stare = el("span", "mic", s.rang >= 0.8 ? "agitat, dar în creștere" : "liniștit");
      var tdS = el("td"); tdS.appendChild(stare); tr.appendChild(tdS);
      t.appendChild(tr);
    });
  }

  function deseneazaDecizii() {
    var t = $("decizii");
    t.textContent = "";
    if (!date.tranzactii.length) {
      var tr0 = el("tr"); var td0 = el("td", "gol", "Încă nimic."); td0.colSpan = 5; tr0.appendChild(td0); t.appendChild(tr0);
    }
    date.tranzactii.forEach(function (x) {
      var tr = el("tr");
      tr.appendChild(el("td", "cand", oraInchiderii(x.ora)));
      tr.appendChild(el("td", "", x.motiv));
      tr.appendChild(el("td", "nr", nr(x.valoare, 2)));
      tr.appendChild(el("td", "nr", pretText(x.pret)));
      // pozitiv = mai prost decât simularea
      var d = x.cantitate > 0 ? x.pret / x.pret_simulare - 1 : 1 - x.pret / x.pret_simulare;
      tr.appendChild(el("td", "nr " + clasa(-d), proc(-d, 3)));
      t.appendChild(tr);
    });
    var c = date.cost_executie;
    $("cost-executie").textContent = c && c.n
      ? "„Față de simulare” = cât de bine ai executat față de închiderea orei, prețul pe care l-ar fi "
        + "folosit simularea (pozitiv = mai bine). Media pe " + c.n + " tranzacții: " + proc(c.medie === null ? null : -c.medie, 3) + "."
      : "";
  }

  function deseneazaExceptii() {
    var e = date.exceptii;
    var pb = e.pe_banca || {};
    var parti = [];
    ["B", "F"].forEach(function (b) {
      if (pb[b] && pb[b].numar) {
        parti.push("Banca " + b + ": " + pb[b].numar + " excepții încheiate, moneda a făcut în medie "
                   + proc(pb[b].medie_bruta) + " în cele 24 h.");
      }
    });
    $("exceptii-rezumat").textContent = parti.length
      ? parti.join(" ") + " Brut, înainte de comision. Din testele istorice: aproximativ +0,4% pe coș "
        + "și +1,1% pe ZEC, cu o marjă de eroare mai mare decât efectul — câteva zeci de excepții nu spun încă nimic."
      : "";
    var t = $("exceptii");
    if (!e.inchise.length) return;
    t.textContent = "";
    e.inchise.forEach(function (x) {
      var tr = el("tr");
      tr.appendChild(el("td", "", x.banca || "B"));
      tr.appendChild(el("td", "", NUME[x.simbol]));
      tr.appendChild(el("td", "cand", oraInchiderii(x.pornita)));
      tr.appendChild(el("td", "cand", oraInchiderii(x.inchisa)));
      tr.appendChild(el("td", "nr " + clasa(x.rezultat_brut), proc(x.rezultat_brut)));
      t.appendChild(tr);
    });
  }

  ceruStarea();
  setInterval(ceruStarea, 60000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) ceruStarea(); });
})();
