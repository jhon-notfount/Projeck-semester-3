/*
 * ===================================================================
 *  HydroTech_ESP32.ino  —  Firmware monitoring & kontrol hidroponik
 * ===================================================================
 *
 *  KONTRAK BACKEND EURISA — diterjemahkan dari audit kode HydroTech
 *  yang berada BERSAMA folder ini (jangan diubah tanpa audit ulang):
 *
 *  POST /api/sensor/ingest.php    header: X-API-Key
 *      body JSON : {"ph_value": 6.52, "ppm_value": 850}
 *                   (keduanya opsional, minimal satu; PPM WAJIB integer;
 *                    pH 0..14; nilai citra rentang ELSE server menolak)
 *      resp      : {"success":true,"message":"...","data":{
 *                   "readings":[...],"reading_ids":[...]}}
 *
 *  GET  /api/sensor/ingest.php    header: X-API-Key
 *      resp      : {"success":true,"data":{"config":{
 *                   "ph":{"min":..,"max":..},
 *                   "ppm":{"min":..,"max":..},
 *                   "dithane":{"interval":"24","duration":"30",
 *                              "start":"07.00"}}}}
 *      catatan   : interval/duration/start berupa STRING; start memakai
 *                  pemisah '.' (contoh 07.00) hasil readDithane().
 *
 *  ATURAN WAJIB DITAATI PENYUNTING FIRMWARE:
 *   - Timestamp ditetapkan SERVER (Asia/Jakarta) — firmware TIDAK
 *     mengirim timestamp. NTP hanya untuk jadwal Dithane & counter harian.
 *   - Heartbeat perangkat = sensor_readings tersimpan; batas online
 *     300 s (api/device/status.php) => UPLOAD_INTERVAL_MS < 300000 ms.
 *   - TIDAK ADA endpoint pompa di backend saat ini. Seluruh pompa
 *     dikendalikan LOKAL dengan parameter aman; pelaporan eksekusi
 *     memakai patch OPSIONAL (firmware/patches/). Endpoint 404 =>
 *     otomatis menonaktifkan pelaporan (tidak mengganggu sistem).
 *   - LOGIKA POMPA (3 kategori: rendah/normal/tinggi):
 *       PPM  : < min → dosis terus (bertahap) HINGGA masuk rentang;
 *              normal → pompa mati; > maks → pompa mati + peringatan.
 *       pH   : arah cairan konfigurable (PH_PUMP_DIR; default PENAIK:
 *              pH < min → dosis hingga masuk rentang; normal → mati;
 *              pH > maks → pompa tetap mati + peringatan server).
 *       Notifikasi kategori ditangani SERVER per PERUBAHAN KATEGORI
 *       (rendah/normal/tinggi) — bukan per pembacaan (PATCH C v2).
 *       Dit. : interval dari WAKTU SELESAI TERAKHIR (NVS + last_completed_at
 *              server); notifikasi mulai & selesai via patch. Slot terlewat
 *              > GRACE tanpa eksekusi susulan.
 *   - Pompa dosing dihentikan langsung bila sensor invalid/aman/spray.
 *
 *  Library: WiFi/HTTPClient/Preferences/esp_task_wdt (bawaan),
 *           ArduinoJson (>=6.19), LiquidCrystal_I2C.
 *  Board  : ESP32 Dev Module, core arduino-esp32 >= 2.0.x
 *           (analogReadMilliVolts dibutuhkan).
 * ===================================================================
 */

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include "esp_task_wdt.h"
#include "time.h"
#include "WiFiClientSecure.h"

/* =====================================================================
 *  1. KONFIGURASI — ubah hanya blok ini untuk tiap instalasi
 * ===================================================================== */

/* --- Wi-Fi + API (JANGAN commit kredensial nyata) ------------------- */
const char* WIFI_SSID      = "ISI-SSID";
const char* WIFI_PASS      = "ISI-PASSWORD";
const char* API_BASE_URL   = "http://192.168.1.10:8000/api/sensor/ingest.php";
const char* API_REPORT_URL = "";                 // kosongkan bila patch report belum dipasang
const char* API_KEY        = "ISI-ESP-API-KEY";  // sama dengan config/esp_key.txt / env
#define API_USE_TLS  0                           // 1 bila API_BASE_URL https://
/* HTTPS produksi: isi TLS_CA_CERT dengan root-CA PEM (baris diganti \n).
   Tanpa CA dan tanpa TLS_DEV_MODE, handshake ditolak — verifikasi
   sertifikat wajib untuk produksi.                                   */
const char* TLS_CA_CERT  = "";
bool        TLS_DEV_MODE = false;                // HANYA untuk pengembangan

/* --- Interval ------------------------------------------------------- */
const uint32_t SENSOR_INTERVAL_MS = 5000;        // sampling + filter sensor
const uint32_t UPLOAD_INTERVAL_MS = 120000;      // < 300 s (batas heartbeat)
const uint32_t CONFIG_INTERVAL_MS = 900000;      // sinkron konfigurasi (15 mnt)
const uint32_t WIFI_RETRY_MS      = 10000;       // jeda antar percobaan Wi-Fi
const uint32_t HTTP_TIMEOUT_MS    = 8000;
const uint32_t MAX_CONFIG_AGE_MS  = 7200000;     // batas umur konfigurasi (2 jam)
const uint8_t  UPLOAD_FAIL_LIMIT  = 5;           // x gagal POST -> mode aman

/* --- Pin ------------------------------------------------------------ */
const uint8_t ADC_PH_PIN  = 34;    // AO sensor pH  (ADC1 — aman dengan Wi-Fi)
const uint8_t ADC_PPM_PIN = 35;    // AO sensor TDS (ADC1)
const uint8_t LED_PIN     = 23;
const int     I2C_SDA     = 21;
const int     I2C_SCL     = 22;
const uint8_t LCD_ADDR_CAND[2] = { 0x27, 0x3F };  // alamat umum LCD 20x4
const uint8_t LCD_COLS = 20, LCD_ROWS = 4;
const uint32_t LCD_PAGE_MS = 5000;                // pergantian halaman LCD

/* --- Kalibrasi default (bisa ditimpa via Serial; tersimpan di NVS) --- */
/* pH: konversi two-point. Nilai = mV AO modul SETELAH pembagi tegangan
   dikembalikan (PH_DIVIDER = Vpin/Vmod). BUKAN rumus universal —
   kalibrasi wajib memakai buffer pH 4,01 dan 7,00 per unit modul.     */
float CAL_PH4_MV  = 1350.0f;    // mV modul pada buffer pH 4,01
float CAL_PH7_MV  = 2030.0f;    // mV modul pada buffer pH 7,00
float PH_DIVIDER  = 1.0f;       // Vpin = Vout modul * PH_DIVIDER
/* TDS: polinomial Gravity pada 25 C tetap. Sensor suhu belum ada —
   firmware TIDAK mengklaim kompensasi suhu aktual.                    */
float TDS_K_VALUE  = 1.0f;
float MIN_PH_VALID = 0.5f,  MAX_PH_VALID = 12.5f;
float MIN_PPM_VALID = 50.0f, MAX_PPM_VALID = 5000.0f;
uint8_t SENSOR_SAMPLES = 21;                      // median filter (ganjil <= 31)

/* --- Pompa dosing AB-Mix & pH-Down ----------------------------------- */
const uint32_t AB_DOSE_MS          = 3000;        // satu siklus dosis (ms)
const uint32_t PHDOSE_MS           = 2000;
const uint32_t MIX_WAIT_S          = 45;          // tunggu pencampuran (detik)
const uint32_t DOSE_COOLDOWN_S     = 120;         // jeda antar dosing
const uint8_t  MAX_DOSE_EVENTS_DAY = 24;          // maks event/hari/pompa
const uint32_t MAX_DOSE_RUNTIME_MS = 90000;       // maks runtime/hari/pompa

/* === ARAH pompa pH (GPIO17) — WAJIB sesuai cairan yang terpasang ===
 * Spesifikasi sistem terbaru: pompa GPIO17 menggerakkan CAIRAN PENAIK pH
 * (aktif saat pH di bawah minimum; pH di atas maksimum TIDAK menyalakan
 * pompa). Jika faktanya modul Anda menggerakkan cairan pH-Down (seperti
 * pinout awal), ganti PH_PUMP_DIR menjadi PH_LOWER.
 *   PH_RAISE : dosing saat pH < min   (cairan penaik)  [default spesifikasi]
 *   PH_LOWER : dosing saat pH > max   (cairan penurun) [pinout awal]      */
