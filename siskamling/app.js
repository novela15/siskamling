/* ===== Helper ===== */
const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

const LEVEL = {
  bahaya:  { label: 'Bahaya',  badge: 'danger' },
  waspada: { label: 'Waspada', badge: 'warn' },
  aman:    { label: 'Aman',    badge: 'safe' }
};

let status = null;
let settings = null;
let currentScreen = 'home';
let lastTab = 'home';
let historyLevel = '';
let dismissedAlertId = null;   // peringatan yang sudah ditutup user
let notifiedAlertId = null;    // peringatan yang sudah dikirimi notifikasi browser

async function api(path, options) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...options
  });
  if (!res.ok) throw new Error(res.status);
  return res.json();
}

const pad = (n) => String(n).padStart(2, '0');
const fmtTime = (ts) => { const d = new Date(ts); return `${pad(d.getHours())}.${pad(d.getMinutes())}`; };

function dayLabel(ts) {
  const d = new Date(ts), now = new Date();
  const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((startOf(now) - startOf(d)) / 86400000);
  if (diff === 0) return 'Hari ini';
  if (diff === 1) return 'Kemarin';
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long' });
}

/* ===== Navigasi ===== */
function show(name) {
  currentScreen = name;
  $$('.screen').forEach((s) => s.classList.toggle('active', s.id === `screen-${name}`));
  $('#phone').classList.toggle('alert', name === 'alert');
  if (name !== 'alert') {
    lastTab = name;
    $$('#nav button').forEach((b) => b.classList.toggle('active', b.dataset.tab === name));
  }
  if (name === 'history') loadHistory();
}

$$('#nav button').forEach((b) => b.addEventListener('click', () => show(b.dataset.tab)));

/* ===== Jam status bar ===== */
function tickClock() { $('#clock').textContent = fmtTime(Date.now()); }
setInterval(tickClock, 15000); tickClock();

/* ===== Render: Beranda ===== */
function eventRowHome(e) {
  return `
    <li class="item">
      <i class="bar-l ${e.level}"></i>
      <div class="txt"><strong>${e.message}</strong><small>${dayLabel(e.created_at)}, ${fmtTime(e.created_at)}</small></div>
      <b class="dist">${Math.round(e.distance)} cm</b>
    </li>`;
}

function renderHome() {
  const s = status;
  $('#connDot').className = 'dot ' + (s.esp32_online ? 'on' : 'err');
  $('#connText').textContent = s.esp32_online ? 'ESP32 terhubung' : 'ESP32 terputus';

  const badge = $('#levelBadge');
  if (s.distance == null || !s.esp32_online) {
    $('#distNum').textContent = s.distance == null ? '--' : Math.round(s.distance);
    badge.className = 'badge idle';
    badge.textContent = s.esp32_online ? 'Menunggu data' : 'Tidak aktif';
  } else {
    $('#distNum').textContent = Math.round(s.distance);
    const lv = LEVEL[s.level];
    badge.className = `badge ${lv.badge}`;
    badge.textContent = lv.label;
  }

  const pos = s.distance == null ? 0 : Math.max(0, Math.min(100, s.distance));
  $('#marker').style.left = pos + '%';
  $('#armedToggle').checked = s.armed;
  $('#sirenBanner').hidden = !s.siren_on;

  const list = $('#recentList');
  list.innerHTML = s.recent.length
    ? s.recent.map(eventRowHome).join('')
    : '<li class="empty">Belum ada objek terdeteksi</li>';
}

/* ===== Render: Pengaturan ===== */
function renderDevice() {
  const s = status;
  const set = (id, on, text, err) => {
    $(`#d${id}`).className = 'dot' + (on ? ' on' : err ? ' err' : '');
    const v = $(`#v${id}`);
    v.textContent = text;
    v.classList.toggle('muted', !on);
  };
  set('Esp', s.esp32_online, s.esp32_online ? 'Terhubung' : 'Terputus', !s.esp32_online);
  set('Sensor', s.esp32_online && s.sensor_ok, s.esp32_online ? (s.sensor_ok ? 'Normal' : 'Bermasalah') : 'Tidak diketahui', s.esp32_online && !s.sensor_ok);
  set('Cam', s.camera_online, s.camera_online ? 'Terhubung' : 'Belum terhubung', false);

  $('#tDanger').textContent = `${s.thresholds.danger_max} cm`;
  $('#tWarn').textContent = `${s.thresholds.warn_max} cm`;
  $('#tSafe').textContent = `${s.thresholds.safe_max} cm`;
}

function renderSettings() {
  $$('.toggles input').forEach((i) => { i.checked = !!settings[i.dataset.key]; });
}

