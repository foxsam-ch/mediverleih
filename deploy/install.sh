#!/usr/bin/env bash
# =====================================================================
#  MediVerleih - Setup auf Ubuntu Server
#
#  Installiert MariaDB, Apache2, PHP und phpMyAdmin, legt die Datenbank
#  an, spielt Schema, Logik, Testdaten und Rechte ein und veroeffentlicht
#  die PHP-Applikation.
#
#  Aufruf (aus dem Projektverzeichnis heraus):
#      sudo bash deploy/install.sh
#
#  Das Skript ist wiederholt ausfuehrbar. Achtung: 01_schema.sql
#  loescht die Datenbank und legt sie neu an.
# =====================================================================

set -euo pipefail

# --------------------------------------------------- Vorbedingungen
if [[ $EUID -ne 0 ]]; then
    echo "Bitte mit sudo starten:  sudo bash deploy/install.sh" >&2
    exit 1
fi

PROJEKT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SQL_DIR="$PROJEKT_DIR/sql"
WEB_QUELLE="$PROJEKT_DIR/web"
WEB_ZIEL="/var/www/mediverleih"
CRED_DATEI="/root/mediverleih-zugangsdaten.txt"

for f in 01_schema.sql 02_logik.sql 03_testdaten.sql 04_rechte.sql; do
    [[ -f "$SQL_DIR/$f" ]] || { echo "FEHLER: $SQL_DIR/$f fehlt." >&2; exit 1; }
done

schritt() { echo; echo "=== $* ==="; }

export DEBIAN_FRONTEND=noninteractive
# verhindert, dass needrestart mitten im Lauf nach Dienst-Neustarts fragt
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1


# --------------------------------------------------- 1) Pakete
schritt "1/7  Paketquellen aktualisieren"
apt-get update -qq
apt-get upgrade -y -qq

schritt "2/7  MariaDB, Apache, PHP installieren"
apt-get install -y -qq \
    mariadb-server mariadb-client \
    apache2 libapache2-mod-php \
    php php-mysql php-mbstring php-zip php-gd php-curl php-xml \
    pwgen

systemctl enable --now mariadb
systemctl enable --now apache2


# --------------------------------------------------- 2) Passwoerter
schritt "3/7  Zugangsdaten erzeugen"
ADMIN_PW="$(pwgen -s 20 1)"
APP_PW="$(pwgen -s 20 1)"
RO_PW="$(pwgen -s 20 1)"
PMA_PW="$(pwgen -s 20 1)"


# --------------------------------------------------- 3) MariaDB absichern
# Ubuntu laesst root ueber unix_socket zu; anonyme Benutzer und die
# Testdatenbank raeumen wir weg (entspricht mysql_secure_installation).
schritt "4/7  MariaDB absichern"
mariadb <<'SQL'
DELETE FROM mysql.global_priv WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
SQL


# --------------------------------------------------- 4) Datenbank einspielen
schritt "5/7  Datenbank anlegen und befuellen"
mariadb < "$SQL_DIR/01_schema.sql"
echo "  - Schema eingespielt"
mariadb < "$SQL_DIR/02_logik.sql"
echo "  - Views, Trigger, Procedures eingespielt"
mariadb < "$SQL_DIR/03_testdaten.sql"
echo "  - Testdaten eingespielt"

# Platzhalter durch die erzeugten Passwoerter ersetzen.
# Die temporaere Datei liegt nur kurz und nur fuer root lesbar herum.
RECHTE_TMP="$(mktemp)"
chmod 600 "$RECHTE_TMP"
sed -e "s|__ADMIN_PW__|$ADMIN_PW|" \
    -e "s|__APP_PW__|$APP_PW|" \
    -e "s|__RO_PW__|$RO_PW|" \
    "$SQL_DIR/04_rechte.sql" > "$RECHTE_TMP"
mariadb < "$RECHTE_TMP"
rm -f "$RECHTE_TMP"
echo "  - Benutzer und Rechte gesetzt"


