(function () {
  const configs = {
    ppm: {
      inputPage: "input-ppm.html",
      settingPage: "pengaturan-ppm.html",
      storageKey: "hydrotech.ppmSettings",
      redirect: "pengaturan-ppm.html",
      defaults: { min: "800", max: "1000" },
      fields: [
        { key: "min", label: "PPM Minimum", unit: "PPM" },
        { key: "max", label: "PPM Maksimum", unit: "PPM" },
      ],
    },
    ph: {
      inputPage: "input-ph.html",
      settingPage: "pengaturan-ph.html",
      storageKey: "hydrotech.phSettings",
      redirect: "pengaturan-ph.html",
      defaults: { min: "5,5", max: "6,5" },
      fields: [
        { key: "min", label: "pH Minimum", unit: "pH" },
        { key: "max", label: "pH Maksimum", unit: "pH" },
      ],
    },
    dithane: {
      inputPage: "input-dithane.html",
      settingPage: "pengaturan-dithane.html",
      storageKey: "hydrotech.dithaneSettings",
      redirect: "pengaturan-dithane.html",
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

  function readSettings() {
    try {
      return {
        ...config.defaults,
        ...JSON.parse(localStorage.getItem(config.storageKey) || "{}"),
      };
    } catch (error) {
      return { ...config.defaults };
    }
  }

  function formatValue(value, unit) {
    let cleanValue = String(value || "").trim();
    if (unit === "WIB") {
      cleanValue = cleanValue.replace(":", ".");
    }
    return unit ? `${cleanValue} ${unit}` : cleanValue;
  }

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

  function applySettingsPage() {
    const settings = readSettings();

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

  function getDeletedRowsKey() {
    return `${config.storageKey}.deletedRows`;
  }

  function readDeletedRows() {
    try {
      return JSON.parse(localStorage.getItem(getDeletedRowsKey()) || "[]");
    } catch (error) {
      return [];
    }
  }

  function writeDeletedRows(rows) {
    localStorage.setItem(getDeletedRowsKey(), JSON.stringify(rows));
  }

  function setupDeleteRows() {
    const table = document.querySelector(".table-card table");
    if (!table || table.dataset.deleteReady === "true") return;

    const deletedRows = readDeletedRows();

    Array.from(table.querySelectorAll("tbody tr")).forEach((row) => {
      const rowId = row.dataset.rowId || row.children[0]?.textContent.trim();
      if (!rowId) return;
      row.dataset.rowId = rowId;
      if (deletedRows.includes(rowId)) {
        row.remove();
      }
    });

    table.dataset.deleteReady = "true";
    table.addEventListener("click", async function (event) {
      const deleteButton = event.target.closest(".trash-btn");
      if (!deleteButton) return;

      const row = deleteButton.closest("tr");
      const rowId = row?.dataset.rowId;
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

      const nextDeletedRows = Array.from(new Set([...readDeletedRows(), rowId]));
      writeDeletedRows(nextDeletedRows);
      row.remove();

      const filter = document.querySelector(".status-filter");
      if (filter && typeof filter.applyFilter === "function") {
        filter.applyFilter();
      }
    });

    const filter = document.querySelector(".status-filter");
    if (filter && typeof filter.applyFilter === "function") {
      filter.applyFilter();
    }
  }

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

    function openModal() {
      const latestSettings = readSettings();
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

    modal.querySelector("#settingsModalSave").addEventListener("click", function () {
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
      localStorage.setItem(config.storageKey, JSON.stringify(nextSettings));
      applySettingsPage();
      closeModal();
      showSuccessToast("Data telah berhasil diseting ulang.");
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal.classList.contains("show")) {
        closeModal();
      }
    });
  }

  function applyInputPage() {
    const settings = readSettings();
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
    saveButton.addEventListener("click", function () {
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
      localStorage.setItem(config.storageKey, JSON.stringify(nextSettings));
      showSuccessToast("Data telah berhasil diseting ulang.");
      saveButton.disabled = true;
      window.setTimeout(() => {
        window.location.href = config.redirect;
      }, 1800);
    });
  }

  if (currentPage === config.inputPage) {
    applyInputPage();
  } else {
    applySettingsPage();
  }
})();
