#!/usr/bin/env bash
# =====================================================================
# HydroTech - Setup Mosquitto + systemd worker (JALANKAN DENGAN SUDO)
#
#   sudo bash deploy/mosquitto/setup_mosquitto.sh
#
# Yang dilakukan (idempoten - aman diulang):
#   1. Pasang /etc/mosquitto/mosquitto.conf + ACL dari deploy/mosquitto/
#   2. Buat password file user esp32 & phpworker (dari config/mqtt_auth.local.php)
#   3. Aktifkan + jalankan service mosquitto
#   4. firewalld: buka 1883 HANYA untuk subnet lokal 192.168.1.0/24
#   5. Pasang + nyalakan systemd unit hydrotech-mqtt-subscriber
# =====================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
AUTH="$ROOT/config/mqtt_auth.local.php"

if [[ $EUID -ne 0 ]]; then echo "Jalankan dengan sudo."; exit 1; fi
if [[ ! -f "$AUTH" ]]; then echo "File $AUTH tidak ditemukan."; exit 1; fi

echo "==> 1/5 Pasang konfigurasi broker"
install -o mosquitto -g mosquitto -m 644 "$ROOT/deploy/mosquitto/mosquitto.conf" /etc/mosquitto/mosquitto.conf
install -o mosquitto -g mosquitto -m 644 "$ROOT/deploy/mosquitto/acl" /etc/mosquitto/acl

echo "==> 2/5 Buat password file (hash PBKDF2)"
ESP_PW="$(php -r 'require $argv[1]; echo $MQTT_PASS_ESP;' "$AUTH")"
PHP_PW="$(php -r 'require $argv[1]; echo $MQTT_PASS_WORKER;' "$AUTH")"
rm -f /etc/mosquitto/passwd
mosquitto_passwd -b -c /etc/mosquitto/passwd esp32 "$ESP_PW"
mosquitto_passwd -b /etc/mosquitto/passwd phpworker "$PHP_PW"
unset ESP_PW PHP_PW
chown mosquitto:mosquitto /etc/mosquitto/passwd
chmod 600 /etc/mosquitto/passwd

echo "==> 3/5 Aktifkan service mosquitto"
systemctl restart mosquitto
systemctl enable mosquitto >/dev/null
systemctl --no-pager is-active mosquitto

echo "==> 4/5 firewalld: izinkan 1883 hanya dari subnet lokal"
if systemctl is-active firewalld >/dev/null; then
  # Subnet diturunkan dari IP AKTIF saat skrip dijalankan (jaringan bisa pindah)
  IP_CUR="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')"
  SUBNET="$(echo "${IP_CUR:-192.168.1.22}" | cut -d. -f1-3).0/24"
  firewall-cmd --permanent --remove-rich-rule="rule family=\"ipv4\" source address=\"192.168.1.0/24\" port protocol=\"tcp\" port=\"1883\" accept" >/dev/null 2>&1 || true
  firewall-cmd --permanent --remove-rich-rule="rule family=\"ipv4\" source address=\"192.168.0.0/24\" port protocol=\"tcp\" port=\"1883\" accept" >/dev/null 2>&1 || true
  firewall-cmd --permanent --add-rich-rule="rule family=\"ipv4\" source address=\"$SUBNET\" port protocol=\"tcp\" port=\"1883\" accept" >/dev/null
  firewall-cmd --reload >/dev/null
  echo "    rich rule $SUBNET -> 1883/tcp dipasang"
else
  echo "    firewalld tidak aktif - lewati (pastikan router tidak meneruskan port 1883)"
fi

echo "==> 5/5 Pasang systemd worker PHP subscriber"
install -m 644 "$ROOT/deploy/systemd/hydrotech-mqtt-subscriber.service" /etc/systemd/system/hydrotech-mqtt-subscriber.service
sed -i "s|__PROJECT_ROOT__|$ROOT|g" /etc/systemd/system/hydrotech-mqtt-subscriber.service
systemctl daemon-reload
systemctl enable --now hydrotech-mqtt-subscriber

echo "==> Selesai. Status:"
systemctl --no-pager --lines=5 status mosquitto hydrotech-mqtt-subscriber | cat
