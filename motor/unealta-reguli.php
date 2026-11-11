<?php
/**
 * Regulile uneltei manuale — funcții pure, fără bază de date și fără rețea.
 *
 * DE CE STAU SINGURE: aceleași reguli sunt folosite în trei locuri — motorul
 * care decide, API-ul care validează ce scrie utilizatorul, și probele. Scrise
 * de trei ori, s-ar desincroniza tăcut, iar o regulă scrisă diferit în două
 * locuri e exact felul de greșeală care nu se vede până nu costă bani.
 *
 * Fișierul ajunge, prin deploy, în /home/marcelpa/autobot-motor/. API-ul îl
 * cere de acolo, pe cale absolută, la fel cum cere configurarea.
 *
 * DOAR LONG. Short-ul a fost scos pe 11.11.2026, la cererea utilizatorului:
 * minimele sunt oportunitatea, maximele sunt profitul.
 */

declare(strict_types=1);

/* ============================ intrarea ================================== */

/**
 * Pragul de la care setarea se armează: cât de departe sub nivelul ales trebuie
 * să coboare prețul ca să credem că a fost acolo cu adevărat.
 */
function u_pragArmare(float $pragIntrare, float $depasire): float {
    return $pragIntrare - $depasire;
}

/** A coborât destul? Se judecă pe wick-uri: contează minimul real. */
function u_sArmat(float $minim, float $pragArmare): bool {
    return $minim <= $pragArmare;
}

/**
 * Extremul de urmărit cât timp setarea e armată: minimul, de la care se măsoară
 * revenirea în sus.
 */
function u_extremIntrare(?float $extrem, float $minim): float {
    return $extrem === null ? $minim : min($extrem, $minim);
}

/**
 * Pragul efectiv de intrare.
 *
 * Nu așteptăm întoarcerea până la nivelul ales dacă prețul a căzut mult mai jos:
 * pragul coboară cu minimul, la `extrem + revenire`. Plafonul e nivelul setat —
 * pragul **doar coboară**, niciodată nu urcă peste el.
 */
function u_pragIntrareEfectiv(float $pragIntrare, ?float $extrem, float $revenire): float {
    if ($extrem === null) return $pragIntrare;
    return min($pragIntrare, $extrem + $revenire);
}

/**
 * Intrarea se declanșează pe ÎNCHIDEREA lumânării de 15m, nu pe atingere.
 * O atingere e zgomot; o închidere peste prag e o afirmație.
 */
function u_declanseazaIntrarea(float $inchidere, float $prag): bool {
    return $inchidere >= $prag;
}

/* ============================ ieșirea =================================== */

/** Ținta a fost atinsă măcar o dată? Pe wick-uri — de aici pornește urmărirea. */
function u_atinsTinta(float $maxim, float $pragIesire): bool {
    return $maxim >= $pragIesire;
}

/** Extremul de urmărit după atingerea țintei: maximul. */
function u_extremIesire(?float $extrem, float $maxim): float {
    return $extrem === null ? $maxim : max($extrem, $maxim);
}

/**
 * Pragul efectiv de ieșire: `maxim − urmărire`, dar niciodată sub țintă.
 * **Doar urcă.** Un prag care ar putea coborî n-ar mai fi o urmărire, ar fi o
 * fluctuație — și ar preda înapoi profitul deja câștigat.
 */
function u_pragIesireEfectiv(float $pragIesire, ?float $extrem, float $urmarire): float {
    if ($extrem === null) return $pragIesire;
    return max($pragIesire, $extrem - $urmarire);
}

/** Ieșirea, tot pe închidere. */
function u_declanseazaIesirea(float $inchidere, float $prag): bool {
    return $inchidere <= $prag;
}

/**
 * Stopul — SINGURA REGULĂ EVALUATĂ PE ATINGERE, cerută explicit de utilizator.
 *
 * Un stop e protecție, nu o afirmație despre piață: dacă prețul a fost acolo,
 * ieșim, fără să așteptăm un sfert de oră confirmarea. Prețul de ieșire se
 * socotește fix la prag — optimism cunoscut și acceptat: un stop adevărat e
 * ordin la piață și alunecă. La bani reali, diferența se măsoară, nu se
 * presupune.
 */
