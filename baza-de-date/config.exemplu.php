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
        'simbol'   => 'ZECUSDC',
        'interval' => '1h',
    ],

    'reguli' => [
        'tp_procent'         => 1.0,    // ieșire la intrare × 1.01
        'comision_o_parte'   => 0.075,  // %, deci 0.15 dus-întors
        'capital_initial'    => 1000.0, // USDC în banca de long
    ],

    // Cheie simplă care apără scrierile din API (salvare/ștergere de triunghiuri).
    // Generează un șir lung și aleatoriu. Nu e autentificare adevărată — aceea
    // vine la etapa 3, cu protecția prin parolă din cPanel.
    'cheie_api' => 'ȘIR-LUNG-ȘI-ALEATORIU-AICI',
];