# --------------------------------------------------- 5) phpMyAdmin
schritt "6/7  phpMyAdmin installieren"
debconf-set-selections <<EOF
phpmyadmin phpmyadmin/dbconfig-install boolean true
phpmyadmin phpmyadmin/mysql/app-pass password $PMA_PW
phpmyadmin phpmyadmin/app-password-confirm password $PMA_PW
phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2
EOF

if apt-get install -y -qq phpmyadmin; then
    PMA_STATUS="ueber apt installiert -> http://SERVER/phpmyadmin"
else
    PMA_STATUS="FEHLGESCHLAGEN - apt-Paket nicht verfuegbar, manuell nachinstallieren"
    echo "WARNUNG: phpMyAdmin liess sich nicht per apt installieren." >&2
fi


# --------------------------------------------------- 6) Webapplikation
schritt "7/7  Webapplikation veroeffentlichen"
mkdir -p "$WEB_ZIEL"

if [[ -d "$WEB_QUELLE" ]] && [[ -n "$(ls -A "$WEB_QUELLE" 2>/dev/null)" ]]; then
    cp -r "$WEB_QUELLE"/. "$WEB_ZIEL"/
    echo "  - Applikation nach $WEB_ZIEL kopiert"
else
    echo "  - Hinweis: $WEB_QUELLE ist leer, nur Konfiguration wird angelegt"
fi

# Zugangsdaten fuer die Applikation. Ausserhalb des DocumentRoot waere
# noch sauberer; fuer die Modularbeit reicht die Dateiberechtigung.
cat > "$WEB_ZIEL/config.php" <<PHPCONF
<?php
// Automatisch erzeugt von deploy/install.sh - nicht ins Repository geben.
return [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=mediverleih;charset=utf8mb4',
    'benutzer' => 'mediverleih_app',
    'passwort' => '$APP_PW',
    // Nur-Lese-Zugang fuer die SQL-Konsole in der Praesentation
    'ro_benutzer' => 'mediverleih_ro',
    'ro_passwort' => '$RO_PW',
];
PHPCONF

chown -R www-data:www-data "$WEB_ZIEL"
chmod 640 "$WEB_ZIEL/config.php"

cat > /etc/apache2/sites-available/mediverleih.conf <<'VHOST'
<VirtualHost *:80>
    ServerName mediverleih.local
    DocumentRoot /var/www/mediverleih

    <Directory /var/www/mediverleih>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/mediverleih-error.log
    CustomLog ${APACHE_LOG_DIR}/mediverleih-access.log combined
</VirtualHost>
VHOST

a2ensite mediverleih.conf   >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest
systemctl reload apache2


# --------------------------------------------------- 7) Zusammenfassung
IP="$(hostname -I | awk '{print $1}')"

cat > "$CRED_DATEI" <<CRED
MediVerleih - Zugangsdaten (erzeugt am $(date '+%Y-%m-%d %H:%M'))

phpMyAdmin   http://$IP/phpmyadmin
  Benutzer   mediverleih_admin
  Passwort   $ADMIN_PW

Applikation  http://$IP/
  DB-Benutzer  mediverleih_app   $APP_PW
  Nur-Lesen    mediverleih_ro    $RO_PW

phpMyAdmin-Servicekonto (nicht fuer die Anmeldung)
  phpmyadmin   $PMA_PW

MariaDB-root: Anmeldung nur lokal ueber "sudo mariadb" (unix_socket).
CRED
chmod 600 "$CRED_DATEI"

echo
echo "======================================================"
echo " Fertig."
echo
echo " Applikation   http://$IP/"
echo " phpMyAdmin    $PMA_STATUS"
echo
echo " Zugangsdaten  $CRED_DATEI   (nur fuer root lesbar)"
echo "               sudo cat $CRED_DATEI"
echo "======================================================"

schritt "Kontrolle: Inhalt der Datenbank"
mariadb -t mediverleih <<'SQL'
SELECT status, COUNT(*) AS geraete FROM geraet GROUP BY status;
SELECT COUNT(*) AS offene_ausleihen FROM v_ausleihen_offen;
SQL
