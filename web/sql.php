<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

/* ---------------------------------------------------------------------
   SQL-Konsole fuer die Praesentation.

   Zwei voneinander unabhaengige Schutzschichten:

   1) Diese Seite laesst nur SELECT und WITH durch, und nur eine
      einzelne Anweisung.
   2) Sie verbindet sich als mediverleih_ro. Dieser Benutzer hat
      ausschliesslich SELECT-Recht auf drei Views - keine Rechte auf
      die Rohtabellen und kein Schreibrecht.

   Schicht 2 ist die wichtige. Wenn Schicht 1 eine Luecke haette,
   wuerde die Datenbank den Zugriff trotzdem verweigern.
   --------------------------------------------------------------------- */

const BEISPIELE = [
    'Gerätebestand' =>
        "SELECT inventarnummer, hersteller, typ, seriennummer, status\n"
      . "  FROM v_geraete_uebersicht\n"
      . " ORDER BY inventarnummer",

    'Überfällige Ausleihen' =>
        "SELECT belegnummer, kunde, geraet, geplante_rueckgabe, verzugstage\n"
      . "  FROM v_ausleihen_offen\n"
      . " WHERE ist_ueberfaellig = 1\n"
      . " ORDER BY verzugstage DESC",

    'Auswertung nach Ausleihart' =>
        "-- Genau dafuer gibt es die Tabelle ausleihart:\n"
      . "-- Freitext liesse sich so nicht gruppieren.\n"
      . "SELECT ausleihart, COUNT(*) AS anzahl\n"
      . "  FROM v_ausleihen_offen\n"
      . " GROUP BY ausleihart\n"
      . " ORDER BY anzahl DESC",

    'Kunden mit Adresse' =>
        "-- COALESCE im View loest auf, ob die Adresse von der\n"
      . "-- Institution oder von der Privatperson kommt:\n"
      . "SELECT kundentyp, institution, name_voll, strasse, plz, ort\n"
      . "  FROM v_kunde\n"
      . " ORDER BY kundentyp, nachname",

    'Bestand nach Status' =>
        "SELECT status, COUNT(*) AS anzahl\n"
      . "  FROM v_geraete_uebersicht\n"
      . " GROUP BY status\n"
      . " ORDER BY anzahl DESC",

    'Rechte-Demo: Rohtabelle' =>
        "-- mediverleih_ro darf nur die Views lesen.\n"
      . "-- Dieser Zugriff wird von der Datenbank abgewiesen:\n"
      . "SELECT * FROM geraet",
];

/** Nur eine einzelne lesende Anweisung zulassen. */
function ist_lesend(string $sql): bool
{
    // Zeilenkommentare entfernen, damit sie die Pruefung nicht verdecken
    $ohneKommentar = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $s = trim($ohneKommentar);

    if ($s === '' || !preg_match('/^(select|with)\b/i', $s)) {
        return false;
    }
    // ein abschliessendes Semikolon ist erlaubt, ein zweites Statement nicht
    return !str_contains(rtrim($s, "; \t\n\r"), ';');
}

$sql      = trim((string)($_POST['sql'] ?? ''));
$zeilen   = [];
$spalten  = [];
$fehler   = null;
$dauerMs  = null;

if ($sql !== '') {
    if (!ist_lesend($sql)) {
        $fehler = 'Nur eine einzelne SELECT- oder WITH-Abfrage ist erlaubt.';
    } else {
        try {
            $start = microtime(true);
            $stmt  = db_ro()->query($sql);
            $zeilen = $stmt->fetchAll();
            $dauerMs = (microtime(true) - $start) * 1000;
            if ($zeilen) {
                $spalten = array_keys($zeilen[0]);
            }
        } catch (PDOException $e) {
            $fehler = $e->getMessage();
        }
    }
}

kopf('sql', 'SQL-Konsole',
     'Nur lesender Zugriff über den Benutzer mediverleih_ro, der ausschliesslich die drei Views sehen darf.');
?>

<div class="karte">
    <form method="post">
        <label for="sql">Abfrage</label>
        <textarea id="sql" name="sql" spellcheck="false" placeholder="SELECT ..."><?= h($sql) ?></textarea>
        <div style="margin-top:12px">
            <button type="submit">Ausführen</button>
        </div>
    </form>

    <div class="beispiele">
        <?php foreach (BEISPIELE as $titel => $beispiel): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="sql" value="<?= h($beispiel) ?>">
                <button type="submit"><?= h($titel) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($fehler !== null): ?>
    <div class="meldung fehler"><?= h($fehler) ?></div>
<?php endif; ?>

<?php if ($sql !== '' && $fehler === null): ?>
    <h3>Ergebnis</h3>
    <div class="karte">
        <p class="hinweis">
            <?= count($zeilen) ?> Zeile(n)<?= $dauerMs !== null ? ' in ' . number_format($dauerMs, 1) . ' ms' : '' ?>
        </p>
        <div class="tabellenrahmen">
        <table>
            <thead>
            <tr><?php foreach ($spalten as $s): ?><th><?= h($s) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php if (!$zeilen): ?>
                <tr><td class="leer">Die Abfrage hat keine Zeilen geliefert.</td></tr>
            <?php endif; ?>
            <?php foreach (array_slice($zeilen, 0, 200) as $z): ?>
                <tr><?php foreach ($z as $feld): ?>
                    <td><?= $feld === null ? '<span class="hinweis">NULL</span>' : h($feld) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($zeilen) > 200): ?>
            <p class="hinweis">Anzeige auf die ersten 200 Zeilen begrenzt.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php fuss(); ?>
