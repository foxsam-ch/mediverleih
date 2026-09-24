<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

/* Filter aus der URL. Jeder Wert geht als gebundener Parameter in die
   Abfrage - der SQL-Text selbst wird nie aus Benutzereingaben gebaut. */

$suche      = trim((string)($_GET['suche']      ?? ''));
$hersteller = trim((string)($_GET['hersteller'] ?? ''));
$status     = trim((string)($_GET['status']     ?? ''));

$bedingungen = [];
$parameter   = [];

if ($suche !== '') {
    $bedingungen[] = '(inventarnummer LIKE :s OR seriennummer LIKE :s OR typ LIKE :s)';
    $parameter[':s'] = '%' . $suche . '%';
}
if ($hersteller !== '') {
    $bedingungen[] = 'hersteller = :h';
    $parameter[':h'] = $hersteller;
}
if ($status !== '') {
    $bedingungen[] = 'status = :st';
    $parameter[':st'] = $status;
}

$wo = $bedingungen ? 'WHERE ' . implode(' AND ', $bedingungen) : '';

$geraete = abfrage(
    "SELECT * FROM v_geraete_uebersicht $wo
      ORDER BY inventarnummer",
    $parameter
);

$herstellerListe = abfrage('SELECT DISTINCT hersteller FROM v_geraete_uebersicht ORDER BY hersteller');
$statusListe     = ['verfuegbar', 'verliehen', 'ausgemustert'];

kopf('geraete', 'Geräteinventar',
     'Jedes physische Gerät mit eigener Seriennummer. Hersteller und Bezeichnung stehen am Gerätetyp, nicht am Einzelstück.');
?>

<div class="karte">
    <form class="filter" method="get">
        <div>
            <label for="suche">Suche</label>
            <input type="text" id="suche" name="suche" value="<?= h($suche) ?>"
                   placeholder="Inventar-Nr., Serien-Nr. oder Typ">
        </div>
        <div>
            <label for="hersteller">Hersteller</label>
            <select id="hersteller" name="hersteller">
                <option value="">alle</option>
                <?php foreach ($herstellerListe as $z): ?>
                    <option value="<?= h($z['hersteller']) ?>"<?= $hersteller === $z['hersteller'] ? ' selected' : '' ?>>
                        <?= h($z['hersteller']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">alle</option>
                <?php foreach ($statusListe as $s): ?>
                    <option value="<?= h($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= h(statustext($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit">Filtern</button>
            <a href="geraete.php"><button type="button" class="still">Zurücksetzen</button></a>
        </div>
    </form>
</div>

<div class="karte">
    <p class="hinweis"><?= count($geraete) ?> Gerät(e)</p>
    <div class="tabellenrahmen">
    <table>
        <thead>
        <tr>
            <th>Inventar-Nr.</th><th>Hersteller</th><th>Typ</th><th>Modell</th>
            <th>Seriennummer</th><th>Status</th><th>Notiz</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$geraete): ?>
            <tr><td colspan="7" class="leer">Kein Gerät entspricht dem Filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($geraete as $g): ?>
            <tr>
                <td class="nowrap"><?= h($g['inventarnummer']) ?></td>
                <td><?= h($g['hersteller']) ?></td>
                <td><?= h($g['typ']) ?></td>
                <td class="nowrap"><?= h($g['modellnummer']) ?></td>
                <td class="nowrap"><?= h($g['seriennummer']) ?></td>
                <td><?= marke($g['status']) ?></td>
                <td><?= h($g['notiz'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php fuss(); ?>
