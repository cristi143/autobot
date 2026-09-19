<?php
/**
 * Regulile uneltei manuale — funcții pure, fără bază de date și fără rețea.
 *
 * DE CE STAU SINGURE, spre deosebire de botul vechi (unde probele își rescriu
 * propria copie a funcțiilor): aceleași reguli sunt folosite în trei locuri —
 * motorul care decide, API-ul care validează ce scrie utilizatorul, și probele.
 * Scrise de trei ori, s-ar desincroniza tăcut, iar o regulă inversată între
 * long și short e exact felul de greșeală care nu se vede până nu costă bani.
 *
 * Fișierul ajunge, prin deploy, în /home/marcelpa/autobot-motor/. API-ul îl
 * cere de acolo, pe cale absolută, la fel cum cere configurarea.
 *
 * TOATE FUNCȚIILE DE AICI SUNT SIMETRICE ÎNTRE LONG ȘI SHORT. Long vede
 * minimele ca oportunitate și maximele ca profit; short exact invers. Simetria
 * e scrisă o singură dată, în fiecare funcție, și nicăieri altundeva.
 */

declare(strict_types=1);

/* ============================ intrarea ================================== */

/**
 * Pragul de la care setarea se armează: cât de departe dincolo de nivelul ales
 * trebuie să meargă prețul ca să credem că a fost acolo cu adevărat.
 */
function u_pragArmare(string $banca, float $pragIntrare, float $depasire): float {
    return $banca === 'long' ? $pragIntrare - $depasire : $pragIntrare + $depasire;
}

/** S-a dus destul de departe? Se judecă pe wick-uri: contează extremul real. */
function u_sArmat(string $banca, float $maxim, float $minim, float $pragArmare): bool {
    return $banca === 'long' ? $minim <= $pragArmare : $maxim >= $pragArmare;
}

/**
 * Extremul de urmărit cât timp setarea e armată: minimul pentru long (de acolo
 * se măsoară revenirea în sus), maximul pentru short.
 */
function u_extremIntrare(string $banca, ?float $extrem, float $maxim, float $minim): float {
    if ($banca === 'long')  { return $extrem === null ? $minim : min($extrem, $minim); }
    return $extrem === null ? $maxim : max($extrem, $maxim);
}

/**
 * Pragul efectiv de intrare.
 *
 * Nu așteptăm întoarcerea până la nivelul ales dacă prețul a căzut mult mai jos:
 * pragul coboară cu minimul, la `extrem + revenire`. Plafonul e nivelul setat —
 * pragul **doar se depărtează** de el, niciodată nu urcă peste. (La short,
 * oglindit: doar coboară sub nivel, niciodată peste.)
 */
function u_pragIntrareEfectiv(string $banca, float $pragIntrare, ?float $extrem, float $revenire): float {
    if ($extrem === null) return $pragIntrare;
    return $banca === 'long'
        ? min($pragIntrare, $extrem + $revenire)
        : max($pragIntrare, $extrem - $revenire);
}

/**
 * Intrarea se declanșează pe ÎNCHIDEREA lumânării de 15m, nu pe atingere.
 * O atingere e zgomot; o închidere dincolo de prag e o afirmație.
 */
function u_declanseazaIntrarea(string $banca, float $inchidere, float $prag): bool {
    return $banca === 'long' ? $inchidere >= $prag : $inchidere <= $prag;
}

/* ============================ ieșirea =================================== */

/** Ținta a fost atinsă măcar o dată? Pe wick-uri — de aici pornește urmărirea. */
function u_atinsTinta(string $banca, float $maxim, float $minim, float $pragIesire): bool {
    return $banca === 'long' ? $maxim >= $pragIesire : $minim <= $pragIesire;
}

/** Extremul de urmărit după atingerea țintei: maximul la long, minimul la short. */
function u_extremIesire(string $banca, ?float $extrem, float $maxim, float $minim): float {
    if ($banca === 'long')  { return $extrem === null ? $maxim : max($extrem, $maxim); }
    return $extrem === null ? $minim : min($extrem, $minim);
}

/**
 * Pragul efectiv de ieșire: `maxim − urmărire`, dar niciodată sub țintă.
 * **Doar urcă.** Un prag care ar putea coborî n-ar mai fi o urmărire, ar fi o
 * fluctuație — și ar preda înapoi profitul deja câștigat.
 */
function u_pragIesireEfectiv(string $banca, float $pragIesire, ?float $extrem, float $urmarire): float {
    if ($extrem === null) return $pragIesire;
    return $banca === 'long'
        ? max($pragIesire, $extrem - $urmarire)
        : min($pragIesire, $extrem + $urmarire);
}