function u_atinsStopul(float $minim, ?float $stop): bool {
    if ($stop === null) return false;
    return $minim <= $stop;
}

/* ============================ banii ===================================== */

/**
 * Randamentul net al unui dus-întors, în procente, după comisionul plătit de
 * două ori.
 */
function u_rezultatNet(float $intrare, float $iesire, float $comision): float {
    return ($iesire / $intrare * (1 - $comision) * (1 - $comision) - 1) * 100;
}

/* ============================ validarea ================================= */

/**
 * Verifică o setare venită de la utilizator. Întoarce lista de probleme; listă
 * goală înseamnă că e bună.
 *
 * Regulile de direcție sunt tot ce apără de o setare care nu se poate declanșa
 * niciodată: ținta sub intrare, un stop deasupra intrării, o revenire mai mare
 * decât coborârea cerută (pragul ar urca peste nivelul ales, deci plafonul l-ar
 * aduce înapoi și „revenirea” n-ar mai însemna nimic).
 */
function u_validareSetare(array $s): array {
    $p = [];

    $numere = [
        'prag_intrare'    => 'Nivelul de intrare',
        'depasire_minima' => 'Coborârea minimă',
        'revenire'        => 'Revenirea',
        'prag_iesire'     => 'Ținta de ieșire',
        'urmarire'        => 'Urmărirea',
    ];
    foreach ($numere as $cheie => $nume) {
        $v = $s[$cheie] ?? null;
        if (!is_numeric($v)) { $p[] = "$nume lipsește sau nu e un număr."; continue; }
        if ((float)$v <= 0)  { $p[] = "$nume trebuie să fie mai mare decât zero."; }
    }
    if ($p) return $p;

    $intrare  = (float)$s['prag_intrare'];
    $depasire = (float)$s['depasire_minima'];
    $revenire = (float)$s['revenire'];
    $iesire   = (float)$s['prag_iesire'];
    $urmarire = (float)$s['urmarire'];
    $stop     = (isset($s['prag_stop']) && $s['prag_stop'] !== null && $s['prag_stop'] !== '')
                ? (float)$s['prag_stop'] : null;

    if ($revenire > $depasire) {
        $p[] = 'Revenirea nu poate fi mai mare decât coborârea minimă'
             . ' — pragul de intrare ar sări peste nivelul ales și plafonul l-ar aduce înapoi.';
    }

    if ($iesire <= $intrare) {
        $p[] = 'Ținta de ieșire trebuie să fie peste nivelul de intrare.';
    }

    // STOPUL TREBUIE SĂ FIE SUB PRAGUL DE ARMARE, nu doar sub nivel. Altfel
    // chiar coborârea care armează setarea o și omoară: cu nivel 800, coborâre
    // minimă 20 și stop 780, aceeași atingere face amândouă, iar setarea n-ar
    // putea intra niciodată. Regula e mai strictă decât „stopul sub nivel” și o
    // înlocuiește.
    $pragArmare = u_pragArmare($intrare, $depasire);
    if ($stop !== null && $stop >= $pragArmare) {
        $p[] = sprintf('Stopul (%s) trebuie să fie sub pragul de armare (%s = nivel − coborâre minimă).'
                     . ' Altfel chiar coborârea care armează setarea o și omoară.',
                       rtrim(rtrim(number_format($stop, 2, '.', ''), '0'), '.'),
                       rtrim(rtrim(number_format($pragArmare, 2, '.', ''), '0'), '.'));
    }

    // O urmărire mai mare decât drumul de la intrare la țintă înseamnă că, în
    // clipa atingerii țintei, pragul ar fi deja sub prețul de intrare. Plafonul
    // îl ține la țintă, deci nu e periculos — dar e semn că cifra e greșită.
    $drum = $iesire - $intrare;
    if ($drum > 0 && $urmarire >= $drum) {
        $p[] = sprintf('Urmărirea (%s) e mai mare decât distanța de la intrare la țintă (%s)'
                     . ' — pragul ar rămâne lipit de țintă și urmărirea n-ar face nimic.',
                       rtrim(rtrim(number_format($urmarire, 2, '.', ''), '0'), '.'),
                       rtrim(rtrim(number_format($drum, 2, '.', ''), '0'), '.'));
    }

    return $p;
}
