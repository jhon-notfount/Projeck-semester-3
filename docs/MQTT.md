# HydroTech — Dokumentasi MQTT (Eclipse Mosquitto)

Transport utama ESP32 ↔ backend setelah migrasi dari HTTP REST.
Broker berjalan di Fedora (hostname mDNS `coba-coba.local:1883`), **hanya
jaringan lokal** (firewalld membatasi akses ke `192.168.1.0/24`,
autentikasi wajib, ACL per user).

**Perubahan IP laptop tidak menjadi masalah**: PHP memakai `127.0.0.1`
(broker di mesin yang sama), dan ESP32 menanyakan IP broker lewat mDNS
(`MDNS.queryHost`, cache 5 menit) — tanpa konfigurasi ulang.

## Autentikasi & ACL

| User | Dipakai | Hak |
|---|---|---|
| `esp32` | firmware ESP32 | readwrite `hydrotech/sensor/#`, `hydrotech/pump/status`, `hydrotech/device/status`; read `hydrotech/cmd/esp32/#` |
| `phpworker` | PHP subscriber/publisher | readwrite `hydrotech/#` |

Password acak tersimpan di `config/mqtt_auth.local.php` (di-gitignore).

## Topik & Payload JSON

Semua payload berisi `ts` (epoch detik dari NTP; 0 = jam belum valid —
waktu penyimpanan database tetap ditetapkan server, WIB).

### 1. `hydrotech/sensor/ph` — QoS 0, tiap 2 detik, tanpa retained
```json
{"value": 6.52, "valid": true, "ts": 1700000000}
```
`valid:false` = pembacaan di luar rentang valid / sensor lepas (nilai 0).

### 2. `hydrotech/sensor/ppm` — QoS 0, tiap 2 detik, tanpa retained
```json
{"value": 850, "valid": true, "ts": 1700000000}
```
PPM selalu bilangan bulat. PHP subscriber memasangkan pH+PPM dengan `ts`
yang sama menjadi **satu baris** `sensor_readings` (aturan lama dipertahankan:
monitoring_logs + notifikasi tetap berjalan lewat `saveSensorMeasurements()`).

### 3. `hydrotech/pump/status` — QoS 1, saat berubah (retained)
```json
{"pump": "ab", "state": "on", "mode": "manual", "remaining_s": 27, "ts": 1700000000}
```
`pump` ∈ `ab | ph | dithane | circ`; `mode` ∈ `auto | manual`.
Dashboard membaca status ini (via `pump_states`) agar tombol mencerminkan
kondisi pompa sebenarnya. Retained diizinkan karena ini **status**, bukan perintah.

### 4. `hydrotech/device/status` — QoS 1, tiap 30 detik (retained) + LWT
```json
{"type": "status", "online": true, "ip": "192.168.1.50", "rssi": -52, "ts": 1700000000}
```
Last-Will (dipublikasikan broker saat ESP hilang tanpa disconnect):
```json
{"type": "status", "online": false, "reason": "lwt", "ts": 1700000000}
```
Ack perintah (tanpa retained):
```json
{"type": "ack", "msg_id": "ph-20261117-095500-ab12cd", "topic": "hydrotech/cmd/esp32/ph", "ok": true, "note": "konfigurasi diterapkan", "ts": 1700000000}
```
Laporan eksekusi pompa (pengganti HTTP `api/device/report.php`; worker PHP
menulis `monitoring_logs` + notifikasi, dedup < 60 detik):
```json
{"type": "pump_activity", "pump": "dithane", "state": "started", "duration_s": 0, "ts": 1700000000}
{"type": "pump_activity", "pump": "dithane", "state": "completed", "duration_s": 30, "ts": 1700000005}
```
Permintaan konfigurasi (dipublikasikan ESP saat connect & tiap 15 menit;
worker PHP menjawab dengan dorongan `cmd/esp32/ph|ppm|dithane` lengkap
dengan `last_completed_at`):
```json
{"type": "config_request", "ts": 1700000000}
```

### 5. `hydrotech/cmd/esp32/ph` — QoS 1, TANPA retained (perintah)
Dikirim PHP saat admin menyimpan Pengaturan pH:
```json
{"msg_id": "ph-20261117-095500-ab12cd", "min": "5,5", "max": "6,5"}
```

