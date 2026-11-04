(function () {
  const SIDEBAR_TEMPLATE = `
<!-- SIDEBAR: logo Hydrotech, navigasi utama, dropdown fitur, jam, dan logout. -->
<aside class="sidebar">
  <div class="side-logo">
    <div class="side-mark">
      <img src="../assets/Logo_hydrotech.png" alt="Logo Hydrotech" />
    </div>
    <div class="side-brand">
      <strong>Hydrotech</strong><span>Dashboard Admin</span>
    </div>
  </div>
  <nav class="nav">
    <a class="nav-item" href="hydrotech-dashboard-panel-2026.php" data-sidebar-page="hydrotech-dashboard-panel-2026.php">
      <span class="ico ico-dashboard" aria-hidden="true"></span>
      <span>Dashboard</span>
    </a>
    <div class="nav-dropdown">
      <button class="nav-item" type="button" data-sidebar-dropdown-toggle>
        <span class="ico ico-features" aria-hidden="true"></span>
        <span data-feature-label>Setting</span>
        <span class="chev">v</span>
      </button>
      <div class="dropdown-menu" id="dropdownMenu">
        <a href="pengaturan-ppm.php" class="dropdown-item" data-sidebar-page="pengaturan-ppm.php">Setting PPM</a>
        <a href="pengaturan-ph.php" class="dropdown-item" data-sidebar-page="pengaturan-ph.php">Setting pH</a>
        <a href="pengaturan-dithane.php" class="dropdown-item" data-sidebar-page="pengaturan-dithane.php">Setting Dithane</a>
      </div>
    </div>
    <a class="nav-item" href="history.php" data-sidebar-page="history.php">
      <span class="ico ico-history" aria-hidden="true"></span>
      <span>History</span>
    </a>
    <a class="nav-item" href="profile.php" data-sidebar-page="profile.php">
      <span class="ico ico-profile" aria-hidden="true"></span>
      <span>Profile</span>
    </a>
  </nav>
  <a class="logout" href="popup-logout.php">Log out</a>
</aside>`;

  const featureLabels = {
    "pengaturan-ppm.php": "Setting PPM",
    "pengaturan-ph.php": "Setting pH",
    "pengaturan-dithane.php": "Setting Dithane",
  };

  function getCurrentPage() {
    const page = window.location.pathname.split("/").pop() || "hydrotech-dashboard-panel-2026.php";
    return page.replace(".html", ".php"); // Fallback jika masih ada yang terbaca html
  }

  function closeDropdown() {
    const dropdownMenu = document.getElementById("dropdownMenu");
    const dropdownButton = document.querySelector(".nav-dropdown > .nav-item");
    if (dropdownMenu) dropdownMenu.classList.remove("show");
    if (dropdownButton) dropdownButton.classList.remove("open");
  }

  function toggleDropdown() {
    const dropdownMenu = document.getElementById("dropdownMenu");
    const dropdownButton = document.querySelector(".nav-dropdown > .nav-item");
    if (!dropdownMenu || !dropdownButton) return;
    const shouldOpen = !dropdownMenu.classList.contains("show");
    requestAnimationFrame(function () {
      dropdownMenu.classList.toggle("show", shouldOpen);
      dropdownButton.classList.toggle("open", shouldOpen);
    });
  }

  function navigateToPage(event, pageUrl) {
    event.preventDefault();
    closeDropdown();
    window.location.href = pageUrl;
  }

  function setActiveNavigation(sidebar) {
    const currentPage = getCurrentPage();
    const featureButton = sidebar.querySelector(".nav-dropdown > .nav-item");
    const featureLabel = sidebar.querySelector("[data-feature-label]");

    sidebar.querySelectorAll("[data-sidebar-page]").forEach(function (item) {
      item.classList.toggle("active", item.dataset.sidebarPage === currentPage);
    });

    if (featureLabel) {
      featureLabel.textContent = featureLabels[currentPage] || "Setting";
    }

    if (featureButton) {
      featureButton.classList.toggle("active", Boolean(featureLabels[currentPage]));
    }
  }

  function setupDropdown(sidebar) {
    const dropdown = sidebar.querySelector(".nav-dropdown");
    const dropdownButton = sidebar.querySelector("[data-sidebar-dropdown-toggle]");

    if (dropdownButton) {
      dropdownButton.addEventListener("click", toggleDropdown);
    }

    sidebar.querySelectorAll(".dropdown-item").forEach(function (item) {
      item.addEventListener("click", function (event) {
        navigateToPage(event, item.getAttribute("href"));
      });
    });

    document.addEventListener("click", function (event) {
      if (!dropdown || dropdown.contains(event.target)) return;
      closeDropdown();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeDropdown();
    });
  }

  function setupSidebarClock(sidebar) {
    const logout = sidebar.querySelector(".logout");
    if (!logout || sidebar.querySelector("[data-sidebar-clock]")) return;

    const clock = document.createElement("section");
    clock.className = "sidebar-clock";
    clock.dataset.sidebarClock = "";
    clock.setAttribute("aria-label", "Tanggal dan waktu WIB");
    clock.innerHTML = `
      <button class="sidebar-clock-icon" type="button" data-sidebar-calendar-toggle aria-haspopup="dialog" aria-expanded="false" aria-label="Buka kalender" title="Buka kalender">
        <span aria-hidden="true"></span>
      </button>
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

    setupSidebarCalendar(sidebar, clock, clock.querySelector("[data-sidebar-calendar-toggle]"));
  }

  /* SIDEBAR CALENDAR: date picker mini yang muncul dekat kotak jam, hanya di dalam area sidebar. */
  function setupSidebarCalendar(sidebar, clock, toggleButton) {
    if (!toggleButton || sidebar.querySelector("[data-sidebar-calendar]")) return;

    const MONTH_NAMES = [
      "Januari", "Februari", "Maret", "April", "Mei", "Juni",
      "Juli", "Agustus", "September", "Oktober", "November", "Desember",
    ];
    const WEEKDAY_SHORT = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];

    // Tanggal hari ini memakai zona Asia/Jakarta agar konsisten dengan jam WIB.
    const todayParts = new Intl.DateTimeFormat("en-CA", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      timeZone: "Asia/Jakarta",
    }).formatToParts(new Date()).reduce(function (acc, part) {
      acc[part.type] = part.value;
      return acc;
    }, {});
    const today = {
      year: Number(todayParts.year),
      month: Number(todayParts.month) - 1,
      day: Number(todayParts.day),
    };

    const popup = document.createElement("div");
    popup.className = "sidebar-calendar";
    popup.dataset.sidebarCalendar = "";
    popup.setAttribute("role", "dialog");
    popup.setAttribute("aria-label", "Kalender");
    popup.setAttribute("aria-hidden", "true");
    popup.innerHTML = `
      <div class="sidebar-calendar-head">
        <button class="sidebar-calendar-nav" type="button" data-calendar-prev aria-label="Bulan sebelumnya">&#8249;</button>
        <div class="sidebar-calendar-title">
          <select data-calendar-month aria-label="Pilih bulan"></select>
          <select data-calendar-year aria-label="Pilih tahun"></select>
        </div>
        <button class="sidebar-calendar-nav" type="button" data-calendar-next aria-label="Bulan berikutnya">&#8250;</button>
      </div>
      <div class="sidebar-calendar-weekdays" aria-hidden="true">
        ${WEEKDAY_SHORT.map(function (day) { return `<span>${day}</span>`; }).join("")}
      </div>
      <div class="sidebar-calendar-grid" data-calendar-grid></div>
      <div class="sidebar-calendar-foot" data-calendar-foot>Belum ada tanggal dipilih</div>
    `;
    sidebar.appendChild(popup);

    const monthSelect = popup.querySelector("[data-calendar-month]");
    const yearSelect = popup.querySelector("[data-calendar-year]");
    const grid = popup.querySelector("[data-calendar-grid]");
    const foot = popup.querySelector("[data-calendar-foot]");

    let view = { year: today.year, month: today.month };
    let selected = null;
    let isOpen = false;

    MONTH_NAMES.forEach(function (name, index) {
      const option = document.createElement("option");
      option.value = String(index);
      option.textContent = name;
      monthSelect.appendChild(option);
    });

    function ensureYearOptions(year) {
      const first = year - 12;
      const last = year + 12;
      const currentFirst = Number(yearSelect.options[0]?.value);
      const currentLast = Number(yearSelect.options[yearSelect.options.length - 1]?.value);
      if (yearSelect.options.length === last - first + 1 && currentFirst === first && currentLast === last) return;
      const previousValue = yearSelect.value;
      yearSelect.innerHTML = "";
      for (let y = first; y <= last; y += 1) {
        const option = document.createElement("option");
        option.value = String(y);
        option.textContent = String(y);
        yearSelect.appendChild(option);
      }
      yearSelect.value = previousValue === String(year) ? String(year) : yearSelect.value;
      if (!yearSelect.value) yearSelect.value = String(year);
    }

    function isSameDate(a, b) {
      return a && b && a.year === b.year && a.month === b.month && a.day === b.day;
    }

    function renderCalendar() {
      monthSelect.value = String(view.month);
      ensureYearOptions(view.year);
      yearSelect.value = String(view.year);

      grid.innerHTML = "";
      const firstWeekday = (new Date(view.year, view.month, 1).getDay() + 6) % 7; // Senin = 0
      const daysInMonth = new Date(view.year, view.month + 1, 0).getDate();
      const daysInPrevMonth = new Date(view.year, view.month, 0).getDate();
      const totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

      for (let cell = 0; cell < totalCells; cell += 1) {
        const dayButton = document.createElement("button");
        dayButton.className = "sidebar-calendar-day";
        dayButton.type = "button";

        if (cell < firstWeekday) {
          const day = daysInPrevMonth - firstWeekday + 1 + cell;
          dayButton.textContent = String(day);
          dayButton.classList.add("muted");
          dayButton.disabled = true;
          dayButton.setAttribute("aria-label", `Tanggal lain di bulan sebelumnya`);
        } else if (cell >= firstWeekday + daysInMonth) {
          dayButton.textContent = String(cell - firstWeekday - daysInMonth + 1);
          dayButton.classList.add("muted");
          dayButton.disabled = true;
          dayButton.setAttribute("aria-label", `Tanggal lain di bulan berikutnya`);
        } else {
          const day = cell - firstWeekday + 1;
          dayButton.textContent = String(day);
          const date = { year: view.year, month: view.month, day: day };
          if (isSameDate(date, today)) dayButton.classList.add("today");
          if (isSameDate(date, selected)) {
            dayButton.classList.add("selected");
            dayButton.setAttribute("aria-pressed", "true");
          }
          dayButton.addEventListener("click", function (event) {
            // Grid akan di-render ulang (tombol ini tergantikan), jadi hentikan
            // propagasi agar listener klik-dokumen tidak mengira klik di luar popup.
            event.stopPropagation();
            selected = date;
            renderCalendar();
            updateFoot();
          });
        }

        grid.appendChild(dayButton);
      }
    }

    function updateFoot() {
      if (!selected) {
        foot.textContent = "Belum ada tanggal dipilih";
        return;
      }
      foot.textContent = `Tanggal dipilih: ${WEEKDAY_SHORT[(new Date(selected.year, selected.month, selected.day).getDay() + 6) % 7]}, ${selected.day} ${MONTH_NAMES[selected.month]} ${selected.year}`;
    }

    function positionCalendar() {
      const clockTop = clock.offsetTop; // offsetParent = .sidebar (fixed)
      const clockHeight = clock.offsetHeight;
      const margin = 10;
      const spaceBelow = sidebar.clientHeight - (clockTop + clockHeight);
      const popupHeight = popup.offsetHeight;
      let top;
      if (popupHeight + margin <= spaceBelow) {
        top = clockTop + clockHeight + margin; // cukup ruang: tampil di bawah kotak jam
      } else if (popupHeight + margin <= clockTop) {
        top = clockTop - popupHeight - margin; // tidak cukup: tampil di atas kotak jam
      } else {
        top = Math.max(margin, sidebar.clientHeight - popupHeight - margin); // layar pendek: diapit di dalam sidebar
      }
      popup.style.top = `${top}px`;
    }

    function openCalendar() {
      if (isOpen) return;
      const focusDate = selected || today;
      view = { year: focusDate.year, month: focusDate.month };
      renderCalendar();
      positionCalendar();
      popup.classList.add("show");
      popup.setAttribute("aria-hidden", "false");
      toggleButton.setAttribute("aria-expanded", "true");
      isOpen = true;
      monthSelect.focus({ preventScroll: true });
    }

    function closeCalendar() {
      if (!isOpen) return;
      popup.classList.remove("show");
      popup.setAttribute("aria-hidden", "true");
      toggleButton.setAttribute("aria-expanded", "false");
      isOpen = false;
    }

    toggleButton.addEventListener("click", function () {
      if (isOpen) closeCalendar();
      else openCalendar();
    });

    popup.querySelector("[data-calendar-prev]").addEventListener("click", function () {
      view.month -= 1;
      if (view.month < 0) {
        view.month = 11;
        view.year -= 1;
      }
      renderCalendar();
      positionCalendar();
    });

    popup.querySelector("[data-calendar-next]").addEventListener("click", function () {
      view.month += 1;
      if (view.month > 11) {
        view.month = 0;
        view.year += 1;
      }
      renderCalendar();
      positionCalendar();
    });

    monthSelect.addEventListener("change", function () {
      view.month = Number(monthSelect.value);
      renderCalendar();
      positionCalendar();
    });

    yearSelect.addEventListener("change", function () {
      view.year = Number(yearSelect.value);
      renderCalendar();
      positionCalendar();
    });

    // Tutup saat klik di luar kalender dan tombol ikon kalender.
    document.addEventListener("click", function (event) {
      if (!isOpen) return;
      if (popup.contains(event.target) || toggleButton.contains(event.target)) return;
      closeCalendar();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && isOpen) {
        closeCalendar();
        toggleButton.focus();
      }
    });

    window.addEventListener("resize", function () {
      if (isOpen) positionCalendar();
    });
  }

  /* DEVICE STATUS INDICATOR: komponen header bersama yang menampilkan status
     koneksi ESP32 (heartbeat dari backend). Ditanam otomatis di semua halaman
     yang punya topbar admin, pada urutan: status -> lonceng -> profil. */
  function setupDeviceStatusIndicator() {
    const adminArea = document.querySelector(".topbar .admin");
    if (!adminArea || document.querySelector("[data-device-status]")) return;

    // Endpoint berbasis heartbeat perangkat (data sensor terakhir di database).
    const STATUS_URL = new URL("../api/device/status.php", window.location.href);
    STATUS_URL.searchParams.set("t", Date.now()); // anti-cache
    const POLL_INTERVAL = 30000; // 30 detik, sejalan dengan refresh dashboard.
    const STATUS_TEXT = {
      online: "Perangkat Online",
      offline: "Perangkat Offline",
      never_connected: "Belum Terhubung",
      checking: "Memeriksa...",
    };

    const chip = document.createElement("div");
    chip.className = "device-status is-checking";
    chip.dataset.deviceStatus = "";
    chip.setAttribute("role", "status");
    chip.setAttribute("aria-live", "polite");
    chip.setAttribute("aria-label", "Status perangkat ESP32");
    chip.title = "Status koneksi perangkat ESP32";
    chip.innerHTML = `
      <span class="device-status-dot" aria-hidden="true"></span>
      <span class="device-status-label" data-device-status-label>${STATUS_TEXT.checking}</span>
    `;

    // Urutan header: indikator status paling kiri, lalu lonceng, lalu profil.
    adminArea.insertBefore(chip, adminArea.firstChild);

    const label = chip.querySelector("[data-device-status-label]");

    function applyStatus(status) {
      chip.classList.remove("is-checking", "is-online", "is-offline", "is-never");
      const state = STATUS_TEXT[status] ? status : "checking";
      chip.classList.add(`is-${state}`);
      if (label) label.textContent = STATUS_TEXT[state];
    }

    async function refreshDeviceStatus() {
      try {
        STATUS_URL.searchParams.set("t", Date.now());
        const response = await fetch(STATUS_URL, { cache: "no-store" });
        const result = await response.json();
        applyStatus(result.success && result.data ? result.data.status : "checking");
      } catch (error) {
        // Gagal menghubungi server: pertahankan status memeriksa (kuning).
        applyStatus("checking");
      }
    }

    refreshDeviceStatus();
    window.setInterval(refreshDeviceStatus, POLL_INTERVAL);
  }

  /* Indikator ditanam setelah dokumen selesai di-parse karena topbar berada
     setelah tag <script> sidebar di setiap halaman. */
  function initDeviceStatusWhenReady() {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", setupDeviceStatusIndicator, { once: true });
    } else {
      setupDeviceStatusIndicator();
    }
  }

  function initializeSidebar(root, html) {
    // Replace all .html to .php in the loaded html to ensure consistency
    html = html.replace(/href="([^"]+)\.html"/g, 'href="$1.php"');
    html = html.replace(/data-sidebar-page="([^"]+)\.html"/g, 'data-sidebar-page="$1.php"');
    
    root.innerHTML = html;
    const sidebar = root.querySelector(".sidebar");
    if (!sidebar) return;

    setActiveNavigation(sidebar);
    setupDropdown(sidebar);
    setupSidebarClock(sidebar);
    initDeviceStatusWhenReady();

    window.toggleDropdown = toggleDropdown;
    window.closeDropdown = closeDropdown;
    window.navigateToPage = navigateToPage;
    document.dispatchEvent(new CustomEvent("hydrotech:sidebar-ready"));
  }

  function loadSidebar(root) {
    let html = SIDEBAR_TEMPLATE;

    try {
      const request = new XMLHttpRequest();
      request.open("GET", "sidebar.html", false);
      request.send(null);

      if (
        (request.status >= 200 && request.status < 300) ||
        (request.status === 0 && request.responseText)
      ) {
        html = request.responseText;
      }
    } catch (error) {
      html = SIDEBAR_TEMPLATE;
    }

    initializeSidebar(root, html);
  }

  function bootSidebar() {
    const root = document.querySelector("[data-sidebar-root]");
    if (!root) return;
    loadSidebar(root);
  }

  if (document.readyState === "loading") {
    bootSidebar();
    if (!document.querySelector("[data-sidebar-root] .sidebar")) {
      document.addEventListener("DOMContentLoaded", bootSidebar, { once: true });
    }
  } else {
    bootSidebar();
  }
})();
