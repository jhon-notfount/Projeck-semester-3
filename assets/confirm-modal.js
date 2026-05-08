(function () {
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
})();