/** Ieșirea, tot pe închidere. */
function u_declanseazaIesirea(string $banca, float $inchidere, float $prag): bool {
    return $banca === 'long' ? $inchidere <= $prag : $inchidere >= $prag;
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
function u_atinsStopul(string $banca, float $maxim, float $minim, ?float $stop): bool {
    if ($stop === null) return false;
    return $banca === 'long' ? $minim <= $stop : $maxim >= $stop;
}

/* ============================ banii ===================================== */

/**
 * Randamentul net al unui dus-întors, în procente, după comisionul plătit de
 * două ori. Pentru short raportul se inversează: se câștigă când prețul scade,
 * iar câștigul se măsoară în ZEC.
 */
function u_rezultatNet(string $banca, float $intrare, float $iesire, float $comision): float {
    $raport = ($banca === 'long') ? $iesire / $intrare : $intrare / $iesire;
    return ($raport * (1 - $comision) * (1 - $comision) - 1) * 100;
}

/* ============================ validarea ================================= */

/**
 * Verifică o setare venită de la utilizator. Întoarce lista de probleme; listă
 * goală înseamnă că e bună.
 *
 * Regulile de direcție sunt tot ce apără de o setare care nu se poate declanșa
 * niciodată: un long cu ținta sub intrare, un stop deasupra intrării, o
 * revenire mai mare decât coborârea cerută (pragul ar urca peste nivelul ales,
 * deci plafonul l-ar aduce înapoi și „revenirea” n-ar mai însemna nimic).
 */
function u_validareSetare(array $s): array {
    $p = [];
    $banca = $s['banca'] ?? '';
    if ($banca !== 'long' && $banca !== 'short') {
        return ['Banca trebuie să fie „long” sau „short”.'];
    }

    $numere = [
        'prag_intrare'    => 'Nivelul de intrare',
        'depasire_minima' => ($banca === 'long' ? 'Coborârea minimă' : 'Urcarea minimă'),
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
        $p[] = 'Revenirea nu poate fi mai mare decât '
             . ($banca === 'long' ? 'coborârea minimă' : 'urcarea minimă')
             . ' — pragul de intrare ar sări peste nivelul ales și plafonul l-ar aduce înapoi.';
    }

    // STOPUL TREBUIE SĂ FIE DINCOLO DE PRAGUL DE ARMARE, nu doar dincolo de
    // nivel. Altfel chiar coborârea care armează setarea o și omoară: cu nivel
    // 800, coborâre minimă 20 și stop 780, aceeași atingere face amândouă, iar
    // setarea n-ar putea intra niciodată. Regula e mai strictă decât „stopul sub
    // nivel” și o înlocuiește.
    $pragArmare = u_pragArmare($banca, $intrare, $depasire);

    if ($banca === 'long') {
        if ($iesire <= $intrare) {
            $p[] = 'La long, ținta de ieșire trebuie să fie peste nivelul de intrare.';
        }
        if ($stop !== null && $stop >= $pragArmare) {
            $p[] = sprintf('Stopul (%s) trebuie să fie sub pragul de armare (%s = nivel − coborâre minimă).'
                         . ' Altfel chiar coborârea care armează setarea o și omoară.',
                           rtrim(rtrim(number_format($stop, 2, '.', ''), '0'), '.'),
                           rtrim(rtrim(number_format($pragArmare, 2, '.', ''), '0'), '.'));
        }
    } else {
        if ($iesire >= $intrare) {
            $p[] = 'La short, ținta de ieșire trebuie să fie sub nivelul de intrare.';
        }
        if ($stop !== null && $stop <= $pragArmare) {
            $p[] = sprintf('Stopul (%s) trebuie să fie peste pragul de armare (%s = nivel + urcare minimă).'
                         . ' Altfel chiar urcarea care armează setarea o și omoară.',
                           rtrim(rtrim(number_format($stop, 2, '.', ''), '0'), '.'),
                           rtrim(rtrim(number_format($pragArmare, 2, '.', ''), '0'), '.'));
        }
    }

    // O urmărire mai mare decât drumul de la intrare la țintă înseamnă că, în
    // clipa atingerii țintei, pragul ar fi deja sub prețul de intrare. Plafonul
    // îl ține la țintă, deci nu e periculos — dar e semn că cifra e greșită.
    $drum = abs($iesire - $intrare);
    if ($urmarire >= $drum) {
        $p[] = sprintf('Urmărirea (%s) e mai mare decât distanța de la intrare la țintă (%s)'
                     . ' — pragul ar rămâne lipit de țintă și urmărirea n-ar face nimic.',
                       rtrim(rtrim(number_format($urmarire, 2, '.', ''), '0'), '.'),
                       rtrim(rtrim(number_format($drum, 2, '.', ''), '0'), '.'));
    }

    return $p;
}
