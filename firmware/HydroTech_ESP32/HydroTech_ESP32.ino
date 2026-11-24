/*
 * ===================================================================
 *  HydroTech_ESP32.ino  â€”  Firmware monitoring & kontrol hidroponik
 * ===================================================================
 *
 *  TRANSPORT: MQTT SAJA (Eclipse Mosquitto). HTTP REST sudah dihapus.
 *  Kontrak lengkap topik & payload JSON: docs/MQTT.md (baca dulu
 *  sebelum menyunting). Ringkasan:
 *
 *  publish   hydrotech/sensor/{ph,ppm}  QoS 0 tiap 2 dtk
 *            {"value":6.52,"valid":true,"ts":1700000000}
 *  publish   hydrotech/pump/status      QoS 1 retained (saat berubah)
 *  publish   hydrotech/device/status    QoS 1 retained 30 dtk + LWT
 *            + ack perintah + pump_activity + config_request
 *  subscribe hydrotech/cmd/esp32/{ph,ppm,dithane,pump}  QoS 1
 *            (perintah WAJIB msg_id unik; TIDAK pernah retained)
 *
 *  ATURAN WAJIB DITAATI PENYUNTING FIRMWARE:
 *   - Timestamp ditetapkan SERVER (worker PHP) — firmware hanya melampirkan
 *     `ts` epoch NTP sebagai pemaring payload (bukan keputusan waktu DB).
 *   - Transport data = MQTT SAJA (Mosquitto). HTTP sudah dihapus.
 *   - Heartbeat perangkat = sensor_readings tersimpan (via MQTT -> worker);
 *     batas online 300 s (api/device/status.php) — stream 2 dtk jauh di bawah.
 *   - Laporan pompa: MQTT device/status {"type":"pump_activity"}; worker PHP
 *     mencatat monitoring_logs + notifikasi (aturan sama dengan endpoint lama).
 *   - Konfigurasi (ambang/jadwal): MQTT config_request -> worker mendorong
 *     cmd/esp32/*. Seluruh pompa dikendalikan LOKAL dengan parameter aman.
 *   - Otomatisasi TETAP BERJALAN LOKAL meski MQTT/broker/Wi-Fi terputus
 *     (mode aman hanya dipicu sensor invalid beruntun).
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
 *  Library: WiFi/Preferences/esp_task_wdt (bawaan), PubSubClient (MQTT),
 *           ArduinoJson (>=6.19), hd44780 (Library Manager).
 *  Board  : ESP32 Dev Module, core arduino-esp32 >= 2.0.x
 *           (analogReadMilliVolts dibutuhkan).
 * ===================================================================
 */

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <WebServer.h>          // halaman setup (mode AP)
#include <DNSServer.h>          // captive portal redirect
#include <Wire.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <PubSubClient.h>       // MQTT (Library Manager: "PubSubClient by Nick O'Leary")
#include <hd44780.h>                        // LCD 20x4 (Library Manager: "hd44780" - Bill Perry)
#include <hd44780ioClass/hd44780_I2Cexp.h>  // mendukung ESP32 penuh (nol peringatan arsitektur)
#include <ESPmDNS.h>            // resolusi broker tanpa IP tetap (coba-coba.local)
#include "esp_task_wdt.h"
#include "esp_wifi.h"          // aturan wilayah WiFi (country code)
#include "time.h"

/* =====================================================================
 *  1. KONFIGURASI â€” ubah hanya blok ini untuk tiap instalasi
 * ===================================================================== */

/* --- Wi-Fi + API (JANGAN commit kredensial nyata) ------------------- */
/* API_BASE_URL/API_KEY hanya dipakai portal setup (identitas perangkat);
   transport data adalah MQTT di bawah - HTTP sudah tidak dipakai.       */
const char* WIFI_SSID      = "ISI-SSID";
const char* WIFI_PASS      = "ISI-PASSWORD";
const char* API_BASE_URL   = "http://192.168.1.10:8000/api/sensor/ingest.php";
const char* API_KEY        = "ISI-ESP-API-KEY";  // sama dengan config/esp_key.txt / env

/* --- MQTT (satu-satunya transport; Mosquitto di server Fedora) ------- */
/* Kredensial: user 'esp32' (lihat config/mqtt_auth.local.php di server).
   JANGAN commit password nyata - sama aturannya dengan API key.          */
/* Host broker: NAMA mDNS (hostname laptop Fedora, lihat `hostname`).
   ESP32 menanyakan IP-nya lewat mDNS tiap kali akan connect, sehingga
   IP laptop berubah (DHCP) pun tetap menemukan broker. Tulis IP literal
   saja bila mDNS bermasalah (contoh "192.168.1.22").                     */
const char* MQTT_HOST       = "coba-coba.local";  // hostname laptop Fedora
const uint16_t MQTT_PORT    = 1883;
const char* MQTT_USER       = "esp32";
const char* MQTT_PASS       = "bf564b3196da7aa6ed77383dfce2e0764fe3";
const char* MQTT_CLIENT_ID  = "hydrotech-esp32";
/* QoS: sensor stream QoS 0 (realtime 2 dtk, kehilangan 1 titik wajar);
   perintah + ack + status pompa/perangkat QoS 1 (tepat sekali);
   retained HANYA untuk status (pump/device) - JANGAN untuk perintah.     */
const uint32_t MQTT_PUBLISH_MS      = 2000;       // wajib: sensor tiap 2 detik
const uint32_t MQTT_RETRY_MS        = 5000;
const uint32_t MQTT_STATUS_MS       = 30000;      // heartbeat device/status
const uint32_t CONFIG_REQUEST_MS    = 900000;     // refresh konfigurasi (15 mnt)
const uint32_t WIFI_RETRY_MS        = 10000;      // jeda antar percobaan Wi-Fi

/* --- Interval ------------------------------------------------------- */
const uint32_t SENSOR_INTERVAL_MS = 2000;        // sampling + stream MQTT 2 dtk
const uint32_t MAX_CONFIG_AGE_MS  = 7200000;     // batas umur konfigurasi (2 jam)

/* --- Pin ------------------------------------------------------------ */
const uint8_t ADC_PH_PIN  = 34;    // AO sensor pH  (ADC1 â€” aman dengan Wi-Fi)
const uint8_t ADC_PPM_PIN = 35;    // AO sensor TDS (ADC1)
const uint8_t LED_PIN     = 23;    // LED hijau: indikator sistem aktif
/* LED indikator status (GPIO output aman; bebas dari pin strapping,
   sensor ADC 34/35, I2C 21/22, dan relay 16-19).                    */
const uint8_t LED_PPM_PIN = 32;    // LED kuning: PPM di bawah ambang minimum
const uint8_t LED_PH_PIN  = 33;    // LED merah : pH di bawah ambang minimum
const int     I2C_SDA     = 21;
const int     I2C_SCL     = 22;
const uint8_t LCD_ADDR_CAND[2] = { 0x27, 0x3F };  // alamat umum LCD 20x4
/* Modul terpasang: 16 kolom x 2 baris (sesuai hardware Anda).
   LCD karakter TIDAK bisa mengubah ukuran font - agar tidak terpotong,
   teks dirancang muat 16 kolom; informasi Dithane dirotasi tiap 2 dtk
   pada modul 2 baris (modul 20x4 menampilkan semua sekaligus).        */
const uint8_t LCD_COLS = 16, LCD_ROWS = 2;
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
 *                              driver menariku input relay active-low).
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
bool      safeMode = false;

/* --- Lain ---------------------------------------------------------------- */
bool     ntpStarted = false;
uint32_t bootMs = 0;

hd44780_I2Cexp* lcdPtr = nullptr;
uint8_t  lcdAddrFound = 0;
uint32_t lcdDraw0 = 0;
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
  pinMode(LED_PPM_PIN, OUTPUT);
  digitalWrite(LED_PPM_PIN, LOW);
  pinMode(LED_PH_PIN, OUTPUT);
  digitalWrite(LED_PH_PIN, LOW);
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
void updateStatusLeds();
void updateLCD();
void handleSerial();
void stopAllPumps();
void setDoseIdle(DoseCtl& d);
bool sprayActive();

struct DeviceCreds {
  String ssid, wpass, url, key;
};
DeviceCreds cred;

DNSServer  dnsServer;
WebServer* setupSrv = nullptr;