enum PhPumpDir : uint8_t { PH_RAISE = 0, PH_LOWER = 1 };
const PhPumpDir PH_PUMP_DIR = PH_RAISE;

/* --- Dithane (zat kimia: interlock ketat) ----------------------------- */
const uint32_t MAX_SPRAY_SECONDS = 600;           // durasi tegas maksimum
const uint32_t SPRAY_GRACE_S     = 300;           // toleransi keterlambatan
const uint32_t BOOT_SETTLE_MS    = 30000;         // jeda aman pasca boot

/* --- Pompa sirkulasi: backend belum menyediakan pengaturan ---------- */
enum CircMode : uint8_t { CIRC_DISABLED = 0, CIRC_PERIODIC = 1 };
const CircMode CIRC_MODE     = CIRC_DISABLED;     // default OFF
const uint32_t CIRC_ON_S     = 300;               // fase nyala (detik)
const uint32_t CIRC_PERIOD_S = 5400;              // periode siklus penuh

/* --- Relay (abstraksi polaritas) --------------------------------------- */
/*  RELAY_ACTIVE_LOW = true  : GPIO LOW  -> relay ON (modul umum).
 *  RELAY_ACTIVE_LOW = false : GPIO HIGH -> relay ON; dipakai untuk
 *                              rancangan ULN2803A (GPIO HIGH membuat
 *                              driver menarik input relay active-low).
 *  Semua logika firmware memakai semantik logis via relayWrite().    */
const bool RELAY_ACTIVE_LOW = false;

/* =====================================================================
 *  2. TIPE & GLOBAL
 * ===================================================================== */

enum PumpId : uint8_t { PUMP_AB = 0, PUMP_PHDOWN, PUMP_DITHANE, PUMP_CIRC, PUMP_COUNT };
const char*   PUMP_NAME[PUMP_COUNT] = { "AB-Mix", "pH-Adj", "Dithane", "Sirkulasi" };
const uint8_t PUMP_PIN [PUMP_COUNT] = { 16, 17, 18, 19 };

/* --- Mesin dosing ------------------------------------------------------
 * Alur: IDLE --trigger--> PUMPING --doseMs--> RECHECK --mixWait--> target?
 *   target belum lagi -> WAIT_COOL -> PUMPING ...
 *   target tercapai   -> IDLE (owner release)
 * dosingOwner menjadikan hanya SATU pompa dosing aktif pada satu waktu. */
enum DoseState : uint8_t { DOSE_IDLE, DOSE_WAIT_COOL, DOSE_PUMPING, DOSE_RECHECK };
struct DoseCtl {
  DoseState state = DOSE_IDLE;
  uint32_t  t0 = 0;                      // millis() saat masuk state
  PumpId    pump = PUMP_AB;
  uint32_t  doseMs = 0;
  uint8_t   eventsToday = 0;
  uint32_t  runMsToday = 0;
  uint32_t  dayKey = 0xFFFFFFFF;
  const char* tag = "";
};
DoseCtl doseAB, dosePH;
PumpId  dosingOwner = PUMP_COUNT;     // PUMP_COUNT = tidak ada yang memegang

/* --- Sirkulasi ------------------------------------------------------- */
enum CircState : uint8_t { CIRC_OFF, CIRC_RUNNING };
CircState circState = CIRC_OFF;
uint32_t  circT0 = 0;
uint8_t   circLastMode = 0xFF;        // 0xFF = belum init

/* --- Dithane ---------------------------------------------------------- */
enum DithState : uint8_t { DITH_IDLE, DITH_SPRAYING };
DithState dithState = DITH_IDLE;
uint32_t  dithT0 = 0, dithDur = 0;
time_t    dithSlot = 0;
bool      dithReported = false;
bool      reportAvail = true;        // patch laporan tersedia?
uint8_t   reportFails = 0;

/* --- Konfigurasi remote ------------------------------------------------ */
struct RemoteConfig {
  bool     valid = false;             // ambang pH/PPM tersinkron
  bool     dithValid = false;
  uint32_t fetchedMs = 0;
  float    phMin = 5.5f,  phMax = 6.5f;
  float    ppmMin = 800.f, ppmMax = 1000.f;
  long     dithIntervalH = 0;         // nilai string di backend
  long     dithDurationS = 0;
  long     dithStartHM = -1;          // (H*60+M)
  bool     hasDeviceCfg = false;      // blok "device" patch opsional
  long     respArmed = -1;            // -1 = tidak ada -> pakai sakelar lokal
  uint8_t  respCircMode = 0xFF;
  uint32_t respDoseABms = 0, respDosePHms = 0;
  uint32_t respMixWaitS = 0, respCooldownS = 0, respMaxEvents = 0;
  uint32_t respCircOnS = 0, respCircPeriodS = 0;
};
RemoteConfig cfg;

/* --- Sensor ------------------------------------------------------------- */
float phValue = NAN,  ppmValue = NAN;
float phRawMv = 0,    ppmRawMv = 0;
bool  phValid = false, ppmValid = false;

/* --- Jaringan ------------------------------------------------------------ */
enum WifiState : uint8_t { WIFI_DOWN, WIFI_CONNECTING, WIFI_OK };
WifiState wifiState = WIFI_DOWN;
uint32_t  wifiLastTry = 0, wifiConnectStart = 0;
uint16_t  uploadFails = 0;
bool      safeMode = false;

/* --- Lain ---------------------------------------------------------------- */
bool     ntpStarted = false;
uint32_t bootMs = 0;

LiquidCrystal_I2C* lcdPtr = nullptr;
uint8_t  lcdAddrFound = 0;
uint8_t  lcdPage = 0; uint32_t lcdPage0 = 0, lcdDraw0 = 0;
bool     ledOn = false; uint32_t led0 = 0;

Preferences nvs;

/* Kompatibel ArduinoJson v6 & v7 */
#if ARDUINOJSON_VERSION_MAJOR >= 7
  #define HY_DOC() JsonDocument doc
#else
  #define HY_DOC() DynamicJsonDocument doc(2048)
#endif

/* =====================================================================
 *  3. WAKTU LOKAL (WIB)
 * ===================================================================== */

struct tm localTm() {
  struct tm t; memset(&t, 0, sizeof(t));
  time_t now = time(nullptr);
  localtime_r(&now, &t);
  return t;
}

bool systemTimeValid() { return time(nullptr) > 1577836800; }  // > 1 Jan 2020

/* =====================================================================
 *  4. RELAY — abstraksi polaritas; semua pompa OFF saat boot/darurat
 * ===================================================================== */

inline void relayWrite(PumpId p, bool on) {
  digitalWrite(PUMP_PIN[p], (on ^ RELAY_ACTIVE_LOW) ? HIGH : LOW);
}

inline bool relayIsOn(PumpId p) {
  return (digitalRead(PUMP_PIN[p]) == HIGH) ^ RELAY_ACTIVE_LOW;
}

void stopAllPumps() {
  for (uint8_t p = 0; p < PUMP_COUNT; p++) relayWrite(static_cast<PumpId>(p), false);
}

void setupPins() {
  /* Semua relay di-drive OFF SEBELUM hal lain. Meskipun Arduino menahan
     pin mengambang beberapa mikrodetik saat reset, output langsung
     ditulis LOW/HIGH sesuai polaritas. Untuk proteksi mutlak terhadap
     lonjakan bahan kimia saat boot, gunakan juga interlock fisik
     (contoh: saklar deret di jalur pompa Dithane).                   */
  for (uint8_t p = 0; p < PUMP_COUNT; p++) {
    relayWrite(static_cast<PumpId>(p), false);   // tentukan logika level
    pinMode(PUMP_PIN[p], OUTPUT);
    relayWrite(static_cast<PumpId>(p), false);   // pastikan OFF
  }
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);
  analogReadResolution(12);
  analogSetPinAttenuation(ADC_PH_PIN,  ADC_11db);
  analogSetPinAttenuation(ADC_PPM_PIN, ADC_11db);
}

