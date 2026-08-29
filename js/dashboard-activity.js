(function () {
  /* ACTIVITY SETUP: mengambil elemen riwayat aktivitas dan filter dashboard. */
  const section = document.querySelector("[data-activity-section]");
  if (!section) return;

  const filterButtons = Array.from(
    section.querySelectorAll("[data-activity-filter]"),
  );
  const rows = Array.from(section.querySelectorAll("[data-activity-type]"));
  const empty = section.querySelector("[data-activity-empty]");
  const counter = section.querySelector("[data-activity-count]");
  const dropdown = section.querySelector("[data-activity-dropdown]");
  const toggle = section.querySelector("[data-activity-toggle]");
  const label = section.querySelector("[data-activity-label]");

  /* DROPDOWN CONTROL: menutup menu filter setelah dipilih atau klik di luar area. */
  function closeDropdown() {
    dropdown?.classList.remove("open");
    toggle?.setAttribute("aria-expanded", "false");
  }

  /* ACTIVITY FILTER: menampilkan aktivitas sesuai kategori PPM, pH, Dithane, atau semua. */
  function applyFilter(filter) {
    let visibleCount = 0;

    rows.forEach((row) => {
      const isVisible = filter === "all" || row.dataset.activityType === filter;
      row.hidden = !isVisible;
      row.classList.toggle("activity-hidden", !isVisible);
      if (isVisible) visibleCount += 1;
    });

    filterButtons.forEach((button) => {
      const isActive = button.dataset.activityFilter === filter;
      button.classList.toggle("active", isActive);
      button.setAttribute("aria-selected", String(isActive));
      if (isActive && label) {
        label.textContent = button.textContent.trim();
      }
    });

    if (empty) empty.classList.toggle("show", visibleCount === 0);
    if (counter) {
      counter.textContent =
        visibleCount > 0
          ? `Menampilkan ${visibleCount} aktivitas`
          : "Tidak ada aktivitas";
    }
  }

  /* FILTER EVENTS: menghubungkan tombol filter dengan tampilan daftar aktivitas. */
  filterButtons.forEach((button) => {
    button.addEventListener("click", function () {
      applyFilter(button.dataset.activityFilter || "all");
      closeDropdown();
    });
  });

  /* DROPDOWN EVENTS: membuka menu filter dan menutupnya saat klik di luar atau tekan Escape. */
  toggle?.addEventListener("click", function (event) {
    event.stopPropagation();
    if (!dropdown) return;
    dropdown.classList.toggle("open");
    const isOpen = dropdown.classList.contains("open");
    toggle.setAttribute("aria-expanded", String(isOpen));
  });

  document.addEventListener("click", function (event) {
    if (!dropdown || dropdown.contains(event.target)) return;
    closeDropdown();
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeDropdown();
  });

  /* INITIAL RENDER: menampilkan semua aktivitas saat halaman pertama kali dibuka. */
  applyFilter("all");
})();
