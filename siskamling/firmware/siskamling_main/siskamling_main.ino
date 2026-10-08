#include <WiFi.h>
#include <HTTPClient.h>

// ====== UBAH BAGIAN INI ======
const char* WIFI_SSID = "NAMA_WIFI";
const char* WIFI_PASS = "PASSWORD_WIFI";
const char* SERVER    = "http://192.168.1.10:3000";  // IP laptop yang menjalankan server
const char* CAM_IP    = "192.168.1.50";              // IP ESP32-CAM (lihat Serial Monitor-nya)
// =============================

// Pin (sesuai Tabel 2 proposal)
#define PIN_TRIG   5
#define PIN_ECHO   18
#define PIN_SOUND  19
#define PIN_LED_R  23
#define PIN_LED_Y  22   // tambahan
#define PIN_LED_G  21   // tambahan
#define PIN_RELAY  26
#define PIN_BUTTON 27

// Banyak modul relay aktif LOW. Kalau sirine nyala terbalik, tukar dua nilai ini.
const int RELAY_ON  = HIGH;
const int RELAY_OFF = LOW;

bool armed = true;
bool siren = false;
String level = "aman";

unsigned long lastSend = 0;
unsigned long lastButton = 0;
const unsigned long SEND_INTERVAL = 1000;  // kirim jarak tiap 1 detik

// ---------- Sensor jarak ----------
float readOnce() {
  digitalWrite(PIN_TRIG, LOW);  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH); delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  long dur = pulseIn(PIN_ECHO, HIGH, 30000);   // timeout 30 ms
  if (dur == 0) return 400.0;                  // tidak ada pantulan = tidak ada objek dekat
  return dur * 0.0343 / 2.0;
}

float readDistance() {            // ambil 3 bacaan, pakai nilai tengah (median)
  float a = readOnce(); delay(20);
  float b = readOnce(); delay(20);
  float c = readOnce();
  if (a > b) { float t = a; a = b; b = t; }
  if (b > c) { float t = b; b = c; c = t; }
  if (a > b) { float t = a; a = b; b = t; }
  return b;
}

// ---------- Output ----------
void applyOutputs() {
  bool r = false, y = false, g = false;
  if (armed) {
    r = (level == "bahaya");
    y = (level == "waspada");
    g = (level == "aman");
  }
  digitalWrite(PIN_LED_R, r);
  digitalWrite(PIN_LED_Y, y);
  digitalWrite(PIN_LED_G, g);
  digitalWrite(PIN_RELAY, (armed && siren) ? RELAY_ON : RELAY_OFF);
}

// ---------- Komunikasi ----------
void connectWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.print("Menyambung Wi-Fi");
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  unsigned long t = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t < 15000) { delay(300); Serial.print("."); }
  Serial.println(WiFi.status() == WL_CONNECTED ? "\nTerhubung: " + WiFi.localIP().toString() : "\nGagal, coba lagi nanti");
}

void triggerCamera() {
  HTTPClient http;
  http.setTimeout(800);
  http.begin(String("http://") + CAM_IP + "/capture");
  int code = http.GET();
  Serial.printf("Minta foto ke CAM: %d\n", code);
  http.end();
}

void sendReading(float distance, bool sound) {
  HTTPClient http;
  http.setTimeout(2000);
  http.begin(String(SERVER) + "/api/readings");
  http.addHeader("Content-Type", "application/json");
  String body = "{\"distance\":" + String(distance, 1) + ",\"sound\":" + (sound ? "true" : "false") + "}";
  int code = http.POST(body);

  if (code == 200) {
    String res = http.getString();
    armed = res.indexOf("\"armed\":true") >= 0;
    siren = res.indexOf("\"siren\":true") >= 0;
    if (res.indexOf("\"level\":\"bahaya\"") >= 0)       level = "bahaya";
    else if (res.indexOf("\"level\":\"waspada\"") >= 0) level = "waspada";
    else                                                level = "aman";
    if (res.indexOf("\"capture\":true") >= 0) triggerCamera();
  } else {
    Serial.printf("Kirim gagal: %d\n", code);
  }
  http.end();
}

void sendArmed() {
  HTTPClient http;
  http.setTimeout(2000);
  http.begin(String(SERVER) + "/api/device/armed");
  http.addHeader("Content-Type", "application/json");
  http.POST(String("{\"armed\":") + (armed ? "true" : "false") + "}");
  http.end();
}

// ---------- Setup & loop ----------
void setup() {
  Serial.begin(115200);
  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  pinMode(PIN_SOUND, INPUT);
  pinMode(PIN_LED_R, OUTPUT);
  pinMode(PIN_LED_Y, OUTPUT);
  pinMode(PIN_LED_G, OUTPUT);
  pinMode(PIN_RELAY, OUTPUT);
  pinMode(PIN_BUTTON, INPUT_PULLUP);   // tombol: kaki 2 ke GND
  digitalWrite(PIN_RELAY, RELAY_OFF);
  connectWifi();
}

void loop() {
  connectWifi();

  // Tombol: aktif/nonaktifkan sistem
  if (digitalRead(PIN_BUTTON) == LOW && millis() - lastButton > 400) {
    lastButton = millis();
    armed = !armed;
    if (!armed) siren = false;
    Serial.println(armed ? "Sistem: AKTIF" : "Sistem: NONAKTIF");
    applyOutputs();
    if (WiFi.status() == WL_CONNECTED) sendArmed();
  }

  // Kirim jarak berkala
  if (millis() - lastSend >= SEND_INTERVAL && WiFi.status() == WL_CONNECTED) {
    lastSend = millis();
    float d = readDistance();
    bool sound = digitalRead(PIN_SOUND) == HIGH;
    Serial.printf("Jarak: %.1f cm | suara: %s\n", d, sound ? "ya" : "tidak");
    sendReading(d, sound);
    applyOutputs();
  }
}

//WIFI_SSID dan WIFI_PASS: Wi-Fi yang dipakai laptop dan ESP32 (harus sama).
// SERVER: IP laptop, bukan localhost. Cek dengan ipconfig (Windows) atau ifconfig (Mac/Linux).
// CAM_IP: isi nanti setelah ESP32-CAM jalan (Langkah 12).