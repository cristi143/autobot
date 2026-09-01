/* Panoul lateral — poziție, triunghi, bănci, istoric.
 *
 * ATENȚIE: deocamdată alimentat cu DATE DE EXEMPLU, dintr-un singur obiect de mai
 * jos. Motorul nu există încă. Când va exista, `DEMO` se înlocuiește cu răspunsul
 * unui `fetch("/api/stare.php")` — restul fișierului rămâne neschimbat.
 *
 * Singurul lucru care se leagă acum de realitate e prețul curent: îl luăm din
 * grafic, ca să se vadă cum se mișcă „câștigul curent" și acul de pe măsură.
 */

(function () {
  "use strict";

  /* ====== date de exemplu ====== */

  var DEMO = {
    pozitie: {
      deschisa:   true,
      tip:        "long",          // "long" | "short"
      banca:      "long",
      intrare:    818.40,
      intrare_la: "2026-09-01 18:00",
      cantitate:  0.6109,          // ZEC
      tp:         826.58,          // intrare × 1.01
      // SL-ul e prețul liniei de intrare la ora curentă — se schimbă în fiecare oră
      sl_acum:    812.10,
      sl_ora_trecuta: 810.40       // ca să putem arăta încotro se mișcă
    },
    triunghi: {
      exista:  true,
      sus:     831.20,
      jos:     812.10,
      desenat: "2026-09-01 09:00"
    },
    banci: {
      long:  { moneda: "ZEC",  sold_usdc: 512.30, randament: 2.46, vs_hold: -1.10 },
      short: { moneda: "ZEC",  sold_zec: 0.6402,  randament: 4.85, vs_hold:  4.85 }
    },
    istoric: [
      { cand: "01.09 18:00", motiv: "intrare LONG",  rezultat: null },
      { cand: "31.08 07:00", motiv: "TP",            rezultat: +0.85 },
      { cand: "30.08 14:00", motiv: "SL",            rezultat: -1.42 },
      { cand: "29.08 21:00", motiv: "TP",            rezultat: +0.85 },
      { cand: "28.08 11:00", motiv: "SL",            rezultat: -0.63 }
    ]
  };

  /* ====== ajutoare ====== */

  var $ = function (id) { return document.getElementById(id); };

  function pret(v)  { return v == null ? "—" : v.toFixed(2); }
  function proc(v, cu_semn) {
    if (v == null) return "—";
    return (cu_semn && v > 0 ? "+" : "") + v.toFixed(2) + "%";
  }
  function semn(el, v) {
    el.classList.remove("sus", "jos");
    if (v > 0) el.classList.add("sus");
    else if (v < 0) el.classList.add("jos");
  }

  /* ====== redare ====== */

  var pretCurent = null;

  function redaPozitie() {
    var p = DEMO.pozitie;
    var tip = $("poz-tip"), varsta = $("poz-varsta");

    if (!p.deschisa) {
      tip.textContent = "fără poziție";
      tip.className = "pastila goala";
      varsta.textContent = "";
      ["poz-intrare", "poz-acum", "poz-tp", "poz-sl", "poz-pl"].forEach(function (id) {
        $(id).textContent = "—";
      });
      $("masura").hidden = true;
      return;
    }

    tip.textContent = p.tip.toUpperCase() + " deschis";
    tip.className = "pastila " + p.tip;
    varsta.textContent = "din " + p.intrare_la;

    $("poz-intrare").textContent = pret(p.intrare) + "  ·  " + p.cantitate.toFixed(4) + " ZEC";
    $("poz-acum").textContent    = pretCurent == null ? "—" : pret(pretCurent);

    var dist_tp = ((p.tp - (pretCurent ?? p.intrare)) / (pretCurent ?? p.intrare)) * 100;
    $("poz-tp").textContent = pret(p.tp) + "   " + proc(dist_tp, true) + " de aici";

    var dist_sl  = (((pretCurent ?? p.intrare) - p.sl_acum) / (pretCurent ?? p.intrare)) * 100;
    var deplasare = p.sl_acum - p.sl_ora_trecuta;
    var sageata = deplasare > 0 ? " ↑" : (deplasare < 0 ? " ↓" : "");
    $("poz-sl").textContent = pret(p.sl_acum) + sageata + "   −" + Math.abs(dist_sl).toFixed(2) + "% de aici";

    if (pretCurent != null) {
      var pl_proc = ((pretCurent - p.intrare) / p.intrare) * 100;
      var pl_usdc = (pretCurent - p.intrare) * p.cantitate;
      var el = $("poz-pl");
      el.textContent = proc(pl_proc, true) + "   " + (pl_usdc >= 0 ? "+" : "") + pl_usdc.toFixed(2) + " USDC";
      semn(el, pl_proc);

      // acul pe măsura SL — TP
      var interval = p.tp - p.sl_acum;
      var poz = interval > 0 ? ((pretCurent - p.sl_acum) / interval) * 100 : 50;
      $("masura-ac").style.left = Math.max(0, Math.min(100, poz)) + "%";
      $("masura-jos").textContent = "SL " + pret(p.sl_acum);
      $("masura-sus").textContent = "TP " + pret(p.tp);
      $("masura").hidden = false;
    }
  }

  function redaTriunghi() {
    var t = DEMO.triunghi;
    $("triunghi-gol").hidden = t.exista;
    $("triunghi-date").hidden = !t.exista;
    if (!t.exista) return;
    $("tri-sus").textContent  = pret(t.sus);
    $("tri-jos").textContent  = pret(t.jos);
    $("tri-cand").textContent = t.desenat;
  }

  function redaBanci() {
    var l = DEMO.banci.long, s = DEMO.banci.short;

    $("bl-stare").textContent = "în " + l.moneda;
    $("bl-sold").textContent  = l.sold_usdc.toFixed(2) + " USDC";
    $("bl-rand").textContent  = proc(l.randament, true);
    semn($("bl-rand"), l.randament);
    $("bl-vs").textContent    = proc(l.vs_hold, true);
    semn($("bl-vs"), l.vs_hold);

    $("bs-stare").textContent = "în " + s.moneda;
    $("bs-sold").textContent  = s.sold_zec.toFixed(4) + " ZEC";
    $("bs-rand").textContent  = proc(s.randament, true);
    semn($("bs-rand"), s.randament);
    $("bs-vs").textContent    = proc(s.vs_hold, true);
    semn($("bs-vs"), s.vs_hold);
  }

  function redaIstoric() {
    var gazda = $("istoric");
    gazda.textContent = "";
    DEMO.istoric.forEach(function (t) {
      var r = document.createElement("div");
      r.className = "tranz";

      var c = document.createElement("span");
      c.className = "cand"; c.textContent = t.cand;

      var m = document.createElement("span");
      m.className = "motiv"; m.textContent = t.motiv;

      var v = document.createElement("span");
      v.className = "rez";
      if (t.rezultat == null) { v.textContent = "deschisă"; v.style.color = "var(--muted)"; }
      else { v.textContent = proc(t.rezultat, true); semn(v, t.rezultat); }

      r.appendChild(c); r.appendChild(m); r.appendChild(v);
      gazda.appendChild(r);
    });
  }

  /* ====== prețul curent vine din grafic ====== */

  document.addEventListener("autobot:pret", function (ev) {
    pretCurent = ev.detail;
    redaPozitie();
  });

  redaPozitie();
  redaTriunghi();
  redaBanci();
  redaIstoric();
})();
