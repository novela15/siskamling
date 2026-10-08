#include "esp_camera.h"
#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>

// ====== UBAH BAGIAN INI ======
const char* WIFI_SSID = "NAMA_WIFI";
const char* WIFI_PASS = "PASSWORD_WIFI";
const char* SERVER    = "http://192.168.1.10:3000";
// =============================

// Pin kamera AI Thinker
#define PWDN_GPIO_NUM  32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM   0
#define SIOD_GPIO_NUM  26
#define SIOC_GPIO_NUM  27
#define Y9_GPIO_NUM    35
#define Y8_GPIO_NUM    34
#define Y7_GPIO_NUM    39
#define Y6_GPIO_NUM    36
#define Y5_GPIO_NUM    21
#define Y4_GPIO_NUM    19
#define Y3_GPIO_NUM    18
#define Y2_GPIO_NUM     5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM  23
#define PCLK_GPIO_NUM  22

WebServer web(80);
volatile bool needCapture = false;
unsigned long lastPing = 0;

bool initCamera() {
  camera_config_t c;
  c.ledc_channel = LEDC_CHANNEL_0;
  c.ledc_timer = LEDC_TIMER_0;
  c.pin_d0 = Y2_GPIO_NUM;  c.pin_d1 = Y3_GPIO_NUM;  c.pin_d2 = Y4_GPIO_NUM;  c.pin_d3 = Y5_GPIO_NUM;
  c.pin_d4 = Y6_GPIO_NUM;  c.pin_d5 = Y7_GPIO_NUM;  c.pin_d6 = Y8_GPIO_NUM;  c.pin_d7 = Y9_GPIO_NUM;
  c.pin_xclk = XCLK_GPIO_NUM;  c.pin_pclk = PCLK_GPIO_NUM;
  c.pin_vsync = VSYNC_GPIO_NUM; c.pin_href = HREF_GPIO_NUM;
  c.pin_sccb_sda = SIOD_GPIO_NUM; c.pin_sccb_scl = SIOC_GPIO_NUM;
  c.pin_pwdn = PWDN_GPIO_NUM;  c.pin_reset = RESET_GPIO_NUM;
  c.xclk_freq_hz = 20000000;
  c.pixel_format = PIXFORMAT_JPEG;
  c.frame_size = FRAMESIZE_VGA;     // 640x480, cukup untuk bukti
  c.jpeg_quality = 12;
  c.fb_count = 1;
  return esp_camera_init(&c) == ESP_OK;
}

void uploadPhoto() {
  camera_fb_t* fb = esp_camera_fb_get();   // buang frame lama
  if (fb) esp_camera_fb_return(fb);
  delay(150);
  fb = esp_camera_fb_get();                // frame baru
  if (!fb) { Serial.println("Gagal ambil foto"); return; }

  HTTPClient http;
  http.setTimeout(8000);
  http.begin(String(SERVER) + "/api/photo");
  http.addHeader("Content-Type", "image/jpeg");
  int code = http.POST(fb->buf, fb->len);
  Serial.printf("Upload foto: %d (%u byte)\n", code, (unsigned)fb->len);
  http.end();
  esp_camera_fb_return(fb);
}

void setup() {
  Serial.begin(115200);
  if (!initCamera()) { Serial.println("Kamera gagal init"); delay(3000); ESP.restart(); }

  WiFi.begin(WIFI_SSID, WIFI_PASS);
  while (WiFi.status() != WL_CONNECTED) { delay(400); Serial.print("."); }
  Serial.println("\nIP ESP32-CAM: " + WiFi.localIP().toString());  // isi ke CAM_IP di ESP32 utama

  web.on("/capture", []() { needCapture = true; web.send(200, "text/plain", "OK"); });
  web.begin();
}

void loop() {
  web.handleClient();

  if (needCapture) { needCapture = false; uploadPhoto(); }

  if (millis() - lastPing > 10000 && WiFi.status() == WL_CONNECTED) {   // lapor "saya hidup"
    lastPing = millis();
    HTTPClient http;
    http.setTimeout(2000);
    http.begin(String(SERVER) + "/api/camera/ping");
    http.POST("");
    http.end();
  }
  if (WiFi.status() != WL_CONNECTED) WiFi.reconnect();
}

// Ubah WIFI_SSID, WIFI_PASS, dan SERVER sama seperti siskamling_cam.ino