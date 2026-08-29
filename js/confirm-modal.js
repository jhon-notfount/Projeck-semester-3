(function () {
  /* CONFIRM ICON: memilih ikon SweetAlert berdasarkan jenis konfirmasi. */
  function getIcon(icon, variant) {
    if (variant === "danger") return "warning";
    if (icon === "R") return "question";
    if (icon === "X") return "warning";
    return "info";
  }

  /* SWEETALERT CONFIRM: dialog konfirmasi utama saat library SweetAlert tersedia. */
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

  /* FALLBACK MODAL: membuat modal konfirmasi manual jika SweetAlert belum dimuat. */
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

  /* CONFIRM FLOW: membuka dialog dan mengembalikan jawaban true atau false. */
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
  /* TOAST API: notifikasi sukses dan error yang bisa dipakai file JavaScript lain. */
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

  /* NOTIFICATION BELL: memuat daftar notifikasi dari API dan menampilkan panelnya. */
  async function setupNotificationBell() {
    const bell = document.querySelector(".bell");
    const admin = document.querySelector(".admin");
    if (!bell || !admin || bell.dataset.notificationReady === "true") return;

    bell.dataset.notificationReady = "true";
    bell.setAttribute("role", "button");
    bell.setAttribute("tabindex", "0");
    bell.setAttribute("aria-haspopup", "dialog");
    bell.setAttribute("aria-expanded", "false");
    bell.setAttribute("title", "Buka notifikasi");

    let isRead = false;
    let items = [];
    
    await fetch("../api/notifications/list.php")
      .then(response => response.json())
      .then(result => {
        if (result.success && result.data) {
          isRead = result.data.all_read;
          items = (result.data.notifications || []).map(n => ({
            label: n.label || "",
            text: n.message || "",
            time: n.created_at ? new Date(n.created_at).toLocaleString("id-ID", {hour:"2-digit",minute:"2-digit"}) : "",
            href: n.href || "#",
            tone: n.tone || "info",
          }));
        }
      })
      .catch(e => {
        console.error("Gagal memuat notifikasi", e);
      });

    bell.classList.toggle("read", isRead);

    const panel = document.createElement("section");
    panel.className = "notification-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Daftar notifikasi");
    
    const itemsHtml = items.map((item) => {
      // Pastikan URL mengarah ke .php
      const href = item.href ? item.href.replace(".html", ".php") : "#";
      return `
        <a class="notification-item ${item.tone || 'info'} ${isRead ? 'read' : ''}" href="${href}">
          <span class="notification-dot"></span>
          <span>
            <b>${item.label}</b>
            <small>${item.text}</small>
            <em>${item.time}</em>
          </span>
        </a>
      `;
    }).join("");

    panel.innerHTML = `
      <div class="notification-head">
        <div>
          <span>Notifikasi</span>
          <strong>Sistem Hidroponik</strong>
        </div>
        <button type="button" data-notification-read>Tandai dibaca</button>
      </div>
      <div class="notification-list">
        ${itemsHtml || '<div style="padding:16px;text-align:center;font-size:12px;color:#888;">Tidak ada notifikasi</div>'}
      </div>
    `;
    admin.appendChild(panel);

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
      ?.addEventListener("click", async function () {
          fetch("../api/notifications/mark-read.php", { method: "POST" })
            .then(response => response.json())
            .then(result => {
              if (result.success) {
                bell.classList.add("read");
                panel.querySelectorAll(".notification-item").forEach((item) => {
                  item.classList.add("read");
                });
              }
            })
            .catch(e => {
              console.error("Gagal menandai dibaca", e);
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

  /* LOGOUT CONFIRM: menampilkan konfirmasi sebelum keluar dari dashboard. */
  document.addEventListener("click", async function (event) {
    const logout = event.target.closest(".logout");
    if (!logout) return;

    event.preventDefault();
    const confirmed = await openConfirm({
      icon: "!",
      title: "Keluar dari Hydrotech?",
      message:
        "Anda akan keluar dari dashboard dan perlu login kembali untuk masuk.",
      confirmText: "Ya, Log out",
      variant: "danger",
    });

    if (confirmed) {
      await fetch("../api/auth/logout.php", { method: "POST" }).catch(e => {});
      window.location.href = "login.php";
    }
  });

  /* RESET CONFIRM: konfirmasi umum untuk tombol reset pada filter halaman. */
  document.addEventListener("click", async function (event) {
    const resetButton = event.target.closest(".filter-bar button");
    if (
      !resetButton ||
      resetButton.textContent.trim().toLowerCase() !== "reset"
    )
      return;

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

  /* TRASH CONFIRM: fallback konfirmasi hapus baris history jika tombol khusus tidak menangani. */
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

  /* GLOBAL INIT: menjalankan fitur umum setelah file dimuat. */
  setupNotificationBell();
})();
