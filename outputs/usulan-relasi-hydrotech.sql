-- USULAN MIGRASI ERD HYDROTECH; BELUM DIJALANKAN.
-- Menggunakan nama tabel dan kolom ASLI agar sesuai basis kode PHP.
-- Tidak mengubah schema.sql atau database secara otomatis.
-- Jalankan hanya setelah meninjau database tujuan, membuat cadangan,
-- serta memastikan semua jenis monitoring/riwayat tersedia pada settings.
-- Skrip satu kali, bukan idempoten. DDL MySQL dapat melakukan implicit commit.
USE `monitoring_sensor`;

-- Pemeriksaan awal: kedua hasil harus kosong sebelum menjalankan ALTER.
SELECT DISTINCT m.type AS jenis_tanpa_pengaturan
FROM monitoring_logs m LEFT JOIN settings s ON s.type = m.type
WHERE s.id IS NULL;
SELECT DISTINCT h.type AS jenis_tanpa_pengaturan
FROM history_logs h LEFT JOIN settings s ON s.type = h.type
WHERE s.id IS NULL;

-- Empat atribut tambahan bersifat opsional untuk mempertahankan data lama.
ALTER TABLE settings
    ADD COLUMN updated_by INT NULL,
    ADD INDEX idx_settings_updated_by (updated_by),
    ADD CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE profile
    ADD COLUMN updated_by INT NULL,
    ADD INDEX idx_profile_updated_by (updated_by),
    ADD CONSTRAINT fk_profile_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE monitoring_logs
    ADD COLUMN sensor_reading_id INT NULL,
    ADD INDEX idx_monitoring_sensor_reading (sensor_reading_id),
    ADD CONSTRAINT fk_monitoring_setting_type FOREIGN KEY (type)
        REFERENCES settings (type) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_monitoring_sensor_reading FOREIGN KEY (sensor_reading_id)
        REFERENCES sensor_readings (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE history_logs
    ADD CONSTRAINT fk_history_setting_type FOREIGN KEY (type)
        REFERENCES settings (type) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE notifications
    ADD COLUMN sensor_reading_id INT NULL,
    ADD INDEX idx_notifications_sensor_reading (sensor_reading_id),
    ADD CONSTRAINT fk_notifications_sensor_reading FOREIGN KEY (sensor_reading_id)
        REFERENCES sensor_readings (id) ON DELETE SET NULL ON UPDATE RESTRICT;

-- Tidak ada FK monitoring_logs -> history_logs karena skema dan alur saat ini
-- menyimpan dua kelompok catatan terpisah. Hubungan tersebut tidak diasumsikan.
-- FK type sah karena settings.type sudah UNIQUE dan ENUM-nya identik.
-- Semua FK non-identifying; primary key setiap tabel tetap id.
