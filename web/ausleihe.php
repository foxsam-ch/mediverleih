<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

/* ---------------------------------------------------------------------
   Die Applikation fuehrt kein eigenes INSERT aus, sondern ruft die
   Stored Procedure sp_geraet_ausleihen(). Damit liegen Pruefung,
   Beleganlage und Statuswechsel in einer Transaktion in der Datenbank -
   nicht verteilt ueber mehrere PHP-Anweisungen.
   --------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('CALL sp_geraet_ausleihen(?, ?, ?, ?, ?, ?, @beleg_id)');
        $stmt->execute([
            (int)($_POST['kunde_id']      ?? 0),
            (int)($_POST['geraet_id']     ?? 0),
            (string)($_POST['von']        ?? ''),
            (string)($_POST['bis']        ?? ''),
            (int)($_POST['ausleihart_id'] ?? 0),
            trim((string)($_POST['bemerkung'] ?? '')) ?: null,
        ]);
        $stmt->closeCursor();

        $belegId = (int)$pdo->query('SELECT @beleg_id')->fetchColumn();
        $beleg   = wert('SELECT belegnummer FROM ausleihe WHERE id = ?', [$belegId]);

        $_SESSION['meldung'] = "Ausleihe erfasst. Belegnummer: $beleg";
        $_SESSION['art']     = 'ok';
    } catch (PDOException $e) {
        // Fachliche Meldungen aus SIGNAL bzw. dem UNIQUE-Index
        $_SESSION['meldung'] = 'Abgewiesen: ' . fehlertext($e);
        $_SESSION['art']     = 'fehler';
    }

    // Weiterleitung, damit ein Neuladen die Ausleihe nicht wiederholt
    header('Location: ausleihe.php');
    exit;
}

$meldung = $_SESSION['meldung'] ?? null;
$art     = $_SESSION['art']     ?? 'ok';
unset($_SESSION['meldung'], $_SESSION['art']);

$kunden = abfrage(
    'SELECT kunde_id, name_voll, institution, kundentyp, ort
       FROM v_kunde ORDER BY institution IS NULL, institution, nachname'
);

/* Bewusst ALLE Geraete anbieten, auch nicht verfuegbare. Die Oberflaeche
   entscheidet nicht, ob eine Ausleihe zulaessig ist - das entscheidet
   die Datenbank. Wer ein gesperrtes Geraet waehlt, bekommt die
   Fehlermeldung aus dem Trigger zu sehen. */
$geraete = abfrage(
    "SELECT geraet_id, inventarnummer, hersteller, typ, status
       FROM v_geraete_uebersicht
      ORDER BY status = 'verfuegbar' DESC, inventarnummer"
);

$arten = abfrage('SELECT id, bezeichnung, beschreibung FROM ausleihart ORDER BY bezeichnung');

$heute  = (new DateTime())->format('Y-m-d');
$inZwei = (new DateTime('+14 days'))->format('Y-m-d');

kopf('ausleihe', 'Ausleihe erfassen',
     'Das Formular ruft die Stored Procedure auf. Prüfung, Beleg und Statuswechsel passieren in der Datenbank.');

meldung($meldung, $art);
?>

<div class="karte">
    <form method="post">
        <div class="feldgruppe">
            <div>
                <label for="kunde_id">Empfänger</label>
                <select id="kunde_id" name="kunde_id" required style="width:100%">
                    <?php foreach ($kunden as $k): ?>
                        <option value="<?= (int)$k['kunde_id'] ?>">
                            <?= h($k['name_voll']) ?>
                            &mdash; <?= h($k['institution'] ?? 'Privat, ' . $k['ort']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="geraet_id">Gerät</label>
                <select id="geraet_id" name="geraet_id" required style="width:100%">
                    <optgroup label="Verfügbar">
                    <?php foreach ($geraete as $g): if ($g['status'] !== 'verfuegbar') continue; ?>
                        <option value="<?= (int)$g['geraet_id'] ?>">
                            <?= h($g['inventarnummer']) ?> &middot; <?= h($g['hersteller']) ?> <?= h($g['typ']) ?>
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Nicht verfügbar – Ausleihe wird von der Datenbank abgewiesen">
                    <?php foreach ($geraete as $g): if ($g['status'] === 'verfuegbar') continue; ?>
                        <option value="<?= (int)$g['geraet_id'] ?>">
                            <?= h($g['inventarnummer']) ?> &middot; <?= h($g['hersteller']) ?> <?= h($g['typ']) ?>
                            [<?= h(statustext((string)$g['status'])) ?>]
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div>
                <label for="ausleihart_id">Ausleihart</label>
                <select id="ausleihart_id" name="ausleihart_id" required style="width:100%">
                    <?php foreach ($arten as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" title="<?= h($a['beschreibung'] ?? '') ?>">
                            <?= h($a['bezeichnung']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="von">Ausgabe</label>
                <input type="date" id="von" name="von" value="<?= h($heute) ?>" required style="width:100%">
            </div>

            <div>
                <label for="bis">Rückgabe geplant</label>
                <input type="date" id="bis" name="bis" value="<?= h($inZwei) ?>" required style="width:100%">
            </div>

            <div style="grid-column:1/-1">
                <label for="bemerkung">Bemerkung <span style="text-transform:none">(Freitext zum Einzelfall)</span></label>
                <input type="text" id="bemerkung" name="bemerkung" maxlength="255"
                       placeholder="z. B. Revision der eigenen Beatmungsgeräte auf der IPS" style="width:100%">
            </div>
        </div>

        <button type="submit">Ausleihe erfassen</button>
    </form>
</div>

<h3>Warum die gesperrten Geräte trotzdem in der Liste stehen</h3>
<div class="karte">
    <p class="hinweis" style="margin:0">
        Die Oberfläche filtert sie bewusst nicht heraus. Wähle ein Gerät aus der
        unteren Gruppe und die Datenbank weist die Ausleihe ab &ndash; mit der
        Meldung aus dem Trigger. Dieselbe Sperre greift auch in phpMyAdmin und
        bei jedem direkten SQL-Zugriff.
    </p>
</div>

<?php fuss(); ?>
