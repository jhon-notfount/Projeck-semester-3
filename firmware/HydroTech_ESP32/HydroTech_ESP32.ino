/*
 * ===================================================================
 *  HydroTech_ESP32.ino  â€”  Firmware monitoring & kontrol hidroponik
 * ===================================================================
 *
 *  KONTRAK BACKEND EURISA â€” diterjemahkan dari audit kode HydroTech
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
 *   - Timestamp ditetapkan SERVER (Asia/Jakarta) â€” firmware TIDAK
 *     mengirim timestamp. NTP hanya untuk jadwal Dithane & counter harian.
 *   - Heartbeat perangkat = sensor_readings tersimpan; batas online
 *     300 s (api/device/status.php) => UPLOAD_INTERVAL_MS < 300000 ms.
 *   - Endpoint laporan pompa: api/device/report.php (POST {pump, state,
 *     duration_s, device_seen}, X-API-Key). Seluruh pompa dikendalikan
 *     LOKAL dengan parameter aman. Endpoint 404 => otomatis menonaktifkan
 *     pelaporan (tidak mengganggu sistem).
 *   - LOGIKA POMPA (3 kategori: rendah/normal/tinggi):
 *       PPM  : < min â†’ dosis terus (bertahap) HINGGA masuk rentang;
 *              normal â†’ pompa mati; > maks â†’ pompa mati + peringatan.
 *       pH   : arah cairan konfigurable (PH_PUMP_DIR; default PENAIK:
 *              pH < min â†’ dosis hingga masuk rentang; normal â†’ mati;
 *              pH > maks â†’ pompa tetap mati + peringatan server).
 *       Notifikasi kategori ditangani SERVER per PERUBAHAN KATEGORI
 *       (rendah/normal/tinggi) â€” bukan per pembacaan (PATCH C v2).
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
#include <WebServer.h>          // halaman setup (mode AP)
#include <DNSServer.h>          // captive portal redirect
#include <Wire.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include "esp_task_wdt.h"
#include "time.h"
#include "WiFiClientSecure.h"

/* =====================================================================
 *  1. KONFIGURASI â€” ubah hanya blok ini untuk tiap instalasi
 * ===================================================================== */

/* --- Wi-Fi + API (JANGAN commit kredensial nyata) ------------------- */
const char* WIFI_SSID      = "ISI-SSID";
const char* WIFI_PASS      = "ISI-PASSWORD";
const char* API_BASE_URL   = "http://192.168.1.10:8000/api/sensor/ingest.php";
const char* API_REPORT_URL = "";                 // kosongkan bila patch report belum dipasang
const char* API_KEY        = "ISI-ESP-API-KEY";  // sama dengan config/esp_key.txt / env
#define API_USE_TLS  0                           // 1 bila API_BASE_URL https://
/* HTTPS produksi: isi TLS_CA_CERT dengan root-CA PEM (baris diganti \n).
   Tanpa CA dan tanpa TLS_DEV_MODE, handshake ditolak â€” verifikasi
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
const uint8_t ADC_PH_PIN  = 34;    // AO sensor pH  (ADC1 â€” aman dengan Wi-Fi)
const uint8_t ADC_PPM_PIN = 35;    // AO sensor TDS (ADC1)
const uint8_t LED_PIN     = 23;
const int     I2C_SDA     = 21;
const int     I2C_SCL     = 22;
const uint8_t LCD_ADDR_CAND[2] = { 0x27, 0x3F };  // alamat umum LCD 20x4
const uint8_t LCD_COLS = 20, LCD_ROWS = 4;
const uint32_t LCD_PAGE_MS = 5000;                // pergantian halaman LCD

/* --- Kalibrasi default (bisa ditimpa via Serial; tersimpan di NVS) --- */
/* pH: konversi two-point. Nilai = mV AO modul SETELAH pembagi tegangan
   dikembalikan (PH_DIVIDER = Vpin/Vmod). BUKAN rumus universal â€”
   kalibrasi wajib memakai buffer pH 4,01 dan 7,00 per unit modul.     */
float CAL_PH4_MV  = 1350.0f;    // mV modul pada buffer pH 4,01
float CAL_PH7_MV  = 2030.0f;    // mV modul pada buffer pH 7,00
float PH_DIVIDER  = 1.0f;       // Vpin = Vout modul * PH_DIVIDER
/* TDS: polinomial Gravity pada 25 C tetap. Sensor suhu belum ada â€”
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

/* === ARAH pompa pH (GPIO17) â€” WAJIB sesuai cairan yang terpasang ===
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
 *  4. RELAY â€” abstraksi polaritas; semua pompa OFF saat boot/darurat
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

/* PPM (rumus Gravity) pada 25 C â€” tanpa kompensasi suhu aktual.        */
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
    Serial.println("[TLS] setInsecure (DEV) â€” JANGAN untuk produksi");
  } else {
    /* CA kosong: gagalkan handshake agar tidak ada koneksi "diam-diam" */
    Serial.println("[TLS] CA belum diset â€” koneksi HTTPS ditolak (set TLS_CA_CERT atau TLS_DEV_MODE)");
    while (true) { delay(10000); }                // jangan jalankan pompa dengan kredensial tak terverifikasi
  }
}