const char*  AP_SSID      = "hydrotech";   // nama AP sesuai spesifikasi
const char*  AP_PASS      = "";            // AP terbuka (lebih cepat dipakai HP)
/* Portal AP SELALU AKTIF (AP+STA simultan): ganti WiFi kapan pun
   lewat http://192.168.4.1 tanpa menghentikan monitoring/pompa.      */
String       portalApIp   = "192.168.4.1";

/* Posisi percobaan koneksi STA (dari halaman "Hubungkan")              */
enum SetupConnState : uint8_t { SC_IDLE, SC_CONNECTING, SC_CONNECTED, SC_FAILED };
SetupConnState connState = SC_IDLE;
String   connSsid = "", connMsg = "", connIp = "";
uint32_t connStartMs = 0, connSuccessMs = 0;
const uint32_t CONNECT_TIMEOUT_MS = 30000;

bool credsValid() {
  /* Transport MQTT: kredensial broker diisi saat kompile (MQTT_PASS);
     portal setup hanya menyiapkan Wi-Fi.                               */
  return cred.ssid.length() > 0;
}

/* Tidak dipakai lagi (transport MQTT); portal tidak menampilkan kredensial. */

void loadCreds() {
  /* Default KOSONG (bukan placeholder): NVS kosong = belum pernah
     dikonfigurasi -> portal AP harus terbuka. WiFi diatur via portal,
     bukan lewat source code.                                           */
  cred.ssid  = nvs.getString("c_ssid",  "");
  cred.wpass = nvs.getString("c_wpass", "");
  if (cred.ssid.length() == 0)
    Serial.println("[PROV] belum ada kredensial WiFi tersimpan - portal AP akan dibuka");
  else
    Serial.printf("[PROV] kred: ssid='%s'\n", cred.ssid.c_str());
}

