<?php
/**
 * PATCH A DIHENTIKAN — endpoint sudah menjadi BAGIAN RESMI backend.
 *
 * File ini adalah cadangan lama (v2). Endpoint pemganti sudah terpasang
 * di lokasi aslinya:  api/device/report.php  beserta logikanya di
 * includes/device_report.php.
 *
 * Catatan penting pencatatan aktivitas Dithane (Data Aktivitas Sistem):
 *  - laporan "started"   -> SATU catatan monitoring_logs per pompa MULAI
 *                           menyala (status "Menyala") + notifikasi web.
 *  - laporan "completed" -> notifikasi web saja, TANPA catatan kedua,
 *                           sehingga riwayat tetap satu baris per nyala.
 * JANGAN lagi memasang salinan patch ini bersamaan dengan endpoint resmi —
 * hal itu menciptakan pencatatan ganda (catatan activity tercatat dua kali).
 */
