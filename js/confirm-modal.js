(function () {
  function updateFeatureLabel() {
    const featureButton = document.querySelector(".nav-dropdown > .nav-item");
    if (!featureButton) return;

    const currentPage = window.location.pathname.split("/").pop();
    const labels = {
      "pengaturan-ppm.html": "Pengaturan PPM",
      "pengaturan-ph.html": "Pengaturan pH",
      "pengaturan-dithane.html": "Pengaturan Dithane",
    };
    const label = labels[currentPage] || "Fitur";

    Array.from(featureButton.childNodes).forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        node.textContent = "";
      }
    });

    let labelSpan = featureButton.querySelector("[data-feature-label]");
    if (!labelSpan) {
      labelSpan = document.createElement("span");
      labelSpan.dataset.featureLabel = "";
      const chevron = featureButton.querySelector(".chev");
      featureButton.insertBefore(labelSpan, chevron || null);
    }
    labelSpan.textContent = label;

    featureButton.classList.toggle("active", Boolean(labels[currentPage]));
  }

  function setupSmoothFeatureDropdown() {
    const dropdownMenu = document.getElementById("dropdownMenu");
    const dropdownButton = document.querySelector(".nav-dropdown > .nav-item");
    if (!dropdownMenu || !dropdownButton) return;

    function setOpen(isOpen) {
      dropdownMenu.classList.toggle("show", isOpen);
      dropdownButton.classList.toggle("open", isOpen);
    }

    window.toggleDropdown = function () {
      const shouldOpen = !dropdownMenu.classList.contains("show");
      requestAnimationFrame(() => setOpen(shouldOpen));
    };

    window.closeDropdown = function () {
      setOpen(false);
    };

    window.navigateToPage = function (event, pageUrl) {
      event.preventDefault();
      window.location.href = pageUrl;
    };
  }

  function setupSidebarClock() {
    const sidebar = document.querySelector(".sidebar");
    const logout = sidebar?.querySelector(".logout");
    if (!sidebar || !logout || sidebar.querySelector("[data-sidebar-clock]")) {
      return;
    }

    const clock = document.createElement("section");
    clock.className = "sidebar-clock";
    clock.dataset.sidebarClock = "";
    clock.setAttribute("aria-label", "Tanggal dan waktu WIB");
    clock.innerHTML = `
      <div class="sidebar-clock-icon" aria-hidden="true">
        <span></span>
      </div>
      <div>
        <span data-sidebar-date>Memuat tanggal...</span>
        <strong data-sidebar-time>--:--:-- WIB</strong>
      </div>
    `;

    sidebar.insertBefore(clock, logout);

    const dateText = clock.querySelector("[data-sidebar-date]");
    const timeText = clock.querySelector("[data-sidebar-time]");
    const dateFormatter = new Intl.DateTimeFormat("id-ID", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
      timeZone: "Asia/Jakarta",
    });
    const timeFormatter = new Intl.DateTimeFormat("id-ID", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false,
      timeZone: "Asia/Jakarta",
    });

    function renderClock() {
      const now = new Date();
      if (dateText) dateText.textContent = dateFormatter.format(now);
      if (timeText) timeText.textContent = `${timeFormatter.format(now)} WIB`;
    }

    renderClock();
    window.setInterval(renderClock, 1000);
  }

  function getIcon(icon, variant) {
    if (variant === "danger") return "warning";
    if (icon === "R") return "question";
    if (icon === "X") return "warning";
    return "info";
  }

  function openSweetAlert(options) {
    return Swal.fire({
      icon: getIcon(options.icon, options.variant),
      title: options.title || "Konfirmasi",
      text: options.message || "Apakah Anda yakin?",
      showCancelButton: true,
      confirmButtonText: options.confirmText || "Ya, Lanjutkan",
      cancelButtonText: options.cancelText || "Batal",
      reverseButtons: true,
      focusCancel: true,
      buttonsStyling: false,
      customClass: {
        popup: "hydrotech-swal",
        icon: "hydrotech-swal-icon",
        title: "hydrotech-swal-title",
        htmlContainer: "hydrotech-swal-text",
        actions: "hydrotech-swal-actions",
        confirmButton: `hydrotech-swal-confirm${options.variant === "danger" ? " danger" : ""}`,
        cancelButton: "hydrotech-swal-cancel",
      },
    }).then((result) => result.isConfirmed);
  }

  function ensureModal() {
    let modal = document.querySelector(".confirm-modal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.className = "confirm-modal";
    modal.innerHTML = `
      <div class="confirm-modal-backdrop" data-confirm-cancel></div>
      <section class="confirm-modal-card" role="dialog" aria-modal="true">
        <div class="confirm-modal-icon" id="confirmModalIcon">!</div>
        <h2 id="confirmModalTitle">Konfirmasi</h2>
        <p id="confirmModalText">Apakah Anda yakin?</p>
        <div class="confirm-modal-actions">
          <button type="button" class="confirm-cancel" data-confirm-cancel>Batal</button>
          <button type="button" class="confirm-ok" id="confirmModalOk">Ya, Lanjutkan</button>
        </div>
      </section>
    `;

    document.body.appendChild(modal);
    return modal;
  }

  function openConfirm(options) {
    if (window.Swal) {
      return openSweetAlert(options || {});
    }

    const modal = ensureModal();
    const icon = modal.querySelector("#confirmModalIcon");
    const title = modal.querySelector("#confirmModalTitle");
    const text = modal.querySelector("#confirmModalText");
    const okButton = modal.querySelector("#confirmModalOk");

    icon.textContent = options.icon || "!";
    title.textContent = options.title || "Konfirmasi";
    text.textContent = options.message || "Apakah Anda yakin?";
    okButton.textContent = options.confirmText || "Ya, Lanjutkan";
    okButton.classList.toggle("danger", options.variant === "danger");

    modal.classList.add("show");
    document.body.classList.add("modal-open");

    return new Promise((resolve) => {
      function close(result) {
        modal.classList.remove("show");
        document.body.classList.remove("modal-open");
        okButton.removeEventListener("click", onOk);
        modal.querySelectorAll("[data-confirm-cancel]").forEach((button) => {
          button.removeEventListener("click", onCancel);
        });
        document.removeEventListener("keydown", onKeydown);
        resolve(result);
      }

      function onOk() {
        close(true);
      }

      function onCancel() {
        close(false);
      }

      function onKeydown(event) {
        if (event.key === "Escape") close(false);
      }

      okButton.addEventListener("click", onOk);
      modal.querySelectorAll("[data-confirm-cancel]").forEach((button) => {
        button.addEventListener("click", onCancel);
      });
      document.addEventListener("keydown", onKeydown);
      okButton.focus();
    });
  }

  window.HydrotechConfirm = { open: openConfirm };
  window.HydrotechToast = {
    success: function (message) {
      let toast = document.querySelector(".settings-toast");
      if (!toast) {
        toast = document.createElement("div");
        toast.className = "settings-toast";

        const icon = document.createElement("span");
        icon.className = "settings-toast-icon";
        icon.setAttribute("aria-hidden", "true");

        const copy = document.createElement("div");
        const title = document.createElement("strong");
        const text = document.createElement("p");

        title.textContent = "Berhasil";
        copy.append(title, text);
        toast.append(icon, copy);
        document.body.appendChild(toast);
      }

      const text = toast.querySelector("p");
      if (text) text.textContent = message || "Data berhasil diperbarui.";

      toast.classList.remove("show");
      window.clearTimeout(toast.hideTimer);

      requestAnimationFrame(() => {
        toast.classList.add("show");
      });

      toast.hideTimer = window.setTimeout(() => {
        toast.classList.remove("show");
      }, 2400);

      return true;
    },
    error: function (message) {
      if (!window.Swal) return false;
      Swal.fire({
        icon: "error",
        title: "Periksa kembali",
        text: message || "Ada data yang belum sesuai.",
        confirmButtonText: "Mengerti",
        buttonsStyling: false,
        customClass: {
          popup: "hydrotech-swal",
          confirmButton: "hydrotech-swal-confirm danger",
        },
      });
      return true;
    },
  };

  function getNotificationItems() {
    const currentPage = window.location.pathname.split("/").pop();
    const baseItems = [
      {
        label: "pH perlu dipantau",
        text: "Nilai pH terakhir mendekati batas atas.",
        time: "2 menit lalu",
        href: "pengaturan-ph.html",
        tone: "warning",
      },
      {
        label: "PPM stabil",
        text: "Nutrisi berada dalam rentang aman.",
        time: "5 menit lalu",
        href: "pengaturan-ppm.html",
        tone: "success",
      },
      {
        label: "Jadwal Dithane aktif",
        text: "Penyemprotan berikutnya mengikuti jadwal otomatis.",
        time: "12 menit lalu",
        href: "pengaturan-dithane.html",
        tone: "info",
      },
    ];

    if (currentPage === "history.html") {
      return [
        {
          label: "History diperbarui",
          text: "Data monitoring terbaru sudah masuk ke tabel.",
          time: "Baru saja",
          href: "history.html",
          tone: "success",
        },
        ...baseItems.slice(0, 2),
      ];
    }

    return baseItems;
  }

  function setupNotificationBell() {
    const bell = document.querySelector(".bell");
    const admin = document.querySelector(".admin");
    if (!bell || !admin || bell.dataset.notificationReady === "true") return;

    const readKey = "hydrotech.notificationsRead";
    const isRead = localStorage.getItem(readKey) === "true";

    bell.dataset.notificationReady = "true";
    bell.setAttribute("role", "button");
    bell.setAttribute("tabindex", "0");
    bell.setAttribute("aria-haspopup", "dialog");
    bell.setAttribute("aria-expanded", "false");
    bell.setAttribute("title", "Buka notifikasi");
    bell.classList.toggle("read", isRead);

    const panel = document.createElement("section");
    panel.className = "notification-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Daftar notifikasi");
    panel.innerHTML = `
      <div class="notification-head">
        <div>
          <span>Notifikasi</span>
          <strong>Sistem Hidroponik</strong>
        </div>
        <button type="button" data-notification-read>Tandai dibaca</button>
      </div>
      <div class="notification-list">
        ${getNotificationItems()
          .map(
            (item) => `
              <a class="notification-item ${item.tone}" href="${item.href}">
                <span class="notification-dot"></span>
                <span>
                  <b>${item.label}</b>
                  <small>${item.text}</small>
                  <em>${item.time}</em>
                </span>
              </a>
            `,
          )
          .join("")}
      </div>
    `;
    admin.appendChild(panel);

    if (isRead) {
      panel.querySelectorAll(".notification-item").forEach((item) => {
        item.classList.add("read");
      });
    }

    function setOpen(isOpen) {
      panel.classList.toggle("show", isOpen);
      bell.classList.toggle("open", isOpen);
      bell.setAttribute("aria-expanded", String(isOpen));
    }

    function togglePanel() {
      setOpen(!panel.classList.contains("show"));
    }

    bell.addEventListener("click", togglePanel);
    bell.addEventListener("keydown", function (event) {
      if (event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
      togglePanel();
    });

    panel
      .querySelector("[data-notification-read]")
      .addEventListener("click", function () {
        localStorage.setItem(readKey, "true");
        bell.classList.add("read");
        panel.querySelectorAll(".notification-item").forEach((item) => {
          item.classList.add("read");
        });
      });

    document.addEventListener("click", function (event) {
      if (!panel.classList.contains("show")) return;
      if (admin.contains(event.target)) return;
      setOpen(false);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && panel.classList.contains("show")) {
        setOpen(false);
        bell.focus();
      }
    });
  }

  document.addEventListener("click", async function (event) {
    const logout = event.target.closest(".logout");
    if (!logout) return;

    event.preventDefault();
    const confirmed = await openConfirm({
      icon: "!",
      title: "Keluar dari Hydrotech?",
      message: "Anda akan keluar dari dashboard dan perlu login kembali untuk masuk.",
      confirmText: "Ya, Log out",
      variant: "danger",
    });

    if (confirmed) {
      window.location.href = "login.html";
    }
  });

  document.addEventListener("click", async function (event) {
    const resetButton = event.target.closest(".filter-bar button");
    if (!resetButton || resetButton.textContent.trim().toLowerCase() !== "reset") return;

    event.preventDefault();
    const confirmed = await openConfirm({
      icon: "R",
      title: "Reset filter data?",
      message: "Filter pencarian akan dikembalikan ke kondisi awal.",
      confirmText: "Ya, Reset",
      variant: "danger",
    });

    if (!confirmed) return;

    const filterBar = resetButton.closest(".filter-bar");
    filterBar?.querySelectorAll("input").forEach((input) => {
      input.value = "";
    });
    filterBar?.querySelectorAll("select").forEach((select) => {
      select.selectedIndex = 0;
    });
  });

  document.addEventListener("click", async function (event) {
    if (event.target.closest(".trash-btn")) return;

    const trashCell = event.target.closest(".history-card .trash");
    if (!trashCell) return;

    event.preventDefault();
    const confirmed = await openConfirm({
      icon: "X",
      title: "Hapus data history?",
      message: "Baris data ini akan dihapus dari tabel history.",
      confirmText: "Ya, Hapus",
      variant: "danger",
    });

    if (confirmed) {
      trashCell.closest("tr")?.remove();
    }
  });

  updateFeatureLabel();
  setupSmoothFeatureDropdown();
  setupSidebarClock();
  setupNotificationBell();
})();