/* =====================================================================
 *  5. SENSOR: sampling median, konversi, validasi
 * ===================================================================== */

uint32_t readMedianMv(uint8_t pin, uint8_t n) {
  uint32_t v[32];
  for (uint8_t i = 0; i < n; i++) {
    v[i] = (uint32_t)analogReadMilliVolts(pin);   // kalibrasi eFuse ADC
    yield();                                      // biar RTOS & Wi-Fi jalan
  }
  for (uint8_t i = 1; i < n; i++) {               // insertion sort, n <= 31
    uint32_t k = v[i]; int8_t j = i - 1;
    while (j >= 0 && v[j] > k) { v[j + 1] = v[j]; j--; }
    v[j + 1] = k;
  }
  return v[n / 2];
}

/* pH two-point; masukan = mV AO modul SETELAH pembagi dibalik.          */
float convertPhMv(float mv) {
  float vFull = mv / max(PH_DIVIDER, 0.01f);
  float span = CAL_PH7_MV - CAL_PH4_MV;
  if (fabsf(span) < 1.0f) return NAN;             // kalibrasi belum diset
  float slope = (7.00f - 4.01f) / span;
  return 4.01f + (vFull - CAL_PH4_MV) * slope;
}

/* PPM (rumus Gravity) pada 25 C — tanpa kompensasi suhu aktual.        */
float convertPpmMv(float mv) {
  float v = mv / 1000.0f;
  float ec = 133.42f*v*v*v - 255.86f*v*v + 857.39f*v;   // uS/cm
  return ec * 0.5f * max(TDS_K_VALUE, 0.01f);
}

void validateSensors() {
  phRawMv  = (float)readMedianMv(ADC_PH_PIN,  SENSOR_SAMPLES);
  ppmRawMv = (float)readMedianMv(ADC_PPM_PIN, SENSOR_SAMPLES);
  phValue  = convertPhMv(phRawMv);
  ppmValue = convertPpmMv(ppmRawMv);
  phValid  = (!isnan(phValue) && phValue >= MIN_PH_VALID  && phValue  <= MAX_PH_VALID);
  ppmValid = (!isnan(ppmValue) && ppmValue >= MIN_PPM_VALID && ppmValue <= MAX_PPM_VALID);
  if (phRawMv  < 10.0f) phValid  = false;         // indikasi sensor terlepas
  if (ppmRawMv < 5.0f)  ppmValid = false;
  if (phRawMv > 3300.0f || ppmRawMv > 3300.0f) { phValid = false; ppmValid = false; }
}

/* =====================================================================
 *  6. TLS helper
 * ===================================================================== */

void applyTls(WiFiClientSecure* c) {
  if (TLS_CA_CERT[0] != '\0') {
    c->setCACert(TLS_CA_CERT);                    // produksi: verifikasi cert
    Serial.println("[TLS] verifikasi sertifikat aktif");
  } else if (TLS_DEV_MODE) {
    c->setInsecure();                             // HANYA pengembangan
    Serial.println("[TLS] setInsecure (DEV) — JANGAN untuk produksi");
  } else {
    /* CA kosong: gagalkan handshake agar tidak ada koneksi "diam-diam" */
    Serial.println("[TLS] CA belum diset — koneksi HTTPS ditolak (set TLS_CA_CERT atau TLS_DEV_MODE)");
    while (true) { delay(10000); }                // jangan jalankan pompa dengan kredensial tak terverifikasi
  }
}

/* =====================================================================
 *  7. Wi-Fi non-blocking
 * ===================================================================== */

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  wifiState = WIFI_CONNECTING;
  wifiConnectStart = millis();
  Serial.println("[WiFi] menghubungkan...");
}

void maintainWiFi() {
  switch (wifiState) {
    case WIFI_DOWN:
      if (millis() - wifiLastTry >= WIFI_RETRY_MS) { wifiLastTry = millis(); connectWiFi(); }
      break;
    case WIFI_CONNECTING:
      if (WiFi.status() == WL_CONNECTED) {
        wifiState = WIFI_OK;
        Serial.printf("[WiFi] terhubung ip=%s rssi=%d dBm\n",
                      WiFi.localIP().toString().c_str(), WiFi.RSSI());
      } else if (millis() - wifiConnectStart > 30000) {
        WiFi.disconnect(false, true);
        wifiState = WIFI_DOWN;
        wifiLastTry = millis();
        Serial.println("[WiFi] timeout — jeda lalu coba lagi");
      }
      break;
    case WIFI_OK:
      if (WiFi.status() != WL_CONNECTED) {
        wifiState = WIFI_CONNECTING;
        wifiConnectStart = millis();
        Serial.println("[WiFi] terputus — reconnect");
      }
      break;
  }
}

/* =====================================================================
 *  8. API: POST sensor (implisit heartbeat) & GET konfigurasi
 * ===================================================================== */

/* Parse "Y-m-d H:i:s" waktu server (WIB, database +07:00) menjadi
   epoch. Nilai dipakai untuk menyelamatkan anchor Dithane pasca
   restart / hilang NVS.                                              */
time_t parseServerLocalEpoch(const char* s) {
  int y, mo, d, h, mi, se;
  if (!s || sscanf(s, "%d-%d-%d %d:%d:%d", &y, &mo, &d, &h, &mi, &se) != 6)
    return 0;
  struct tm t; memset(&t, 0, sizeof(t));
  t.tm_year = y - 1900; t.tm_mon = mo - 1; t.tm_mday = d;
  t.tm_hour = h; t.tm_min = mi; t.tm_sec = se;
  return mktime(&t);   // TZ aktif = WIB-7 → hasil epoch akurat WIB
}

bool postSensors() {
  if (wifiState != WIFI_OK) return false;
  if (!phValid && !ppmValid) {
    Serial.println("[HTTP] sensor tidak valid — data tidak dikirim "
                   "(heartbeat melemah: status dashboard akan offline; perbaiki sensor)");
    return false;
  }
  char body[96];
  if (phValid && ppmValid)
    snprintf(body, sizeof(body), "{\"ph_value\":%.2f,\"ppm_value\":%ld}",
             phValue, (long)roundf(ppmValue));
  else if (phValid)
    snprintf(body, sizeof(body), "{\"ph_value\":%.2f}", phValue);
  else
    snprintf(body, sizeof(body), "{\"ppm_value\":%ld}", (long)roundf(ppmValue));

  HTTPClient http;
#if API_USE_TLS
  WiFiClientSecure client; applyTls(&client);
#else
  WiFiClient client;
#endif
  bool ok = false;
  if (!http.begin(client, API_BASE_URL)) { Serial.println("[HTTP] begin gagal"); return false; }
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", API_KEY);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(String(body));
  if (code > 0) {
    String resp = http.getString();
    HY_DOC();
    DeserializationError err = deserializeJson(doc, resp);
    if (err) {
      Serial.printf("[HTTP] respons POST bukan JSON valid (HTTP %d)\n", code);
    } else if (doc["success"].as<bool>() && doc["data"]["readings"].size() > 0) {
      ok = true;
      Serial.print("[HTTP] tersimpan, reading_ids=[");
      int i = 0;
      for (JsonVariant v : doc["data"]["reading_ids"].as<JsonArray>()) {
        if (i++) Serial.print(',');
        Serial.print((long long)v.as<long long>());
      }
      Serial.println("]");
    } else {
      Serial.printf("[HTTP] server menolak POST (HTTP %d)\n", code);
    }
  } else {
    Serial.printf("[HTTP] POST gagal code=%d — TIDAK diulang supaya "
                  "tidak menggandakan log di server\n", code);
  }
  http.end();
  return ok;
}

