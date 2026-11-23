/* Kontrol manual pompa HydroTech (kartu PPM, pH, Dithane).
 * Tombol = pengirim perintah MQTT langsung (QoS 1, tanpa retained).
 * Status tombol DISINKRONKAN dari telemetri perangkat
 * (hydrotech/pump/status -> api/pump/status.php) sehingga selalu
 * mencerminkan kondisi relay sebenarnya, termasuk nyala otomatis.
 * Override manual selalu berdurasi: habis durasi, ESP kembali
 * ke otomatisasi (timer lokal perangkat).
 */
(async function () {
  const buttons = Array.from(document.querySelectorAll(".pump-btn[data-pump]"));
  if (!buttons.length) return;

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const POLL_MS = 2500;
  const DEFAULT_ON = 30;

  function token() {
    return csrfMeta ? csrfMeta.getAttribute("content") : "";
  }

  function render(button, on, note) {
    button.classList.toggle("on", on);
    const label = button.querySelector("[data-pump-state]");
    if (label) label.textContent = on ? "ON" : "OFF";
  }

  /* HITUNGAN MUNDUR DITHANE: jam:menit:detik menuju penyemprotan
     berikutnya, tampil tepat setelah keterangan jadwal di kartu.      */
  let nextSprayEpoch = null;
  let dithaneSpraying = false;
  let countdownTarget = null;
  function renderCountdown() {
    const note = document.querySelector(".stat-dithane .stat-note");
    if (!note) return;
    let el = note.querySelector("[data-dithane-countdown]");
    if (!el) { el = document.createElement("div"); el.setAttribute("data-dithane-countdown", ""); el.style.marginTop = "3px"; el.style.fontWeight = "700"; el.style.color = "#0e8a44"; note.appendChild(el); }
    if (dithaneSpraying) { el.textContent = "Dithane menyala sekarang"; return; }
    if (!nextSprayEpoch) { el.textContent = ""; return; }
    if (!countdownTarget || countdownTarget !== nextSprayEpoch) countdownTarget = nextSprayEpoch;
    let s = nextSprayEpoch - Math.floor(Date.now() / 1000);
    if (s < 0) s = 0;
    const hh = String(Math.floor(s / 3600)).padStart(2, "0");
    const mm = String(Math.floor((s % 3600) / 60)).padStart(2, "0");
    const ss = String(s % 60).padStart(2, "0");
    el.textContent = `Menyala dalam ${hh}:${mm}:${ss}`;
  }
  window.setInterval(renderCountdown, 1000);

  /* SINKRON STATUS: dari telemetri asli perangkat (bukan tebakan). */
  async function pollStatus() {
    try {
      const response = await fetch("../api/pump/status.php", { cache: "no-store" });
      const result = await response.json();
      if (!result.success || !result.data) return;
      const { pumps, device_online: online } = result.data;

      buttons.forEach((button) => {
        button.dataset.online = online ? "1" : "0";
        button.title = online
          ? "Klik untuk kontrol manual pompa ini"
          : "Perangkat ESP TIDAK TERHUBUNG - perintah tidak akan dieksekusi sampai perangkat online";
      });
      Object.entries(pumps || {}).forEach(([pump, info]) => {
        const button = buttons.find((b) => b.dataset.pump === pump);
        if (!button) return;
        render(button, info.state === "on");
        if (pump === "dithane") dithaneSpraying = info.state === "on";
      });
      if (typeof result.data.next_spray_epoch === "number") {
        nextSprayEpoch = result.data.next_spray_epoch;
      }
      renderCountdown();
    } catch (error) {
      console.error("Gagal membaca status pompa:", error);
    }
  }

  async function sendCommand(button, action, dur) {
    const body = new URLSearchParams({
      pump: button.dataset.pump,
      action,
      duration_s: String(dur),
      csrf_token: token(),
    });
    const response = await fetch("../api/pump/control.php", { method: "POST", body });
    return response.json();
  }

  buttons.forEach((button) => {
    render(button, false);
    button.addEventListener("click", async () => {
      const realOn = button.classList.contains("on");
      const action = realOn ? "off" : "on";
      const max = Number(button.dataset.pumpMax || 120);
      const dur = action === "on" ? DEFAULT_ON : max;
      const label = button.dataset.pumpLabel || button.dataset.pump;
      const offline = button.dataset.online === "0";
      const offlineNote = offline
        ? "\n\nPERINGATAN: Perangkat ESP sedang TIDAK TERHUBUNG - perintah ini tidak akan dieksekusi sampai perangkat online kembali."
        : "";
      const confirmText = (action === "on"
        ? `Kirim perintah ON pompa ${label} (${dur} detik, lalu kembali otomatis)?`
        : `Kirim perintah OFF pompa ${label} (otomatisasi berhenti ${dur} detik)?`) + offlineNote;

      if (window.Swal) {
        const ask = await Swal.fire({
          title: action === "on" ? "Nyalakan pompa?" : "Matikan pompa?",
          text: confirmText,
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "Kirim perintah",
          cancelButtonText: "Batal",
          reverseButtons: true,
        });
        if (!ask.isConfirmed) return;
      } else if (!window.confirm(confirmText)) {
        return;
      }

      button.classList.add("busy"); button.disabled = true;
      try {
        const result = await sendCommand(button, action, dur);
        if (result.success) {
          /* Optimistic lalu dikoreksi oleh telemetri saat poll berikutnya. */
          render(button, action === "on");
          if (window.Swal) {
            Swal.fire({
              title: "Perintah terkirim",
              text: result.message,
              icon: "success",
              timer: 2000,
              showConfirmButton: false,
            });
          }
        } else if (window.Swal) {
          Swal.fire({ title: "Perintah ditolak", text: result.message, icon: "error", timer: 3200, showConfirmButton: true });
        } else {
          window.alert(result.message);
        }
      } catch (error) {
        console.error("Gagal mengirim perintah pompa:", error);
      } finally {
        button.classList.remove("busy");
        button.disabled = false;          // selalu bisa diklik lagi tanpa refresh
        pollStatus();
      }
    });
  });

  pollStatus();
  window.setInterval(pollStatus, POLL_MS);
})();
