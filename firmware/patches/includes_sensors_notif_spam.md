# PATCH C v2 — NOTIFIKASI 3 KATEGORI (RENDAH / NORMAL / TINGGI) — `includes/sensors.php`

> **Status: PROPOSAL — file asli tidak diubah otomatis.** Terapkan
> manual, lalu jalankan `php tests/measurement_integration.php` dan
> `php tests/column_names_integration.php`.
>
> Versi ini **MENGGANTIKAN PATCH C v1** (anti-spam abnormal-only).
> Sesuai spesifikasi terbaru: notifikasi dikirim untuk SEMUA kategori
> (rendah, normal, **dan** tinggi) saat terjadi **perubahan kategori**,
> bukan hanya rentang abnormal.

## Ketentuan yang dilayani

* Setiap sensor pH/PPM punya 3 kategori kondisi: **Rendah** (`< min`),
  **Normal** (`min..max`), **Tinggi** (`> max`).
* Notifikasi HANYA saat kategori berubah (state-change):
  Rendah → Normal, Normal → Tinggi, Tinggi → Normal, Normal → Rendah, dll.
* Isi notifikasi wajib: **jenis sensor**, **nilai terbaru**, **kategori**,
  **waktu perubahan** (WIB, jam server).
* Kategori terakhir sudah tersimpan di `monitoring_logs.status` (per
  pembacaan) sehingga deteksi state **kebal restart ESP** — pembacaan
  berulang dengan kategori sama tidak mengirim ulang apa pun.
* Batas maksimum hanya peringatan (pompa tidak menyala) — logika pompa
  ada di firmware; patch ini murni lapisan notifikasi.

## Perubahan (2 titik) di `saveSensorMeasurements()`

### Perubahan 1 — ambil kategori SEBELUMNYA per jenis

Tepat **sebelum** baris
`$timestamp = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();`
(MUSTI sebelum baris log saat ini di-INSERT, agar baris pembanding
adalah pembacaan SEBELUMNYA), sisipkan:

```php
        // PATCH C v2 (mulai): kategori kondisi TERAKHIR per type dari
        // monitoring_logs — sumber state yang kebal restart perangkat.
        $prevStatusQ = $pdo->prepare(
            "SELECT status FROM monitoring_logs WHERE type = ? "
            . "ORDER BY monitoring_id DESC LIMIT 1"
        );
        $prevStatusPerType = [];
        foreach ($values as $type => $_v) {
            $prevStatusQ->execute([$type]);
            $prevStatusPerType[$type] = $prevStatusQ->fetchColumn() ?: null;
        }
        // PATCH C v2 (selesai)
```

### Perubahan 2 — kirim notifikasi hanya saat status berubah

Ganti baris (yang ada SETELAH `$log->execute([...])`, log tetap ditulis
untuk setiap pembacaan — tidak diubah):

```php
            sensorWarning($pdo, $reading, $limits);
```

menjadi:

```php
            // PATCH C v2: notifikasi hanya pada perubahan kategori
            // (Rendah/Normal/Tinggi). Isi wajib: jenis sensor, nilai
            // terbaru, kategori, waktu perubahan (WIB, jam server).
            if ($status !== $prevStatusPerType[$type]) {
                $sensorName = $type === 'ph' ? 'pH' : 'PPM';
                $notifLabel = $sensorName . ' ' . strtolower($status);
                $notifTone  = $status === 'Tinggi' ? 'warning'
                            : ($status === 'Rendah' ? 'info' : 'success');
                $notifMsg = sprintf(
                    '%s: nilai %.2f — kategori %s pada %s WIB%s',
                    'Kondisi ' . $sensorName,
                    (float)$value,
                    $status,
                    $clock->format('d-m-Y H:i'),
                    $prevStatusPerType[$type] !== null
                        ? ' (perubahan dari ' . $prevStatusPerType[$type] . ')'
                        : ' (pembacaan pertama)'
                );
                $notif = $pdo->prepare(
                    "INSERT INTO notifications (label, message, tone, href)
                     VALUES (?, ?, ?, ?)"
                );
                $notif->execute([$notifLabel, $notifMsg, $notifTone,
                                 "pengaturan-$type.php"]);
            }
```

## Contoh hasil notifikasi (menus custom, dibaca dashboard)

| Kejadian | label | message | tone |
|---|---|---|---|
| pH 5,82 (Normal → Rendah) | `pH rendah` | `Kondisi pH: nilai 5.82 — kategori Rendah pada 01-11-2026 14:32 WIB (perubahan dari Normal)` | info |
| pH 6,45 (Rendah → Normal) | `pH normal` | `Kondisi pH: nilai 6.45 — kategori Normal pada 01-11-2026 14:34 WIB (perubahan dari Rendah)` | success |
| pH 8,50 (Normal → Tinggi) | `pH tinggi` | `Kondisi pH: nilai 8.50 — kategori Tinggi pada 01-11-2026 14:40 WIB (perubahan dari Normal)` | warning |
| PPM 590 (Normal → Rendah) | `PPM rendah` | `Kondisi PPM: nilai 590.00 — kategori Rendah pada ...` | info |

## Perilaku tepat sesuai spesifikasi

| Kondisi | monitoring_logs | notifications |
|---|---|---|
| Rendah → Rendah (nilai 585 → 590) | ditulis | TIDAK (kategori sama) |
| Rendah → Normal | ditulis | 1 notifikasi "normal" |
| Normal → Tinggi | ditulis | 1 notifikasi "tinggi" (peringatan) |
| Tinggi → Normal | ditulis | 1 notifikasi "normal" |
| Normal → Rendah | ditulis | 1 notifikasi "rendah" |
| ESP restart, kategori tidak berubah | ditulis | TIDAK (state ada di DB) |

## Yang perlu diketahui

* `sensorWarning()` TIDAK dihapus dan tetap dipakai di
  `api/notifications/generate.php` — patch ini hanya mengganti
  pemanggilannya di jalur ingest ESP (`saveSensorMeasurements`).
* Kolom `Href` mengikuti pola bawaan (`pengaturan-ph.php` /
  `pengaturan-ppm.php`) sehingga notifikasi membuka halaman setelan yang
  sesuai daristitan dashboard.
* Notifikasi kategori TIDAK berperan mengendalikan pompa — pompa
  memakai pembacaan sensor melalui firmware; ini murni pusat informasi.
