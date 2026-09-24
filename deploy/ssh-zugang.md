# SSH-Zugang zum Ubuntu-Server einrichten

**Zielserver:** `192.168.1.107` (Ubuntu Server auf Hyper-V, im LAN)

Anmeldung per Schlüsselpaar statt Passwort.

- **Privater Schlüssel**: `C:\Users\Samuel Fuchs\.ssh\mediverleih` – bleibt lokal, wird nie kopiert
- **Öffentlicher Schlüssel**: `C:\Users\Samuel Fuchs\.ssh\mediverleih.pub` – kommt auf den Server
- **Key-Fingerprint**: `SHA256:MAkuZ749zjkOiZnH9NzIF1gUaN46E4781FMXzVtJ22U`

Der Schlüssel ist bewusst ohne Passphrase, damit die Verbindung ohne Rückfrage
funktioniert. Deshalb ist er **projektspezifisch**: nach Abschluss der Modularbeit
eine Zeile aus `authorized_keys` löschen und der Zugang ist weg.

---

## Schritt 0 – Server verifizieren

Damit sicher ist, dass wir mit der richtigen VM reden. Auf der **Hyper-V-Konsole**
der VM:

```bash
ip -4 addr show | grep inet
```

Erwartung: `192.168.1.107`

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

Erwartung: `SHA256:Lz9Ugz2scZmO8xulFJPdx/wV8dpGLKmTNvkTid3I0Sk`

---

## Schritt 1 – Benutzer

Ein normaler Benutzer mit sudo, nicht root. Falls noch keiner existiert,
auf dem Server:

```bash
sudo adduser samuel
```

```bash
sudo usermod -aG sudo samuel
```

---

## Schritt 2 – Öffentlichen Schlüssel hinterlegen

### Variante A: Passwort-Zugang per SSH vorhanden

Auf **deinem Windows-Rechner** (Git Bash), `BENUTZER` ersetzen:

```bash
ssh-copy-id -i ~/.ssh/mediverleih.pub BENUTZER@192.168.1.107
```

### Variante B: nur Hyper-V-Konsole

Auf dem **Server**:

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh && nano ~/.ssh/authorized_keys
```

Diese Zeile einfügen (exakt, ohne Zeilenumbruch):

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKRQ15WiQDfAdr4P6Tb+uq8DS5L8VFUehqVF3WOi+PQa mediverleih-modularbeit-20260904
```

Speichern, dann:

```bash
chmod 600 ~/.ssh/authorized_keys
```

---

## Schritt 3 – Verbindung testen

```bash
ssh -i ~/.ssh/mediverleih BENUTZER@192.168.1.107
```

---

## Schritt 4 – SSH-Config

Datei `C:\Users\Samuel Fuchs\.ssh\config` ergänzen:

```
Host mediverleih
    HostName 192.168.1.107
    User BENUTZER
    IdentityFile ~/.ssh/mediverleih
    IdentitiesOnly yes
    ServerAliveInterval 60
```

Danach genügt:

```bash
ssh mediverleih
```

---

## Schritt 5 – Absichern (erst wenn Schritt 3 funktioniert!)

**Vorher** prüfen, dass die Schlüsselanmeldung läuft – sonst sperrst du dich aus.
Am besten eine zweite SSH-Sitzung offen lassen, während du das machst.

Auf dem Server:

```bash
sudo nano /etc/ssh/sshd_config.d/99-mediverleih.conf
```

Inhalt:

```
PasswordAuthentication no
PermitRootLogin no
```

Übernehmen:

```bash
sudo systemctl restart ssh
```

---

## Zugang später wieder entziehen

Auf dem Server:

```bash
sed -i '/mediverleih-modularbeit/d' ~/.ssh/authorized_keys
```

Lokal aufräumen:

```bash
rm ~/.ssh/mediverleih ~/.ssh/mediverleih.pub
```

---

## Hinweis zur Sicherheit

Mit diesem Zugang habe ich eine Shell auf dem Server und kann dort mit `sudo`
Software installieren. Das ist für die Aufgabe nötig (MariaDB, Apache, PHP,
phpMyAdmin), heisst aber: **nur auf einem Server einsetzen, der ausschliesslich
für diese Modularbeit da ist.** Keine produktiven Daten, keine anderen Dienste.