bool getRemoteConfig() {
  if (wifiState != WIFI_OK) return false;
  HTTPClient http;
#if API_USE_TLS
  WiFiClientSecure client; applyTls(&client);
#else
  WiFiClient client;
#endif
  bool ok = false;
  if (!http.begin(client, API_BASE_URL)) return false;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", API_KEY);
  int code = http.GET();
  if (code > 0) {
    String resp = http.getString();
    HY_DOC();
    DeserializationError err = deserializeJson(doc, resp);
    if (!err && doc["success"].as<bool>() && doc["data"]["config"].is<JsonObject>()) {
      JsonObject c = doc["data"]["config"];
      if (c["ph"].is<JsonObject>()) {
        float mn = c["ph"]["min"] | 0.f, mx = c["ph"]["max"] | 0.f;
        if (mn >= 0 && mx <= 14 && mn <= mx) {
          cfg.phMin = mn; cfg.phMax = mx;
          cfg.valid = true; cfg.fetchedMs = millis();
        } else Serial.println("[CFG] ambang pH invalid — diabaikan");
      }
      if (c["ppm"].is<JsonObject>()) {
        float mn = c["ppm"]["min"] | 0.f, mx = c["ppm"]["max"] | 0.f;
        if (mn >= 0 && mn <= mx) { cfg.ppmMin = mn; cfg.ppmMax = mx; }
      }
      if (c["dithane"].is<JsonObject>()) {
        char st[12]; strncpy(st, c["dithane"]["start"] | "", sizeof(st) - 1); st[sizeof(st) - 1] = '\0';
        for (char* p = st; *p; ++p) if (*p == ':') *p = '.';  // alat kompatibilitas HH:MM
        long ivL = (long)atol(c["dithane"]["interval"] | "");
        long duL = (long)atol(c["dithane"]["duration"] | "");
        int  dH = 0, dM = 0;
        if (sscanf(st, "%d.%d", &dH, &dM) == 2 && strchr(st, '.') &&
            dH >= 0 && dH < 24 && dM >= 0 && dM < 60 && ivL > 0 && duL > 0) {
          cfg.dithIntervalH = ivL;
          cfg.dithDurationS = (duL > (long)MAX_SPRAY_SECONDS) ? (long)MAX_SPRAY_SECONDS : duL;
          cfg.dithStartHM = dH * 60 + (long)dM;
          cfg.dithValid = true; cfg.fetchedMs = millis();
          Serial.printf("[CFG] dithane: interval=%ldh dur=%lds mulai=%02d:%02d WIB\n",
                        ivL, cfg.dithDurationS, dH, dM);
          /* Ketahanan pasca-restart: waktu selesai terakhir dari database
             (PATCH A/B: kolom virtual last_completed_at). Bila lebih baru
             dari NVS lokal → diadopsi.                                  */
          time_t serverDone = parseServerLocalEpoch(
              c["dithane"]["last_completed_at"] | "");
          if (serverDone > 0 && serverDone > dithLastDone()) {
            dithMarkDone(serverDone);
            Serial.println("[CFG] anchor Dithane diadopsi dari server "
                           "(last_completed_at lebih baru)");
          }
        } else Serial.println("[CFG] konfig dithane tidak valid — diabaikan");
      }
      if (c["device"].is<JsonObject>()) {                 // patch opsional
        JsonObject d = c["device"];
        cfg.hasDeviceCfg    = true;
        cfg.respArmed       = (d["armed"].as<int>() != 0) ? 1 : 0;
        cfg.respCircMode    = (d["circulation"].as<int>() != 0) ? CIRC_PERIODIC : CIRC_DISABLED;
        cfg.respDoseABms    = d["dose_ab_ms"] | 0;
        cfg.respDosePHms    = d["dose_ph_ms"] | 0;
        cfg.respMixWaitS    = d["mix_wait_sec"] | 0;
        cfg.respCooldownS   = d["cooldown_sec"] | 0;
        cfg.respMaxEvents   = d["max_events_day"] | 0;
        cfg.respCircOnS     = d["circ_on_sec"] | 0;
        cfg.respCircPeriodS = d["circ_period_sec"] | 0;
        Serial.println("[CFG] blok device (patch) terbaca");
      }
      ok = true;
    } else Serial.printf("[HTTP] GET config tidak dikenal (HTTP %d)\n", code);
  } else {
    Serial.printf("[HTTP] GET config gagal code=%d\n", code);
  }
  http.end();
  return ok;
}

/* =====================================================================
 *  9. LAPORAN EKSEKUSI POMPA (patch OPSIONAL; auto-off bila 404/err)
 *
 *  state "started"   : ditulis saat relay menyala -> notifikasi web
 *  state "completed" : ditulis hanya setelah durasi aktual penuh ->
 *                       log monitoring_logs dengan bukti durasi
 *  Kedua kirim DIDE-DUP SERVER (patch) — HTTP tidak di-retry.
 * ===================================================================== */

void reportPump(const char* pump, const char* state, long durationS) {
  if (API_REPORT_URL[0] == '\0') return;           // patch tidak dikonfigurasi
  if (!reportAvail || wifiState != WIFI_OK) return;
  HTTPClient http;
#if API_USE_TLS
  WiFiClientSecure client; applyTls(&client);
#else
  WiFiClient client;
#endif
  struct tm t = localTm();
  char body[160];
  snprintf(body, sizeof(body),
           "{\"pump\":\"%s\",\"state\":\"%s\",\"duration_s\":%ld,"
           "\"device_seen\":\"%04d-%02d-%02d %02d:%02d:%02d\"}",
           pump, state, durationS,
           t.tm_year + 1900, t.tm_mon + 1, t.tm_mday, t.tm_hour, t.tm_min, t.tm_sec);
  if (!http.begin(client, API_REPORT_URL)) return;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", API_KEY);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(String(body));
  if (code == 404) {
    reportAvail = false;                           // patch belum terpasang
    Serial.println("[REPORT] endpoint laporan 404 — pelaporan dimatikan (endpoint belum dipatch)");
  } else if (code > 0) {
    HY_DOC();
    bool okf = (!deserializeJson(doc, http.getString())) && doc["success"].as<bool>();
    Serial.printf("[REPORT] laporan %s (HTTP %d)\n", okf ? "diterima" : "ditolak server", code);
  } else {
    reportFails++;
    if (reportFails >= 3) reportAvail = false;
    Serial.printf("[REPORT] POST gagal code=%d — tanpa retry agar tidak duplikat\n", code);
  }
  http.end();
}

/* =====================================================================
 * 10. DOSING (AB-Mix & pH-Down) — mesin state
 * ===================================================================== */

bool configFresh() { return cfg.valid && (millis() - cfg.fetchedMs) <= MAX_CONFIG_AGE_MS; }
bool sprayActive() { return dithState == DITH_SPRAYING; }

bool dosingAllowed() {                            // prasyarat bersama dosing
  return !safeMode && configFresh() && !sprayActive() && systemTimeValid();
}

bool abTriggered() { return ppmValid && (ppmValue <  cfg.ppmMin); }

/* Pemicu pompa pH mengikuti ARAH cairan (PH_PUMP_DIR):
 *   PH_RAISE : pH di bawah min diberlakukan menyala; pH di atas maks TIDAK.
 *   PH_LOWER : pH di atas maks yang menyala (pinout awal "pH-Down").      */
bool phTriggered() {
  if (!phValid) return false;
  return (PH_PUMP_DIR == PH_RAISE) ? (phValue < cfg.phMin) : (phValue > cfg.phMax);
}

/* Target tercapai = nilai sudah MASUK rentang (pompa wajib berhenti).
   Spesifikasi 3 kategori: pompa mati begitu nilai "mencapai atau
   melewati batas minimum" (atau maksimum utk arah penurun):
   PH_RAISE : berhenti segera setelah pH >= min.
   PH_LOWER : berhenti segera setelah pH <= max.                        */
bool phTargetReached() {
  if (!phValid) return false;
  return (PH_PUMP_DIR == PH_RAISE)
      ? (phValue >= cfg.phMin)
      : (phValue <= cfg.phMax);
}

void releaseDosingOwner() {
  dosingOwner = PUMP_COUNT;
}

/* Frame harian: reset caps tiap pergantian tanggal WIB.               */
void rotateDaily(DoseCtl& d) {
  struct tm t = localTm();
  uint32_t key = (uint32_t)(t.tm_year + 1900) * 1000u + (uint32_t)t.tm_yday;
  if (d.dayKey != key) { d.dayKey = key; d.eventsToday = 0; d.runMsToday = 0; }
}

