/* Kontrol manual pompa HydroTech (kartu PPM, pH, Dithane).
 *  - Kirim perintah via api/pump/control.php (login + CSRF).
 *  - Status tombol disinkronkan dengan kondisi pompa sebenarnya dari
 *    api/pump/status.php (laporan perangkat, poll tiap 2,5 detik).
 *  - Override manual berdurasi: habis durasi, ESP32 kembali ke otomatisasi.
 */
(async function () {
  const buttons = Array.from(document.querySelectorAll(".pump-btn[data-pump]"));
  if (!buttons.length) return;

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const POLL_MS = 2500;
  /* Durasi default override (detik) per aksi — server membatasi maksimum. */
  const DEFAULT_DURATION = { on: 30, off: 300 };

  function token() {
    return csrfMeta ? csrfMeta.getAttribute("content") : "";
  }

  function setBusy(button, busy) {
    button.classList.toggle("busy", busy);
    button.disabled = busy || button.dataset.offline === "1";
  }

  /* RENDER STATUS: tombol mencerminkan pompa sebenarnya (bukan asumsi). */
  function renderPump(pump, info) {
    const button = buttons.find((b) => b.dataset.pump === pump);
    if (!button) return;
    const on = info.state === "on";
    button.classList.toggle("on", on);
    const label = button.querySelector("[data-pump-state]");
    if (label) label.textContent = on ? "ON" : "OFF";
    if (on && info.remaining_s) {
      button.title = `Manual ${info.remaining_s} s lagi, lalu otomatis`;
    } else if (!on && info.mode === "manual") {
      button.title = `Paksa OFF ${info.remaining_s} s lagi, lalu otomatis`;
    } else {
      button.title = on ? "Pompa aktif (otomatis)" : "Klik untuk kontrol manual";
    }
    button.dataset.mode = info.mode;
  }

  async function pollStatus() {
    try {
      const response = await fetch("../api/pump/status.php", { cache: "no-store" });
      const result = await response.json();
      if (!result.success || !result.data) return;
      const { pumps, device_online: online } = result.data;
      buttons.forEach((button) => {
        button.dataset.offline = online ? "0" : "1";
        button.disabled = button.classList.contains("busy") || !online;
        button.title = online ? button.title : "Perangkat ESP tidak terhubung";
      });
      Object.entries(pumps || {}).forEach(([pump, info]) => renderPump(pump, info));
    } catch (error) {
      /* Server tidak merespons: tombol tetap bisa dicoba saat pulih. */
      console.error("Gagal membaca status pompa:", error);
    }
  }

  async function sendCommand(button, action) {
    const body = new URLSearchParams({
      pump: button.dataset.pump,
      action,
      duration_s: String(DEFAULT_DURATION[action]),
      csrf_token: token(),
    });
    const response = await fetch("../api/pump/control.php", {
      method: "POST",
      body,
    });
    return response.json();
  }

  /* KLIK: konfirmasi per aksi. ON = nyalakan manual berdurasi default;
     OFF = paksa mati sementara; otomatisasi menyusul setelah durasi. */
  buttons.forEach((button) => {
    button.addEventListener("click", async () => {
      const isOn = button.classList.contains("on");
      const action = isOn ? "off" : "on";
      const dur = DEFAULT_DURATION[action];
      const label = button.dataset.pumpLabel || button.dataset.pump;
      const confirmText = isOn
        ? `Paksa pompa ${label} OFF selama ${dur} detik? Setelah itu otomatisasi berjalan lagi.`
        : `Nyalakan pompa ${label} secara manual selama ${dur} detik? Setelah itu otomatisasi berjalan lagi.`;

      if (window.Swal) {
        const ask = await Swal.fire({
          title: isOn ? "Paksa OFF pompa?" : "Nyalakan manual?",
          text: confirmText,
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: isOn ? "Ya, OFF" : "Ya, ON",
          cancelButtonText: "Batal",
          reverseButtons: true,
        });
        if (!ask.isConfirmed) return;
      } else if (!window.confirm(confirmText)) {
        return;
      }

      setBusy(button, true);
      try {
        const result = await sendCommand(button, action);
        if (!result.success) {
          if (window.Swal) {
            Swal.fire({ title: "Perintah ditolak", text: result.message, icon: "error", timer: 3200, showConfirmButton: true });
          } else {
            window.alert(result.message);
          }
        }
      } catch (error) {
        console.error("Gagal mengirim perintah pompa:", error);
      } finally {
        setBusy(button, false);
        pollStatus();
      }
    });
  });

  pollStatus();
  window.setInterval(pollStatus, POLL_MS);
})();
