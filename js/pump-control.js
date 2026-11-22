/* Kontrol manual pompa HydroTech (kartu PPM, pH, Dithane).
 * KEPUTUSAN DESAIN: tombol = pengirim perintah LANGSUNG via MQTT
 * (api/pump/control.php -> hydrotech/cmd/esp32/pump, QoS 1, tanpa retained,
 * tanpa tabel antrean/status di database). Teks tombol = perintah terakhir
 * yang dikirim sesi ini (bukan kondisi relay sebenarnya).
 * Perangkat dicek via api/device/status.php (kesegaran sensor) agar
 * tombol dinonaktifkan saat ESP tidak terhubung.
 */
(async function () {
  const buttons = Array.from(document.querySelectorAll(".pump-btn[data-pump]"));
  if (!buttons.length) return;

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const POLL_MS = 5000;
  /* Durasi default override (detik) per aksi — server membatasi maksimum. */
  const DEFAULT_ON = 30;

  function token() {
    return csrfMeta ? csrfMeta.getAttribute("content") : "";
  }

  /* Status perangkat: sensor terakhir masuk < 300 s = online. */
  async function checkDevice() {
    try {
      const r = await (await fetch("../api/device/status.php", { cache: "no-store" })).json();
      return !!(r.success && r.data && r.data.status === "online");
    } catch { return false; }
  }

  function render(button, on, note) {
    button.classList.toggle("on", on);
    const label = button.querySelector("[data-pump-state]");
    if (label) label.textContent = on ? "ON" : "OFF";
    button.title = note
      || (on ? "Perintah ON terkirim; pompa kembali otomatis setelah durasi"
             : "Klik untuk menyalakan pompa secara manual");
  }

  async function pollDevice() {
    const online = await checkDevice();
    buttons.forEach((b) => { b.disabled = !online && !b.classList.contains("busy"); });
  }

  async function sendCommand(button, action) {
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
      const isOn = button.classList.contains("on");
      const action = isOn ? "off" : "on";
      const max = Number(button.dataset.pumpMax || 120);
      const dur = action === "on" ? DEFAULT_ON : max;   // OFF = batas maksimum pompa
      const label = button.dataset.pumpLabel || button.dataset.pump;
      const confirmText = action === "on"
        ? `Kirim perintah ON pompa ${label} (${dur} detik, lalu kembali otomatis)?`
        : `Kirim perintah OFF pompa ${label} (otomatisasi berhenti ${dur} detik)?`;

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
        const result = await sendCommand(button, action);
        if (result.success) {
          render(button, action === "on");        // teks tombol = perintah terakhir
          if (window.Swal) {
            Swal.fire({
              title: "Perintah terkirim",
              text: result.message,
              icon: "success",
              timer: 2500,
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
        pollDevice();
      }
    });
  });

  pollDevice();
  window.setInterval(pollDevice, POLL_MS);
})();