uint32_t effDoseMs(DoseCtl& d) {
  if (d.pump == PUMP_AB) return cfg.respDoseABms  ? cfg.respDoseABms  : AB_DOSE_MS;
  return cfg.respDosePHms ? cfg.respDosePHms : PHDOSE_MS;
}
uint32_t effMixWaitS()  { return cfg.respMixWaitS  ? cfg.respMixWaitS  : MIX_WAIT_S; }
uint32_t effCooldownS() { return cfg.respCooldownS ? cfg.respCooldownS : DOSE_COOLDOWN_S; }
uint8_t  effMaxEvents() { return cfg.respMaxEvents ? (uint8_t)cfg.respMaxEvents : MAX_DOSE_EVENTS_DAY; }

void setDoseIdle(DoseCtl& d) {
  d.state = DOSE_IDLE;
  releaseDosingOwner();                 // pompa dosing lain boleh masuk
}

void runDose(DoseCtl& d) {
  switch (d.state) {
    case DOSE_IDLE:
      break;
    case DOSE_WAIT_COOL:
      /* Setelah cooldown: verifikasi ulang trigger, cap harian, dan
         guard overdose SEBELUM memompa lagi — kondisi bisa berubah.   */
      if (millis() - d.t0 < effCooldownS() * 1000UL) break;
      if (!dosingAllowed()) { setDoseIdle(d); break; }
      if (d.eventsToday >= effMaxEvents() || d.runMsToday >= MAX_DOSE_RUNTIME_MS) {
        setDoseIdle(d);
        break;
      }
      { bool trig = (d.pump == PUMP_AB) ? abTriggered() : phTriggered();
        if (!trig) { setDoseIdle(d); break; } }
      d.state = DOSE_PUMPING; d.t0 = millis();
      dosingOwner = d.pump;
      break;
    case DOSE_PUMPING:
      /* KEAMANAN (ketentuan #4): dosis aktif langsung DIBATAL bila sensor
         tidak valid / mode aman / semprotan aktif — pompa tidak boleh
         terus bekerja dengan pembacaan tidak normal.                   */
      { bool sensorOk = (d.pump == PUMP_AB) ? ppmValid : phValid;
        if (sprayActive() || !dosingAllowed() || !sensorOk) {
          relayWrite(d.pump, false);
          setDoseIdle(d);
          if (!sensorOk)
            Serial.printf("[PUMP] %s sensor INVALID saat dosing — DIHENTIKAN\n", d.tag);
          break;
        } }
      if (millis() - d.t0 >= d.doseMs) {
        relayWrite(d.pump, false);
        d.runMsToday += d.doseMs;
        if (d.eventsToday < 250) d.eventsToday++;
        d.state = DOSE_RECHECK; d.t0 = millis();
        Serial.printf("[PUMP] %s dosis #%u selesai (%lu ms; runtime hari ini %lu ms)\n",
                      d.tag, d.eventsToday, (unsigned long)d.doseMs,
                      (unsigned long)d.runMsToday);
      }
      break;
    case DOSE_RECHECK:
      if (millis() - d.t0 >= effMixWaitS() * 1000UL) {
        d.state = DOSE_WAIT_COOL; d.t0 = millis();      // evaluasi lanjutan di sini
      }
      break;
  }
}

void controlABMix() {
  rotateDaily(doseAB);
  switch (doseAB.state) {
    case DOSE_IDLE: {
      relayWrite(PUMP_AB, false);
      if (!dosingAllowed() || !abTriggered() || dosingOwner != PUMP_COUNT) return;
      if (doseAB.eventsToday >= effMaxEvents() || doseAB.runMsToday >= MAX_DOSE_RUNTIME_MS) return;
      if (ppmValue > cfg.ppmMax) return;                    // guard overdose
      doseAB.doseMs = effDoseMs(doseAB);
      doseAB.state = DOSE_PUMPING;
      doseAB.t0 = millis();
      dosingOwner = PUMP_AB;
      Serial.printf("[PUMP] %s mulai dosing (PPM=%.0f < min %.0f)\n",
                    PUMP_NAME[PUMP_AB], ppmValue, cfg.ppmMin);
      break;
    }
    default:
      runDose(doseAB);
      /* Pemati tepat: begitu pengukuran masuk rentang, pompa mati      */
      if (doseAB.state == DOSE_WAIT_COOL && ppmValid && ppmValue >= cfg.ppmMin) {
        setDoseIdle(doseAB);
        Serial.printf("[PUMP] %s target tercapai (PPM=%.0f masuk rentang)\n",
                      PUMP_NAME[PUMP_AB], ppmValue);
      }
      break;
  }
}

void controlPHDown() {
  rotateDaily(dosePH);
  /* Kondisi "tidak dapat dikoreksi pompa ini" — hanya NOTIFIKASI server:
     PH_RAISE : pH > max di atas rentang (pompa penaik tidak boleh
                digunakan untuk menurunkan pH) -> notifikasi saja.
     PH_LOWER : pH < min di bawah rentang (pompa penurun sama logika). */
  static bool phUnfixLogged = false;
  bool unfixable = (PH_PUMP_DIR == PH_RAISE)
      ? (phValid && configFresh() && phValue > cfg.phMax)
      : (phValid && configFresh() && phValue < cfg.phMin);
  if (unfixable) {
    if (!phUnfixLogged) {
      phUnfixLogged = true;
      Serial.printf("[PH] pH %s rentang dan pengganti arah pompa = %s — "
                    "pompa tidak menyala (notifikasi dikirim server, "
                    "state-change).\n",
                    (PH_PUMP_DIR == PH_RAISE) ? "DI ATAS maksimum" : "DI BAWAH minimum",
                    (PH_PUMP_DIR == PH_RAISE) ? "cairan penaik" : "cairan penurun");
    }
  } else if (phValid) phUnfixLogged = false;
  switch (dosePH.state) {
    case DOSE_IDLE: {
      relayWrite(PUMP_PHDOWN, false);
      if (!dosingAllowed() || !phTriggered() || dosingOwner != PUMP_COUNT) return;
      if (dosePH.eventsToday >= effMaxEvents() || dosePH.runMsToday >= MAX_DOSE_RUNTIME_MS) return;
      /* Guard overdose: nilai pada sisi yang TIDAK dikoreksi pompa ini
         tidak pernah memicu dosing — sesuai spesifikasi ("di atas 8,
         pompa tidak diaktifkan" untuk arah penaik).                    */
      if ((PH_PUMP_DIR == PH_RAISE) ? (phValue > cfg.phMax) : (phValue < cfg.phMin)) return;
      dosePH.doseMs = effDoseMs(dosePH);
      dosePH.state = DOSE_PUMPING;
      dosePH.t0 = millis();
      dosingOwner = PUMP_PHDOWN;
      Serial.printf("[PUMP] %s mulai dosing arah %s (pH=%.2f %s)\n",
                    PUMP_NAME[PUMP_PHDOWN],
                    (PH_PUMP_DIR == PH_RAISE) ? "PENAIK" : "PENURUN",
                    phValue,
                    (PH_PUMP_DIR == PH_RAISE) ? "< min" : "> max");
      break;
    }
    default:
      runDose(dosePH);
      /* Pompa WAJIB berhenti begitu pengukuran masuk rentang (target). */
      if (dosePH.state == DOSE_WAIT_COOL && phTargetReached()) {
        setDoseIdle(dosePH);
        Serial.printf("[PUMP] %s target tercapai (pH=%.2f masuk rentang)\n",
                      PUMP_NAME[PUMP_PHDOWN], phValue);
      }
      break;
  }
}

/* =====================================================================
 * 11. JADWAL DITHANE — interval SEJAK PENYEMPROTAN TERAKHIR SELESAI
 *
 * Spesifikasi:
 *   - interval (jam, dari dashboard) dihitung dari waktu SELESAI
 *     penyemprotan terakhir (bukan dari slot jam mulai, bukan dari
 *     waktu sistem menyala).
 *   - waktu selesai terakhir disimpan dua tempat: NVS lokal (utama,
 *     atomik per eksekusi) DAN database server via laporan "completed"
 *     (resp. last_completed_at PATCH A / PATCH B). Nilai server yang
 *     LEBIH BARU diadopsi perangkat — NVS hilang / jam bergeser /
 *     ganti perangkat tetap akurat.
 *   - belum pernah menyemprot -> jadwal pertama memakai start_time.
 *   - slot terlampaui melebihi GRACE (mis. perangkat lama mati):
 *     DILEWATI tanpa eksekusi susulan, sehingga siklus berikutnya
 *     tetap berjarak penuh — tanpa semprot beruntun (keselamatan kimia).
 * ===================================================================== */

