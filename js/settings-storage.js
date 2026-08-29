(async function () {
  /* SETTINGS CONFIG: konfigurasi halaman PPM, pH, dan Dithane beserta nilai defaultnya. */
  const configs = {
    ppm: {
      type: "ppm",
      inputPage: "input-ppm.php",
      settingPage: "pengaturan-ppm.php",
      redirect: "pengaturan-ppm.php",
      defaults: { min: "800", max: "1000" },
      fields: [
        { key: "min", label: "PPM Minimum", unit: "PPM" },
        { key: "max", label: "PPM Maksimum", unit: "PPM" },
      ],
    },
    ph: {
      type: "ph",
      inputPage: "input-ph.php",
      settingPage: "pengaturan-ph.php",
      redirect: "pengaturan-ph.php",
      defaults: { min: "5,5", max: "6,5" },
      fields: [
        { key: "min", label: "pH Minimum", unit: "pH" },
        { key: "max", label: "pH Maksimum", unit: "pH" },
      ],
    },
    dithane: {
      type: "dithane",
      inputPage: "input-dithane.php",
      settingPage: "pengaturan-dithane.php",
      redirect: "pengaturan-dithane.php",
      defaults: { interval: "48", duration: "10", start: "08.00" },
      fields: [
        { key: "interval", label: "Interval Penyemprotan", unit: "Jam" },
        { key: "duration", label: "Durasi Penyemprotan", unit: "Detik" },
        { key: "start", label: "Jam Mulai", unit: "WIB" },
      ],
    },
  };

  const currentPage = window.location.pathname.split("/").pop();
  const config = Object.values(configs).find(
    (item) => item.inputPage === currentPage || item.settingPage === currentPage,
  );

  if (!config) return;

  /* STORAGE READ: membaca pengaturan dari API. */
  async function readSettings() {
    return fetch(`../api/settings/get.php?type=${config.type}`)
      .then((response) => response.json())
      .then((result) => {
        if (result.success && result.data) {
          const apiConfig = result.data.config || {};
          return {
            ...config.defaults,
            ...apiConfig,
            updatedAt: result.data.updated_at || "Baru saja",
          };
        }
        return { ...config.defaults };
      })
      .catch((error) => {
        console.error("Gagal membaca pengaturan dari API", error);
        return { ...config.defaults };
      });
  }

  /* VALUE FORMAT: menambahkan satuan PPM, pH, Jam, Detik, atau WIB ke tampilan. */
  function formatValue(value, unit) {
    let cleanValue = String(value || "").trim();
    if (unit === "WIB") {
      cleanValue = cleanValue.replace(":", ".");
    }
    return unit ? `${cleanValue} ${unit}` : cleanValue;
  }

  /* SUCCESS TOAST: menampilkan notifikasi berhasil setelah data pengaturan disimpan. */
  function showSuccessToast(message) {
    if (window.HydrotechToast?.success(message)) return;

    let toast = document.querySelector(".settings-toast");
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "settings-toast";
      toast.innerHTML = `
        <span class="settings-toast-icon">OK</span>
        <div>
          <strong>Berhasil</strong>
          <p></p>
        </div>
      `;
      document.body.appendChild(toast);
    }

    toast.querySelector("p").textContent =
      message || "Data telah berhasil diseting ulang.";
    toast.classList.remove("show");
    window.clearTimeout(toast.hideTimer);

    requestAnimationFrame(() => {
      toast.classList.add("show");
    });

    toast.hideTimer = window.setTimeout(() => {
      toast.classList.remove("show");
    }, 2500);
  }

  /* UPDATED TEXT: memperbarui info waktu terakhir pengaturan di kartu ringkasan. */
  function setUpdatedText(settings) {
    const updated = settings.updatedAt || "5 Menit yang lalu";
    document.querySelectorAll(".sum-card").forEach((card) => {
      const label = card.querySelector("small");
      const value = card.querySelector("b");
      if (!label || !value) return;
      if (label.textContent.toLowerCase().includes("terakhir diperbarui")) {
        value.textContent = updated;
      }
    });
  }

  /* SETTINGS PAGE: memasang nilai tersimpan ke halaman pengaturan dan fitur tabel. */
  async function applySettingsPage() {
    const settings = await readSettings();

    document.querySelectorAll(".sum-card").forEach((card) => {
      const label = card.querySelector("small");
      const value = card.querySelector("b");
      if (!label || !value) return;

      const labelText = label.textContent.toLowerCase();
      const field = config.fields.find((item) =>
        labelText.includes(item.label.toLowerCase()),
      );

      if (field && settings[field.key]) {
        value.textContent = formatValue(settings[field.key], field.unit);
      }
    });

    setUpdatedText(settings);
    setupStatusFilter();
    setupDeleteRows();
    setupEditModal(settings);
  }

  /* STATUS FILTER: memfilter tabel pengaturan berdasarkan status Normal, Rendah, atau Tinggi. */
  function setupStatusFilter() {
    const filter = document.querySelector(".status-filter");
    const table = document.querySelector(".table-card table");
    const showing = document.querySelector(".table-card .showing");
    if (!filter || !table || filter.dataset.ready === "true") return;

    const defaultShowingText = showing ? showing.textContent : "";

    function applyFilter() {
      const selectedStatus = filter.value.toLowerCase();
      let visibleCount = 0;
      const rows = Array.from(table.querySelectorAll("tbody tr"));

      rows.forEach((row) => {
        const statusCell = row.children[4];
        const statusText = statusCell ? statusCell.textContent.trim().toLowerCase() : "";
        const isVisible = !selectedStatus || statusText === selectedStatus;
        row.style.display = isVisible ? "" : "none";
        if (isVisible) visibleCount += 1;
      });

      if (showing) {
        showing.textContent = selectedStatus
          ? `Menampilkan ${visibleCount} data dengan status ${filter.value}`
          : defaultShowingText;
      }
    }

    filter.dataset.ready = "true";
    filter.addEventListener("change", applyFilter);
    filter.applyFilter = applyFilter;
    applyFilter();
  }

  /* DELETE ROWS: menghapus baris tabel setelah konfirmasi via API. */
  function setupDeleteRows() {
    const table = document.querySelector(".table-card table");
    if (!table || table.dataset.deleteReady === "true") return;

    table.dataset.deleteReady = "true";
    table.addEventListener("click", async function (event) {
      const deleteButton = event.target.closest(".trash-btn");
      if (!deleteButton) return;

      const row = deleteButton.closest("tr");
      const rowId = row?.dataset.rowId || row?.children[0]?.textContent.trim();
      if (!row || !rowId) return;

      if (window.HydrotechConfirm) {
        const confirmed = await window.HydrotechConfirm.open({
          icon: "X",
          title: "Hapus data monitoring?",
          message: "Data ini akan dihapus dari tabel dan tidak muncul lagi setelah halaman di-refresh.",
          confirmText: "Ya, Hapus",
          variant: "danger",
        });
        if (!confirmed) return;
      }

      const formData = new FormData();
      formData.append("id", rowId);
      
      fetch("../api/monitoring/delete.php", {
        method: "POST",
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          row.remove();
          const filter = document.querySelector(".status-filter");
          if (filter && typeof filter.applyFilter === "function") {
            filter.applyFilter();
          }
        } else {
          if (window.HydrotechToast?.error) {
             window.HydrotechToast.error("Gagal menghapus data.");
          } else {
             alert("Gagal menghapus data.");
          }
        }
      })
      .catch(error => {
        console.error("Delete error:", error);
      });
    });

    const filter = document.querySelector(".status-filter");
    if (filter && typeof filter.applyFilter === "function") {
      filter.applyFilter();
    }
  }

  /* EDIT MODAL: membuat isi modal edit berdasarkan field halaman yang sedang aktif. */
  function createModal(settings) {
    const fields = config.fields
      .map((field) => {
        const value =
          field.key === "start"
            ? String(settings[field.key] || "").replace(".", ":")
            : settings[field.key] || "";
        const isTimeField = field.key === "start";
        const type = isTimeField ? "time" : "text";
        const inputMode = isTimeField ? "" : "decimal";
        const fieldClass =
          isTimeField
            ? "settings-modal-field time-field"
            : "settings-modal-field";
        const clockIcon =
          isTimeField ? '<span class="settings-modal-clock">◷</span>' : "";
        const placeholder =
          isTimeField ? "08:00" : `Masukkan ${field.label.toLowerCase()}`;
        const timeAttributes = isTimeField
          ? 'step="60" data-time-picker="true"'
          : "";

        return `
          <label class="${fieldClass}">
            <span>${field.label}</span>
            <div class="settings-modal-control">
              ${clockIcon}
              <input
                type="${type}"
                inputmode="${inputMode}"
                data-setting-key="${field.key}"
                value="${value}"
                placeholder="${placeholder}"
                ${timeAttributes}
              />
              <small>${field.unit}</small>
            </div>
          </label>
        `;
      })
      .join("");

    const modal = document.createElement("div");
    modal.className = "settings-modal";
    modal.innerHTML = `
      <div class="settings-modal-backdrop" data-close-modal></div>
      <section class="settings-modal-card" role="dialog" aria-modal="true">
        <div class="settings-modal-head">
          <div class="settings-modal-title">
            <span class="settings-modal-icon">⚙</span>
            <div>
              <span>Edit Pengaturan</span>
              <h2>${document.querySelector(".page-title h1")?.textContent || "Pengaturan"}</h2>
              <p>Perbarui nilai sistem, lalu simpan untuk menerapkan perubahan.</p>
            </div>
          </div>
        </div>
        <div class="settings-modal-body">
          ${fields}
          <p class="settings-modal-message" id="settingsModalMessage"></p>
        </div>
        <div class="settings-modal-actions">
          <button type="button" class="btn-secondary" data-close-modal>Batal</button>
          <button type="button" class="btn-save" id="settingsModalSave">Simpan Perubahan</button>
        </div>
      </section>
    `;

    document.body.appendChild(modal);
    return modal;
  }

  /* EDIT FLOW: membuka modal, validasi input, menyimpan perubahan ke API, dan menutup modal. */
  function setupEditModal(settings) {
    const editButton = document.querySelector(".edit-btn");
    if (!editButton) return;
    if (document.querySelector(".settings-modal")) return;

    const modal = createModal(settings);
    const message = modal.querySelector("#settingsModalMessage");
    const inputs = Array.from(modal.querySelectorAll("[data-setting-key]"));
    const timeInput = modal.querySelector('[data-setting-key="start"]');

    if (timeInput) {
      function openTimePicker() {
        if (typeof timeInput.showPicker === "function") {
          try {
            timeInput.showPicker();
          } catch (error) {
            timeInput.focus();
          }
        }
      }

      timeInput.addEventListener("click", openTimePicker);
      timeInput.addEventListener("focus", openTimePicker);
      timeInput.addEventListener("keydown", function (event) {
        const allowedKeys = ["Tab", "Shift", "Escape", "Enter"];
        if (allowedKeys.includes(event.key)) return;
        event.preventDefault();
        openTimePicker();
      });
    }

    async function openModal() {
      const latestSettings = await readSettings();
      inputs.forEach((input) => {
        const key = input.dataset.settingKey;
        input.value =
          key === "start"
            ? String(latestSettings[key] || "").replace(".", ":")
            : latestSettings[key] || "";
      });
      message.textContent = "";
      modal.classList.add("show");
      document.body.classList.add("modal-open");
      inputs[0]?.focus();
    }

    function closeModal() {
      modal.classList.remove("show");
      document.body.classList.remove("modal-open");
    }

    editButton.addEventListener("click", function (event) {
      event.preventDefault();
      openModal();
    });

    modal.querySelectorAll("[data-close-modal]").forEach((button) => {
      button.addEventListener("click", closeModal);
    });

    modal.querySelector("#settingsModalSave").addEventListener("click", async function () {
      const nextSettings = {};
      let isValid = true;

      inputs.forEach((input) => {
        const value = input.value.trim();
        if (!value) isValid = false;
        nextSettings[input.dataset.settingKey] = value;
      });

      if (!isValid) {
        message.textContent = "Semua field wajib diisi sebelum menyimpan.";
        message.className = "settings-modal-message error";
        return;
      }

      nextSettings.updatedAt = "Baru saja";
      
      const formData = new FormData();
      formData.append("type", config.type);
      formData.append("config", JSON.stringify(nextSettings));
      
      fetch("../api/settings/update.php", {
        method: "POST",
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          applySettingsPage();
          closeModal();
          showSuccessToast("Data telah berhasil diseting ulang.");
        } else {
          message.textContent = "Gagal menyimpan perubahan.";
          message.className = "settings-modal-message error";
        }
      })
      .catch(e => {
        console.error(e);
        message.textContent = "Terjadi kesalahan jaringan.";
        message.className = "settings-modal-message error";
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal.classList.contains("show")) {
        closeModal();
      }
    });
  }

  /* INPUT PAGE: mengisi form input, reset nilai, batal, dan simpan pengaturan baru via API. */
  async function applyInputPage() {
    const settings = await readSettings();
    const inputs = Array.from(document.querySelectorAll(".field input"));

    config.fields.forEach((field, index) => {
      const input = inputs[index];
      if (!input) return;
      input.type = field.key === "start" ? "time" : "text";
      input.inputMode = field.key === "start" ? "numeric" : "decimal";
      input.value =
        field.key === "start"
          ? String(settings[field.key] || "").replace(".", ":")
          : settings[field.key] || "";
    });

    const saveButton = document.querySelector(".btn-save");
    const cancelButton = document.querySelector(".btn-secondary");
    const resetButton = document.querySelectorAll(".btn-secondary")[1];

    if (cancelButton) {
      cancelButton.type = "button";
      cancelButton.addEventListener("click", function () {
        window.location.href = config.redirect;
      });
    }

    if (resetButton) {
      resetButton.type = "button";
      resetButton.addEventListener("click", async function () {
        if (window.HydrotechConfirm) {
          const confirmed = await window.HydrotechConfirm.open({
            icon: "R",
            title: "Reset isi form?",
            message: "Semua input akan dikembalikan ke nilai awal.",
            confirmText: "Ya, Reset",
            variant: "danger",
          });
          if (!confirmed) return;
        }

        config.fields.forEach((field, index) => {
          if (!inputs[index]) return;
          inputs[index].value =
            field.key === "start"
              ? String(config.defaults[field.key] || "").replace(".", ":")
              : config.defaults[field.key] || "";
        });
      });
    }

    if (!saveButton) return;
    saveButton.type = "button";
    saveButton.addEventListener("click", async function () {
      const nextSettings = {};
      let isValid = true;

      config.fields.forEach((field, index) => {
        const input = inputs[index];
        const value = input ? input.value.trim() : "";
        if (!value) isValid = false;
        nextSettings[field.key] = value;
      });

      if (!isValid) {
        if (!window.HydrotechToast?.error("Semua field wajib diisi sebelum menyimpan.")) {
          alert("Semua field wajib diisi sebelum menyimpan.");
        }
        return;
      }

      nextSettings.updatedAt = "Baru saja";
      
      const formData = new FormData();
      formData.append("type", config.type);
      formData.append("config", JSON.stringify(nextSettings));
      
      fetch("../api/settings/update.php", {
        method: "POST",
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showSuccessToast("Data telah berhasil diseting ulang.");
          saveButton.disabled = true;
          window.setTimeout(() => {
            window.location.href = config.redirect;
          }, 1800);
        } else {
           if (!window.HydrotechToast?.error("Gagal menyimpan data.")) {
              alert("Gagal menyimpan data.");
           }
        }
      })
      .catch(e => {
         console.error(e);
         if (!window.HydrotechToast?.error("Kesalahan jaringan.")) {
            alert("Kesalahan jaringan.");
         }
      });
    });
  }

  /* PAGE ROUTER: memilih alur halaman input atau halaman pengaturan. */
  if (currentPage === config.inputPage) {
    await applyInputPage();
  } else {
    await applySettingsPage();
  }
})();
