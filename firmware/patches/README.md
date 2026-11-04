# PATCH OPSIONAL — Ekstensi backend untuk kebutuhan pompa ESP32

> **Status: PROPOSAL — BELUM diterapkan ke backend.** Semua patch
> berada terpisah di folder ini; file asli (`api/sensor/ingest.php`,
> `includes/*`, tabel database) TIDAK diubah oleh dokumen ini.
> Firmware bekerja normal tanpa patch: parameter memakai default aman
> internal, pelaporan eksekusi otomatis mati bila endpoint 404.

## Ringkasan keterkaitan spesifikasi ↔ patch

| Ketentuan spesifikasi | Penutupnya |
|---|---|
| Ambang PPM/pH & jadwal Dithane diatur via website | SUDAH ADA: `settings`, `dithane_schedules`, dikirim `GET /api/sensor/ingest.php` |
| Notifikasi 3 kategori (rendah/**normal**/tinggi) hanya saat perubahan kategori + isi lengkap (jenis, nilai, kategori, waktu) | PATCH C v2: `includes_sensors_notif_spam.md` |
| Notifikasi Dithane mulai & selesai | PATCH A: `api/device/report.php` v2 |
| Interval Dithane dari waktu selesai terakhir, tetap akurat pasca-restart | PATCH A (laporan `completed`) + PATCH B-2 (`dithane.last_completed_at` dari DB) |
| Parameter pompa (dosis, cooldown, armed, sirkulasi) via website | PATCH B: blok `device` dari `config/esp_device.json` |
| Pompa otomatis tanpa aktivasi manual | firmware otomatis penuh; `armed` (interlock kimia) bisa di-set 1 dari website via PATCH B |

## PATCH A — `api/device/report.php` (laporan eksekusi Dithane)

> **Status: SUDAH DITERAPKAN ke backend.** Endpoint resmi kini ada di
> `api/device/report.php` (logika di `includes/device_report.php`). Cadangan
> di folder ini tidak lagi perlu dipasang — jangan dipakai bersamaan dengan
> endpoint resmi agar tidak mecataat ganda.

Aturan pencatatan aktivitas Dithane yang berlaku (Data Aktivitas Sistem):

* Laporan `started` → SATU catatan `monitoring_logs` per pompa MULAI menyala
  (status "Menyala") + satu notifikasi web.
* Laporan `completed` → hanya notifikasi web (tanpa catatan kedua).
* Tidak ada catatan berkala selama pompa tetap menyala; tidak ada catatan
  dari pembacaan sensor atau pengecekan jadwal.
* Dedup: laporan ulang < 60 detik diabaikan server; firmware tidak me-retry.

## PATCH B — Parameter pompa + anchor waktu via website (blok `device`)

1. Tambahkan baris berikut dalam blok GET `api/sensor/ingest.php`
   (setelah bagian `$schedule = readDithane($pdo); ...`):

```php
        /* PATCH B-1 (opsional): parameter pompa perangkat dari file
           config/esp_device.json (bila file tidak ada, firmware memakai
           default aman internal).                                     */
        $deviceFile = __DIR__ . '/../../config/esp_device.json';
        if (is_readable($deviceFile)) {
            $deviceCfg = json_decode((string)file_get_contents($deviceFile), true);
            if (is_array($deviceCfg)) $config['device'] = $deviceCfg;
        }

        /* PATCH B-2 (opsional): waktu penyemprotan SELESAI terakhir dari
           monitoring_logs — anchor interval "sejak selesai terakhir"
           tetap akurat meski ESP restart / NVS hilang.                */
        $lastDone = $pdo->query(
            "SELECT MAX(created_at) FROM monitoring_logs WHERE type = 'dithane'"
        )->fetchColumn();
        if ($schedule && $lastDone) {
            $config['dithane']['last_completed_at'] = $lastDone;
        }
```

Firmware membaca `dithane.last_completed_at` (format `Y-m-d H:i:s`
WIB) dan mengadopsinya bila lebih baru dari NVS lokal. Kombinasi ini
sengaja: NVS = tulis cepat dan lokal; DB = sumber kebenaran pasca
listrik mati lama / NVS terhapus.

2. Buat `config/esp_device.json` (contoh awal, semua aman):

```json
{
  "armed": 0,
  "circulation": 0,
  "circ_on_sec": 300,
  "circ_period_sec": 5400,
  "dose_ab_ms": 3000,
  "dose_ph_ms": 2000,
  "mix_wait_sec": 45,
  "cooldown_sec": 120,
  "max_events_day": 24
}
```

Keterangan:

| Kunci | Makna | Perlakuan firmware |
|---|---|---|
| `armed` | interlock Dithane | `1` = armed (dari website), `0` = disarmed; menimpa sakelar Serial |
| `circulation` | 0/1 izin sirkulasi periodik | default OFF |
| `circ_on_sec`, `circ_period_sec` | fase nyala / periode | firmware menjamin fase OFF ≥ 60 s |
| `dose_ab_ms`, `dose_ph_ms` | durasi dosis | tetap dibatasi cap harian firmware |
| `mix_wait_sec`, `cooldown_sec` | interval antar dosing | — |
| `max_events_day` | cap event harian per pompa | cap runtime firmware tetap berlaku |

> Catatan jujur: metode ini belum punya halaman UI di dashboard —
> admin mengubah file JSON di server (atau buat halaman pengaturan
> kecil kemudian). Alternatif menambah nilai ENUM `settings.device`
> memerlukan `ALTER TABLE` sehingga tidak dipilih.

## PATCH C v2 — Notifikasi 3 kategori berbasis perubahan state

Lihat `firmware/patches/includes_sensors_notif_spam.md`: dua perubahan
dalam `includes/sensors.php` → saat kategori sensor berubah
(`Rendah` / `Normal` / `Tinggi`), dikirim satu notifikasi berisi
**jenis sensor, nilai terbaru, kategori, dan waktu WIB** — termasuk
kategori "normal". Kategori sama berulang (mis. ESP restart) tidak
diciptakan ulang; state persis di `monitoring_logs` (kebal restart).

## Yang tidak disentuh

* Tidak ada `ALTER TABLE`, tidak ada tabel baru.
* Tidak ada perubahan `api/auth/*`, `api/settings/*`, `api/sensor/*`
  (kecuali 3 baris opsional PATCH B), skema database, atau seed.
* Tidak ada kredensial di file patch; API key tetap di
  `config/esp.php` / `config/esp_key.txt` via `requireEspKey()`.