time_t dithLastDone()  { return (time_t)nvs.getULong64("dithdone", 0ULL); }
void   dithMarkDone(time_t t) { nvs.putULong64("dithdone", (uint64_t)t); }

bool dithArmed() {
  /* Interlock penyemprotan bahan kimia:
     - Tanpa patch (tidak ada blok "device" dari server): HARUS
       diaktifkan eksplisit via Serial ("arm") — default DISARMED.
     - Dengan patch: "device.armed" dari website memengaruhi penuh
       (1 = armed, 0 = disarmed) sehingga operator tidak butuh akses
       Serial; sakelar tetap berganda (website ATAU Serial).            */
  if (cfg.hasDeviceCfg && cfg.respArmed >= 0) return cfg.respArmed == 1;
  return nvs.getBool("armed", false);
}

/* Tentukan waktu jatuh tempo (dueAt) penyemprotan berikutnya:
 *   - sudah pernah selesai : dueAt = lastDone + interval
 *   - belum pernah         : slot pertama dari start_time (WIB)
 * Kembalikan true hanya bila now berada di [dueAt, dueAt+GRACE].
 * Slot yang terlampaui melebihi GRACE untuk satu demi satu dilompati
 * (tanpa eksekusi susulan) hingga dueAt berada di masa depan/grace.    */
bool dithDue(time_t& slotOut) {
  if (!systemTimeValid() || !cfg.dithValid) return false;
  long period = cfg.dithIntervalH * 3600L;
  if (period <= 0) return false;
  time_t now = time(nullptr);
  time_t lastDone = dithLastDone();
  time_t dueAt;
  if (lastDone == 0) {
    /* Belum pernah selesai: jadwal pertama mengikuti jam mulai (WIB). */
    struct tm b = localTm();
    b.tm_hour = (int)(cfg.dithStartHM / 60);
    b.tm_min  = (int)(cfg.dithStartHM % 60);
    b.tm_sec  = 0;
    dueAt = mktime(&b);
    while (dueAt > now) dueAt -= period;             // mundur ke slot terdahulu
  } else {
    /* INTERVAL dari waktu penyemprotan terakhir SELESAI.             */
    dueAt = lastDone + period;
  }
  if (dueAt <= lastDone) return false;               // data tak konsisten
  bool missed = false;
  while (now >= dueAt && (now - dueAt) > (time_t)SPRAY_GRACE_S) {
    dueAt += period;                                 // lewati tanpa eksekusi susulan
    missed = true;
  }
  if (missed)
    Serial.println("[DITH] ada jadwal terlewat (di luar toleransi) — "
                   "tanpa semprot susulan; siklus dilanjutkan grid berikutnya");
  slotOut = dueAt;
  return (now >= dueAt) && ((now - dueAt) <= (time_t)SPRAY_GRACE_S);
}

/* Simpan waktu SELESAI ke NVS; interval berikutnya dihitung dari sini.
   juga diadopsi otomatis dari server (last_completed_at) bila lebih baru:
   inilah ketahanan pasca-restart/NVS-hilang yang diminta spesifikasi.  */
void controlDithane() {
  switch (dithState) {
    case DITH_IDLE: {
      if (safeMode || !dithArmed() || !configFresh()) return;
      if (millis() - bootMs < BOOT_SETTLE_MS) return;
      time_t slot = 0;
      if (!dithDue(slot)) return;
      /* INTERLOCK: hentikan seluruh relay & fase dosing sebelum semprot */
      stopAllPumps();
      dosingOwner = PUMP_COUNT;
      circState = CIRC_OFF; circT0 = millis();
      dithDur = (cfg.dithDurationS > 0 && cfg.dithDurationS <= (long)MAX_SPRAY_SECONDS)
                ? (uint32_t)cfg.dithDurationS : MAX_SPRAY_SECONDS;
      dithState = DITH_SPRAYING;
      dithT0 = millis(); dithSlot = slot; dithReported = false;
      struct tm t = localTm();
      Serial.printf("[DITH] MULAI semprot %lu s (slot=%lld, %02d:%02d:%02d WIB)\n",
                    (unsigned long)dithDur, (long long)slot,
                    t.tm_hour, t.tm_min, t.tm_sec);
      /* Relay benar-benar dinyalakan DULU; baru notifikasi "mulai"
         dikirim — laporan mengikuti aksi, bukan sebaliknya.            */
      relayWrite(PUMP_DITHANE, true);
      reportPump("dithane", "started", 0);
      break;
    }
    case DITH_SPRAYING: {
      relayWrite(PUMP_DITHANE, true);
      if (millis() - dithT0 >= dithDur * 1000UL) {
        relayWrite(PUMP_DITHANE, false);
        dithState = DITH_IDLE;
        dithMarkDone(time(nullptr));              // ANCHOR interval berikutnya
        struct tm t = localTm();
        Serial.printf("[DITH] SELESAI durasi aktual %lu s pada %02d:%02d:%02d WIB "
                      "(rencana %lu s)\n",
                      (unsigned long)((millis() - dithT0) / 1000UL),
                      t.tm_hour, t.tm_min, t.tm_sec, (unsigned long)dithDur);
        if (!dithReported) {
          dithReported = true;
          /* Notifikasi "selesai": hanya setelah durasi aktual penuh; bila
             firmware restart DI TENGAH semprot, slot belum ditandai dan
             akan diulang utuh — laporan "started" ganda dide-dup di server. */
          reportPump("dithane", "completed", (long)dithDur);
        }
      }
      break;
    }
  }
}

/* =====================================================================
 * 12. POMPA SIRKULASI (lokal; backend belum menyediakan pengaturan)
 * ===================================================================== */

void circReset() {
  circState = CIRC_OFF;
  relayWrite(PUMP_CIRC, false);
  circT0 = millis();                    // mulai fase OFF baru
}

void controlCirculation() {
  uint8_t mode = (cfg.respCircMode != 0xFF) ? cfg.respCircMode : (uint8_t)CIRC_MODE;
  if (mode != circLastMode) { circLastMode = mode; circReset(); }
  if (mode == CIRC_DISABLED || safeMode || sprayActive()) {
    if (circState != CIRC_OFF || relayIsOn(PUMP_CIRC)) circReset();
    return;
  }
  uint32_t onS  = cfg.respCircOnS     ? cfg.respCircOnS     : CIRC_ON_S;
  uint32_t perS = cfg.respCircPeriodS ? cfg.respCircPeriodS : CIRC_PERIOD_S;
  if (onS + 60 >= perS) onS = (perS > 120) ? (perS - 60) : 60;   // fase OFF >= 60 s
  switch (circState) {
    case CIRC_OFF:
      if (millis() - circT0 >= (perS - onS) * 1000UL) {
        relayWrite(PUMP_CIRC, true);
        circState = CIRC_RUNNING; circT0 = millis();
        Serial.println("[PUMP] sirkulasi ON");
      }
      break;
    case CIRC_RUNNING:
      if (millis() - circT0 >= onS * 1000UL) {
        relayWrite(PUMP_CIRC, false);
        circState = CIRC_OFF; circT0 = millis();
        Serial.println("[PUMP] sirkulasi OFF");
      }
      break;
  }
}

/* =====================================================================
 * 13. FAIL-SAFE
 * ===================================================================== */

void evaluateFailsafe() {
  static uint32_t last = 0;
  if (millis() - last < 5000) return;
  last = millis();
  bool was = safeMode;
  safeMode = (uploadFails >= UPLOAD_FAIL_LIMIT);
  if (safeMode && !was) {
    Serial.println("[FAILSAFE] MODE AMAN: upload gagal berulang — semua pompa "
                   "dikunci OFF. Sensor tetap dibaca; sistem pulih otomatis "
                   "bila upload berhasil kembali.");
  }
  if (!safeMode && was) Serial.println("[FAILSAFE] pulih — kontrol pompa aktif ulang");
}

