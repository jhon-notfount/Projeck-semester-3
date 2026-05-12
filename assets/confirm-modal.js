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
      if (!window.Swal) return false;
      Swal.fire({
        icon: "success",
        title: "Berhasil",
        text: message || "Data berhasil diperbarui.",
        timer: 2200,
        showConfirmButton: false,
        toast: true,
        position: "top-end",
        customClass: {
          popup: "hydrotech-toast",
          title: "hydrotech-toast-title",
          htmlContainer: "hydrotech-toast-text",
        },
      });
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
})();
