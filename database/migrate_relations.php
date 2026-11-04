<?php
/**
 * Hydrotech - Aktivasi relasi antar tabel (ALTER, tanpa hapus/reset data).
 *
 * Penambahan:
 *   users -> settings.updated_by            (SET NULL CASCADE)
 *   users -> dithane_schedules.updated_by   (SET NULL CASCADE)
 *   users -> profile.user_id                (CASCADE; baris profil
 *   ditandatangani ke akun admin yang ada tanpa menghapus datanya)
 *   sensor_readings -> monitoring_logs.reading_id (SET NULL; NULL = manual)
 *   dithane_schedules -> monitoring_logs.schedule_id (SET NULL)
 *   monitoring_logs -> notifications.monitoring_id (SET NULL)
 *   users -> notifications.user_id          (SET NULL; NULL = broadcast)
 *
 * Usage: php database/migrate_relations.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/database.php';

function columnExists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

function constraintExists(PDO $pdo, string $table, string $constraint): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $q->execute([$table, $constraint]);
    return (int)$q->fetchColumn() > 0;
}

/* ABLE: idempoten dan aman diulang; tiap langkah hanya menambah kolom/index/constraint. */
function migrateRelations(PDO $pdo): array {
    $applied = [];

    /* Orphan sensor referensi: log yang menunjuk reading terhapus dulu
       dinormalkan ke NULL agar FK SET NULL bisa dibuat (log tetap ada).  */
    $orphans = (int)$pdo->query(
        'SELECT COUNT(*) FROM monitoring_logs l LEFT JOIN sensor_readings r ON r.reading_id = l.reading_id
         WHERE l.reading_id IS NOT NULL AND r.reading_id IS NULL'
    )->fetchColumn();
    if ($orphans > 0) {
        $pdo->exec('UPDATE monitoring_logs l LEFT JOIN sensor_readings r ON r.reading_id = l.reading_id
                    SET l.reading_id = NULL WHERE l.reading_id IS NOT NULL AND r.reading_id IS NULL');
        echo "[NOTE] $orphans log merujuk reading yang tidak ada; reading_id dinormalkan ke NULL (log tidak dihapus).\n";
    }

    if (!columnExists($pdo, 'settings', 'updated_by')) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN updated_by INT NULL, ADD INDEX idx_settings_updated_by (updated_by)");
        $applied[] = 'settings.updated_by';
    }
    if (!constraintExists($pdo, 'settings', 'fk_settings_user')) {
        $pdo->exec("ALTER TABLE settings ADD CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_settings_user';
    }

    if (!columnExists($pdo, 'dithane_schedules', 'updated_by')) {
        $pdo->exec("ALTER TABLE dithane_schedules ADD COLUMN updated_by INT NULL, ADD INDEX idx_dithane_updated_by (updated_by)");
        $applied[] = 'dithane_schedules.updated_by';
    }
    if (!constraintExists($pdo, 'dithane_schedules', 'fk_dithane_user')) {
        $pdo->exec("ALTER TABLE dithane_schedules ADD CONSTRAINT fk_dithane_user FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_dithane_user';
    }

    if (!columnExists($pdo, 'monitoring_logs', 'schedule_id')) {
        $pdo->exec("ALTER TABLE monitoring_logs ADD COLUMN schedule_id INT NULL, ADD INDEX idx_monitoring_schedule (schedule_id)");
        $applied[] = 'monitoring_logs.schedule_id';
    }
    if (!constraintExists($pdo, 'monitoring_logs', 'fk_monitoring_reading')) {
        $pdo->exec("ALTER TABLE monitoring_logs ADD CONSTRAINT fk_monitoring_reading FOREIGN KEY (reading_id) REFERENCES sensor_readings (reading_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_monitoring_reading';
    }
    if (!constraintExists($pdo, 'monitoring_logs', 'fk_monitoring_schedule')) {
        $pdo->exec("ALTER TABLE monitoring_logs ADD CONSTRAINT fk_monitoring_schedule FOREIGN KEY (schedule_id) REFERENCES dithane_schedules (schedule_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_monitoring_schedule';
    }

    if (!columnExists($pdo, 'notifications', 'monitoring_id')) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN monitoring_id INT NULL, ADD INDEX idx_notifications_monitoring (monitoring_id)");
        $applied[] = 'notifications.monitoring_id';
    }
    if (!constraintExists($pdo, 'notifications', 'fk_notifications_monitoring')) {
        $pdo->exec("ALTER TABLE notifications ADD CONSTRAINT fk_notifications_monitoring FOREIGN KEY (monitoring_id) REFERENCES monitoring_logs (monitoring_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_notifications_monitoring';
    }
    if (!columnExists($pdo, 'notifications', 'user_id')) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN user_id INT NULL, ADD INDEX idx_notifications_user (user_id)");
        $applied[] = 'notifications.user_id';
    }
    if (!constraintExists($pdo, 'notifications', 'fk_notifications_user')) {
        $pdo->exec("ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE");
        $applied[] = 'fk_notifications_user';
    }

    if (!columnExists($pdo, 'profile', 'user_id')) {
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount === 0) throw new RuntimeException('Tidak ada user: profil individuan tidak bisa ditandatangani.');
        $profileCount = (int)$pdo->query('SELECT COUNT(*) FROM profile')->fetchColumn();
        /* Isian profil yang sudah ada menjadi milik satu-satunya akun.      */
        if ($userCount > 1) echo "[NOTE] Ada $userCount user: baris profil lama ditandatangani ke admin pertama (user_id terkecil).\n";
        $pdo->exec("ALTER TABLE profile ADD COLUMN user_id INT NULL");
        $pdo->exec('UPDATE profile SET user_id = (SELECT MIN(user_id) FROM users) WHERE user_id IS NULL');
        $pdo->exec("ALTER TABLE profile MODIFY user_id INT NOT NULL, ADD INDEX idx_profile_user (user_id)");
        $applied[] = 'profile.user_id (' . $profileCount . ' baris milik admin)';
    }
    try {
        $pdo->exec("ALTER TABLE profile ADD CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE");
        $applied[] = 'fk_profile_user';
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), '1452') || stripos($e->getMessage(), 'Loses foreign key') !== false) {
            throw new RuntimeException('Ada baris profil dengan user_id yang tidak ada di users. Periksa data profile sebelum migrasi ulang: ' . $e->getMessage());
        }
        if (constraintExists($pdo, 'profile', 'fk_profile_user')) { $applied[] = 'fk_profile_user (sudah ada)'; }
        else throw $e;
    }
    if (!constraintExists($pdo, 'profile', 'uq_profile_section_label')) {
        try {
            $pdo->exec("ALTER TABLE profile ADD UNIQUE KEY uq_profile_section_label (user_id, section, label)");
            $applied[] = 'uq_profile_section_label';
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '1062')) {
                $dups = join('; ', array_map('json_encode', $pdo->query('SELECT user_id, section, label, COUNT(*) c FROM profile GROUP BY user_id, section, label HAVING c > 1')->fetchAll(PDO::FETCH_ASSOC)));
                throw new RuntimeException("Ada label profil duplikat per user. Rapikan dulu (duplikat tidak dihapus otomatis): $dups");
            }
            throw $e;
        }
    }

    return $applied;
}

try {
    $pdo = getDBConnection();
    /* MySQL melakukan COMMIT implisit per DDL: migrasi tidak dibungkus
       tranjasi, keamanan datang dari pemeriksaan idempoten per langkah
       dan pemeriksaan orphan/keterbacaan SEBELUM tiap ALTER.             */
    $applied = migrateRelations($pdo);
    echo $applied ? 'Relasi diaktifkan: ' . implode(', ', $applied) . "\n"
                  : "Semua relasi sudah aktif (tidak ada perubahan).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migrasi relasi gagal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