void setupWatchdog() {
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t w;
  memset(&w, 0, sizeof(w));
  w.timeout_ms = 30000;
  w.idle_core_mask = 0;
  w.trigger_panic = true;
  esp_task_wdt_reconfigure(&w);
#else
  esp_task_wdt_init(30, true);
#endif
  esp_task_wdt_add(NULL);
}

/* =====================================================================
 * 14. LCD 20x4 — 3 halaman rotasi, refresh non-blocking
 * ===================================================================== */

void lcdLine(uint8_t row, const char* text) {
  char buf[LCD_COLS + 1];
  snprintf(buf, sizeof(buf), "%-20s", text);
  lcdPtr->setCursor(0, row);
  lcdPtr->print(buf);
}

void setupLCD() {
  if (lcdAddrFound == 0) {                       // coba 0x27 lalu 0x3F
    for (int i = 0; i < 2; i++) {
      Wire.beginTransmission(LCD_ADDR_CAND[i]);
      if (Wire.endTransmission() == 0) { lcdAddrFound = LCD_ADDR_CAND[i]; break; }
    }
    if (lcdAddrFound) nvs.putUChar("lcdaddr", lcdAddrFound);
  }
  if (!lcdAddrFound) { Serial.println("[LCD] tidak terdeteksi — berjalan tanpa LCD"); return; }
  lcdPtr = new LiquidCrystal_I2C(lcdAddrFound, LCD_COLS, LCD_ROWS);
  lcdPtr->init();
  lcdPtr->backlight();
  lcdLine(0, "HYDROTECH SYSTEM");
  lcdLine(1, "Booting ...");
  Serial.printf("[LCD] 20x4 I2C 0x%02X siap\n", lcdAddrFound);
}

void updateLCD() {
  if (!lcdPtr) return;
  uint32_t now = millis();
  if (now - lcdPage0 >= LCD_PAGE_MS) { lcdPage0 = now; lcdPage++; }
  if (now - lcdDraw0 < 250) return;
  lcdDraw0 = now;
  char a[24], b[24], c[24], d[24];
  switch (lcdPage % 3) {
    case 0:
      if (phValid) snprintf(a, sizeof(a), "pH  : %.2f", phValue);
      else         snprintf(a, sizeof(a), "pH  : ERR");
      if (ppmValid) snprintf(b, sizeof(b), "PPM : %.0f", ppmValue);
      else          snprintf(b, sizeof(b), "PPM : ERR");
      snprintf(c, sizeof(c), "WiFi: %s",
               wifiState == WIFI_OK ? "ONLINE" : (wifiState == WIFI_CONNECTING ? "..." : "DOWN"));
      lcdLine(0, "HYDROTECH SYSTEM");
      lcdLine(1, a);
      lcdLine(2, b);
      lcdLine(3, c);
      break;
    case 1:
      snprintf(a, sizeof(a), "AB-Mix : %s", relayIsOn(PUMP_AB)      ? "AKTIF" : "OFF");
      snprintf(b, sizeof(b), "pH-Adj: %s", relayIsOn(PUMP_PHDOWN) ? "AKTIF" : "OFF");
      snprintf(c, sizeof(c), "Dithane: %s", sprayActive()           ? "AKTIF" : "OFF");
      snprintf(d, sizeof(d), "Sirk   : %s", relayIsOn(PUMP_CIRC)    ? "AKTIF" : "OFF");
      lcdLine(0, "STATUS POMPA");
      lcdLine(1, a); lcdLine(2, b); lcdLine(3, c);
      break;
    case 2: {
      const char* sync = safeMode ? "SAFE-MODE!"
                        : (configFresh() ? "SYNC"
                        : (cfg.valid ? "STALE" : "BELUM"));
      snprintf(a, sizeof(a), "Server : %s", sync);
      snprintf(b, sizeof(b), "Sensor : %s", (phValid || ppmValid) ? "OK" : "ERR");
      snprintf(c, sizeof(c), "Dith   : %s", dithArmed() ? "ARMED" : "DISARM");
      struct tm t = localTm();
      snprintf(d, sizeof(d), "Jam    : %02d:%02d WIB", t.tm_hour, t.tm_min);
      lcdLine(0, "SYNC & KEAMANAN");
      lcdLine(1, a); lcdLine(2, b); lcdLine(3, c);
      break;
    }
  }
}

/* =====================================================================
 * 15. LED STATUS (GPIO23)
 *    steady  : aktif + komunikasi normal
 *    lambat  : mencari Wi-Fi
 *    cepat   : gangguan komunikasi (upload pernah gagal)
 *    safe    : mode aman (kunci pompa)
 * ===================================================================== */

void updateLED() {
  uint32_t period = 0;                       // 0 = steady
  if (safeMode)                  period = 1300;
  else if (wifiState != WIFI_OK) period = 1600;
  else if (uploadFails > 0)      period = 300;
  if (period == 0) { if (!ledOn) { digitalWrite(LED_PIN, HIGH); ledOn = true; } return; }
  uint32_t half = period / 2;
  if (millis() - led0 >= half) { led0 = millis(); ledOn = !ledOn; digitalWrite(LED_PIN, ledOn); }
}

/* =====================================================================
 * 16. SERIAL CONFIG — API key TIDAK pernah dicetak
 * ===================================================================== */

void serialHelp() {
  Serial.println("=== HydroTech Serial ===");
  Serial.println(" help | status | sensors | cal");
  Serial.println(" setcal4 <mV_pada_pH4> | setcal7 <mV_pada_pH7>");
  Serial.println(" setvdiv <rasio_pembagi> | setk <TDS_k>");
  Serial.println(" resetcal");
  Serial.println(" arm | disarm   (interlock Dithane)");
  Serial.println(" test <ab|ph|dith|circ> <1..2000 ms>   (uji relay)");
  Serial.println(" upload | sync | lcdscan");
}

void printStatus() {
  Serial.println("---- STATUS ----");
  Serial.printf("wifi=%d safe=%d uploadFails=%u ntpValid=%d rssi=%d\n",
                (int)wifiState, (int)safeMode, uploadFails,
                (int)systemTimeValid(), WiFi.RSSI());
  Serial.printf("pH  raw=%.0f mV -> %.2f (valid=%d)\n", phRawMv, phValue, (int)phValid);
  Serial.printf("PPM raw=%.0f mV -> %.0f (valid=%d)\n", ppmRawMv, ppmValue, (int)ppmValid);
  Serial.printf("cfg valid=%d fresh=%d | pH %.2f..%.2f | PPM %.0f..%.0f\n",
                (int)cfg.valid, (int)configFresh(), cfg.phMin, cfg.phMax, cfg.ppmMin, cfg.ppmMax);
  if (cfg.dithValid)
    Serial.printf("dithane: tiap %ldh, %ld s, mulai %02ld:%02ld WIB "
                  "(interval sejak selesai terakhir)\n",
                  cfg.dithIntervalH, cfg.dithDurationS,
                  cfg.dithStartHM / 60, cfg.dithStartHM % 60);
  time_t ld = dithLastDone();
  struct tm ldt; localtime_r(&ld, &ldt);
  if (ld > 0)
    Serial.printf("lastDone(epoch)=%lld = %04d-%02d-%02d %02d:%02d WIB\n",
                  (long long)ld, ldt.tm_year + 1900, ldt.tm_mon + 1,
                  ldt.tm_mday, ldt.tm_hour, ldt.tm_min);
  else Serial.println("lastDone=(belum ada; jadwal pertama pakai jam mulai)");
  Serial.printf("armed=%d pHdir=%s\n", (int)dithArmed(),
                (PH_PUMP_DIR == PH_RAISE) ? "PENAIK" : "PENURUN");
  Serial.printf("dose AB  evt=%u run=%lums | dose pH evt=%u run=%lums\n",
                doseAB.eventsToday, (unsigned long)doseAB.runMsToday,
                dosePH.eventsToday, (unsigned long)dosePH.runMsToday);
  Serial.print("relay: ");
  for (uint8_t p = 0; p < PUMP_COUNT; p++)
    Serial.printf("%s=%d ", PUMP_NAME[p], (int)relayIsOn(static_cast<PumpId>(p)));
  Serial.println();
}

