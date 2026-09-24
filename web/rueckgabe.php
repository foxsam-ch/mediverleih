<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

/* ---------------------------------------------------------------------
   Rueckgabe ueber die Stored Procedure sp_geraet_zurueckgeben().
   Sie setzt nur das Rueckgabedatum - alles Weitere erledigt der
   Trigger: Geraet wird wieder verfuegbar, und sobald alle Positionen
   eines Belegs zurueck sind, wird der Beleg geschlossen.
   --------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $geraetId = (int)($_POST['geraet_id'] ?? 0);

    try {
        $stmt = db()->prepare('CALL sp_geraet_zurueckgeben(?)');
        $stmt->execute([$geraetId]);
        $stmt->closeCursor();

        $_SESSION['meldung'] = 'Rückgabe erfasst. Gerät ist wieder verfügbar.';
        $_SESSION['art']     = 'ok';
    } catch (PDOException $e) {
        $_SESSION['meldung'] = 'Abgewiesen: ' . fehlertext($e);
        $_SESSION['art']     = 'fehler';
    }

    header('Location: rueckgabe.php');
    exit;
}

$meldung = $_SESSION['meldung'] ?? null;
$art     = $_SESSION['art']     ?? 'ok';
unset($_SESSION['meldung'], $_SESSION['art']);

$offene = abfrage(
    'SELECT * FROM v_ausleihen_offen ORDER BY verzugstage DESC, geplante_rueckgabe'
);

$abgeschlossen = abfrage(
    "SELECT a.belegnummer, vk.name_voll AS kunde, vk.institution,
            a.ausleihdatum, a.tatsaechliche_rueckgabe,
            art.bezeichnung AS ausleihart
       FROM ausleihe a
       JOIN v_kunde vk    ON vk.kunde_id = a.kunde_id
       JOIN ausleihart art ON art.id = a.ausleihart_id
      WHERE a.status = 'zurueckgegeben'
      ORDER BY a.tatsaechliche_rueckgabe DESC
      LIMIT 10"
);

kopf('rueckgabe', 'Rückgabe',
     'Die Rücknahme setzt nur das Rückgabedatum. Gerätestatus und Belegabschluss erledigt der Trigger.');

meldung($meldung, $art);
?>

<h3>Laufende Ausleihen &ndash; Rücknahme</h3>
<div class="karte">
    <div class="tabellenrahmen">
    <table>
        <thead>
        <tr>
            <th>Beleg</th><th>Empfänger</th><th>Gerät</th><th>Inventar-Nr.</th>
            <th>Ausgabe</th><th>Rückgabe geplant</th><th class="zahl">Verzug</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$offene): ?>
            <tr><td colspan="8" class="leer">Keine laufenden Ausleihen.</td></tr>
        <?php endif; ?>
        <?php foreach ($offene as $z): ?>
            <tr>
                <td class="nowrap"><?= h($z['belegnummer']) ?></td>
                <td><?= h($z['kunde']) ?><br>
                    <span class="hinweis"><?= h($z['institution'] ?? 'Privat') ?></span></td>
                <td><?= h($z['geraet']) ?></td>
                <td class="nowrap"><?= h($z['inventarnummer']) ?></td>
                <td class="nowrap"><?= datum($z['ausleihdatum']) ?></td>
                <td class="nowrap"><?= datum($z['geplante_rueckgabe']) ?></td>
                <td class="zahl">
                    <?php if ((int)$z['verzugstage'] > 0): ?>
                        <span class="verzug"><?= (int)$z['verzugstage'] ?> T</span>
                    <?php else: ?>&ndash;<?php endif; ?>
                </td>
                <td>
                    <form method="post">
                        <input type="hidden" name="geraet_id" value="<?= (int)$z['geraet_id'] ?>">
                        <button type="submit" class="klein">Zurücknehmen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<h3>Zuletzt abgeschlossene Belege</h3>
<div class="karte">
    <p class="hinweis">
        Ein Beleg wird nicht von der Applikation geschlossen, sondern vom Trigger
        &ndash; sobald die letzte Position zurückgegeben ist.
    </p>
    <div class="tabellenrahmen">
    <table>
        <thead>
        <tr><th>Beleg</th><th>Empfänger</th><th>Institution</th>
            <th>Ausleihart</th><th>Ausgabe</th><th>Rückgabe</th></tr>
        </thead>
        <tbody>
        <?php if (!$abgeschlossen): ?>
            <tr><td colspan="6" class="leer">Noch kein Beleg abgeschlossen.</td></tr>
        <?php endif; ?>
        <?php foreach ($abgeschlossen as $b): ?>
            <tr>
                <td class="nowrap"><?= h($b['belegnummer']) ?></td>
                <td><?= h($b['kunde']) ?></td>
                <td><?= h($b['institution'] ?? 'Privat') ?></td>
                <td><?= h($b['ausleihart']) ?></td>
                <td class="nowrap"><?= datum($b['ausleihdatum']) ?></td>
                <td class="nowrap"><?= datum($b['tatsaechliche_rueckgabe']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php fuss(); ?>
