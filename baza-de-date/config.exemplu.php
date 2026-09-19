<?php
/**
 * Model pentru fișierul de configurare.
 *
 * SE COPIAZĂ ÎN AFARA ZONEI PUBLICE, ca /home/marcelpa/autobot-config.php,
 * și se completează cu datele reale. NU se pune în document root și NU intră
 * în git — .gitignore blochează deja `config.php`.
 *
 * Motivul pentru care stă în afara document root-ului: dacă PHP-ul ar cădea
 * vreodată (o actualizare stricată, o configurare greșită), Apache ar servi
 * fișierele .php ca text simplu, iar parola bazei ar deveni publică.
 */

return [
    'db' => [
        'gazda'  => 'localhost',
        'nume'   => 'marcelpa_autobot',   // exact cum îl arată cPanel, cu prefix
        'user'   => 'marcelpa_autobot',
        'parola' => 'PAROLA-GENERATĂ-DE-CPANEL',
    ],

    'piata' => [
        'simbol' => 'ZECUSDC',
    ],

    'reguli' => [
        'comision_o_parte' => 0.075,  // %, deci 0.15 dus-întors
    ],

    // Cheie simplă care apără scrierile din API (setări, „ieși acum").
    // Generează un șir lung și aleatoriu. Nu e autentificare adevărată —
    // parola pe site e amânată deliberat cât timp banii sunt fictivi.
    'cheie_api' => 'ȘIR-LUNG-ȘI-ALEATORIU-AICI',
];