void saveCreds() {
  nvs.putString("c_ssid",  cred.ssid);
  nvs.putString("c_wpass", cred.wpass);
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
<title>HydroTech · Pengaturan Jaringan</title><style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  background:linear-gradient(160deg,#eef7f0,#e2f2e8 55%,#dcefe2);color:#16321f}
body:after{content:"";position:fixed;inset:auto -120px -140px auto;width:340px;height:340px;border-radius:50%;
  background:rgba(7,141,67,.06);pointer-events:none}
.wrap{max-width:430px;margin:0 auto;padding:22px 16px 34px}
.brand{display:flex;align-items:center;gap:13px;margin:2px 0 18px}
.logo{width:56px;height:56px;border-radius:16px;background:linear-gradient(150deg,#e9f8ee,#c9ecd5);
  display:grid;place-items:center;box-shadow:0 8px 18px rgba(7,141,67,.14)}
.brand h1{margin:0;font-size:26px;font-weight:800;color:#14532d;letter-spacing:-.02em}
.brand small{display:block;margin-top:3px;color:#7d8f83;font-size:13px;font-weight:600}
.card{background:#fff;border:1px solid #dcebe0;border-radius:16px;box-shadow:0 10px 26px rgba(23,73,44,.08);
  padding:16px;margin-bottom:14px}
.status-card{background:linear-gradient(170deg,#f2fcf5,#e7f7ed);border-color:#bfe6cb;padding:15px 16px}
.sc-head{display:flex;align-items:center;gap:9px;font-weight:700;color:#1d5b32;font-size:14.5px}
.dot{width:11px;height:11px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.15);
  animation:pulse 1.6s ease-out infinite}
.dot.warn{background:#e2a03f;box-shadow:0 0 0 4px rgba(226,160,63,.15)}
.dot.off{background:#9aa8a0;box-shadow:0 0 0 4px rgba(120,140,128,.14);animation:none}
@keyframes pulse{50%{box-shadow:0 0 0 7px rgba(22,163,74,.05)}}
.sc-ssid{margin:7px 0 9px;font-size:19px;font-weight:800;color:#14532d;word-break:break-all}
.sc-meta{display:flex;align-items:center;gap:9px;border-top:1px solid #d5ebda;padding-top:9px;
  font-size:12.5px;color:#3e5a48;font-weight:600;flex-wrap:wrap}
.sc-meta svg{vertical-align:-2px;margin-right:4px}
.sc-meta b{color:#0e8a44}
.sep{width:1px;height:14px;background:#cfe5d5}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.card-head h2{margin:0;font-size:16.5px;font-weight:800;color:#14532d}
.ghost{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#0e8a44;border:1.5px solid #9fd4ae;
  border-radius:10px;padding:7px 13px;font-size:13px;font-weight:700;cursor:pointer}
.ghost:disabled{opacity:.5;cursor:wait}
.netlist{border:1px solid #e3efe6;border-radius:12px;overflow:hidden;margin-bottom:14px;max-height:212px;overflow-y:auto}
.net{display:flex;align-items:center;gap:10px;width:100%;background:#fff;border:0;color:#1d4230;padding:11px 12px;
  cursor:pointer;border-bottom:1px solid #eef4ef;text-align:left;font-family:inherit}
.net:last-child{border-bottom:0}
.net:hover{background:#f4fbf6}
.net.sel{background:#e9f8ee}
.net svg{flex-shrink:0;color:#0e8a44}
.net .nm{flex:1;font-size:14px;font-weight:700;color:#20503a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.net .db{color:#7c8c82;font-size:12.5px;font-weight:600}
.badge{background:#16a34a;color:#fff;border-radius:999px;font-size:10.5px;font-weight:800;padding:4px 10px}
label{display:block;font-size:12.5px;font-weight:700;color:#3e5a48;margin:0 0 6px}
input[type=text],input[type=password]{width:100%;background:#fff;border:1.5px solid #cfe0d3;border-radius:11px;
  padding:11px 13px;color:#14532d;font-size:14.5px;font-weight:600;font-family:inherit}
input:focus{outline:2px solid rgba(22,163,74,.25);border-color:#7cc98f}
.f{margin-bottom:12px}
.pw{position:relative}
.pw input{padding-right:42px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;padding:8px;cursor:pointer;color:#8a9a90}
.eye:active{color:#0e8a44}
.chk{display:flex;align-items:center;gap:8px;font-size:13px;color:#2e4a3a;font-weight:600;margin:2px 0 14px;cursor:pointer}
.chk input{width:17px;height:17px;accent-color:#16a34a;cursor:pointer}
.primary{width:100%;display:flex;align-items:center;justify-content:center;gap:9px;background:linear-gradient(150deg,#16a34a,#0e8a44);
  color:#fff;border:0;border-radius:12px;padding:13px;font-size:15.5px;font-weight:800;cursor:pointer;
  box-shadow:0 10px 20px rgba(14,138,68,.24);transition:transform .15s ease,box-shadow .15s ease;font-family:inherit}
.primary:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(14,138,68,.3)}
.primary:active{transform:translateY(0)}
.primary:disabled{opacity:.55;cursor:wait;transform:none}
.msg{display:none;border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;margin-bottom:12px;line-height:1.45}
.msg.err{display:block;background:#fdeeee;border:1px solid #eec6c6;color:#a33}
.msg.ok{display:block;background:#e9f8ee;border:1px solid #c4e8cf;color:#0e8a44}
.muted{padding:14px;color:#87978d;font-size:13px;text-align:center}
footer{display:flex;align-items:center;gap:12px;margin-top:20px;color:#0e8a44;font-size:12.5px;font-weight:600}
footer .line{flex:1;height:1px;background:#b9d8c2}
@media (max-width:420px){.wrap{padding:16px 12px 28px}.brand h1{font-size:22px}}
</style></head><body>
<div class="wrap">
  <header class="brand">
    <div class="logo">
      <svg width="34" height="34" viewBox="0 0 48 48" fill="none">
        <circle cx="24" cy="30" r="12" fill="#16a34a" opacity=".16"/>
        <path d="M24 8c7 5 12 11 12 17a12 12 0 0 1-24 0c0-6 5-12 12-17Z" fill="#16a34a"/>
        <path d="M24 40c0-8 3-14 9-18" stroke="#eafff1" stroke-width="2.4" stroke-linecap="round" fill="none"/>
        <path d="M24 12c-2 4-2 8 1 12 4-1 6-4 6-8" fill="#eafff1" opacity=".9"/>
      </svg>
    </div>
    <div>
      <h1>HydroTech</h1>
      <small>Pengaturan Jaringan · ESP32</small>
    </div>
  </header>

  <div class="card status-card" id="statusCard">
    <div class="sc-head"><span class="dot off" id="scDot"></span><span id="scTitle">Memeriksa status...</span></div>
    <div class="sc-ssid" id="scSsid">-</div>
    <div class="sc-meta" id="scMeta"><span>Status diperiksa ulang setiap 2 detik</span></div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Pilih Jaringan WiFi</h2>
      <button type="button" class="ghost" id="refreshBtn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>
        Pindai</button>
    </div>
    <div id="netList" class="netlist"><div class="muted">Memindai jaringan di sekitar...</div></div>

    <div class="f"><label for="ssid">SSID WiFi</label>
      <input id="ssid" type="text" maxlength="32" placeholder="pilih dari daftar atau ketik manual" autocomplete="off">
    </div>
    <div class="f"><label for="pass">Password WiFi</label>
      <div class="pw">
        <input id="pass" type="password" maxlength="63" autocomplete="new-password" placeholder="••••••••">
        <button type="button" class="eye" id="eyeBtn" aria-label="Tampilkan password">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-7.5 11-7.5S23 12 23 12s-4 7.5-11 7.5S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <label class="chk"><input type="checkbox" id="nopass"> Tanpa Password (jaringan terbuka)</label>

    <div id="msg" class="msg"></div>
    <button type="button" class="primary" id="goBtn">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><circle cx="12" cy="19.4" r="1.6" fill="#fff" stroke="none"/></svg>
      Simpan &amp; Hubungkan</button>
  </div>

  <footer><span class="line"></span>HydroTech ESP32 · Konfigurasi jaringan<span class="line"></span></footer>
</div>

<script>
const $ = (id) => document.getElementById(id);
const nets = [];
let currentSsid = "";
let ssidTouched = false;
let pollTimer = null;

function esc(t){return String(t).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");}
function showMsg(t,cls){const m=$("msg");if(!t){m.className="msg";return}m.className="msg "+cls;m.textContent=t;}

/* ==== STATUS AKTUAL: poll /api/status tiap 2 detik ==== */
async function pollStatus(){
  try{
    const r = await (await fetch("/api/status",{cache:"no-store"})).json();
    renderStatus(r);
  }catch(e){}
}
function bar(rssi){return rssi>=-55?"Kuat":rssi>=-67?"Baik":rssi>=-78?"Lemah":"Sangat lemah";}

function renderStatus(s){
  const dot=$("scDot"),title=$("scTitle"),ssidEl=$("scSsid"),meta=$("scMeta");
  const connIcon='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0e8a44" stroke-width="2.4" stroke-linecap="round"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><circle cx="12" cy="19.4" r="1.4" fill="#0e8a44" stroke="none"/></svg>';
  const pinIcon='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0e8a44" stroke-width="2.2" stroke-linecap="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2.6"/></svg>';
  if(s.state==="connected"&&s.online){
    dot.className="dot"; title.textContent="Perangkat Terhubung";
    ssidEl.textContent="WiFi saat ini: "+(s.ssid||"-");
    meta.innerHTML=connIcon+"<span>Status: <b>Online</b></span><span class=\"sep\"></span>"+pinIcon+"<span>IP ESP32: <b>"+esc(s.ip||"-")+"</b></span>";
    currentSsid=s.ssid||"";
    if(!ssidTouched&&!$("ssid").value)$("ssid").value=currentSsid;
  }else if(s.state==="connecting"){
    dot.className="dot warn"; title.textContent="Menghubungkan...";
    ssidEl.textContent=s.ssid?("Menyambung ke "+s.ssid):"Menyambung...";
    meta.innerHTML="<span>Status: <b>Menghubungkan...</b></span>";
  }else if(s.state==="failed"){
    dot.className="dot off"; title.textContent="Disconnected";
    ssidEl.textContent="Gagal terhubung";
    meta.innerHTML="<span>"+esc(s.reason||"Coba pindai lalu hubungkan ulang")+"</span>";
  }else{
    dot.className="dot off"; title.textContent="Belum terhubung ke WiFi";
    ssidEl.textContent="Disconnected";
    meta.innerHTML="<span>Status: <b>Offline</b></span>";
  }
  renderNets();
}

/* ==== DAFTAR JARINGAN ==== */
async function rescan(){
  const btn=$("refreshBtn");btn.disabled=true;
  $("netList").innerHTML='<div class="muted">Memindai jaringan di sekitar...</div>';
  try{
    await fetch("/api/scan",{cache:"no-store"});
    for(let t=0;t<40;t++){
      const r=await (await fetch("/api/scanresult",{cache:"no-store"})).json();
      if(r.scanning){await new Promise(res=>setTimeout(res,650));continue}
      nets.length=0;(r.networks||[]).forEach(n=>nets.push(n));
      renderNets();break;
    }
  }catch(e){showMsg("Gagal memindai jaringan - coba lagi.","err")}
  btn.disabled=false;
}
function renderNets(){
  const el=$("netList");
  if(!nets.length){el.innerHTML='<div class="muted">Tidak ada jaringan ditemukan. Tekan Pindai.</div>';return}
  el.innerHTML=nets.map((n,i)=>{
    const cur=currentSsid&&n.ssid===currentSsid;
    const icon='<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0e8a44" stroke-width="2.2" stroke-linecap="round"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><circle cx="12" cy="19.4" r="1.4" fill="#0e8a44" stroke="none"/></svg>';
    const badge=cur?'<span class="badge">Terhubung</span>':'<span class="db">'+n.rssi+' dBm</span>';
    return '<button class="net'+(cur?" sel":"")+'" data-i="'+i+'" type="button">'+icon+
      '<span class="nm">'+esc(n.ssid)+'</span><span class="db">'+bar(n.rssi)+'</span>'+badge+'</button>';
  }).join("");
}
$("netList").addEventListener("click",(e)=>{
  const b=e.target.closest(".net");if(!b)return;
  const n=nets[+b.dataset.i];if(!n)return;
  $("ssid").value=n.ssid;ssidTouched=true;
  if(!n.secure){$("nopass").checked=true;applyNopass()}else{$("nopass").checked=false;applyNopass()}
  renderNets();
  $("pass").focus();
});

/* ==== EYE + CHECKBOX ==== */
$("eyeBtn").addEventListener("click",()=>{
  const p=$("pass");const show=p.type==="password";
  p.type=show?"text":"password";
  $("eyeBtn").style.color=show?"#0e8a44":"";
});
function applyNopass(){
  const off=$("nopass").checked;
  $("pass").disabled=off;
  if(off)$("pass").value="";
}
$("nopass").addEventListener("change",applyNopass);
$("ssid").addEventListener("input",()=>{ssidTouched=true});

/* ==== SIMPAN & HUBUNGKAN (mekanisme lama tetap dipakai) ==== */
$("goBtn").addEventListener("click",async()=>{
  const ssid=$("ssid").value.trim(),pass=$("pass").value,open=$("nopass").checked;
  if(!ssid)return showMsg("Pilih jaringan WiFi dari daftar atau isi SSID.","err");
  if(!open&&pass.length<8)return showMsg("Password WiFi minimal 8 karakter (atau centang Tanpa Password).","err");
  showMsg("");
  const btn=$("goBtn");btn.disabled=true;
  try{
    const body=new URLSearchParams({ssid,pass,nopass:open?"1":"0"});
    const r=await (await fetch("/api/connect",{method:"POST",body})).json();
    if(!r.ok)throw new Error(r.error||"Server menolak konfigurasi.");
    showMsg("Konfigurasi disimpan - menyambung ke "+ssid+"...","ok");
  }catch(e){showMsg(e.message||"Gagal mengirim konfigurasi.","err");btn.disabled=false}
});

/* ==== INIT ==== */
$("pass").value="";
rescan();
pollTimer=setInterval(pollStatus,2000);
pollStatus();
</script></body></html>
)html";

/* ---------------------------------------------------------------------
 * ROUTE HANDLERS
 * --------------------------------------------------------------------- */

void handleRoot() {
  setupSrv->send(200, "text/html", PORTAL_PAGE);
}

/* Probe captive portal berbagai OS: alihkan semua ke halaman setup      */
void handleProbe() {
  setupSrv->sendHeader("Location", String("http://") + portalApIp + "/", true);
  setupSrv->send(302, "text/plain", "");
}

void handleScanStart() {
  WiFi.scanNetworks(true, false, false, 300);      // pemindaian sungguhan, async
  setupSrv->send(200, "application/json", "{\"scanning\":true}");
}

void handleScanResult() {
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
  if (connState == SC_CONNECTING) {
    setupSrv->send(200, "application/json",
                   "{\"ok\":false,\"error\":\"Koneksi sedang berjalan - tunggu hasilnya.\"}");
    return;
  }
  String ssid  = setupSrv->arg("ssid");
  String pass  = setupSrv->arg("pass");
  String nopass= setupSrv->arg("nopass");
  String url   = setupSrv->arg("url");           // tidak dipakai lagi (MQTT saja) - diabaikan
  String key   = setupSrv->arg("key");           // idem
  (void)url; (void)key;
  bool openNet = (nopass == "1");

  if (ssid.length() == 0 || ssid.length() > 32) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"SSID tidak valid.\"}"); return;
  }
  if (!openNet && pass.length() < 8) {
    setupSrv->send(200, "application/json", "{\"ok\":false,\"error\":\"Password minimum 8 karakter (atau centang Tanpa Password).\"}"); return;
  }

  /* Simpan permanen (NVS) SEBELUM koneksi: aman meski listrik padam
     saat koneksi berlangsung.                                        */
  cred.ssid = ssid;
  cred.wpass = openNet ? String("") : pass;
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
  Serial.printf("[SETUP] koneksi: ssid='%s' open=%d\n", ssid.c_str(), (int)openNet);
  setupSrv->send(200, "application/json",
                 "{\"ok\":true,\"ssid\":\"" + jsonEsc(ssid) + "\"}");
}

String wlFailReason(wl_status_t s) {
  switch (s) {
    case WL_NO_SSID_AVAIL: return "SSID tidak ditemukan di sekitar.";
#ifdef WL_WRONG_PASSWORD
    case WL_WRONG_PASSWORD: return "Password WiFi salah.";
#endif
    case WL_CONNECT_FAILED: return "Koneksi ditolak router.";
    case WL_IDLE_STATUS: return "Router menjawab lambat / sinyal lemah.";
    default: return "Gagal menghubungkan (kode " + String((int)s) + ").";
  }
}

void handleStatus() {
  /* Status AKTUAL perangkat (bukan statis): online = STA tersambung.
     state: connecting / connected / failed / idle.                     */
  bool online = (WiFi.status() == WL_CONNECTED);

  if (connState == SC_CONNECTING && !online) {
    wl_status_t s = WiFi.status();
    uint32_t elapsed = (millis() - connStartMs) / 1000UL;
    if (s == WL_CONNECT_FAILED || s == WL_NO_SSID_AVAIL
#ifdef WL_WRONG_PASSWORD
        || s == WL_WRONG_PASSWORD
#endif
       ) {
      connMsg = wlFailReason(s);
      connState = SC_FAILED;
      Serial.printf("[SETUP] gagal: %s\n", connMsg.c_str());
    }
  }

  String state;
  String ssidOut;
  String ipOut;
  String reason = "";
  if (online) {
    state = "connected";
    ssidOut = WiFi.SSID();
    ipOut = WiFi.localIP().toString();
  } else if (connState == SC_CONNECTING) {
    state = "connecting";
    ssidOut = connSsid;
  } else if (connState == SC_FAILED) {
    state = "failed";
    reason = jsonEsc(connMsg);
  } else {
    state = "idle";
  }

  setupSrv->send(200, "application/json",
                 "{\"state\":\"" + state + "\",\"online\":" +
                 (online ? "true" : "false") +
                 ",\"ssid\":\"" + jsonEsc(ssidOut) +
                 "\",\"ip\":\"" + jsonEsc(ipOut) +
                 "\",\"reason\":\"" + reason + "\"}");
}

/* ---------------------------------------------------------------------
 * SIKLUS PORTAL - SELALU AKTIF (AP+STA simultan)
 * Sistem (monitoring/pompa/MQTT) TETAP BERJALAN saat portal dibuka.
 * --------------------------------------------------------------------- */

void startConfigPortal() {
  if (setupSrv) return;                         // sudah aktif

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
  setupSrv->onNotFound(handleProbe);              // URL apa pun -> halaman setup
  setupSrv->begin();

  Serial.printf("[SETUP] AP '%s' AKTIF TETAP - ganti WiFi kapan pun via http://%s/\n",
                AP_SSID, portalApIp.c_str());
}

/* dipanggil tiap loop: layani portal tanpa menghentikan sistem utama.   */
void configPortalLoop() {
  if (setupSrv) setupSrv->handleClient();
  dnsServer.processNextRequest();
}

/* =====================================================================
 *  7. Wi-Fi non-blocking
 * ===================================================================== */

void connectWiFi() {
  WiFi.mode(WIFI_AP_STA);         // STA + AP portal tetap hidup bersamaan
  WiFi.setSleep(false);
  /* Country code Indonesia: scan & asosiasi channel 1-13 (router rumah
     bisa di channel 12/13). Tanpa ini jaringan ch12-13 sering gagal
     asosiasi / tidak terlihat. Wajib SETELAH driver WiFi aktif.        */
  esp_wifi_set_country_code("ID", true);
  /* Kunci stabilitas AP+STA: softAP HARUS satu channel dengan jaringan
     tujuan - asosiasi beda channel di ESP32 sering gagal/terputus.
     Scan dulu, lalu pindahkan softAP ke channel SSID tujuan.           */
  int n = WiFi.scanNetworks();
  for (int i = 0; i < n; i++) {
    if (WiFi.SSID(i) == cred.ssid) {
      uint8_t ch = WiFi.channel(i);
      if (ch && ch != 6) {
        WiFi.softAP(AP_SSID, AP_PASS, ch);    // pindahkan AP ikut channel router
        Serial.printf("[WiFi] softAP pindah ke channel %d (mengikuti %s)\n", ch, cred.ssid.c_str());
      }
      break;
    }
  }
  WiFi.begin(cred.ssid.c_str(), cred.wpass.c_str());
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
 *  8. WAKTU SERVER (utilitas)
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

/* =====================================================================
 *  9. LAPORAN EKSEKUSI POMPA (via MQTT, pengganti api/device/report.php)
 *
 *  state "started"   : dikirim saat relay menyala -> SERVER (worker PHP)
 *                       membuat SATU catatan monitoring_logs (Data
 *                       Aktivitas Sistem, status "Menyala") + notifikasi
 *  state "completed" : dikirim setelah durasi aktual penuh ->
 *                       notifikasi web saja, TANPA catatan kedua
 *  Satu catatan aktivitas = satu kali pompa MULAI menyala. Tidak ada
 *  catatan berkala selama pompa tetap menyala, dan tidak ada catatan
 *  dari pembacaan sensor / pengecekan jadwal. Dedup < 60 detik tetap
 *  di server (worker PHP). QoS 1; bila broker terputus, laporan tunggal
 *  ini dihilangkan (perilaku lama: HTTP tanpa retry agar tidak duplikat).
 * ===================================================================== */

WiFiClient   mqttNet;
PubSubClient mqtt(mqttNet);
uint32_t lastMqttSensorMs = 0, lastMqttStatusMs = 0, lastMqttTryMs = 0;
uint32_t lastPumpPubMs = 0, lastCfgReqMs = 0;
time_t   mqttTs();
void     requestConfigMqtt();
time_t   dithLastDone();
void     dithMarkDone(time_t t);

void reportPump(const char* pump, const char* state, long durationS) {
  if (!mqtt.connected() || wifiState != WIFI_OK) return;
  struct tm t = localTm();
  char body[160];
  snprintf(body, sizeof(body),
           "{\"type\":\"pump_activity\",\"pump\":\"%s\",\"state\":\"%s\",\"duration_s\":%ld,"
           "\"device_seen\":\"%04d-%02d-%02d %02d:%02d:%02d\",\"ts\":%lld}",
           pump, state, durationS,
           t.tm_year + 1900, t.tm_mon + 1, t.tm_mday, t.tm_hour, t.tm_min, t.tm_sec,
           (long long)mqttTs());
  mqtt.publish("hydrotech/device/status", body, false);   // QoS 1, tanpa retained
  Serial.printf("[REPORT] laporan pompa %s/%s terkirim via MQTT\n", pump, state);
}

/* =====================================================================
 * 9b. KONTROL MANUAL DARI DASHBOARD (via MQTT hydrotech/cmd/esp32/pump)
 *
 * Prioritas: failsafe sensor > interlock Dithane > MANUAL (berdurasi)
 *            > otomatisasi lokal.
 *  - Override manual selalu berbatas waktu (duration_s) dengan timer
 *    LOKAL di ESP32: bila server/broker putus di tengah jalan,
 *    pompa tetap kembali ke otomatisasi tepat waktu.
 *  - Manual ON pompa dosing ditolak bila mode aman, semprotan aktif,
 *    atau sensor pompa tersebut invalid (keselamatan kimia).
 *  - Manual OFF selalu diizinkan (arah paling aman).
 *  - Manual spray Dithane TIDAK menggeser anchor jadwal (dithMarkDone
 *    tidak dipanggil) sehingga jadwal otomatis tidak berubah.
 *  - Semua perintah membawa msg_id; dieksekusi SEKALI lalu di-ack,
 *    sehingga perintah duplikat tidak dijalankan ulang.
 * ===================================================================== */

struct ManualCtl {
  bool     active = false;
  bool     on = false;
  uint32_t until = 0;
};
ManualCtl manCtl[PUMP_COUNT];
bool      manDithSpraying = false;
uint32_t  manDithT0 = 0, manDithDur = 0;

bool manualActive(PumpId p) { return manCtl[p].active; }

uint32_t manualRemainingS(PumpId p) {
  return (millis() < manCtl[p].until) ? (manCtl[p].until - millis()) / 1000UL + 1 : 0;
}

void manualEnd(PumpId p, bool notify) {
  bool wasOn = manCtl[p].on;
  manCtl[p].active = false;
  manCtl[p].on = false;
  if (notify && wasOn) {                      // laporan selesai override ON
    const char* name = (p == PUMP_AB) ? "ab" : (p == PUMP_PHDOWN ? "ph" : "dithane");
    reportPump(name, "completed", 0);
  }
}

void cancelAutoDosing(PumpId p) {
  if (p == PUMP_AB && doseAB.state != DOSE_IDLE) { relayWrite(PUMP_AB, false); setDoseIdle(doseAB); }
  if (p == PUMP_PHDOWN && dosePH.state != DOSE_IDLE) { relayWrite(PUMP_PHDOWN, false); setDoseIdle(dosePH); }
}

bool applyManual(const String& pump, bool on, long durationS) {
  PumpId p;
  if      (pump == "ab")      p = PUMP_AB;
  else if (pump == "ph")      p = PUMP_PHDOWN;
  else if (pump == "dithane") p = PUMP_DITHANE;
  else return false;
  if (durationS < 1 || durationS > 3600) return false;

  if (!on) {                                    // OFF selalu aman diizinkan
    if (p == PUMP_DITHANE && manDithSpraying) {
      manDithSpraying = false;
      relayWrite(PUMP_DITHANE, false);
      Serial.println("[CMD]: spray manual Dithane DIBATAL");
    }
    cancelAutoDosing(p);
    manualEnd(p, false);
    manCtl[p].active = true; manCtl[p].on = false;
    manCtl[p].until = millis() + (uint32_t)durationS * 1000UL;
    Serial.printf("[CMD]: %s OFF manual %ld s\n", PUMP_NAME[p], durationS);
    return true;
  }

  /* --- Manual ON: SELALU dieksekusi (hak penuh operator). Batas
     waktu otomatis adalah satu-satunya pengaman agar tidak ada
     pompa menyala tanpa pengawasan selamanya.                       */
  if (p == PUMP_DITHANE) {
    stopAllPumps();
    dosingOwner = PUMP_COUNT;
    circState = CIRC_OFF; circT0 = millis();
    cancelAutoDosing(PUMP_AB); cancelAutoDosing(PUMP_PHDOWN);
    manDithSpraying = true;
    manDithT0 = millis();
    manDithDur = (uint32_t)durationS;
    Serial.printf("[CMD]: spray manual Dithane MULAI %ld s\n", durationS);
    reportPump("dithane", "started", 0);
    return true;
  }
  cancelAutoDosing(p);                          // hentikan dosis otomatis berjalan
  manualEnd(p, false);
  manCtl[p].active = true; manCtl[p].on = true;
  manCtl[p].until = millis() + (uint32_t)durationS * 1000UL;
  Serial.printf("[CMD]: %s ON manual %ld s\n", PUMP_NAME[p], durationS);
  { const char* name = (p == PUMP_AB) ? "ab" : "ph";
    reportPump(name, "started", durationS); }
  return true;
}

/* Terapkan & awasi override manual; dipanggil tiap loop SEBELUM kontrol
   otomatis (pompa manual dilewati kontrol otomatisnya).                */
void applyManualOverrides() {
  for (uint8_t i = 0; i < PUMP_COUNT; i++) {
    PumpId p = static_cast<PumpId>(i);
    if (!manCtl[i].active) continue;
    if (millis() >= manCtl[i].until) {        // durasi habis -> auto kembali
      if (manCtl[i].on) Serial.printf("[MAN] %s override habis — otomatisasi lanjut\n", PUMP_NAME[p]);
      relayWrite(p, false);
      manualEnd(p, true);
      continue;
    }
    if (manCtl[i].on) {
      /* Hak penuh operator: manual ON selalu dieksekusi di kondisi
         apa pun; pengaman = durasi terbatas (otomatis kembali).        */
      relayWrite(p, true);
    } else {
      relayWrite(p, false);                   // paksa OFF selama override
    }
  }
  if (manDithSpraying) {                      // spray manual berjalan
    relayWrite(PUMP_DITHANE, true);
    if (millis() - manDithT0 >= manDithDur * 1000UL) {
      relayWrite(PUMP_DITHANE, false);
      manDithSpraying = false;
      Serial.printf("[MAN] spray manual Dithane SELESAI (%lu s)\n", (unsigned long)manDithDur);
      reportPump("dithane", "completed", (long)manDithDur);
    }
  }
}

/* =====================================================================
 * 9c. MQTT — transport utama (Eclipse Mosquitto, jaringan lokal)
 *
 * Topik (lihat docs/MQTT.md):
 *   publish : hydrotech/sensor/{ph,ppm}   QoS 0, tiap 2 dtk, JSON + ts
 *   publish : hydrotech/pump/status       QoS 1, saat berubah, retained
 *   publish : hydrotech/device/status     QoS 1, 30 dtk + LWT, retained
 *   publish : hydrotech/device/status     ack perintah {"type":"ack"}
 *   subscribe: hydrotech/cmd/esp32/{ph,ppm,dithane,pump}  QoS 1
 *
 * Keandalan:
 *  - Reconnect otomatis tiap MQTT_RETRY_MS.
 *  - Pencegahan eksekusi perintah duplikat: cache msg_id (FNV-1a) 32 kali
 *    terakhir; perintah juga TIDAK pernah retained dari sisi server.
 *  - Validasi payload sebelum diterapkan (ambang pH 0..14, PPM >= 0,
 *    jadwal Dithane konsisten); ditolak -> ack dengan ok:false.
 *  - Konfigurasi awal/berkala: perangkat meminta lewat config_request,
 *    worker PHP menjawab dengan dorongan cmd/ph|ppm|dithane.
 * ===================================================================== */
bool     lastPubOn[PUMP_COUNT] = { false, false, false, false };
bool     lastPubManual[PUMP_COUNT] = { false, false, false, false };

/* Cache msg_id: FNV-1a 32-bit, cincin 32 entri (dedup perintah MQTT).   */
uint32_t msgIdSeen[32];
uint8_t  msgIdSeenPos = 0;
bool     msgIdSeenInit = false;

bool msgIdIsNew(const char* id) {
  if (!id || !id[0]) return true;
  uint32_t h = 2166136261u;
  for (const char* c = id; *c; ++c) { h ^= (uint8_t)*c; h *= 16777619u; }
  if (!msgIdSeenInit) {                       // inisialisasi pertama: isi = 0
    for (uint8_t i = 0; i < 32; i++) msgIdSeen[i] = 0;
    msgIdSeenInit = true;
  }
  for (uint8_t i = 0; i < 32; i++) if (msgIdSeen[i] == h && h != 0) return false;
  msgIdSeen[msgIdSeenPos] = h;
  msgIdSeenPos = (msgIdSeenPos + 1) % 32;
  return true;
}

time_t mqttTs() { return systemTimeValid() ? time(nullptr) : 0; }

/* Konversi nilai JSON toleran: angka, atau string "5,5"/"800".        */
float jsonToFloat(JsonVariant v) {
  if (v.isNull()) return NAN;
  if (v.is<float>() || v.is<int>()) return v.as<float>();
  const char* s = v.as<const char*>();
  if (!s || !*s) return NAN;
  String t(s); t.replace(',', '.');
  return t.toFloat();
}

time_t dithNextSlot();
void mqttPublishDeviceStatus(bool online) {
  char payload[224];
  if (online) {
    snprintf(payload, sizeof(payload),
             "{\"type\":\"status\",\"online\":true,\"ip\":\"%s\",\"rssi\":%d,\"next_due\":%lld,\"ts\":%lld}",
             WiFi.localIP().toString().c_str(), WiFi.RSSI(),
             (long long)dithNextSlot(), (long long)mqttTs());
  } else {
    snprintf(payload, sizeof(payload), "{\"type\":\"status\",\"online\":false,\"ts\":%lld}", (long long)mqttTs());
  }
  mqtt.publish("hydrotech/device/status", payload, true);   // retained: status boleh
  Serial.printf("[MQTT] device/status -> %s\n", payload);
}

/* Ack perintah: dipublikasikan QoS 1 TANPA retained.                    */
void mqttAck(const char* msgId, const char* cmdTopic, bool ok, const char* note) {
  char payload[224];
  snprintf(payload, sizeof(payload),
           "{\"type\":\"ack\",\"msg_id\":\"%s\",\"topic\":\"%s\",\"ok\":%s,\"note\":\"%s\",\"ts\":%lld}",
           msgId ? msgId : "", cmdTopic, ok ? "true" : "false", note, (long long)mqttTs());
  mqtt.publish("hydrotech/device/status", payload, false);
  Serial.printf("[MQTT] ack %s ok=%d (%s)\n", msgId ? msgId : "-", ok, note);
}

/* Terapkan konfigurasi ambang/jadwal dari cmd topic; true bila valid.   */
bool applyConfigCmd(const String& type, JsonDocument& doc) {
  if (type == "ph" || type == "ppm") {
    float mn = jsonToFloat(doc["min"]), mx = jsonToFloat(doc["max"]);
    float lo = 0.f, hi = (type == "ph") ? 14.f : 5000.f;
    if (isnan(mn) || isnan(mx) || mn < lo || mx > hi || mn > mx) return false;
    if (type == "ph") { cfg.phMin = mn; cfg.phMax = mx; }
    else              { cfg.ppmMin = mn; cfg.ppmMax = mx; }
    cfg.valid = true; cfg.fetchedMs = millis();
    Serial.printf("[MQTT] ambang %s: %.2f..%.2f\n", type.c_str(), mn, mx);
    return true;
  }
  if (type == "dithane") {
    long ivL = (long)atol(doc["interval"] | "");
    long duL = (long)atol(doc["duration"] | "");
    char st[12]; strncpy(st, doc["start"] | "", sizeof(st) - 1); st[sizeof(st) - 1] = '\0';
    for (char* p = st; *p; ++p) if (*p == ':') *p = '.';     // kompatibel HH:MM
    int dH = 0, dM = 0;
    if (sscanf(st, "%d.%d", &dH, &dM) == 2 && strchr(st, '.') &&
        dH >= 0 && dH < 24 && dM >= 0 && dM < 60 && ivL > 0 && duL > 0) {
      cfg.dithIntervalH = ivL;
      cfg.dithDurationS = (duL > (long)MAX_SPRAY_SECONDS) ? (long)MAX_SPRAY_SECONDS : duL;
      cfg.dithStartHM = dH * 60 + (long)dM;
      cfg.dithValid = true; cfg.fetchedMs = millis();
      time_t serverDone = parseServerLocalEpoch(doc["last_completed_at"] | "");
      if (serverDone > 0 && serverDone > dithLastDone()) dithMarkDone(serverDone);
      Serial.printf("[MQTT] jadwal dithane: tiap %ldh, %ld s, mulai %02d:%02d\n",
                    ivL, cfg.dithDurationS, dH, dM);
      return true;
    }
    return false;
  }
  return false;
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  String t = String(topic);
  if (!t.startsWith("hydrotech/cmd/esp32/")) return;
  String type = t.substring(strlen("hydrotech/cmd/esp32/"));

  /* Payload JSON ke buffer null-terminated.                              */
  char buf[384];
  unsigned int n = (length < sizeof(buf) - 1) ? length : sizeof(buf) - 1;
  memcpy(buf, payload, n); buf[n] = '\0';

  HY_DOC();
  DeserializationError err = deserializeJson(doc, buf);
  if (err) { Serial.printf("[MQTT] payload cmd bukan JSON: %s\n", buf); return; }

  /* Perintah WAJIB membawa msg_id; duplikat tidak dieksekusi dua kali.   */
  const char* msgId = doc["msg_id"] | "";
  if (!msgIdIsNew(msgId)) {
    Serial.printf("[MQTT] msg_id duplikat diabaikan: %s\n", msgId);
    return;
  }

  if (type == "pump") {                          // kontrol manual ON/OFF
    String pump = doc["pump"] | "";
    String action = doc["action"] | "";
    long durationS = doc["duration_s"] | 0L;
    long issuedBy = 0;
    bool ok = (action == "on" || action == "off") && durationS >= 1 && durationS <= 3600
              && applyManual(pump, action == "on", durationS);
    mqttAck(msgId, topic, ok, ok ? "diproses (eksekusi/ditolak aturan keselamatan)" : "payload tidak valid");
    (void)issuedBy;
    return;
  }

  /* Perintah konfigurasi (ph/ppm/dithane).                               */
  bool ok = applyConfigCmd(type, doc);
  mqttAck(msgId, topic, ok, ok ? "konfigurasi diterapkan" : "konfigurasi tidak valid");
}

void publishPumpStatusMqtt(bool force) {
  uint32_t now = millis();
  if (!force && now - lastPumpPubMs < 500) return;   // maks 2 Hz saat manual
  bool published = false;
  for (uint8_t i = 0; i < PUMP_COUNT; i++) {
    PumpId p = static_cast<PumpId>(i);
    bool isOn = (manDithSpraying && p == PUMP_DITHANE) ? true : relayIsOn(p);
    bool isManual = manCtl[i].active;
    /* Auto & tidak berubah: jangan bertele-tele; manual: perbarui mundur. */
    if (!force && !isManual && !lastPubManual[i] && isOn == lastPubOn[i]) continue;
    const char* keys[PUMP_COUNT] = { "ab", "ph", "dithane", "circ" };
    char payload[160];
    if (isManual) {
      snprintf(payload, sizeof(payload),
               "{\"pump\":\"%s\",\"state\":\"%s\",\"mode\":\"manual\",\"remaining_s\":%lu,\"ts\":%lld}",
               keys[i], isOn ? "on" : "off", (unsigned long)manualRemainingS(p), (long long)mqttTs());
    } else {
      snprintf(payload, sizeof(payload),
               "{\"pump\":\"%s\",\"state\":\"%s\",\"mode\":\"auto\",\"ts\":%lld}",
               keys[i], isOn ? "on" : "off", (long long)mqttTs());
    }
    mqtt.publish("hydrotech/pump/status", payload, true);   // retained: status boleh
    lastPubOn[i] = isOn; lastPubManual[i] = isManual;
    published = true;
  }
  if (published) lastPumpPubMs = now;
}

void publishSensorsMqtt() {
  char payload[96];
  if (phValid) {
    snprintf(payload, sizeof(payload), "{\"value\":%.2f,\"valid\":true,\"ts\":%lld}", phValue, (long long)mqttTs());
  } else {
    snprintf(payload, sizeof(payload), "{\"value\":0,\"valid\":false,\"ts\":%lld}", (long long)mqttTs());
  }
  mqtt.publish("hydrotech/sensor/ph", payload, false);        // QoS 0, tanpa retained
  if (ppmValid) {
    snprintf(payload, sizeof(payload), "{\"value\":%ld,\"valid\":true,\"ts\":%lld}", (long)roundf(ppmValue), (long long)mqttTs());
  } else {
    snprintf(payload, sizeof(payload), "{\"value\":0,\"valid\":false,\"ts\":%lld}", (long long)mqttTs());
  }
  mqtt.publish("hydrotech/sensor/ppm", payload, false);       // QoS 0, tanpa retained
}

void maintainMqtt() {
  if (wifiState != WIFI_OK) return;                           // tunggu Wi-Fi stabil
  if (!mqtt.connected()) {
    if (millis() - lastMqttTryMs < MQTT_RETRY_MS) return;
    lastMqttTryMs = millis();

    /* Resolusi broker: nama .local -> IP via mDNS. Cache 5 menit supaya
       reconnect cepat, tapi perubahan IP laptop tetap diikuti.          */
    String host = MQTT_HOST;
    if (host.endsWith(".local")) {
      static IPAddress brokerIp;
      static uint32_t lastResolveMs = 0;
      if (brokerIp == IPAddress(0, 0, 0, 0) || millis() - lastResolveMs > 300000UL) {
        brokerIp = MDNS.queryHost(host.substring(0, host.length() - 6), 2000);
        lastResolveMs = millis();
        if (brokerIp == IPAddress(0, 0, 0, 0)) {
          Serial.println("[MQTT] broker .local tidak ditemukan — coba lagi nanti");
          return;
        }
        mqtt.setServer(brokerIp, MQTT_PORT);
        Serial.printf("[MQTT] broker %s = %s (mDNS)\n", host.c_str(), brokerIp.toString().c_str());
      }
      if (brokerIp == IPAddress(0, 0, 0, 0)) return;
    }

    /* Client-id unik per perangkat: broker menendang klien id sama.    */
    String clientId = String(MQTT_CLIENT_ID) + "-" + WiFi.macAddress();
    boolean ok = mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASS,
                              "hydrotech/device/status", 1, true,
                              "{\"type\":\"status\",\"online\":false,\"reason\":\"lwt\"}");
    if (!ok) {
      /* Diagnosa gagal: -1/=-2 = jaringan/firewall, 4/5 = kredensial salah. */
      Serial.printf("[MQTT] GAGAL konek (state=%d) - cek password/ACL/firewall\n", mqtt.state());
      return;
    }
    if (ok) {
      Serial.println("[MQTT] terhubung ke broker");
      mqtt.subscribe("hydrotech/cmd/esp32/ph", 1);
      mqtt.subscribe("hydrotech/cmd/esp32/ppm", 1);
      mqtt.subscribe("hydrotech/cmd/esp32/dithane", 1);
      mqtt.subscribe("hydrotech/cmd/esp32/pump", 1);
      mqttPublishDeviceStatus(true);
      publishPumpStatusMqtt(true);                            // status terkini sekali
      lastCfgReqMs = millis();
      requestConfigMqtt();                                    // ambang + jadwal
    }
    return;
  }
  mqtt.loop();

  /* Sensor: WAJIB terkirim tiap 2 detik saat MQTT hidup.                 */
  if (millis() - lastMqttSensorMs >= MQTT_PUBLISH_MS) {
    lastMqttSensorMs = millis();
    if (phValid || ppmValid) publishSensorsMqtt();
  }
  if (millis() - lastMqttStatusMs >= MQTT_STATUS_MS) {
    lastMqttStatusMs = millis();
    mqttPublishDeviceStatus(true);
  }
  publishPumpStatusMqtt(false);
}

/* Minta konfigurasi: worker PHP menjawab dengan dorongan cmd/*.        */
void requestConfigMqtt() {
  if (!mqtt.connected()) return;
  char payload[96];
  snprintf(payload, sizeof(payload), "{\"type\":\"config_request\",\"ts\":%lld}", (long long)mqttTs());
  mqtt.publish("hydrotech/device/status", payload, false);
  Serial.println("[MQTT] config_request terkirim — menunggu dorongan konfigurasi");
}

/* =====================================================================
 * 10. DOSING (AB-Mix & pH-Down) â€” mesin state
 * ===================================================================== */

bool configFresh() { return cfg.valid && (millis() - cfg.fetchedMs) <= MAX_CONFIG_AGE_MS; }
/* sprayActive mencakup spray manual dari dashboard (interlock sama).     */
bool sprayActive() { return dithState == DITH_SPRAYING || manDithSpraying; }

bool dosingAllowed() {                            // prasyarat bersama dosing
  /* Jam valid tidak lagi wajib untuk dosing pH/PPM (tidak memakai jam);
     jam hanya wajib untuk slot Dithane terjadwal (dithDue).            */
  return !safeMode && configFresh() && !sprayActive();
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
      if (dosingOwner != PUMP_COUNT && dosingOwner != d.pump) break;  // pompa lain sedang dosing
      d.state = DOSE_PUMPING; d.t0 = millis();
      dosingOwner = d.pump;
      relayWrite(d.pump, true);              // RELAY ON (perbaikan: tidak pernah ditulis)
      Serial.printf("[PUMP] %s dosing ulang (siklus berikutnya)\n", d.tag);
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
        dosingOwner = PUMP_COUNT;      // lepaskan hak: pompa lain boleh dosing
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
      relayWrite(PUMP_AB, true);             // RELAY ON (perbaikan)
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
      relayWrite(PUMP_PHDOWN, true);         // RELAY ON (perbaikan)
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
  return nvs.getBool("armed", true);    // jadwal otomatis AKTIF secara default
}

/* Tentukan waktu jatuh tempo (dueAt) penyemprotan berikutnya:
 *   - sudah pernah selesai : dueAt = lastDone + interval
 *   - belum pernah         : slot pertama dari start_time (WIB)
 * Kembalikan true hanya bila now berada di [dueAt, dueAt+GRACE].
 * Slot yang terlampaui melebihi GRACE untuk satu demi satu dilompati
 * (tanpa eksekusi susulan) hingga dueAt berada di masa depan/grace.    */
/* Tanda tangan jadwal aktif; berubah = jadwal BARU dari pengaturan.    */
String dithSignature() {
  return String(cfg.dithIntervalH) + "h/" + String(cfg.dithDurationS) + "s/" +
         String(cfg.dithStartHM);
}

bool dithScheduleChanged() {
  String sig = dithSignature();
  String stored = nvs.getString("dithsig", "");
  return stored != sig;
}

void dithRememberSchedule() { nvs.putString("dithsig", dithSignature()); }

/* Hitung slot penyemprotan BERIKUTNYA (tanpa menyemprot):
   dipakai mesin jadwal DAN telemetri next_due untuk countdown.        */
time_t dithNextSlot() {
  if (!systemTimeValid() || !cfg.dithValid) return 0;
  long period = cfg.dithIntervalH * 3600L;
  if (period <= 0) return 0;
  time_t now = time(nullptr);
  time_t lastDone = dithLastDone();
  time_t dueAt;
  if (lastDone == 0 || dithScheduleChanged()) {
    /* Belum pernah menyemprot ATAU pengguna baru mengubah jadwal:
       slot berikutnya = JAM MULAI yang baru diatur. Setelah semprot,
       interval berantai dari waktu selesai.                           */
    struct tm b = localTm();
    b.tm_hour = (int)(cfg.dithStartHM / 60);
    b.tm_min  = (int)(cfg.dithStartHM % 60);
    b.tm_sec  = 0;
    dueAt = mktime(&b);
    while (dueAt <= now && (now - dueAt) > (time_t)SPRAY_GRACE_S) dueAt += 86400;
    dithRememberSchedule();                 // tandai jadwal sudah diadopsi
  } else {
    /* INTERVAL dari waktu penyemprotan terakhir SELESAI.             */
    dueAt = lastDone + period;
    if (dueAt <= lastDone) return 0;                // data tak konsisten
    while (now >= dueAt && (now - dueAt) > (time_t)SPRAY_GRACE_S) {
      dueAt += period;                              // lewati slot lampau
    }
  }
  return dueAt;
}

bool dithDue(time_t& slotOut) {
  time_t dueAt = dithNextSlot();
  if (dueAt == 0) return false;
  time_t now = time(nullptr);
  slotOut = dueAt;
  return (now >= dueAt) && ((now - dueAt) <= (time_t)SPRAY_GRACE_S);
}

/* Simpan waktu SELESAI ke NVS; interval berikutnya dihitung dari sini.
   juga diadopsi otomatis dari server (last_completed_at) bila lebih baru:
   inilah ketahanan pasca-restart/NVS-hilang yang diminta spesifikasi.  */
void controlDithane() {
  /* Override manual aktif utk Dithane (ON/OFF) -> siklus terjadwal
     menunda diri sampai override berakhir (hak penuh operator).        */
  if (manDithSpraying || manualActive(PUMP_DITHANE)) return;
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
  static uint8_t invalidStreak = 0;
  if (millis() - last < 5000) return;
  last = millis();
  /* MODE AMAN sekarang dipicu SENSOR (bukan koneksi): koneksi hilang
     TIDAK boleh menghentikan otomatisasi lokal (syarat ketahanan).
     Sensor kedua invalid beruntun -> pompa dikunci (keselamatan kimia);
     pulih otomatis begitu sensor valid kembali.                        */
  if (!phValid && !ppmValid) invalidStreak++; else invalidStreak = 0;
  bool was = safeMode;
  safeMode = (invalidStreak >= 5);
  if (safeMode && !was) {
    Serial.println("[FAILSAFE] MODE AMAN: sensor tidak valid beruntun â€” semua pompa "
                   "dikunci OFF. Pulih otomatis bila sensor kembali valid.");
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
  /* AUTO-LOCATE hd44780: deteksi alamat I2C (0x20-0x3F) + pemetaan pin
     modul secara otomatis - menutupi semua varian modul I2C LCD.        */
  lcdPtr = new hd44780_I2Cexp();
  int status = lcdPtr->begin(LCD_COLS, LCD_ROWS);
  if (status != 0) {                             // gagal: coba alamat eksplisit
    delete lcdPtr; lcdPtr = nullptr;
    uint8_t candidates[3] = { lcdAddrFound, LCD_ADDR_CAND[0], LCD_ADDR_CAND[1] };
    for (int i = 0; i < 3 && !lcdPtr; i++) {
      if (!candidates[i]) continue;
      hd44780_I2Cexp* retry = new hd44780_I2Cexp(candidates[i]);
      if (retry->begin(LCD_COLS, LCD_ROWS) == 0) lcdPtr = retry;
      else delete retry;
    }
  }
  if (!lcdPtr) {
    Serial.println("[LCD] tidak terdeteksi - berjalan tanpa LCD (cek kabel SDA=21 SCL=22, jalankan 'lcdscan')");
    return;
  }
  lcdPtr->backlight();
  lcdPtr->clear();
  lcdLine(0, "Web:Terputus");
  lcdLine(1, "pH:-- PPM:--");
  Serial.println("[LCD] I2C siap (auto-locate hd44780)");
}

void updateLCD() {
  if (!lcdPtr) return;

  /* Status koneksi WEB = hasil komunikasi NYATA dengan server:
     WiFi OK + MQTT terhubung (bukan sekadar koneksi WiFi).          */
  bool webOk = (wifiState == WIFI_OK) && mqtt.connected();

  /* L0 (baris 1): status Web HydroTech.                              */
  char l0[LCD_COLS + 1];
  snprintf(l0, sizeof(l0), "Web:%s", webOk ? "Terhubung" : "Terputus");

  /* L1 (baris 2): pH + PPM realtime; pada modul 2 baris, Dithane
     dirotasi bergantian tiap 2 dtk (batas fisik 16 kolom).            */
  char l1[LCD_COLS + 1];
  bool phOk = phValid, ppmOk = ppmValid;
  if (phOk && ppmOk)
    snprintf(l1, sizeof(l1), "pH:%.1f PPM:%ld", phValue, (long)roundf(ppmValue));
  else if (phOk)
    snprintf(l1, sizeof(l1), "pH:%.1f PPM:--", phValue);
  else if (ppmOk)
    snprintf(l1, sizeof(l1), "pH:-- PPM:%ld", (long)roundf(ppmValue));
  else
    snprintf(l1, sizeof(l1), "pH:-- PPM:--");

  /* Dithane: status pompa sesungguhnya (manual atau otomatis).        */
  bool dithOn = manDithSpraying || relayIsOn(PUMP_DITHANE);

  lcdLine(0, l0);

  if (LCD_ROWS >= 4) {
    /* Modul 20x4: semua informasi tampil sekaligus.                   */
    char l2[LCD_COLS + 1];
    snprintf(l2, sizeof(l2), "PPM : %s", ppmOk ? String((long)roundf(ppmValue), DEC).c_str() : "--");
    char l3[LCD_COLS + 1];
    snprintf(l3, sizeof(l3), "Dithane : %s", dithOn ? "Menyala" : "Mati");
    lcdLine(1, l1);
    lcdLine(2, l2);
    lcdLine(3, l3);
  } else {
    /* Modul 16x2: baris 2 dirotasi tiap 2 dtk (sensor <-> Dithane).   */
    bool faseDithane = ((millis() / 2000) % 2) == 1;
    if (faseDithane) {
      char l1b[LCD_COLS + 1];
      snprintf(l1b, sizeof(l1b), "Dithane : %s", dithOn ? "Menyala" : "Mati");
      lcdLine(1, l1b);
    } else {
      lcdLine(1, l1);
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
  else if (!mqtt.connected())    period = 300;
  if (period == 0) { if (!ledOn) { digitalWrite(LED_PIN, HIGH); ledOn = true; } return; }
  uint32_t half = period / 2;
  if (millis() - led0 >= half) { led0 = millis(); ledOn = !ledOn; digitalWrite(LED_PIN, ledOn); }
}

/* =====================================================================
 * 15b. LED INDIKATOR STATUS (kuning = PPM rendah, merah = pH rendah)
 *
 * Ambang diambil dari konfigurasi DASHBOARD (cfg.ppmMin / cfg.phMin,
 * tersinkron via MQTT config_request) — BUKAN angka hardcode.
 * Update tiap loop mengikuti pembacaan sensor terbaru; bekerja
 * independen satu sama lain.
 *   - Menyala  : sensor valid + konfigurasi segar + nilai < minimum
 *   - Mati     : nilai sudah mencapai/masuk rentang normal, ATAU
 *                sensor tidak valid / konfigurasi belum tersinkron
 *                (data tidak ada = jangan diasumsikan normal)
 * ===================================================================== */

void updateStatusLeds() {
  bool ppmLow = ppmValid && configFresh() && (ppmValue < cfg.ppmMin);
  bool phLow  = phValid  && configFresh() && (phValue  < cfg.phMin);
  digitalWrite(LED_PPM_PIN, ppmLow ? HIGH : LOW);
  digitalWrite(LED_PH_PIN,  phLow  ? HIGH : LOW);
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
  Serial.println(" sync | lcdscan        (sync = minta config via MQTT)");
  Serial.println(" setup | reboot — portal AP / restart bersih");
}

void printStatus() {
  Serial.println("---- STATUS ----");
  Serial.printf("wifi=%d safe=%d mqtt=%d ntpValid=%d rssi=%d\n",
                (int)wifiState, (int)safeMode, (int)mqtt.connected(),
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
  Serial.printf("LED: ppm-rendah=%d (kuning), ph-rendah=%d (merah)\n",
                (int)(ppmValid && configFresh() && (ppmValue < cfg.ppmMin)),
                (int)(phValid && configFresh() && (phValue < cfg.phMin)));
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
      Serial.printf("[SETUP] AP '%s' selalu aktif - buka http://%s/ di HP/laptop\n",
                    AP_SSID, portalApIp.c_str());
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
    else if (cmd == "sync")   requestConfigMqtt();
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

uint32_t lastSensorMs = 0;

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
  startConfigPortal();          // SELALU AKTIF: ganti WiFi kapan pun via 192.168.4.1
  if (credsValid()) connectWiFi();
  else Serial.println("[SAFETY] Belum ada WiFi - sambungkan HP ke AP 'hydrotech' untuk konfigurasi.");

  setupWatchdog();
  /* MQTT: broker + callback + buffer (payload cmd dithane > default 256). */
  MDNS.begin("hydrotech-esp32");              // hidupkan stack mDNS (untuk queryHost)
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(mqttCallback);
  mqtt.setBufferSize(512);
  Serial.println("[BOOT] siap");
}

void loop() {
  esp_task_wdt_reset();

  uint32_t now = millis();

  maintainWiFi();
  configPortalLoop();          // portal AP selalu aktif (tanpa menghentikan sistem)

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

  maintainMqtt();
  bool mqttUp = mqtt.connected();

  /* Refresh konfigurasi berkala via MQTT (config_request).              */
  if (mqttUp && now - lastCfgReqMs >= CONFIG_REQUEST_MS) {
    lastCfgReqMs = now;
    requestConfigMqtt();
  }

  /* Urutan eksekusi kontrol:
     - otomatisasi berjalan hanya bila pompa itu tidak di-override manual
     - mode aman (sensor invalid) hanya mengunci OTOMATISASI;
       perintah manual operator TETAP dieksekusi (hak penuh operator)   */
  controlDithane();
  controlCirculation();
  if (!manualActive(PUMP_AB)) controlABMix();
  if (!manualActive(PUMP_PHDOWN)) controlPHDown();
  applyManualOverrides();     // manual dieksekusi TERAKHIR = selalu menang


  updateLED();
  updateStatusLeds();          // indikator PPM/pH mengikuti sensor terbaru
  updateLCD();
  evaluateFailsafe();
  handleSerial();
}