/* ===== Render: Riwayat ===== */
async function loadHistory() {
  const q = historyLevel ? `?level=${historyLevel}` : '';
  let events;
  try { events = await api('/api/events' + q); } catch { return; }

  const box = $('#historyList');
  if (!events.length) { box.innerHTML = '<p class="empty">Belum ada kejadian</p>'; return; }

  const showThumb = settings && settings.save_photo;
  let html = '', lastDay = '';
  for (const e of events) {
    const day = dayLabel(e.created_at);
    if (day !== lastDay) {
      html += `${lastDay ? '</ul>' : ''}<h4 class="group-day">${day}</h4><ul>`;
      lastDay = day;
    }
    html += `
      <li class="item">
        <i class="bar-l ${e.level}"></i>
        <div class="txt"><strong>${e.message}</strong><small>${fmtTime(e.created_at)}${e.photo ? ', ada foto' : ''}</small></div>
        <b class="dist">${Math.round(e.distance)} cm</b>
        ${e.photo && showThumb ? `<img class="thumb" src="${e.photo}" alt="Foto kejadian" />` : ''}
      </li>`;
  }
  box.innerHTML = html + '</ul>';
}

$$('#chips .chip').forEach((c) => c.addEventListener('click', () => {
  $$('#chips .chip').forEach((x) => x.classList.remove('active'));
  c.classList.add('active');
  historyLevel = c.dataset.level;
  loadHistory();
}));

/* ===== Notifikasi bahaya ===== */
const PLACEHOLDER_SVG = `
  <svg viewBox="0 0 320 190" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
    <rect x="170" y="14" width="120" height="176" rx="4" fill="#2a4655"/>
    <circle cx="272" cy="104" r="3.5" fill="#8fa5ad"/>
    <circle cx="82" cy="92" r="26" fill="#4d6572"/>
    <path d="M30 190c4-42 24-58 52-58s48 16 52 58z" fill="#4d6572"/>
  </svg>`;

function renderAlert() {
  const a = status.alert;
  const limit = status.thresholds.danger_max;
  $('#alertWhen').textContent = `Pintu depan, ${dayLabel(a.created_at).toLowerCase()} ${fmtTime(a.created_at)}`;
  $('#alertDetail').textContent = `Jarak terbaca ${Math.round(a.distance)} cm, di bawah batas ${limit} cm`;
  $('#sirenNote').textContent = `Menyala otomatis di bawah ${limit} cm`;
  const pill = $('#sirenPill');
  pill.textContent = status.siren_on ? 'Menyala' : 'Mati';
  pill.classList.toggle('off', !status.siren_on);

  const photo = $('#alertPhoto');
  const key = a.photo || 'none';
  if (photo.dataset.key !== key) {          // jangan render ulang kalau tidak berubah
    photo.dataset.key = key;
    photo.innerHTML = a.photo
      ? `<img src="${a.photo}" alt="Foto bukti" /><span class="cap">Foto ESP32-CAM</span>`
      : `${PLACEHOLDER_SVG}<span class="cap">Menunggu foto ESP32-CAM</span>`;
  }
}

function browserNotify(a) {
  if (!settings || !settings.notif_phone) return;
  if (navigator.vibrate) navigator.vibrate([300, 150, 300]);
  if ('Notification' in window && Notification.permission === 'granted' && notifiedAlertId !== a.id) {
    notifiedAlertId = a.id;
    new Notification('SISKAMLING: Ada objek sangat dekat', {
      body: `Jarak terbaca ${Math.round(a.distance)} cm di pintu depan`
    });
  }
}

function handleAlert() {
  const a = status.alert;
  const active = a && status.siren_on;
  if (active && a.id !== dismissedAlertId) {
    renderAlert();
    if (currentScreen !== 'alert') { show('alert'); browserNotify(a); }
  } else if (currentScreen === 'alert') {
    show(lastTab);
  }
}

async function sirenOff() {
  try { await api('/api/siren/off', { method: 'POST' }); } catch {}
  if (status && status.alert) dismissedAlertId = status.alert.id;
  show('home');
  poll();
}
$('#sirenOff').addEventListener('click', sirenOff);
$('#sirenBanner').addEventListener('click', sirenOff);
$('#toHistory').addEventListener('click', () => {
  if (status && status.alert) dismissedAlertId = status.alert.id;
  show('history');
});

/* ===== Toggle ===== */
async function patchSettings(body) {
  try { settings = await api('/api/settings', { method: 'PATCH', body: JSON.stringify(body) }); }
  catch { /* biarkan, poll berikutnya mengoreksi tampilan */ }
  renderSettings();
  poll();
}

$('#armedToggle').addEventListener('change', (e) => patchSettings({ armed: e.target.checked }));

$$('.toggles input').forEach((i) => i.addEventListener('change', () => {
  if (i.dataset.key === 'notif_phone' && i.checked && 'Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
  }
  patchSettings({ [i.dataset.key]: i.checked });
}));

/* ===== Polling ===== */
async function poll() {
  try {
    status = await api('/api/status');
    if (!settings) { settings = await api('/api/settings'); renderSettings(); }
    renderHome();
    renderDevice();
    handleAlert();
    if (currentScreen === 'history') loadHistory();
  } catch {
    $('#connDot').className = 'dot err';
    $('#connText').textContent = 'Server tidak terjangkau';
  }
}

poll();
setInterval(poll, 2000);