### 6. `hydrotech/cmd/esp32/ppm` — QoS 1, TANPA retained
```json
{"msg_id": "ppm-20261117-095500-ab12cd", "min": "800", "max": "1000"}
```

### 7. `hydrotech/cmd/esp32/dithane` — QoS 1, TANPA retained
```json
{"msg_id": "dithane-20261117-100000-cd34ef", "interval": "48", "duration": "30", "start": "08.00", "last_completed_at": "2026-11-17 08:00:30"}
```
`start` memakai pemisah `.` atau `:` (HH.MM); `interval` jam; `duration` detik.
`last_completed_at` opsional (WIB) — diadopsi bila lebih baru dari NVS.

### 8. `hydrotech/cmd/esp32/pump` — QoS 1, TANPA retained (perintah pompa)
```json
{"msg_id": "httpcmd-3", "pump": "ab", "action": "on", "duration_s": 30, "issued_by": "dashboard"}
```
`pump` ∈ `ab | ph | dithane`. Override manual **berdurasi**; habis durasi
ESP32 kembali ke otomatisasi (timer lokal — aman meski broker mati).

## Aturan Keselamatan & Reliabilitas

- **Dedup**: setiap perintah membawa `msg_id` unik; ESP32 menyimpan cache
  32 `msg_id` terakhir (FNV-1a) — perintah duplikat **tidak pernah dieksekusi
  dua kali**, termasuk saat worker mengirim ulang.
- **Validasi**: ambang pH 0–14, PPM 0–5000, `min ≤ max`; jadwal Dithane
  diverifikasi sama seperti jalur HTTP; payload tak valid di-`ack` `ok:false`.
- **Prioritas kontrol** di ESP32:
  `failsafe sensor > interlock Dithane > manual (berdurasi) > otomatisasi lokal`.
- **Retained**: hanya untuk topik **status** (`pump/status`, `device/status`).
  Perintah (`cmd/*`) **tidak pernah retained** — ESP32 yang baru hidup tidak
  mengeksekusi perintah lama.
- **Reconnect**: ESP32 mencoba ulang tiap 5 s; worker PHP backoff 2 s → 60 s.
- **MQTT = satu-satunya transport** (jalur HTTP firmware sudah dihapus).
  Saat broker/Wi-Fi terputus, otomatisasi tetap berjalan 100% lokal: ambang
  & jadwal terakhir tetap berlaku di RAM ESP32, override manual berakhir
  tepat waktu dengan timer lokal, dan mode aman hanya dipicu **sensor
  invalid beruntun** (bukan kehilangan koneksi). Konfigurasi berumur 2 jam
  (`MAX_CONFIG_AGE_MS`): tanpa broker lebih lama itu, dosing berhenti
  secara aman sampai koneksi pulih.
- **LWT**: ESP hilang mendadak → broker menerbitkan `online:false` otomatis.

## Komponen

| File | Peran |
|---|---|
| `deploy/mosquitto/mosquitto.conf` | konfigurasi broker (auth + ACL + persistence) |
| `deploy/mosquitto/acl` | aturan topik per user |
| `deploy/mosquitto/setup_mosquitto.sh` | instalasi sekali jalan (sudo) |
| `vendor/hydrotech/MqttClient.php` | klien MQTT 3.1.1 minimal untuk PHP |
| `bin/mqtt_subscriber.php` | worker: sensor → MySQL, relay antrean perintah, terima ack (systemd: `hydrotech-mqtt-subscriber`) |
| `bin/mqtt_publisher.php` | CLI publish manual (untuk uji/debug) |

## Uji cepat (setelah setup)

```bash
# lihat stream sensor realtime
mosquitto_sub -h 192.168.1.22 -u phpworker -P '<pass>' -t 'hydrotech/sensor/#' -v
# kirim perintah uji
mosquitto_pub -h 192.168.1.22 -u phpworker -P '<pass>' -t hydrotech/cmd/esp32/pump \
  -m '{"msg_id":"tes1","pump":"ab","action":"on","duration_s":10}' -q 1
```