/* =====================================================================
 *  6c. Wi-Fi SETUP (AP + Captive Portal + halaman mobile)
 *  Modul TERPISAH dari logika monitoring/pompa. Diaktifkan saat kredensial
 *  belum valid (belakang mula) atau lewat Serial ('setup'). Menyediakan:
 *    - AP "hydrotech" (terbuka) + DNS wild-card (captive portal)
 *      -> HP/laptop otomatis diarahkan ke halaman setup;
 *    - /api/scan & /api/scanresult  : pemindaian WiFi sungguhan (non-blocking);
 *    - /api/connect & /api/status   : validasi + simpan NVS + koneksi STA
 *      (AP DIADAPun tetap hidup selama uji supaya status sukses/gagal bisa
 *      dilain di halaman, lalu dinonaktifkan setelah "Selesai"/120 dtk);
 *    - LCD menampilkan SSID "hydrotech", IP AP, dan status koneksi.
 * ===================================================================== */

/* Deklarasi maju agar tidak bergantung pada pozisi torino di file:        */
void updateLED();
void updateLCD();
void handleSerial();
void stopAllPumps();

struct DeviceCreds {
  String ssid, wpass, url, key;
};
DeviceCreds cred;

DNSServer  dnsServer;
WebServer* setupSrv = nullptr;

const char*  AP_SSID      = "hydrotech";   // nama AP sesuai spesifikasi
const char*  AP_PASS      = "";            // AP terbuka (lebih cepat dipakai HP)
bool         portalActive = false;
String       portalApIp   = "192.168.4.1";
uint32_t     portalStartMs   = 0;
uint32_t     portalActivityMs = 0;         // di-set tiap request/kejadian
bool         portalExitReq   = false;
uint32_t     portalExitMs    = 0;
const uint32_t PORTAL_IDLE_MS    = 300000; // 5 menit tanpa aktivitas -> exit
const uint32_t PORTAL_GRACE_MS   = 120000; // 2 menit setelah sukses -> exit

/* Posisi percobaan koneksi STA (dari halaman "Hubungkan")              */
enum SetupConnState : uint8_t { SC_IDLE, SC_CONNECTING, SC_CONNECTED, SC_FAILED };
SetupConnState connState = SC_IDLE;
String   connSsid = "", connMsg = "", connIp = "";
uint32_t connStartMs = 0, connSuccessMs = 0;
const uint32_t CONNECT_TIMEOUT_MS = 30000;

bool credsValid() {
  bool urlOk = cred.url.startsWith("http://") || cred.url.startsWith("https://");
  return cred.ssid.length() > 0 && cred.key.length() >= 8 &&
         cred.key.length() <= 120 && urlOk;
}

String maskKey(const String& k) {
  if (k.length() <= 8) return "****";
  return k.substring(0, 4) + "..." + k.substring(k.length() - 4);
}

void loadCreds() {
  cred.ssid  = nvs.getString("c_ssid",  WIFI_SSID);
  cred.wpass = nvs.getString("c_wpass", WIFI_PASS);
  cred.url   = nvs.getString("c_url",   API_BASE_URL);
  cred.key   = nvs.getString("c_key",   API_KEY);
  Serial.printf("[PROV] kred: ssid='%s' url=%s key=%s\n",
                cred.ssid.c_str(), cred.url.c_str(), maskKey(cred.key).c_str());
}

void saveCreds() {
  nvs.putString("c_ssid",  cred.ssid);
  nvs.putString("c_wpass", cred.wpass);
  nvs.putString("c_url",   cred.url);
  nvs.putString("c_key",   cred.key);
}

/* escape JSON minimalis (SSID bisa berisi kutip/backslash/emoji)        */
String jsonEsc(const String& s) {
  String r; r.reserve(s.length() + 8);
  for (uint16_t i = 0; i < s.length(); i++) {
    char c = s.charAt(i);
    switch (c) {
      case '"': r += "\\\""; break;
      case '\\': r += "\\\\"; break;
      case '\b': r += "\\b"; break;
      case '\f': r += "\\f"; break;
      case '\n': r += "\\n"; break;
      case '\r': r += "\\r"; break;
      case '\t': r += "\\t"; break;
      default:
        if ((uint8_t)c < 0x20) { char b[8]; snprintf(b, sizeof(b), "\\u%04X", (uint8_t)c); r += b; }
        else r += c;
    }
  }
  return r;
}

/* ---------------------------------------------------------------------
 * HALAMAN SETUP (tema gelap HydroTech, responsif HP & laptop)
 * --------------------------------------------------------------------- */
