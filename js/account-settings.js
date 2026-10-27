/* ACCOUNT SETTINGS: komponen pop-up pengelolaan akun Hydrotech.
   Dipakai dua halaman:
   - Login   : HydrotechAccount.openResetModal()  -> reset password (verifikasi password lama).
   - Profile : HydrotechAccount.openSettingsModal() -> dua tab (ubah username / ubah password).
   Komponen membangun DOM sendiri, memakai CSS bersama css/account-modal.css,
   mengirim CSRF dari meta tag, dan menampilkan pesan validasi di dekat kolom. */
(function () {
  /* ICONS: ikon SVG inline agar tidak bergantung library eksternal. */
  const ICONS = {
    gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1A2 2 0 1 1 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3h.1A1.7 1.7 0 0 0 10 3.1V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    eyeOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="M2 2l20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>',
    lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
  };

  let activeModal = null;

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") || "" : "";
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  /* FIELD DEFINITIONS: tiap form dideklarasikan sebagai daftar kolom. */
  function field(type, name, label, options) {
    options = options || {};
    return { type, name, label, ...options };
  }

  function fieldHtml(f) {
    const isPassword = f.type === "password";
    const input = `
      <div class="acc-control">
        <input type="${isPassword ? "password" : "text"}" name="${f.name}"
          value="${escapeHtml(f.value || "")}"
          placeholder="${escapeHtml(f.placeholder || f.label)}"
          autocomplete="${isPassword ? (f.current ? "current-password" : "new-password") : "off"}"
          ${f.required ? "required" : ""} ${f.readonly ? "readonly" : ""} />
        ${isPassword ? `<button type="button" class="acc-eye" data-acc-eye title="Tampilkan/sembunyikan password">${ICONS.eye}</button>` : ""}
      </div>`;
    return `
      <label class="acc-field" data-acc-field="${f.name}">
        <span>${escapeHtml(f.label)}</span>
        ${input}
        <small class="acc-field-error" data-acc-error-for="${f.name}"></small>
      </label>`;
  }

  function modalHtml(config) {
    const tabs = config.tabs ? `
      <div class="acc-tabs" role="tablist">
        ${config.tabs.map((tab, index) => `
          <button type="button" class="acc-tab ${index === 0 ? "active" : ""}" role="tab"
            data-acc-tab="${tab.id}">${escapeHtml(tab.label)}</button>`).join("")}
      </div>` : "";

    const forms = (config.tabs || [{ id: config.formId, submitLabel: config.submitLabel, fields: config.fields, endpoint: config.endpoint }])
      .map((tab, index) => `
        <form class="acc-modal-body ${index > 0 ? "hidden" : ""}" data-acc-form="${tab.id}"
          data-endpoint="${tab.endpoint}" novalidate>
          ${tab.notice ? `<p class="acc-hint">${escapeHtml(tab.notice)}</p>` : ""}
          ${tab.fields.map(fieldHtml).join("")}
          <footer class="acc-modal-actions">
            <button type="button" class="acc-btn-secondary" data-acc-close>Batalkan</button>
            <button type="submit" class="acc-btn-primary" data-acc-submit>${escapeHtml(tab.submitLabel)}</button>
          </footer>
        </form>`).join("");

    return `
      <div class="acc-modal-backdrop" data-acc-close></div>
      <section class="acc-modal-card" role="dialog" aria-modal="true" aria-label="${escapeHtml(config.title)}">
        <header class="acc-modal-head">
          <div class="acc-modal-icon">${config.icon === "user" ? ICONS.user : ICONS.lock}</div>
          <div>
            <span>${escapeHtml(config.kicker || "Hydrotech")}</span>
            <h2>${escapeHtml(config.title)}</h2>
          </div>
          <button type="button" class="acc-modal-close" data-acc-close aria-label="Tutup">&times;</button>
        </header>
        ${tabs}
        ${forms}
      </section>`;
  }

  /* FIELD HELPERS: pesan validasi dan status loading. */
  function showFieldError(form, fieldName, message) {
    const fieldEl = form.querySelector(`[data-acc-field="${fieldName}"]`);
    if (!fieldEl) {
      const general = form.querySelector("[data-acc-general]");
      if (general) {
        general.textContent = message;
        general.classList.add("show");
      }
      return;
    }
    fieldEl.classList.add("invalid");
    const errorEl = fieldEl.querySelector("[data-acc-error-for]");
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.classList.add("show");
    }
  }

  function clearErrors(form) {
    form.querySelectorAll(".acc-field.invalid").forEach((el) => el.classList.remove("invalid"));
    form.querySelectorAll(".acc-field-error.show").forEach((el) => {
      el.classList.remove("show");
      el.textContent = "";
    });
  }

  function setSubmitting(form, isSubmitting, loadingText) {
    const button = form.querySelector("[data-acc-submit]");
    if (!button) return;
    if (isSubmitting) {
      button.dataset.originalText = button.textContent;
      button.textContent = loadingText || "Menyimpan...";
      button.disabled = true;
    } else {
      button.textContent = button.dataset.originalText || button.textContent;
      button.disabled = false;
    }
  }

  function showSwal(icon, title, text, timer) {
    if (window.Swal) {
      return window.Swal.fire({
        icon,
        title,
        text,
        timer: timer || 2000,
        showConfirmButton: !timer,
        buttonsStyling: false,
        customClass: {
          popup: "hydrotech-swal",
          icon: "hydrotech-swal-icon",
          title: "hydrotech-swal-title",
          htmlContainer: "hydrotech-swal-text",
          actions: "hydrotech-swal-actions",
          confirmButton: "hydrotech-swal-confirm",
        },
      });
    }
    window.alert(`${title}: ${text}`);
    return Promise.resolve();
  }

  /* CLIENT VALIDATION: umpan balik cepat sebelum request (server tetap otoritatif). */
  function clientValidate(form, values) {
    const errors = [];
    for (const f of values.fields) {
      if (!f.required) continue;
      if (!String(values.data[f.name] || "").trim()) {
        errors.push([f.name, `${f.label} wajib diisi.`]);
      }
    }
    if (values.mode === "reset") {
      if (!errors.length && values.data.new_password !== values.data.confirm_password) {
        errors.push(["confirm_password", "Konfirmasi password baru tidak cocok."]);
      }
      if (!errors.length && values.data.new_password.length < 8) {
        errors.push(["new_password", "Password baru minimal 8 karakter."]);
      }
    }
    if (values.mode === "password") {
      if (!errors.length && values.data.new_password !== values.data.confirm_password) {
        errors.push(["confirm_password", "Konfirmasi password baru tidak cocok."]);
      }
      if (!errors.length && values.data.new_password.length < 8) {
        errors.push(["new_password", "Password baru minimal 8 karakter."]);
      }
      if (!errors.length && values.data.new_password === values.data.current_password) {
        errors.push(["new_password", "Password baru harus berbeda dari password lama."]);
      }
    }
    if (values.mode === "username" && !errors.length && values.data.new_username.trim() === values.currentUsername) {
      errors.push(["new_username", "Username baru sama dengan username saat ini."]);
    }
    return errors;
  }

  /* SUBMIT: kirim ke endpoint, tampilkan pesan validasi dari server di kolom terkait. */
  async function submitForm(form, onSuccess) {
    clearErrors(form);
    const endpoint = form.dataset.endpoint;
    const payload = {};
    form.querySelectorAll("input").forEach((input) => {
      payload[input.name] = input.value;
    });

    const mode = form.dataset.accForm;
    const context = {
      mode,
      data: payload,
      fields: FIELD_MAP[mode],
      currentUsername: form.dataset.currentUsername || "",
    };

    const clientErrors = clientValidate(form, context);
    if (clientErrors.length) {
      clientErrors.forEach(([fieldName, message]) => showFieldError(form, fieldName, message));
      return;
    }

    const body = new FormData();
    Object.entries(payload).forEach(([key, value]) => body.append(key, value));
    body.append("csrf_token", csrfToken());

    setSubmitting(form, true, "Menyimpan...");
    try {
      const response = await fetch(endpoint, { method: "POST", body });
      const result = await response.json();
      if (result.success) {
        onSuccess(result);
        return;
      }
      if (result.field) showFieldError(form, result.field, result.message);
      else showSwal("error", "Gagal", result.message || "Terjadi kesalahan.");
      setSubmitting(form, false);
    } catch (error) {
      showSwal("error", "Error", "Tidak dapat terhubung ke server.");
      setSubmitting(form, false);
    }
  }

  const FIELD_MAP = {
    reset: [
      field("text", "username", "Username", { required: true }),
      field("password", "old_password", "Password Lama", { required: true, current: true }),
      field("password", "new_password", "Password Baru", { required: true }),
      field("password", "confirm_password", "Konfirmasi Password Baru", { required: true }),
    ],
    username: [
      field("text", "current_username_display", "Username Saat Ini", { readonly: true }),
      field("text", "new_username", "Username Baru", { required: true }),
      field("password", "current_password", "Password Saat Ini", { required: true, current: true }),
    ],
    password: [
      field("password", "current_password", "Password Saat Ini", { required: true, current: true }),
      field("password", "new_password", "Password Baru", { required: true }),
      field("password", "confirm_password", "Konfirmasi Password Baru", { required: true }),
    ],
  };

  function bindModalEvents(modal, config) {
    function close() {
      modal.classList.remove("show");
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("modal-open");
      document.removeEventListener("keydown", onKeydown);
      setTimeout(() => modal.remove(), 240);
      if (activeModal === modal) activeModal = null;
    }
    modal._close = close;

    modal.querySelectorAll("[data-acc-close]").forEach((el) => el.addEventListener("click", close));
    modal.addEventListener("click", (event) => {
      const eye = event.target.closest("[data-acc-eye]");
      if (eye) {
        const input = eye.parentElement.querySelector("input");
        const showing = input.type === "text";
        input.type = showing ? "password" : "text";
        eye.innerHTML = showing ? ICONS.eye : ICONS.eyeOff;
        return;
      }
      const tab = event.target.closest("[data-acc-tab]");
      if (tab) {
        modal.querySelectorAll(".acc-tab").forEach((t) => t.classList.toggle("active", t === tab));
        modal.querySelectorAll("[data-acc-form]").forEach((f) => {
          f.classList.toggle("hidden", f.dataset.accForm !== tab.dataset.accTab);
        });
      }
    });
    modal.querySelectorAll("[data-acc-form]").forEach((form) => {
      form.addEventListener("input", (event) => {
        const fieldEl = event.target.closest(".acc-field");
        if (fieldEl && fieldEl.classList.contains("invalid")) {
          fieldEl.classList.remove("invalid");
          const errorEl = fieldEl.querySelector(".acc-field-error.show");
          if (errorEl) errorEl.classList.remove("show");
        }
      });
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        submitForm(form, (result) => config.onSuccess(result, form));
      });
    });
    document.addEventListener("keydown", onKeydown);
    function onKeydown(event) {
      if (event.key === "Escape" && modal.classList.contains("show")) close();
    }

    const firstInput = modal.querySelector(".acc-modal-body:not(.hidden) input");
    if (firstInput) firstInput.focus({ preventScroll: true });
  }

  function openModal(config) {
    if (activeModal) activeModal._close();
    const modal = document.createElement("div");
    modal.className = "acc-modal";
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = modalHtml(config);
    document.body.appendChild(modal);
    activeModal = modal;
    bindModalEvents(modal, config);
    requestAnimationFrame(() => modal.classList.add("show"));
    document.body.classList.add("modal-open");
    return modal;
  }

  /* PUBLIC API: dua pop-up pengelolaan akun. */
  window.HydrotechAccount = {
    /* RESET PASSWORD (halaman login): verifikasi username + password lama. */
    openResetModal(options) {
      options = options || {};
      openModal({
        kicker: "Verifikasi Akun",
        title: "Reset Password",
        icon: "lock",
        formId: "reset",
        endpoint: "../api/auth/reset-password.php",
        submitLabel: "Reset Password",
        notice: "Masukkan username dan password lama untuk verifikasi, lalu buat password baru.",
        fields: [
          { ...FIELD_MAP.reset[0], value: options.username || "" },
          ...FIELD_MAP.reset.slice(1),
        ],
        onSuccess: async (result, form) => {
          form.querySelector("[data-acc-submit]").textContent = "Tersimpan!";
          await showSwal(
            "success",
            "Password Berhasil Direset",
            "Silakan login menggunakan password baru Anda.",
            2200
          );
          window.HydrotechAccount.closeActive();
        },
      });
    },

    /* PENGATURAN AKUN (halaman profile): dua tab username/password. */
    openSettingsModal(options) {
      options = options || {};
      openModal({
        kicker: "Hydrotech",
        title: "Pengaturan Akun",
        icon: "user",
        tabs: [
          {
            id: "username",
            label: "Ubah Username",
            endpoint: "../api/account/change-username.php",
            submitLabel: "Simpan Username",
            notice: "Username dipakai untuk login berikutnya dan tampil di header dashboard.",
            fields: [
              { ...FIELD_MAP.username[0], value: options.username || "" },
              ...FIELD_MAP.username.slice(1),
            ],
          },
          {
            id: "password",
            label: "Ubah Password",
            endpoint: "../api/account/change-password.php",
            submitLabel: "Simpan Password",
            notice: "Password minimal 8 karakter, mengandung huruf dan angka. Sesi lain akan otomatis logout.",
            fields: FIELD_MAP.password,
          },
        ],
        onSuccess: (result, form) => {
          if (form.dataset.accForm === "username") {
            document.querySelectorAll(".admin-name").forEach((el) => {
              el.textContent = result.data.username;
            });
            document.querySelectorAll(".admin-a").forEach((el) => {
              el.textContent = (result.data.username || "A").charAt(0).toUpperCase();
            });
            document.querySelectorAll(".page-title p").forEach((el) => {
              el.textContent = `Selamat datang kembali, ${result.data.username}`;
            });
          }
          const messages = {
            username: ["Username Berhasil Diubah", `Login berikutnya menggunakan username: ${result.data.username}`],
            password: ["Password Berhasil Diubah", "Gunakan password baru untuk login berikutnya."],
          };
          const [title, text] = messages[form.dataset.accForm];
          showSwal("success", title, text, 2000);
          window.HydrotechAccount.closeActive();
        },
      });
    },

    closeActive() {
      if (activeModal) {
        activeModal._close();
        activeModal = null;
      }
      document.body.classList.remove("modal-open");
    },
  };
})();
