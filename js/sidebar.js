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
    <a class="nav-item" href="hydrotech-dashboard-panel-2026.html" data-sidebar-page="hydrotech-dashboard-panel-2026.html">
      <span class="ico ico-dashboard" aria-hidden="true"></span>
      <span>Dashboard</span>
    </a>
    <div class="nav-dropdown">
      <button class="nav-item" type="button" data-sidebar-dropdown-toggle>
        <span class="ico ico-features" aria-hidden="true"></span>
        <span data-feature-label>Fitur</span>
        <span class="chev">v</span>
      </button>
      <div class="dropdown-menu" id="dropdownMenu">
        <a href="pengaturan-ppm.html" class="dropdown-item" data-sidebar-page="pengaturan-ppm.html">Pengaturan PPM</a>
        <a href="pengaturan-ph.html" class="dropdown-item" data-sidebar-page="pengaturan-ph.html">Pengaturan pH</a>
        <a href="pengaturan-dithane.html" class="dropdown-item" data-sidebar-page="pengaturan-dithane.html">Pengaturan Dithane</a>
      </div>
    </div>
    <a class="nav-item" href="history.html" data-sidebar-page="history.html">
      <span class="ico ico-history" aria-hidden="true"></span>
      <span>History</span>
    </a>
    <a class="nav-item" href="profile.html" data-sidebar-page="profile.html">
      <span class="ico ico-profile" aria-hidden="true"></span>
      <span>Profile</span>
    </a>
  </nav>
  <a class="logout" href="popup-logout.html">Log out</a>
</aside>`;

  const featureLabels = {
    "pengaturan-ppm.html": "Pengaturan PPM",
    "pengaturan-ph.html": "Pengaturan pH",
    "pengaturan-dithane.html": "Pengaturan Dithane",
  };

  function getCurrentPage() {
    return window.location.pathname.split("/").pop() || "hydrotech-dashboard-panel-2026.html";
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
      featureLabel.textContent = featureLabels[currentPage] || "Fitur";
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

  function initializeSidebar(root, html) {
    root.innerHTML = html;
    const sidebar = root.querySelector(".sidebar");
    if (!sidebar) return;

    setActiveNavigation(sidebar);
    setupDropdown(sidebar);
    setupSidebarClock(sidebar);

    window.toggleDropdown = toggleDropdown;
    window.closeDropdown = closeDropdown;
    window.navigateToPage = navigateToPage;
    document.dispatchEvent(new CustomEvent("hydrotech:sidebar-ready"));
  }

  function loadSidebar(root) {
    let html = SIDEBAR_TEMPLATE;

    try {
      const request = new XMLHttpRequest();
      request.open("GET", "../components/sidebar.html", false);
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
