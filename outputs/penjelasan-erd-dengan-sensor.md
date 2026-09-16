# ERD Hydrotech dengan Sensor - versi implementasi

Skema proyek dan database lokal telah disesuaikan: **8 tabel, 57 atribut, dan 7 foreign key**. Sensor berbeda dari catatan pembacaannya.

Dokumentasi operasional, migrasi, API, serta batasan simulasi terdapat pada [database/README.md](../database/README.md). Contoh SQL rancangan tetap tersedia untuk referensi, tetapi sumber skema aplikasi adalah [database/schema.sql](../database/schema.sql).

## Pemetaan seluruh atribut

### Pengguna (`users`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_pengguna | `id` | `INT` | PK | Tidak |
| nama_pengguna | `username` | `VARCHAR(50)` | UQ | Tidak |
| kata_sandi | `password` | `VARCHAR(255)` | - | Tidak |
| email | `email` | `VARCHAR(100)` | - | Ya |
| peran | `role` | `ENUM('admin','user')` | - | Tidak |
| dibuat_pada | `created_at` | `TIMESTAMP` | - | Ya |
| diperbarui_pada | `updated_at` | `TIMESTAMP` | - | Ya |

### Pengaturan (`settings`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_pengaturan | `id` | `INT` | PK | Tidak |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | UQ | Tidak |
| konfigurasi_json | `config_json` | `JSON` | - | Tidak |
| diperbarui_pada | `updated_at` | `TIMESTAMP` | - | Ya |
| diubah_oleh_id | `updated_by` | `INT` | FK | Ya |

### Profil (`profile`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_profil | `id` | `INT` | PK | Tidak |
| bagian | `section` | `ENUM('owner','team')` | - | Tidak |
| label | `label` | `VARCHAR(100)` | - | Tidak |
| isi | `value` | `TEXT` | - | Tidak |
| urutan | `sort_order` | `INT` | - | Tidak |
| diubah_oleh_id | `updated_by` | `INT` | FK | Ya |

### Sensor (`sensors`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_sensor | `id` | `INT` | PK | Tidak |
| nama_sensor | `name` | `VARCHAR(100)` | - | Tidak |
| jenis_sensor | `type` | `ENUM('ph','ppm')` | - | Tidak |
| satuan | `unit` | `ENUM('pH','ppm')` | - | Tidak |
| status_sensor | `status` | `ENUM('aktif','nonaktif','rusak')` | - | Tidak |
| lokasi | `location` | `VARCHAR(150)` | - | Ya |
| dibuat_pada | `created_at` | `TIMESTAMP` | - | Tidak |
| diperbarui_pada | `updated_at` | `TIMESTAMP` | - | Tidak |

### Pembacaan Sensor (`sensor_readings`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_pembacaan | `id` | `INT` | PK | Tidak |
| id_sensor | `sensor_id` | `INT` | FK | Tidak |
| nilai | `value` | `DECIMAL(12,4)` | - | Tidak |
| direkam_pada | `recorded_at` | `TIMESTAMP` | - | Tidak |

### Catatan Monitoring (`monitoring_logs`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_monitoring | `id` | `INT` | PK | Tidak |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | FK | Tidak |
| nama_hari | `day_name` | `VARCHAR(20)` | - | Tidak |
| jam_catatan | `log_time` | `VARCHAR(10)` | - | Tidak |
| nilai | `value` | `VARCHAR(20)` | - | Tidak |
| status | `status` | `VARCHAR(30)` | - | Tidak |
| keterangan | `note` | `TEXT` | - | Ya |
| dihapus | `is_deleted` | `TINYINT(1)` | - | Tidak |
| dibuat_pada | `created_at` | `TIMESTAMP` | - | Ya |
| id_pembacaan | `sensor_reading_id` | `INT` | FK | Ya |

### Riwayat (`history_logs`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_riwayat | `id` | `INT` | PK | Tidak |
| nama_hari | `day_name` | `VARCHAR(20)` | - | Tidak |
| tanggal_catatan | `log_date` | `DATE` | - | Tidak |
| jam_catatan | `log_time` | `VARCHAR(10)` | - | Tidak |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | FK | Tidak |
| status | `status` | `VARCHAR(30)` | - | Tidak |
| keterangan | `note` | `TEXT` | - | Ya |
| dihapus | `is_deleted` | `TINYINT(1)` | - | Tidak |
| dibuat_pada | `created_at` | `TIMESTAMP` | - | Ya |

### Notifikasi (`notifications`)

| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |
|---|---|---|---|---|
| id_notifikasi | `id` | `INT` | PK | Tidak |
| label | `label` | `VARCHAR(100)` | - | Tidak |
| pesan | `message` | `TEXT` | - | Tidak |
| tingkat_pesan | `tone` | `ENUM('warning','success','info')` | - | Tidak |
| tautan | `href` | `VARCHAR(255)` | - | Ya |
| sudah_dibaca | `is_read` | `TINYINT(1)` | - | Tidak |
| dibuat_pada | `created_at` | `TIMESTAMP` | - | Ya |
| id_pembacaan | `sensor_reading_id` | `INT` | FK | Ya |

