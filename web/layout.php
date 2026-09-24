<?php
declare(strict_types=1);

/* =====================================================================
   MediVerleih - gemeinsames Seitengeruest
   ===================================================================== */

/** Kurzform fuer htmlspecialchars - jede Ausgabe laeuft hierueber. */
function h($wert): string
{
    return htmlspecialchars((string)$wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Die Datenbank speichert Statuswerte ohne Umlaute, damit die ENUM-Werte
   in SQL bequem zu tippen sind. Die Beschriftung gehoert in die
   Oberflaeche, nicht in die Daten. */
const STATUS_TEXT = [
    'verfuegbar'   => 'Verfügbar',
    'verliehen'    => 'Verliehen',
    'ausgemustert' => 'Ausgemustert',
];

function statustext(string $status): string
{
    return STATUS_TEXT[$status] ?? ucfirst($status);
}

/** Statusmarke mit passender Farbe. */
function marke(string $status): string
{
    return '<span class="marke ' . h($status) . '">' . h(statustext($status)) . '</span>';
}

/** Datum als TT.MM.JJJJ. */
function datum(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '&ndash;';
    }
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d.m.Y') : h($iso);
}

const SEITEN = [
    'index'     => 'Dashboard',
    'geraete'   => 'Geräte',
    'ausleihe'  => 'Ausleihe erfassen',
    'rueckgabe' => 'Rückgabe',
    'sql'       => 'SQL-Konsole',
];

function kopf(string $aktiv, string $titel, string $hinweis = ''): void
{
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titel) ?> &ndash; MediVerleih</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="kopfzeile">
        <h1>MediVerleih</h1>
        <span class="untertitel">Verleih medizintechnischer Geräte</span>
    </div>
    <nav>
        <?php foreach (SEITEN as $datei => $beschriftung): ?>
            <a href="<?= h($datei) ?>.php"<?= $datei === $aktiv ? ' class="aktiv"' : '' ?>><?= h($beschriftung) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main>
    <h2><?= h($titel) ?></h2>
    <?php if ($hinweis !== ''): ?><p class="hinweis"><?= h($hinweis) ?></p><?php endif; ?>
    <?php
}

function fuss(): void
{
    ?>
</main>
<footer>
    MediVerleih &middot; Modularbeit Datenbankimplementierung &middot;
    MariaDB <?= h(db()->query('SELECT VERSION()')->fetchColumn()) ?>
</footer>
</body>
</html>
    <?php
}

/** Erfolgs- oder Fehlermeldung ausgeben. */
function meldung(?string $text, string $art = 'ok'): void
{
    if ($text === null || $text === '') {
        return;
    }
    echo '<div class="meldung ' . h($art) . '">' . h($text) . '</div>';
}
