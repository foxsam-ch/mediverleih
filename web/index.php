<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

/* Alle Kennzahlen kommen aus den Views bzw. direkt aus der Tabelle -
   die Applikation rechnet nichts nach, was die Datenbank schon weiss. */

$total        = (int)wert('SELECT COUNT(*) FROM geraet');
$verfuegbar   = (int)wert("SELECT COUNT(*) FROM geraet WHERE status = 'verfuegbar'");
$verliehen    = (int)wert("SELECT COUNT(*) FROM geraet WHERE status = 'verliehen'");
$ausgemustert = (int)wert("SELECT COUNT(*) FROM geraet WHERE status = 'ausgemustert'");
$ueberfaellig = (int)wert('SELECT COUNT(*) FROM v_ausleihen_offen WHERE ist_ueberfaellig = 1');

$offeneListe = abfrage(
    'SELECT * FROM v_ausleihen_offen ORDER BY verzugstage DESC, geplante_rueckgabe ASC'
);

kopf('index', 'Dashboard', 'Übersicht über Gerätebestand und laufende Ausleihen.');
?>

<div class="kennzahlen">
    <div class="kennzahl"><div class="wert"><?= $total ?></div><div class="titel">Geräte total</div></div>
    <div class="kennzahl"><div class="wert"><?= $verfuegbar ?></div><div class="titel">Verfügbar</div></div>
    <div class="kennzahl"><div class="wert"><?= $verliehen ?></div><div class="titel">Verliehen</div></div>
    <div class="kennzahl"><div class="wert"><?= $ausgemustert ?></div><div class="titel">Ausgemustert</div></div>
    <div class="kennzahl<?= $ueberfaellig > 0 ? ' achtung' : '' ?>"><div class="wert"><?= $ueberfaellig ?></div><div class="titel">Überfällig</div></div>
</div>

<h3>Laufende Ausleihen</h3>
<div class="karte">
    <div class="tabellenrahmen">
    <table>
        <thead>
        <tr>
            <th>Beleg</th><th>Institution</th><th>Empfänger</th><th>Gerät</th>
            <th>Inventar-Nr.</th><th>Ausleihart</th><th>Ausgabe</th>
            <th>Rückgabe geplant</th><th class="zahl">Leihdauer</th><th class="zahl">Verzug</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$offeneListe): ?>
            <tr><td colspan="10" class="leer">Keine laufenden Ausleihen.</td></tr>
        <?php endif; ?>
        <?php foreach ($offeneListe as $z): ?>
            <tr>
                <td class="nowrap"><?= h($z['belegnummer']) ?></td>
                <td><?= $z['institution'] !== null ? h($z['institution']) : '<em>Privat</em>' ?></td>
                <td><?= h($z['kunde']) ?></td>
                <td><?= h($z['geraet']) ?></td>
                <td class="nowrap"><?= h($z['inventarnummer']) ?></td>
                <td><?= h($z['ausleihart']) ?></td>
                <td><?= datum($z['ausleihdatum']) ?></td>
                <td><?= datum($z['geplante_rueckgabe']) ?></td>
                <td class="zahl"><?= (int)$z['leihdauer_tage'] ?> T</td>
                <td class="zahl">
                    <?php if ((int)$z['verzugstage'] > 0): ?>
                        <span class="verzug"><?= (int)$z['verzugstage'] ?> T</span>
                    <?php else: ?>
                        &ndash;
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php fuss(); ?>