void handleSerial() {
  while (Serial.available()) {
    String line = Serial.readStringUntil('\n');
    line.trim();
    if (!line.length()) continue;
    String cmd = line; cmd.toLowerCase();

    if (cmd == "help") serialHelp();
    else if (cmd == "status") printStatus();
    else if (cmd == "sensors") { validateSensors(); printStatus(); }
    else if (cmd == "cal") {
      Serial.printf("pH : 4.01 -> %.0f mV | 7.00 -> %.0f mV | pembagi=%.4f\n",
                    CAL_PH4_MV, CAL_PH7_MV, PH_DIVIDER);
      Serial.printf("TDS: k=%.4f | valid pH %.2f..%.2f | PPM %.0f..%.0f\n",
                    TDS_K_VALUE, MIN_PH_VALID, MAX_PH_VALID, MIN_PPM_VALID, MAX_PPM_VALID);
    }
    else if (cmd.startsWith("setcal4 ")) {
      float v = cmd.substring(8).toFloat();
      if (v > 100) { CAL_PH4_MV = v; nvs.putFloat("ph4mv", v); Serial.printf("pH4=%.0f mV\n", v); }
      else Serial.println("nilai tidak valid");
    }
    else if (cmd.startsWith("setcal7 ")) {
      float v = cmd.substring(8).toFloat();
      if (v > 100) { CAL_PH7_MV = v; nvs.putFloat("ph7mv", v); Serial.printf("pH7=%.0f mV\n", v); }
      else Serial.println("nilai tidak valid");
    }
    else if (cmd.startsWith("setvdiv ")) {
      float r = cmd.substring(8).toFloat();
      if (r > 0 && r <= 3) { PH_DIVIDER = r; nvs.putFloat("phdiv", r); Serial.printf("pembagi=%.4f\n", r); }
      else Serial.println("rasio 0<r<=3");
    }
    else if (cmd.startsWith("setk ")) {
      float k = cmd.substring(5).toFloat();
      if (k > 0 && k < 10) { TDS_K_VALUE = k; nvs.putFloat("tdsk", k); Serial.printf("k=%.4f\n", k); }
      else Serial.println("k 0<k<10");
    }
    else if (cmd == "resetcal") {
      CAL_PH4_MV = 1350; CAL_PH7_MV = 2030; PH_DIVIDER = 1; TDS_K_VALUE = 1;
      nvs.putFloat("ph4mv", CAL_PH4_MV); nvs.putFloat("ph7mv", CAL_PH7_MV);
      nvs.putFloat("phdiv", PH_DIVIDER); nvs.putFloat("tdsk", TDS_K_VALUE);
      Serial.println("kalibrasi ke default (isi ulang dengan buffer)");
    }
    else if (cmd == "arm")   { nvs.putBool("armed", true);  Serial.println("ARMED — jadwal Dithane dapat menembak bila konfigurasi segar"); }
    else if (cmd == "disarm"){ nvs.putBool("armed", false); stopAllPumps(); dosingOwner = PUMP_COUNT; Serial.println("DISARMED — semua relay OFF"); }
    else if (cmd.startsWith("test ")) {
      if (safeMode) { Serial.println("SAFE-MODE: uji ditolak"); continue; }
      int sp = cmd.indexOf(' ', 5);
      if (sp < 0) { Serial.println("format: test <ab|ph|dith|circ> <ms>"); continue; }
      String which = cmd.substring(5, sp);
      long ms = cmd.substring(sp + 1).toInt();
      if (ms <= 0 || ms > 2000) { Serial.println("ms harus 1..2000"); continue; }
      PumpId p;
      if      (which == "ab")   p = PUMP_AB;
      else if (which == "ph")   p = PUMP_PHDOWN;
      else if (which == "dith") p = PUMP_DITHANE;
      else if (which == "circ") p = PUMP_CIRC;
      else { Serial.println("pompa tidak dikenal"); continue; }
      Serial.printf("uji relay %s selama %ld ms\n", PUMP_NAME[p], ms);
      relayWrite(p, true);  delay(ms);          // delay kecil ini khusus uji
      relayWrite(p, false);
      Serial.println("uji selesai");
    }
    else if (cmd == "upload") Serial.println(postSensors() ? "upload OK" : "upload gagal (lihat log)");
    else if (cmd == "sync")   Serial.println(getRemoteConfig() ? "config OK" : "config gagal (lihat log)");
    else if (cmd == "lcdscan") {
      Serial.println("Scanning I2C ...");
      for (uint8_t a = 1; a < 127; a++) {
        Wire.beginTransmission(a);
        if (Wire.endTransmission() == 0) Serial.printf("  perangkat I2C di 0x%02X\n", a);
      }
      Serial.println("selesai (umum LCD: 0x27, 0x3F)");
    }
    else Serial.println("perintah tidak dikenal (help)");
  }
}

/* =====================================================================
 * 17. SETUP & LOOP
 * ===================================================================== */

uint32_t lastSensorMs = 0, lastUploadMs = 0, lastCfgMs = 0;

void setup() {
  setupPins();                          // SEMUA RELAY PERTAMA: OFF
  Serial.begin(115200);
  delay(300);
  Serial.println("\n=== HydroTech ESP32 ===");
  Serial.println("[BOOT] semua relay OFF");
  nvs.begin("hydrotech", false);
  CAL_PH4_MV  = nvs.getFloat("ph4mv", CAL_PH4_MV);
  CAL_PH7_MV  = nvs.getFloat("ph7mv", CAL_PH7_MV);
  PH_DIVIDER  = nvs.getFloat("phdiv", PH_DIVIDER);
  TDS_K_VALUE = nvs.getFloat("tdsk",  TDS_K_VALUE);
  lcdAddrFound = nvs.getUChar("lcdaddr", 0);
  doseAB.pump = PUMP_AB;     doseAB.tag = PUMP_NAME[PUMP_AB];
  dosePH.pump = PUMP_PHDOWN; dosePH.tag = PUMP_NAME[PUMP_PHDOWN];
  Wire.begin(I2C_SDA, I2C_SCL);
  setupLCD();
  bootMs = millis(); circT0 = bootMs;
  connectWiFi();
  setupWatchdog();
  if (!nvs.getBool("armed", false))
    Serial.println("[SAFETY] Dithane DISARMED. Ketik 'arm' untuk aktivasi "
                   "penyemprotan (setelah pemasangan fisik diverifikasi).");
  Serial.println("[BOOT] siap");
}

void loop() {
  esp_task_wdt_reset();
  uint32_t now = millis();

  maintainWiFi();

  /* NTP sekali per sesi Wi-Fi (untuk slot Dithane; server menetapkan
     waktu data yang dikirim ke DB).                                  */
  if (wifiState == WIFI_OK && !ntpStarted) {
    ntpStarted = true;
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    setenv("TZ", "WIB-7", 1);           // Asia/Jakarta, UTC+7
    tzset();
    if (systemTimeValid()) {
      struct tm t = localTm();
      Serial.printf("[TIME] %02d-%02d-%02d %02d:%02d WIB (lokal)\n",
                    t.tm_year + 1900, t.tm_mon + 1, t.tm_mday, t.tm_hour, t.tm_min);
    } else Serial.println("[TIME] NTP sinkron berjalan (Dithane menunggu jam valid)");
  }

  if (now - lastSensorMs >= SENSOR_INTERVAL_MS) { lastSensorMs = now; validateSensors(); }

  if (wifiState == WIFI_OK && now - lastUploadMs >= UPLOAD_INTERVAL_MS) {
    lastUploadMs = now;
    if (postSensors()) { uploadFails = 0; }
    else uploadFails = (uint16_t)min((int)(uploadFails + 1), 250);
  }

  if (wifiState == WIFI_OK && now - lastCfgMs >= CONFIG_INTERVAL_MS) {
    lastCfgMs = now;
    if (getRemoteConfig()) Serial.println("[CFG] konfigurasi tersinkron");
  }

  if (!safeMode) {
    controlDithane();
    controlCirculation();
    controlABMix();
    controlPHDown();
  } else {
    stopAllPumps();
    dosingOwner = PUMP_COUNT;
  }

  updateLED();
  updateLCD();
  evaluateFailsafe();
  handleSerial();
}