const char PORTAL_PAGE[] = R"html(
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HydroTech Â· Setup WiFi</title><style>
*{box-sizing:border-box}
body{margin:0;background:#0d1526;color:#e6f0ff;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;min-height:100vh}
.wrap{max-width:430px;margin:0 auto;padding:20px 14px 40px}
.brand{display:flex;align-items:center;gap:12px;margin:6px 0 16px}
.logo{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#2e7d32,#16a34a);display:flex;align-items:center;justify-content:center;font-size:20px}
.brand b{font-size:17px;display:block}.brand small{color:#9fb3d9;font-size:12px}
.card{background:#101b31;border:1px solid #1d2c4f;border-radius:14px;padding:16px}
h2{font-size:14px;margin:0 0 10px;color:#9fb3d9;text-transform:uppercase;letter-spacing:.08em}
.sechead{display:flex;justify-content:space-between;align-items:center;margin:18px 0 10px}
.sechead h2{margin:0}
button{font-family:inherit}
.ghost{background:#182645;color:#cfe3ff;border:1px solid #24365e;border-radius:8px;padding:7px 12px;font-size:12px;cursor:pointer}
.ghost:disabled{opacity:.5;cursor:wait}
.netlist{max-height:170px;overflow-y:auto;border:1px solid #1d2c4f;border-radius:10px;margin-bottom:8px}
.net{display:flex;justify-content:space-between;align-items:center;width:100%;background:none;border:0;color:#e6f0ff;padding:10px 12px;cursor:pointer;border-bottom:1px solid #16233f;text-align:left;font-size:14px}
.net:hover,.net.sel{background:#16254a}
.net:last-child{border-bottom:0}
.sig{color:#9fb3d9;font-size:12px;white-space:nowrap;margin-left:8px}
.open{color:#16a34a;font-size:12px}
.muted{padding:12px;color:#7b90b8;font-size:13px}
.field{margin:10px 0}
label{display:block;font-size:12px;color:#9fb3d9;margin-bottom:5px}
input[type=text],input[type=password]{width:100%;background:#0b1322;border:1px solid #24365e;border-radius:8px;padding:10px 12px;color:#e6f0ff;font-size:14px}
input:disabled{opacity:.45}
.pw{display:flex;gap:8px}
.chk{display:flex;align-items:center;gap:8px;font-size:13px;color:#cfe3ff;margin:6px 0 2px}
.chk input{width:16px;height:16px}
.msg{padding:9px 11px;border-radius:8px;font-size:13px;margin:10px 0;line-height:1.4;display:none}
.msg.err{display:block;background:#3a1622;border-left:3px solid #e05260;color:#ffd7db}
.msg.ok{display:block;background:#123322;border-left:3px solid #2ecc71;color:#c8f7dc}
.msg.wr{n display:block;background:#3a2d12;border-left:3px solid #e2a03f;color:#ffeec4}
.primary{width:100%;padding:13px;border:0;border-radius:10px;background:#2e7d32;color:#fff;font-size:15px;font-weight:600;cursor:pointer;margin-top:6px}
.primary:disabled{opacity:.55;cursor:wait}
.stage{margin-top:12px}
.spin{display:inline-block;width:14px;height:14px;border:2px solid #24365e;border-top-color:#16a34a;border-radius:50%;animation:sp 1s linear infinite;vertical-align:-2px;margin-right:8px}
@keyframes sp{to{transform:rotate(360deg)}}
.stage .okcard,.stage .failcard{border-radius:10px;padding:12px;font-size:13px;line-height:1.55}
.okcard{background:#123322;border:1px solid #1f5c39}
.failcard{background:#3a1622;border:1px solid #5c2830}
.link{color:#7fd8a0;word-break:break-all}
.foot{color:#5a6d92;font-size:11px;text-align:center;margin-top:14px}
</style></head><body>
<div class="wrap">
  <div class="brand">
    <div class="logo">âš¡</div>
    <div><b>HydroTech</b><small>Setup &middot; ESP32</small></div>
  </div>
  <div class="card">
    <div class="sechead"><h2>Pilih Jaringan WiFi</h2>
      <button id="refreshBtn" class="ghost" type="button">&#x27F3; Pindai</button>
    </div>
    <div id="netList" class="netlist"><div class="muted">Memindai jaringan di sekitar ...</div></div>
    <div class="field"><label>SSID WiFi</label>
      <input id="ssid" maxlength="32" placeholder="pilih di daftar atau ketik manual">
    </div>
    <div class="field"><label>Password WiFi</label>
      <input id="pass" type="password" maxlength="63" autocomplete="off">
    </div>
    <label class="chk"><input id="nopass" type="checkbox"> Tanpa Password (jaringan terbuka)</label>

    <div class="sechead"><h2>Server HydroTech</h2></div>
    <div class="field"><label>API Base URL</label>
      <input id="url" maxlength="140" placeholder="http://192.168.1.10:8000/api/sensor/ingest.php">
    </div>
    <div class="field"><label>API Key</label>
      <div class="pw">
        <input id="key" type="password" maxlength="120" autocomplete="off">
        <button id="eyeKey" class="ghost" type="button">Tampilkan</button>
      </div>
    </div>

    <div id="msg" class="msg"></div>
    <button id="goBtn" class="primary">Hubungkan</button>
    <div id="stage" class="stage"></div>
    <div class="foot">HydroTech ESP32 &middot; halaman konfigurasi jaringan</div>
  </div>
</div>
<script>
const $ = (id) => document.getElementById(id);
const nets = [];           // [{ssid,rssi,secure}]
let chosen = "";
let pollTimer = null;

function esc(t) {
  return String(t).replace(/&/g, "&amp;").replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function bar(rssi) {
  const d = rssi >= -55 ? 4 : rssi >= -67 ? 3 : rssi >= -78 ? 2 : 1;
  return "\u2581".repeat(d) + "\u2581".repeat(4 - d).replace(/./g, " ");
}
function showMsg(text, cls) {
  const m = $("msg");
  if (!text) { m.className = "msg"; m.hidden = true; return; }
  m.className = "msg " + cls; m.hidden = false; m.textContent = text;
}
function renderNets() {
  if (!nets.length) { $("netList").innerHTML = '<div class="muted">Tidak ada jaringan ditemukan. Tekan Pindai.</div>'; return; }
  $("netList").innerHTML = nets.map((n, i) =>
    `<button class="net${n.ssid === chosen ? " sel" : ""}" data-i="${i}" type="button">
       <span>${esc(n.ssid)}${n.secure ? "" : ' <span class="open">(terbuka)</span>'}</span>
       <span class="sig">${bar(n.rssi)} ${n.rssi} dBm</span>
     </button>`).join("");
}
$("netList").addEventListener("click", (e) => {
  const b = e.target.closest(".net"); if (!b) return;
  const n = nets[+b.dataset.i]; if (!n) return;
  chosen = n.ssid; $("ssid").value = n.ssid;
  if (!n.secure) { $("nopass").checked = true; applyNopass(); }
  renderNets();
  (n.secure ? $("pass") : $("goBtn")).focus();
});
async function rescan() {
  const btn = $("refreshBtn"); btn.disabled = true; btn.innerHTML = "<span class='spin'></span> Memindai...";
  $("netList").innerHTML = '<div class="muted">Memindai jaringan di sekitar ...</div>';
  try {
    await fetch("/api/scan", { cache: "no-store" });
    for (let t = 0; t < 40; t++) {                 // tunggu pemindaian nyata di ESP
      const r = await (await fetch("/api/scanresult", { cache: "no-store" })).json();
      if (r.scanning) { await new Promise(res => setTimeout(res, 700)); continue; }
      nets.length = 0;
      (r.networks || []).forEach(n => nets.push(n));
      renderNets();
      break;
    }
  } catch (e) { showMsg("Gagal memindai jaringan - coba lagi.", "err"); }
  btn.disabled = false; btn.innerHTML = "&#x27F3; Pindai";
}
$("refreshBtn").addEventListener("click", rescan);

function applyNopass() {
  const off = $("nopass").checked;
  $("pass").disabled = off;
  if (off) $("pass").value = "";
  showMsg("", null);
}
$("nopass").addEventListener("change", applyNopass);
$("eyeKey").addEventListener("click", () => {
  const k = $("key");
  const show = k.type === "password";
  k.type = show ? "text" : "password";
  $("eyeKey").textContent = show ? "Sembunyikan" : "Tampilkan";
});

function validate() {
  const ssid = $("ssid").value.trim(), pass = $("pass").value,
        url  = $("url").value.trim(), key = $("key").value.trim();
  if (!ssid) return "Pilih jaringan WiFi dari daftar atau isi SSID.";
  const open = $("nopass").checked;
  if (!open && pass.length < 8) return "Password WiFi minimal 8 karakter (atau centang Tanpa Password).";
  if (!open && !pass) return "Password wajib diisi, atau centang Tanpa Password.";
  if (!(url.startsWith("http://") || url.startsWith("https://")) || url.length < 18)
    return "API Base URL tidak valid (contoh: http://192.168.1.10:8000/api/sensor/ingest.php).";
  if (key.length < 8) return "API Key minimal 8 karakter.";
  return null;
}
$("goBtn").addEventListener("click", async () => {
  const err = validate();
  if (err) { showMsg(err, "err"); return; }
  showMsg("", null);
  const btn = $("goBtn"); btn.disabled = true;
  try {
    const body = new URLSearchParams({
      ssid: $("ssid").value.trim(),
      pass: $("pass").value,
      nopass: $("nopass").checked ? "1" : "0",
      url: $("url").value.trim(),
      key: $("key").value.trim(),
    });
    const r = await (await fetch("/api/connect", { method: "POST", body })).json();
    if (!r.ok) throw new Error(r.error || "Server menolak data konfigurasi.");
    startPolling(r);
  } catch (e) {
    showMsg(e.message || "Gagal mengirim konfigurasi.", "err");
    btn.disabled = false;
  }
});
function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
function startPolling() {
  $("stage").innerHTML = '<div class="okcard"><span class="spin"></span>Menghubungkan ke WiFi... jangan tutup halaman ini.</div>';
  stopPolling();
  pollTimer = setInterval(async () => {
    try {
      const s = await (await fetch("/api/status", { cache: "no-store" })).json();
      if (s.state === "connecting") {
        $("stage").innerHTML = `<div class="okcard"><span class="spin"></span>Menghubungkan ke ${esc(s.ssid || "")}... (${s.elapsed || 0} dtk)</div>`;
      } else if (s.state === "connected") {
        stopPolling();
        $("stage").innerHTML =
          `<div class="okcard"><b>&#10004; Terhubung ke ${esc(s.ssid)}</b><br>
           IP ESP32 di jaringan baru: <span class="link">${esc(s.ip)}</span><br>
           Satukan perangkat ini (HP/laptop) ke WiFi tersebut, lalu buka Dashboard:<br>
           <span class="link">http://${esc(s.ip)}:8000</span><br><br>
           <button class="ghost" id="doneBtn" type="button">Selesai &amp; Tutup AP</button></div>`;
        $("doneBtn").addEventListener("click", async () => { try { await fetch("/api/closeap", { method: "POST" }); } catch (e) {} $("stage").innerHTML = ""; showMsg("Konfigurasi selesai. Perangkat berjalan normal.", "ok"); });
      } else if (s.state === "failed") {
        stopPolling();
        $("goBtn").disabled = false;
        $("stage").innerHTML =
          `<div class="failcard"><b>&#10008; Koneksi gagal</b><br>${esc(s.reason || "-")}<br><br>
           <button class="ghost" id="retryBtn" type="button">Coba Lagi</button></div>`;
        $("retryBtn").addEventListener("click", () => { $("stage").innerHTML = ""; });
      }
    } catch (e) { /* AP dapat berhenti setelah sukses: biarkan halaman tenang */ }
  }, 1000);
}
applyNopass();
rescan();
</script></body></html>
)html";

/* ---------------------------------------------------------------------
 * ROUTE HANDLERS
 * --------------------------------------------------------------------- */

void handleRoot() {
  portalActivityMs = millis();
  setupSrv->send(200, "text/html", PORTAL_PAGE);
}

/* Probe captive portal berbagai OS: alihkan semua ke halaman setup      */
void handleProbe() {
  setupSrv->sendHeader("Location", String("http://") + portalApIp + "/", true);
  setupSrv->send(302, "text/plain", "");
}

void handleScanStart() {
  portalActivityMs = millis();
  WiFi.scanNetworks(true, false, false, 300);      // pemindaian sungguhan, async
  setupSrv->send(200, "application/json", "{\"scanning\":true}");
}

void handleScanResult() {
  portalActivityMs = millis();
  int8_t result = WiFi.scanComplete();
  if (result == WIFI_SCAN_RUNNING || result < 0) {
    setupSrv->send(200, "application/json",
                   result == WIFI_SCAN_RUNNING ? "{\"scanning\":true}"
                                               : "{\"scanning\":false,\"networks\":[]}");
    return;
  }
  /* Dedupe per SSID (pertahankan sinyal terkuat), buang SSID kosong.     */
  String out = "{\"scanning\":false,\"networks\":[";
  bool noSsid = (result <= 0);
  for (int i = 0; i < result && !noSsid; i++) {
    String ssid = WiFi.SSID(i);
    if (ssid.length() == 0) continue;
    int32_t rssi = WiFi.RSSI(i);
    bool best = true;
    for (int j = 0; j < result; j++) {
      if (j != i && WiFi.SSID(j) == ssid && WiFi.RSSI(j) > rssi) { best = false; break; }
    }
    if (!best) continue;
    bool secure = WiFi.encryptionType(i) != WIFI_AUTH_OPEN;
    if (out.endsWith("[") == false) out += ",";
    out += "{\"ssid\":\"" + jsonEsc(ssid) + "\",\"rssi\":" + String((int)rssi) +
           ",\"secure\":" + (secure ? "true" : "false") + "}";
  }
  out += "]}";
  WiFi.scanDelete();
  setupSrv->send(200, "application/json", out);
}

/* POST /api/connect â€” validasi, simpan NVS, mulai koneksi STA.
   AP TETAP hidup selama uji agar halaman dapat menampilkan status; AP
   dimatikan setelah sukses dikonfirmasi (tombol Selesai / grace).       */
void handleConnect() {
  portalActivityMs = millis();
  if (connState == SC_CONNECTING) {
    setupSrv->send(200, "application/json",
                   "{\"ok\":false,\"error\":\"Koneksi sedang berjalan - tunggu hasilnya.\"}");
    return;
  }
  String ssid  = setupSrv->arg("ssid");
  String pass  = setupSrv->arg("pass");
  String nopass= setupSrv->arg("nopass");
  String url   = setupSrv->arg("url");
  String key   = setupSrv->arg("key");
  bool openNet = (nopass == "1");

  if (ssid.length() == 0 || ssid.length() > 32) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"SSID tidak valid.\"}"); return;
  }
  if (!openNet && pass.length() < 8) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"Password minimum 8 karakter (atau centang Tanpa Password).\"}"); return;
  }
  String cleanUrl = url; cleanUrl.trim();
  if (!(cleanUrl.startsWith("http://") || cleanUrl.startsWith("https://")) || cleanUrl.length() < 18) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"API Base URL tidak valid.\"}"); return;
  }
  key.trim();
  if (key.length() < 8 || key.length() > 120) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"API Key harus 8..120 karakter.\"}"); return;
  }

  /* Simpan permanen (NVS) SEBELUM koneksi - Legacy komfirnosti: sudah
     ada power jenis ini meski listrik padam saat koneksi berlangsung.  */
  cred.ssid = ssid;
  cred.wpass = openNet ? String("") : pass;
  cred.url = cleanUrl;
  cred.key = key;
  saveCreds();

  /* Mulai koneksi STA; AP tetap jalan supaya halaman bisa konfirmasi.   */
  WiFi.mode(WIFI_AP_STA);
  WiFi.setSleep(false);
  WiFi.disconnect();
  WiFi.begin(cred.ssid.c_str(), cred.wpass.c_str());
  connState = SC_CONNECTING;
  connSsid = ssid;
  connMsg = "Menghubungkan ke " + ssid;
  connIp = "";
  connStartMs = millis();
  wifiState = WIFI_CONNECTING;
  Serial.printf("[SETUP] koneksi: ssid='%s' open=%d url=%s key=%s\n",
                ssid.c_str(), (int)openNet, cleanUrl.c_str(), maskKey(key).c_str());
  setupSrv->send(200, "application/json",
                 "{\"ok\":true,\"ssid\":\"" + jsonEsc(ssid) + "\"}");
}

String wlFailReason(wl_status_t s) {
  switch (s) {
    case WL_NO_SSID_FOUND: return "SSID tidak ditemukan di sekitar.";
#ifdef WL_WRONG_PASSWORD
    case WL_WRONG_PASSWORD: return "Password WiFi salah.";
#endif
    case WL_CONNECT_FAILED: return "Koneksi ditolak router.";
    case WL_IDLE_STATUS: return "Router menjawab lambat / sinyal lemah.";
    default: return "Gagal menghubungkan (kode " + String((int)s) + ").";
  }
}

void handleStatus() {
  portalActivityMs = millis();
  if (connState == SC_CONNECTING) {
    wl_status_t s = WiFi.status();
    uint32_t elapsed = (millis() - connStartMs) / 1000UL;
    if (s == WL_CONNECTED) {
      connIp = WiFi.localIP().toString();
      connState = SC_CONNECTED;
      connSuccessMs = millis();
      connMsg = "Terhubung ke " + connSsid;
      Serial.printf("[SETUP] TERHUBUNG ip=%s â€” AP + halaman masih hidup Â±2 menit\n",
                    connIp.c_str());
    } else if (elapsed >= CONNECT_TIMEOUT_MS / 1000UL
               || s == WL_CONNECT_FAILED || s == WL_NO_SSID_FOUND) {
      if (s == WL_CONNECT_FAILED || s == WL_NO_SSID_FOUND
#ifdef WL_WRONG_PASSWORD
          || s == WL_WRONG_PASSWORD
#endif
         ) connMsg = wlFailReason(s);
      else connMsg = "Waktu koneksi habis (" + String(elapsed) + " s). " + wlFailReason(s);
      connState = SC_FAILED;
      Serial.printf("[SETUP] gagal: %s\n", connMsg.c_str());
    } else {
      setupSrv->send(200, "application/json",
                     "{\"state\":\"connecting\",\"ssid\":\"" + jsonEsc(connSsid) +
                     "\",\"elapsed\":" + String((unsigned long)elapsed) + "}");
      return;
    }
  }
  if (connState == SC_CONNECTED) {
    setupSrv->send(200, "application/json",
                   "{\"state\":\"connected\",\"ssid\":\"" + jsonEsc(connSsid) +
                   "\",\"ip\":\"" + jsonEsc(connIp) + "\"}");
    return;
  }
  if (connState == SC_FAILED) {
    setupSrv->send(200, "application/json",
                   "{\"state\":\"failed\",\"reason\":\"" + jsonEsc(connMsg) + "\"}");
    return;
  }
  setupSrv->send(200, "application/json", "{\"state\":\"idle\"}");
}

void handleCloseAp() {
  portalActivityMs = millis();
  portalExitReq = true;               // jalankan stopSetupMode setelah respons
  portalExitMs  = millis() + 400;
  connState = SC_IDLE;                // konfigurasi sudah tersimpan di NVS
  setupSrv->send(200, "application/json", "{\"ok\":true}");
}

/* ---------------------------------------------------------------------
 * SIKLUS MODE SETUP
 * --------------------------------------------------------------------- */

void startSetupMode() {
  if (portalActive) return;
  stopAllPumps();
  dosingOwner = PUMP_COUNT;
  portalActive = true;
  connState = SC_IDLE; connMsg = ""; connIp = ""; connSsid = "";
  portalStartMs = portalActivityMs = millis();
  portalExitReq = false;

  WiFi.softAPConfig(IPAddress(192, 168, 4, 1), IPAddress(192, 168, 4, 1),
                    IPAddress(255, 255, 255, 0));
  WiFi.softAP(AP_SSID, AP_PASS, 6);
  portalApIp = WiFi.softAPIP().toString();

  dnsServer.setErrorReplyCode(DNSReplyCode::NoError);
  dnsServer.start(53, "*", WiFi.softAPIP());      // captive portal: domain apa pun

  setupSrv = new WebServer(80);
  setupSrv->on("/", handleRoot);
  /* probe / deteksi captive portal OS ponsel & laptop                   */
  setupSrv->on("/generate_204", handleProbe);
  setupSrv->on("/gen204", handleProbe);
  setupSrv->on("/hotspot-detect.html", handleProbe);
  setupSrv->on("/library/test/success.html", handleProbe);
  setupSrv->on("/success.txt", handleProbe);
  setupSrv->on("/ncsi.txt", handleProbe);
  setupSrv->on("/connecttest.txt", handleProbe);
  setupSrv->on("/fwlink", handleProbe);
  setupSrv->on("/redirect", handleProbe);
  setupSrv->on("/api/scan", handleScanStart);
  setupSrv->on("/api/scanresult", handleScanResult);
  setupSrv->on("/api/connect", handleConnect);
  setupSrv->on("/api/status", handleStatus);
  setupSrv->on("/api/closeap", handleCloseAp);
  setupSrv->onNotFound(handleProbe);              // URL apa pun -> halaman setup
  setupSrv->begin();

  Serial.printf("[SETUP] AP '%s' aktif (terbuka) â€” buka http://%s/ (IP di LCD)\n",
                AP_SSID, portalApIp.c_str());
  Serial.println("[SETUP] monitoring & pompa dihentikan sementara (modul terpisah).");
}

void stopSetupMode() {
  if (!portalActive) return;
  dnsServer.stop();
  if (setupSrv) { setupSrv->stop(); delete setupSrv; setupSrv = nullptr; }
  WiFi.softAPdisconnect(true);
  portalActive = false;
  connState = SC_IDLE;
  Serial.print("[SETUP] portal ditutup. ");
  if (credsValid()) {
    WiFi.mode(WIFI_STA);
    WiFi.begin(cred.ssid.c_str(), cred.wpass.c_str());
    wifiState = WIFI_CONNECTING;
    wifiConnectStart = millis();
    Serial.println("Mode normal lanjut â€” koneksi ke Wi-Fi tersimpan.");
  } else {
    Serial.println("kredensial belum lengkap â€” restart untuk membuka setup lagi.");
    delay(500);
    ESP.restart();
  }
}

/* dipanggil dari loop() selama portalActive                             */
void portalLoop() {
  if (setupSrv) setupSrv->handleClient();
  dnsServer.processNextRequest();

  updateLED();
  updateLCD();
  handleSerial();

  /* sukses: beri waktu pengguna membaca IP baru, lalu tutup AP.         */
  if (connState == SC_CONNECTED && millis() - connSuccessMs >= PORTAL_GRACE_MS
      && !portalExitReq) {
    portalExitReq = true;
    portalExitMs = millis();
  }
  if (portalExitReq && millis() >= portalExitMs) {
    portalExitReq = false;
    stopSetupMode();
    return;
  }
  /* idle 5 menit tanpa perkara halaman/S: hindari perangkat "nyangkut". */
  if (millis() - portalActivityMs >= PORTAL_IDLE_MS) {
    Serial.println("[SETUP] idle 5 menit â€” keluar mode setup");
    stopSetupMode();
    return;
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
  /* Tanpa kredensial valid (mode AP/setup aktif): jangan mencoba SSID
     placeholder â€” portal setup yang memandu pengguna.                 */
  if (!credsValid()) { wifiState = WIFI_DOWN; return; }
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
        Serial.println("[WiFi] timeout â€” jeda lalu coba lagi");
      }
      break;
    case WIFI_OK:
      if (WiFi.status() != WL_CONNECTED) {
        wifiState = WIFI_CONNECTING;
        wifiConnectStart = millis();
        Serial.println("[WiFi] terputus â€” reconnect");
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
  return mktime(&t);   // TZ aktif = WIB-7 â†’ hasil epoch akurat WIB
}

bool postSensors() {
  if (wifiState != WIFI_OK) return false;
  if (!phValid && !ppmValid) {
    Serial.println("[HTTP] sensor tidak valid â€” data tidak dikirim "
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
  if (!http.begin(client, cred.url)) { Serial.println("[HTTP] begin gagal"); return false; }
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", cred.key);
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
    Serial.printf("[HTTP] POST gagal code=%d â€” TIDAK diulang supaya "
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
  if (!http.begin(client, cred.url)) return false;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", cred.key);
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
        } else Serial.println("[CFG] ambang pH invalid â€” diabaikan");
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
             dari NVS lokal â†’ diadopsi.                                  */
          time_t serverDone = parseServerLocalEpoch(
              c["dithane"]["last_completed_at"] | "");
          if (serverDone > 0 && serverDone > dithLastDone()) {
            dithMarkDone(serverDone);
            Serial.println("[CFG] anchor Dithane diadopsi dari server "
                           "(last_completed_at lebih baru)");
          }
        } else Serial.println("[CFG] konfig dithane tidak valid â€” diabaikan");
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
 *  9. LAPORAN EKSEKUSI POMPA (endpoint api/device/report.php)
 *
 *  state "started"   : dikirim saat relay menyala -> SERVER membuat SATU
 *                       catatan monitoring_logs (Data Aktivitas Sistem,
 *                       status "Menyala") + notifikasi web
 *  state "completed" : dikirim setelah durasi aktual penuh ->
 *                       notifikasi web saja, TANPA catatan kedua
 *  Satu catatan aktivitas = satu kali pompa MULAI menyala. Tidak ada
 *  catatan berkala selama pompa tetap menyala, dan tidak ada catatan
 *  dari pembacaan sensor / pengecekan jadwal. Laporan ulang < 60 detik
 *  dide-dup SERVER — HTTP tidak di-retry.
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
  http.addHeader("X-API-Key", cred.key);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(String(body));
  if (code == 404) {
    reportAvail = false;                           // patch belum terpasang
    Serial.println("[REPORT] endpoint laporan 404 â€” pelaporan dimatikan (endpoint belum dipatch)");
  } else if (code > 0) {
    HY_DOC();
    bool okf = (!deserializeJson(doc, http.getString())) && doc["success"].as<bool>();
    Serial.printf("[REPORT] laporan %s (HTTP %d)\n", okf ? "diterima" : "ditolak server", code);
  } else {
    reportFails++;
    if (reportFails >= 3) reportAvail = false;
    Serial.printf("[REPORT] POST gagal code=%d â€” tanpa retry agar tidak duplikat\n", code);
  }
  http.end();
}

/* =====================================================================
 * 10. DOSING (AB-Mix & pH-Down) â€” mesin state
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
         guard overdose SEBELUM memompa lagi â€” kondisi bisa berubah.   */
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
         tidak valid / mode aman / semprotan aktif â€” pompa tidak boleh
         terus bekerja dengan pembacaan tidak normal.                   */
      { bool sensorOk = (d.pump == PUMP_AB) ? ppmValid : phValid;
        if (sprayActive() || !dosingAllowed() || !sensorOk) {
          relayWrite(d.pump, false);
          setDoseIdle(d);
          if (!sensorOk)
            Serial.printf("[PUMP] %s sensor INVALID saat dosing â€” DIHENTIKAN\n", d.tag);
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
  /* Kondisi "tidak dapat dikoreksi pompa ini" â€” hanya NOTIFIKASI server:
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
      Serial.printf("[PH] pH %s rentang dan pengganti arah pompa = %s â€” "
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
         tidak pernah memicu dosing â€” sesuai spesifikasi ("di atas 8,
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
 * 11. JADWAL DITHANE â€” interval SEJAK PENYEMPROTAN TERAKHIR SELESAI
 *
 * Spesifikasi:
 *   - interval (jam, dari dashboard) dihitung dari waktu SELESAI
 *     penyemprotan terakhir (bukan dari slot jam mulai, bukan dari
 *     waktu sistem menyala).
 *   - waktu selesai terakhir disimpan dua tempat: NVS lokal (utama,
 *     atomik per eksekusi) DAN database server via laporan "completed"
 *     (resp. last_completed_at PATCH A / PATCH B). Nilai server yang
 *     LEBIH BARU diadopsi perangkat â€” NVS hilang / jam bergeser /
 *     ganti perangkat tetap akurat.
 *   - belum pernah menyemprot -> jadwal pertama memakai start_time.
 *   - slot terlampaui melebihi GRACE (mis. perangkat lama mati):
 *     DILEWATI tanpa eksekusi susulan, sehingga siklus berikutnya
 *     tetap berjarak penuh â€” tanpa semprot beruntun (keselamatan kimia).
 * ===================================================================== */

time_t dithLastDone()  { return (time_t)nvs.getULong64("dithdone", 0ULL); }
void   dithMarkDone(time_t t) { nvs.putULong64("dithdone", (uint64_t)t); }

bool dithArmed() {
  /* Interlock penyemprotan bahan kimia:
     - Tanpa patch (tidak ada blok "device" dari server): HARUS
       diaktifkan eksplisit via Serial ("arm") â€” default DISARMED.
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
    Serial.println("[DITH] ada jadwal terlewat (di luar toleransi) â€” "
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
         dikirim â€” laporan mengikuti aksi, bukan sebaliknya.            */
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
             akan diulang utuh â€” laporan "started" ganda dide-dup di server. */
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
    Serial.println("[FAILSAFE] MODE AMAN: upload gagal berulang â€” semua pompa "
                   "dikunci OFF. Sensor tetap dibaca; sistem pulih otomatis "
                   "bila upload berhasil kembali.");
  }
  if (!safeMode && was) Serial.println("[FAILSAFE] pulih â€” kontrol pompa aktif ulang");
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
 * 14. LCD 20x4 â€” 3 halaman rotasi, refresh non-blocking
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
  if (!lcdAddrFound) { Serial.println("[LCD] tidak terdeteksi â€” berjalan tanpa LCD"); return; }
  lcdPtr = new LiquidCrystal_I2C(lcdAddrFound, LCD_COLS, LCD_ROWS);
  lcdPtr->init();
  lcdPtr->backlight();
  lcdLine(0, "HYDROTECH SYSTEM");
  lcdLine(1, "Booting ...");
  Serial.printf("[LCD] 20x4 I2C 0x%02X siap\n", lcdAddrFound);
}

void updateLCD() {
  if (!lcdPtr) return;

  /* LAYAR SETUP (AP aktif): nama AP + IP untuk halaman setup, lalu
     status koneksi (mencoba/terhubung/gagal) sesuai keinginan spec.   */
  if (portalActive) {
    char a[24], b[24], c[24];
    switch (connState) {
      case SC_CONNECTING:
        snprintf(a, sizeof(a), "Sambung WiFi...");
        snprintf(b, sizeof(b), "SSID: %s", connSsid.c_str());
        snprintf(c, sizeof(c), "%lu dtk ...", (unsigned long)((millis() - connStartMs) / 1000UL));
        break;
      case SC_CONNECTED:
        snprintf(a, sizeof(a), "WiFi terhubung");
        snprintf(b, sizeof(b), "SSID: %s", connSsid.c_str());
        snprintf(c, sizeof(c), "IP : %s", connIp.c_str());
        break;
      case SC_FAILED:
        snprintf(a, sizeof(a), "Koneksi GAGAL");
        snprintf(b, sizeof(b), "SSID: %s", connSsid.c_str());
        snprintf(c, sizeof(c), "coba: %s", portalApIp.c_str());
        break;
      default: /* SC_IDLE — AP menunggu klien */
        snprintf(a, sizeof(a), "AP : %s", AP_SSID);
        snprintf(b, sizeof(b), "IP : %s", portalApIp.c_str());
        snprintf(c, sizeof(c), "buka di HP/laptop");
        break;
    }
    lcdLine(0, "HYDROTECH SETUP");
    lcdLine(1, a);
    lcdLine(2, b);
    lcdLine(3, c);
    return;
  }

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
 * 16. SERIAL CONFIG â€” API key TIDAK pernah dicetak
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
  Serial.println(" setup | reboot — portal AP / restart bersih");
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
    else if (cmd == "reboot") { stopAllPumps(); dosingOwner = PUMP_COUNT; delay(300); ESP.restart(); }
    else if (cmd == "setup") {
      Serial.println("[SETUP] membuka portal AP — operasi normal dijeda hingga setup selesai");
      startSetupMode();
    }
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
    else if (cmd == "arm")   { nvs.putBool("armed", true);  Serial.println("ARMED â€” jadwal Dithane dapat menembak bila konfigurasi segar"); }
    else if (cmd == "disarm"){ nvs.putBool("armed", false); stopAllPumps(); dosingOwner = PUMP_COUNT; Serial.println("DISARMED â€” semua relay OFF"); }
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
  loadCreds();
  if (credsValid()) {
    connectWiFi();
    if (!nvs.getBool("armed", false))
      Serial.println("[SAFETY] Dithane DISARMED. Ketik 'arm' untuk aktivasi "
                     "penyemprotan (setelah pemasangan fisik diverifikasi).");
  } else {
    /* BELUM ADA KONFIGURASI -> mode AP "hydrotech" + halaman setup   */
    startSetupMode();
    Serial.println("[SAFETY] Dithane DISARMED — aktifkan setelah setup sukses.");
  }
  setupWatchdog();
  Serial.println("[BOOT] siap");
}

void loop() {
  esp_task_wdt_reset();

  /* MODE SETUP (AP "hydrotech" + captive portal): alur Terpisah dari
     jurnal monitoring/pompa — pompa sudah dijaga aman OFF saat masuk
     mode ini; sensor/LCD/LED tetap hidup pada halaman setup.         */
  if (portalActive) {
    portalLoop();
    return;
  }

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
