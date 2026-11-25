(function () {
  /* HISTORY SETUP: Data Aktivitas Sistem = riwayat KESIMPULAN gabungan
     (pH + PPM + Dithane, satu baris per kejadian, type='sistem').
     Semua filter dieksekusi di DATABASE (api/history/list.php); halaman
     hanya merender hasilnya. Pagination tetap di sisi klien. */
  const rowsPerPage = 5;

  /* CSRF TOKEN: dari meta tag halaman (dipakai semua POST).            */
  function token() {
    if (window.HydrotechCsrf?.token) return window.HydrotechCsrf.token();
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }
  const table = document.querySelector(".history-table");
  if (!table) return;
  const tableBody = table.querySelector("tbody");

  const searchInput = document.querySelector("[data-history-search]");
  const dayFilter = document.querySelector("[data-history-day]");
  const monthFilter = document.querySelector("[data-history-month]");
  const yearFilter = document.querySelector("[data-history-year]");
  const statusFilter = document.querySelector("[data-history-status]");
  const timeRangeBtn = document.querySelector("[data-history-time-range]");
  const timePopup = document.querySelector("[data-history-time-popup]");
  const fromJamSel = popupQuery("[data-history-time-from-jam]");
  const fromMenitSel = popupQuery("[data-history-time-from-menit]");
  const toJamSel = popupQuery("[data-history-time-to-jam]");
  const toMenitSel = popupQuery("[data-history-time-to-menit]");
  function popupQuery(sel) {
    return document.querySelector(sel);
  }
  /* Rentang yang SUDAH diterapkan (dipakai fetch & PDF).             */
  let appliedFrom = "";
  let appliedTo = "";
  function timeRangeValues() {
    return { from: appliedFrom, to: appliedTo };
  }

  /* DROPDOWN KUSTOM: membuka KE BAWAH, daftar putih terbatas 5 baris
     (sisanya scroll), tertutup saat memilih/klik luar.                 */
  function transformTimeDropdowns() {
    [fromJamSel, fromMenitSel, toJamSel, toMenitSel].forEach((sel) => {
      if (!sel || sel.dataset.hyddReady) return;
      sel.dataset.hyddReady = "1";
      sel.style.display = "none";

      const wrap = document.createElement("div");
      wrap.className = "hyd-dd";
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "hyd-dd-btn";
      btn.textContent = sel.value || "--";
      const list = document.createElement("div");
      list.className = "hyd-dd-list";

      function markSelected() {
        list.querySelectorAll(".hyd-dd-item").forEach((item) => {
          item.classList.toggle("sel", item.dataset.value === sel.value);
        });
      }
      Array.from(sel.options).forEach((o) => {
        const item = document.createElement("div");
        item.className = "hyd-dd-item" + (o.value === sel.value ? " sel" : "");
        item.dataset.value = o.value;
        item.textContent = o.textContent;
        item.addEventListener("click", function (event) {
          event.stopPropagation();
          sel.value = o.value;
          btn.textContent = o.value === "" ? "--" : o.value;
          markSelected();
          list.classList.remove("open");
          sel.dispatchEvent(new Event("change", { bubbles: true }));
        });
        list.appendChild(item);
      });
      btn.addEventListener("click", function (event) {
        event.stopPropagation();
        document.querySelectorAll(".hyd-dd-list.open").forEach((l) => {
          if (l !== list) l.classList.remove("open");
        });
        markSelected();
        list.classList.toggle("open");
      });
      wrap.appendChild(btn);
      wrap.appendChild(list);
      sel.parentNode.insertBefore(wrap, sel);
    });
    if (!window.__hyddOutsideBound) {
      window.__hyddOutsideBound = true;
      document.addEventListener("click", function () {
        document.querySelectorAll(".hyd-dd-list.open").forEach((l) => l.classList.remove("open"));
      });
    }
  }
  function updateTimeLabel() {
    const label = document.querySelector("[data-history-time-label]");
    if (!label) return;
    const fmt = (v) => (v ? v.replace(":", ".") : "--");
    label.textContent = appliedFrom || appliedTo
      ? `Pukul: ${fmt(appliedFrom)} – ${fmt(appliedTo)}`
      : "Pukul: --";
  }
  const resetButton = document.querySelector("[data-history-reset]");
  const exportButton = document.querySelector("[data-history-export]");
  const showing = document.querySelector("[data-history-showing]");
  const pagination = document.querySelector("[data-history-pagination]");
  const emptyState = document.querySelector("[data-history-empty]");
  let currentPage = 1;
  let historyRows = [];          // dataset hasil filter terkini (dari database)

  /* ISI DROPDOWN DALAM PANEL: Jam 00-23, Menit 00-59 (24 jam).        */
  function populateTimeSelects() {
    const pad = (n) => String(n).padStart(2, "0");
    [fromJamSel, toJamSel].forEach((sel) => {
      if (!sel || sel.options.length > 1) return;
      for (let h = 0; h < 24; h++) {
        const o = document.createElement("option");
        o.value = pad(h); o.textContent = pad(h);
        sel.appendChild(o);
      }
    });
    [fromMenitSel, toMenitSel].forEach((sel) => {
      if (!sel || sel.options.length > 1) return;
      for (let m = 0; m < 60; m++) {
        const o = document.createElement("option");
        o.value = pad(m); o.textContent = pad(m);
        sel.appendChild(o);
      }
    });
  }
  function timeRangeValues() {
    return { from: appliedFrom, to: appliedTo };
  }

  /* DROPDOWN KUSTOM: membuka KE BAWAH, daftar putih terbatas 5 baris
     (sisanya scroll), tertutup saat memilih/klik luar.                 */
  function transformTimeDropdowns() {
    [fromJamSel, fromMenitSel, toJamSel, toMenitSel].forEach((sel) => {
      if (!sel || sel.dataset.hyddReady) return;
      sel.dataset.hyddReady = "1";
      sel.style.display = "none";

      const wrap = document.createElement("div");
      wrap.className = "hyd-dd";
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "hyd-dd-btn";
      btn.textContent = sel.value || "--";
      const list = document.createElement("div");
      list.className = "hyd-dd-list";

      function markSelected() {
        list.querySelectorAll(".hyd-dd-item").forEach((item) => {
          item.classList.toggle("sel", item.dataset.value === sel.value);
        });
      }
      Array.from(sel.options).forEach((o) => {
        const item = document.createElement("div");
        item.className = "hyd-dd-item" + (o.value === sel.value ? " sel" : "");
        item.dataset.value = o.value;
        item.textContent = o.textContent;
        item.addEventListener("click", function (event) {
          event.stopPropagation();
          sel.value = o.value;
          btn.textContent = o.value === "" ? "--" : o.value;
          markSelected();
          list.classList.remove("open");
          sel.dispatchEvent(new Event("change", { bubbles: true }));
        });
        list.appendChild(item);
      });
      btn.addEventListener("click", function (event) {
        event.stopPropagation();
        document.querySelectorAll(".hyd-dd-list.open").forEach((l) => {
          if (l !== list) l.classList.remove("open");
        });
        markSelected();
        list.classList.toggle("open");
      });
      wrap.appendChild(btn);
      wrap.appendChild(list);
      sel.parentNode.insertBefore(wrap, sel);
    });
    if (!window.__hyddOutsideBound) {
      window.__hyddOutsideBound = true;
      document.addEventListener("click", function () {
        document.querySelectorAll(".hyd-dd-list.open").forEach((l) => l.classList.remove("open"));
      });
    }
  }

  const monthNames = [
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember",
  ];
  const dayNamesLower = ["senin", "selasa", "rabu", "kamis", "jumat", "sabtu", "minggu"];

  /* TEXT HELPERS: penampilan tanggal Indonesia + normalisasi huruf. */
  function normalize(value) {
    return String(value || "").trim().toLowerCase();
  }

  function formatTanggalIndo(logDate) {
    if (!logDate) return "-";
    const parts = String(logDate).split("-");
    if (parts.length !== 3) return logDate;
    const day = Number(parts[2]);
    const month = Number(parts[1]) - 1;
    const year = parts[0];
    if (!Number.isFinite(day) || !monthNames[month]) return logDate;
    return `${day} ${monthNames[month]} ${year}`;
  }

  /* STATUS BADGE: pemetaan status sistem ke kelas warna yang sudah ada. */
  function statusClass(status) {
    const s = normalize(status);
    if (s === "tidak normal") return "high";
    if (s === "perhatian") return "low";
    return "normal";
  }

  /* FETCH: ambil data dari database dengan seluruh filter (server-side). */
  let fetchSeq = 0;
  async function fetchRows() {
    const seq = ++fetchSeq;
    const params = new URLSearchParams();
    if (searchInput?.value.trim()) params.set("search", searchInput.value.trim());
    if (dayFilter?.value) params.set("day", dayFilter.value);
    if (monthFilter?.value) params.set("month", monthFilter.value);
    if (yearFilter?.value) params.set("year", yearFilter.value);
    if (statusFilter?.value) params.set("status", statusFilter.value);
    const tr = timeRangeValues();
    if (tr.from) params.set("time_from", tr.from);
    if (tr.to) params.set("time_to", tr.to);
    try {
      const response = await fetch(`../api/history/list.php?${params.toString()}`, { cache: "no-store" });
      const result = await response.json();
      if (!result.success || !result.data) throw new Error(result.message || "Gagal memuat data.");
      if (seq !== fetchSeq) return;   // respons lama tidak menimpa yang terbaru
      historyRows = Array.isArray(result.data.rows) ? result.data.rows : [];
      populateYears(result.data.years || []);
      renderTable();
    } catch (error) {
      console.error("Gagal memuat Data Aktivitas Sistem:", error);
    }
  }

  /* FILTER TAHUN: isi pilihan dari data yang tersedia (sekali per fetch). */
  function populateYears(years) {
    if (!yearFilter) return;
    const selected = yearFilter.value;
    yearFilter.querySelectorAll("option:not(:first-child)").forEach((o) => o.remove());
    years.forEach((year) => {
      const option = document.createElement("option");
      option.value = String(year);
      option.textContent = String(year);
      yearFilter.appendChild(option);
    });
    if (selected && years.map(String).includes(selected)) yearFilter.value = selected;
  }

  /* TABLE RENDER: baris kesimpulan (No, Hari, Tanggal, Jam, Status, Keterangan, Aksi). */
  function renderTable() {
    const totalPages = Math.max(1, Math.ceil(historyRows.length / rowsPerPage));
    if (currentPage > totalPages) currentPage = totalPages;
    const pageStart = (currentPage - 1) * rowsPerPage;
    const pageRows = historyRows.slice(pageStart, pageStart + rowsPerPage);

    tableBody.innerHTML = "";
    pageRows.forEach((row, index) => {
      const tr = document.createElement("tr");
      tr.dataset.rowId = row.id;
      const cells = [
        String(pageStart + index + 1),
        row.day_name || "-",
        formatTanggalIndo(row.log_date),
        (row.log_time || "-").replace(":", ".").slice(0, 5),
        null, // badge status
        row.note || "-",
        null, // tombol hapus
      ];
      cells.forEach((value, cellIndex) => {
        const td = document.createElement("td");
        if (cellIndex === 4) {
          const badge = document.createElement("span");
          badge.className = `badge ${statusClass(row.status)}`;
          badge.textContent = row.status || "-";
          td.appendChild(badge);
        } else if (cellIndex === 6) {
          td.className = "trash";
          const button = document.createElement("button");
          button.type = "button";
          button.className = "trash-btn";
          button.setAttribute("aria-label", "Hapus data");
          button.innerHTML = "&#128465;";
          td.appendChild(button);
        } else {
          td.textContent = value;
        }
        tr.appendChild(td);
      });
      tableBody.appendChild(tr);
    });

    if (showing) {
      const from = historyRows.length ? pageStart + 1 : 0;
      const to = pageStart + pageRows.length;
      showing.textContent = `Menampilkan ${from}-${to} dari ${historyRows.length} data`;
    }
    if (emptyState) {
      emptyState.classList.toggle("show", historyRows.length === 0);
    }
    renderPagination(totalPages);
  }

  /* PAGINATION: membuat tombol halaman sebelumnya, nomor halaman, dan halaman berikutnya. */
  function renderPagination(totalPages) {
    if (!pagination) return;
    pagination.innerHTML = "";

    const previous = document.createElement("button");
    previous.type = "button";
    previous.textContent = "<";
    previous.disabled = currentPage === 1;
    previous.addEventListener("click", function () {
      if (currentPage > 1) {
        currentPage -= 1;
        renderTable();
      }
    });
    pagination.appendChild(previous);

    for (let page = 1; page <= totalPages; page += 1) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = page;
      button.classList.toggle("current", page === currentPage);
      button.addEventListener("click", function () {
        currentPage = page;
        renderTable();
      });
      pagination.appendChild(button);
    }

    const next = document.createElement("button");
    next.type = "button";
    next.textContent = ">";
    next.disabled = currentPage === totalPages;
    next.addEventListener("click", function () {
      if (currentPage < totalPages) {
        currentPage += 1;
        renderTable();
      }
    });
    pagination.appendChild(next);
  }

  /* RESET FILTER: mengosongkan semua filter dan memuat ulang dari database. */
  function resetFilters() {
    if (searchInput) searchInput.value = "";
    if (dayFilter) dayFilter.value = "";
    if (monthFilter) monthFilter.value = "";
    if (yearFilter) yearFilter.value = "";
    if (statusFilter) statusFilter.value = "";
    appliedFrom = ""; appliedTo = "";
    [fromJamSel, fromMenitSel, toJamSel, toMenitSel].forEach((sel) => { if (sel) sel.value = ""; });
    updateTimeLabel();
    currentPage = 1;
    fetchRows();
  }

  /* DELETED ROWS: baris dihapus langsung oleh backend, tidak perlu disembunyikan via JS lagi. */

  /* SUMMARY HELPERS: menghitung jumlah data dan rata-rata dari dataset terfilter. */
  function countBy(items, key) {
    return items.reduce((result, item) => {
      const value = item[key] || "Tidak diketahui";
      result[value] = (result[value] || 0) + 1;
      return result;
    }, {});
  }

  function formatCounts(counts) {
    const entries = Object.entries(counts);
    if (!entries.length) return "Belum ada data tercatat";
    return entries.map(([label, total]) => `${label}: ${total}`).join(", ");
  }

  /* RECOMMENDATION: catatan tindakan pemilik berdasarkan dataset terfilter. */
  function getFilteredRecommendation(rows) {
    const statusCounts = countBy(rows, "status");
    const notes = [];

    if (statusCounts["Tidak Normal"]) {
      notes.push("Terdapat periode dengan status Tidak Normal (pH dan PPM sekaligus di luar rentang). Periksa sensor, larutan nutrisi, dan penyebab kenaikan/penurunan parameter secara bersamaan.");
    }
    if (statusCounts["Perhatian"]) {
      notes.push("Terdapat status Perhatian (parameter keluar rentang, data sensor tidak tersedia, atau Dithane melebihi jadwal). Lakukan pemeriksaan dan penyesuaian bertahap.");
    }
    if (!notes.length && statusCounts.Normal) {
      notes.push("Kondisi sistem relatif stabil pada periode terfilter. Tetap pantau tren harian agar perubahan kecil tidak terlambat ditangani.");
    }
    if (!notes.length) {
      notes.push("Belum ada data pada periode terfilter. Pastikan perangkat ESP mengirim data sensor dan jadwal Dithane berjalan.");
    }
    return notes;
  }

  /* EXPORT PROMPT: tanggal pembuatan laporan (ekspor mengikuti filter aktif). */
  function getGeneratedAtText() {
    return new Intl.DateTimeFormat("id-ID", {
      day: "numeric",
      month: "long",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: "Asia/Jakarta",
    }).format(new Date());
  }

  /* FILTER DESCRIPTION: teks periode/filter aktif untuk judul PDF. */
  function getFilterDescription() {
    const parts = [];
    const monthIdx = monthFilter?.value;
    if (monthIdx) parts.push(monthNames[Number(monthIdx) - 1]);
    if (yearFilter?.value) parts.push(yearFilter.value);
    if (dayFilter?.value) {
      const d = normalize(dayFilter.value);
      parts.push("hari " + d.charAt(0).toUpperCase() + d.slice(1));
    }
    const trDesc = timeRangeValues();
    if (trDesc.from || trDesc.to) {
      const f = (v) => (v ? v.replace(":", ".") : "00.00");
      parts.push(`jam ${f(trDesc.from)} - ${f(trDesc.to)}`);
    }
    if (statusFilter?.value) parts.push(`status ${statusFilter.value}`);
    return parts.length ? parts.join(", ") : "semua data";
  }

  /* =====================================================================
     LAPORAN PDF — desain "Professional IoT Monitoring Report"
     - Page 1 (portrait) : ringkasan, kartu statistik, donut komposisi,
                           kesimpulan & rekomendasi.
     - Page 2 (landscape): grafik monitoring pH & PPM — konfigurasi
                           SAMA dengan grafik web (Chart.js, warna, skala).
     - Page 3 (portrait) : ringkasan aktivitas pompa (data nyata).
     - Page 4+ (portrait): tabel lengkap riwayat (header berulang).
     - Footer semua halaman: identitas + nomor halaman + waktu cetak.
     ===================================================================== */
  let phAxis = { min: 5, max: 7 };
  let ppmAxis = { min: 750, max: 1050 };
  const PDF_C = {
    greenDark: [20, 83, 45], green: [22, 163, 74], greenInk: [14, 138, 68],
    border: [220, 235, 224], text: [46, 74, 58], muted: [125, 143, 131],
    normal: [22, 163, 74], warn: [217, 119, 6], danger: [220, 38, 38],
    ph: [255, 119, 119], ppm: [7, 166, 79],
  };
  const STATUS_COLOR = { Normal: PDF_C.normal, Perhatian: PDF_C.warn, "Tidak Normal": PDF_C.danger };

  function addWrappedText(doc, text, x, y, maxWidth, lineHeight) {
    const lines = doc.splitTextToSize(text, maxWidth);
    doc.text(lines, x, y);
    return y + lines.length * lineHeight;
  }

  function drawSectionTitle(doc, text, x, y) {
    doc.setFont("helvetica", "bold");
    doc.setFontSize(14);
    doc.setTextColor(...PDF_C.greenDark);
    doc.text(text, x, y);
    doc.setDrawColor(...PDF_C.border);
    doc.setLineWidth(0.4);
    doc.line(x, y + 2.4, doc.internal.pageSize.getWidth() - 14, y + 2.4);
    doc.setFont("helvetica", "normal");
    return y + 7;
  }

  function drawStatCard(doc, x, y, w, h, title, value, note, rgb) {
    doc.setFillColor(248, 252, 250);
    doc.setDrawColor(...PDF_C.border);
    doc.roundedRect(x, y, w, h, 3, 3, "FD");
    doc.setFillColor(...rgb);
    doc.roundedRect(x + 4, y + 4, 2.6, h - 8, 1.2, 1.2, "F");
    doc.setFont("helvetica", "bold");
    doc.setFontSize(8);
    doc.setTextColor(...PDF_C.muted);
    doc.text(title, x + 10, y + 8);
    doc.setFontSize(17);
    doc.setTextColor(...rgb);
    doc.text(String(value), x + 10, y + 16.5);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(7.5);
    doc.setTextColor(...PDF_C.muted);
    doc.text(note, x + 10, y + h - 4);
  }

  function addFootersAllPages(doc, subtitle) {
    const total = doc.internal.getNumberOfPages();
    for (let p = 1; p <= total; p++) {
      doc.setPage(p);
      const w = doc.internal.pageSize.getWidth();
      const h = doc.internal.pageSize.getHeight();
      doc.setDrawColor(...PDF_C.border);
      doc.setLineWidth(0.3);
      doc.line(14, h - 12, w - 14, h - 12);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8);
      doc.setTextColor(...PDF_C.muted);
      doc.text("HydroTech · Laporan Data Aktivitas Sistem", 14, h - 7);
      doc.text(subtitle, w / 2, h - 7, { align: "center" });
      doc.text(`Halaman ${p} dari ${total}`, w - 14, h - 7, { align: "right" });
    }
  }

  /* GRAFIK KOMPOSISI STATUS: doughnut Chart.js — warna sesuai status.  */
  async function createStatusDonutImage(counts) {
    if (typeof Chart === "undefined") return null;
    const entries = ["Normal", "Perhatian", "Tidak Normal"]
      .map((k) => [k, counts[k] || 0]);
    if (!entries.some(([, v]) => v > 0)) return null;
    const canvas = document.createElement("canvas");
    canvas.width = 560; canvas.height = 400;
    canvas.style.position = "fixed"; canvas.style.left = "-9999px";
    document.body.appendChild(canvas);
    const chart = new Chart(canvas, {
      type: "doughnut",
      data: {
        labels: entries.map(([k]) => k),
        datasets: [{
          data: entries.map(([, v]) => v),
          backgroundColor: entries.map(([k]) =>
            k === "Normal" ? "#16a34a" : k === "Perhatian" ? "#f59e0b" : "#dc2626"),
          borderColor: "#ffffff", borderWidth: 3,
        }],
      },
      options: {
        responsive: false, animation: false, cutout: "58%",
        plugins: {
          legend: { position: "bottom", labels: { color: "#2e4a3a", font: { size: 15, weight: "bold" }, padding: 16, usePointStyle: true, pointStyle: "rectRounded" } },
        },
      },
    });
    await new Promise((r) => requestAnimationFrame(r));
    const img = canvas.toDataURL("image/png", 1);
    chart.destroy(); canvas.remove();
    return img;
  }

  /* GRAFIK TREND: konfigurasi & identitas visual SAMA dengan grafik web
     (Chart.js — warna pH/PPM, gradient area, dua sumbu, grid putus-putus),
     canvas berukuran sesuai area cetak agar utuh & tajam.               */
  async function createTrendChartImage(trends, monthName, reportYear, chartW, chartH) {
    if (typeof Chart === "undefined" || !trends.length) return null;
    const canvas = document.createElement("canvas");
    canvas.width = chartW; canvas.height = chartH;
    canvas.style.position = "fixed"; canvas.style.left = "-9999px"; canvas.style.top = "0";
    document.body.appendChild(canvas);
    if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) {} }
    const context = canvas.getContext("2d");
    const whiteBackground = {
      id: "whiteBackground",
      beforeDraw(chart) {
        const ctx = chart.canvas.getContext("2d");
        ctx.save(); ctx.globalCompositeOperation = "destination-over";
        ctx.fillStyle = "#ffffff"; ctx.fillRect(0, 0, chart.width, chart.height); ctx.restore();
      },
    };
    const chart = new Chart(canvas, {
      type: "line",
      data: {
        labels: trends.map((item) => `${item.day}, ${item.date} ${item.time}`),
        datasets: [
          {
            label: "pH", data: trends.map((item) => item.ph), yAxisID: "yPh",
            borderColor: "#ff7777",
            backgroundColor: makeHistoryGradient(context, "rgb(255, 119, 119)"),
            borderWidth: 4, pointRadius: 4, pointHoverRadius: 7,
            pointBackgroundColor: "#ffffff", pointBorderColor: "#ff7777", pointBorderWidth: 3,
            tension: 0.42, fill: true, spanGaps: true,
          },
          {
            label: "PPM", data: trends.map((item) => item.ppm), yAxisID: "yPpm",
            borderColor: "#07a64f",
            backgroundColor: makeHistoryGradient(context, "rgb(7, 166, 79)"),
            borderWidth: 4, pointRadius: 4, pointHoverRadius: 7,
            pointBackgroundColor: "#ffffff", pointBorderColor: "#07a64f", pointBorderWidth: 3,
            tension: 0.42, fill: true, spanGaps: true,
          },
        ],
      },
      plugins: [whiteBackground],
      options: {
        responsive: false, animation: false, maintainAspectRatio: false,
        layout: { padding: { top: 16, right: 16, bottom: 4, left: 10 } },
        interaction: { intersect: false, mode: "index" },
        plugins: {
          legend: {
            display: true, position: "top", align: "end",
            labels: { color: "#203428", font: { size: 15, weight: "bold" }, usePointStyle: true, pointStyle: "circle", padding: 14 },
          },
          tooltip: {
            backgroundColor: "rgba(255,255,255,0.96)", titleColor: "#203428",
            bodyColor: "#203428", borderColor: "#dfe8e2", borderWidth: 1, padding: 10,
            callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y}${c.dataset.label === "PPM" ? " PPM" : ""}` },
          },
        },
        scales: {
          x: { ticks: { color: "#6b7c70", font: { size: 12, weight: "700" }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } },
          yPh: { position: "left", min: phAxis.min, max: phAxis.max, title: { display: true, text: "pH", color: "#d95f5f", font: { size: 13, weight: "900" } }, ticks: { color: "#d95f5f", font: { size: 12 } }, grid: { color: "rgba(111,134,120,0.14)", borderDash: [4, 8] } },
          yPpm: { position: "right", min: ppmAxis.min, max: ppmAxis.max, title: { display: true, text: "PPM", color: "#078d43", font: { size: 13, weight: "900" } }, ticks: { color: "#078d43", font: { size: 12 } }, grid: { drawOnChartArea: false } },
        },
      },
    });
    await new Promise((r) => requestAnimationFrame(r));
    await new Promise((r) => requestAnimationFrame(r));
    const image = canvas.toDataURL("image/png", 1);
    chart.destroy(); canvas.remove();
    return image;
  }

  /* RINGKASAN AKTIVITAS POMPA: data nyata (log Dithane) sesuai filter.  */
  async function fetchPumpActivity() {
    const params = new URLSearchParams();
    if (searchInput?.value.trim()) params.set("search", searchInput.value.trim());
    if (dayFilter?.value) params.set("day", dayFilter.value);
    if (monthFilter?.value) params.set("month", monthFilter.value);
    if (yearFilter?.value) params.set("year", yearFilter.value);
    const tr = timeRangeValues();
    if (tr.from) params.set("time_from", tr.from);
    if (tr.to) params.set("time_to", tr.to);
    params.set("type", "dithane");
    const response = await fetch(`../api/history/list.php?${params.toString()}`, { cache: "no-store" });
    const result = await response.json();
    return result.success && Array.isArray(result.data?.rows) ? result.data.rows : [];
  }

  async function exportHistoryPdf() {
    console.log("[PDF] mulai menyusun laporan...");
    const jsPdf = window.jspdf?.jsPDF;
    if (!jsPdf) {
      alert("Generator PDF belum berhasil dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
      return;
    }
    if (!historyRows.length) {
      if (window.Swal) {
        Swal.fire({ title: "Tidak ada data", text: "Tidak ada data pada filter aktif untuk diekspor.", icon: "info" });
      } else { alert("Tidak ada data pada filter aktif."); }
      return;
    }

    const rows = historyRows;
    const filterText = getFilterDescription();
    const statusCounts = countBy(rows, "status");
    const recommendations = getFilteredRecommendation(rows);
    const dominantStatus = Object.entries(statusCounts).sort((a, b) => b[1] - a[1])[0]?.[0] || "-";

    const phValues = rows.map((r) => Number(r.ph_value)).filter(Number.isFinite);
    const ppmValues = rows.map((r) => Number(r.ppm_value)).filter(Number.isFinite);
    const avgPhFiltered = phValues.length ? (phValues.reduce((s, v) => s + v, 0) / phValues.length).toFixed(2) : "-";
    const avgPpmFiltered = ppmValues.length ? Math.round(ppmValues.reduce((s, v) => s + v, 0) / ppmValues.length) : "-";

    /* Tren: bulan catatan terbaru pada filter aktif (data sensor asli). */
    const newest = rows[0];
    const newestDate = new Date(String(newest.created_at || "").replace(" ", "T"));
    const trendMonth = newest.created_at && Number.isFinite(newestDate.getMonth()) ? newestDate.getMonth() : new Date().getMonth();
    const trendYear = newest.created_at ? newestDate.getFullYear() : new Date().getFullYear();
    const monthName = monthNames[trendMonth];
    const trends = await fetchMonthlyTrend(trendMonth);
    const averagePh = getAverage(trends, "ph", 1);
    const averagePpm = getAverage(trends, "ppm", 0);
    phAxis = getAxisRange(trends, "ph", 0.18, 0.1, { min: 5, max: 7 });
    ppmAxis = getAxisRange(trends, "ppm", 28, 25, { min: 750, max: 1050 });

    if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) {} }
    const trendImage = await createTrendChartImage(trends, monthName, trendYear, 1680, 640);
    const donutImage = await createStatusDonutImage(statusCounts);
    const dithRows = await fetchPumpActivity();

    /* P1 PORTRAIT: ringkasan + kartu statistik + donut + kesimpulan.     */
    const doc = new jsPdf({ orientation: "portrait", unit: "mm", format: "a4" });
    if (typeof doc.autoTable !== "function") {
      alert("Plugin tabel PDF belum berhasil dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
      return;
    }
    const PW = doc.internal.pageSize.getWidth();    // 210mm
    const PH = doc.internal.pageSize.getHeight();   // 297mm
    const margin = 14;
    const usable = PW - margin * 2;

    doc.setFillColor(...PDF_C.greenDark);
    doc.rect(0, 0, PW, 30, "F");
    doc.setFillColor(...PDF_C.green);
    doc.roundedRect(PW - 26, 6, 20, 18, 3, 3, "F");
    doc.setFont("helvetica", "bold"); doc.setFontSize(9); doc.setTextColor(255, 255, 255);
    doc.text("HT", PW - 16, 17.5, { align: "center" });
    doc.setFontSize(17);
    doc.text("Laporan Data Aktivitas Sistem", margin, 14);
    doc.setFont("helvetica", "normal"); doc.setFontSize(9);
    doc.text(`HydroTech Monitoring IoT  ·  Filter: ${filterText}`, margin, 21);
    doc.text(`Dibuat: ${getGeneratedAtText()} WIB`, margin, 26);

    doc.setTextColor(...PDF_C.greenDark);
    doc.setFont("helvetica", "bold"); doc.setFontSize(13);
    doc.text("Ringkasan Statistik Utama", margin, 42);
    doc.setFont("helvetica", "normal");

    const cw = (usable - 12) / 3;
    let cy = 47; const ch = 22;
    drawStatCard(doc, margin, cy, cw, ch, "Total catatan aktivitas", rows.length, "kesimpulan sistem", PDF_C.greenDark);
    drawStatCard(doc, margin + cw + 6, cy, cw, ch, "Rata-rata pH", avgPhFiltered, "dari nilai terfilter", [217, 95, 95]);
    drawStatCard(doc, margin + (cw + 6) * 2, cy, cw, ch, "Rata-rata PPM", avgPpmFiltered, "dari nilai terfilter", [7, 141, 67]);
    cy += ch + 6;
    drawStatCard(doc, margin, cy, cw, ch, "Status dominan", dominantStatus, "berdasarkan filter", [22, 118, 164]);
    drawStatCard(doc, margin + cw + 6, cy, cw, ch, "Kondisi Normal", statusCounts["Normal"] || 0, "kondisi tercatat", PDF_C.normal);
    drawStatCard(doc, margin + (cw + 6) * 2, cy, cw, ch, "Kondisi Perhatian", statusCounts["Perhatian"] || 0, "kondisi tercatat", PDF_C.warn);
    cy += ch + 6;
    drawStatCard(doc, margin, cy, cw, ch, "Kondisi Tidak Normal", statusCounts["Tidak Normal"] || 0, "perlu penanganan segera", PDF_C.danger);

    /* DONUT komposisi status (Chart.js — library grafik proyek).       */
    if (donutImage) {
      doc.addImage(donutImage, "PNG", PW - 14 - 62, cy - 2, 62, 44);
    }
    cy += ch + 10;

    doc.setFont("helvetica", "bold"); doc.setFontSize(13);
    doc.setTextColor(...PDF_C.greenDark);
    doc.text("Kesimpulan & Rekomendasi", margin, cy + 4);
    doc.setFont("helvetica", "normal"); doc.setFontSize(10);
    let yy = cy + 12;
    yy = addWrappedText(doc,
      `Periode laporan: ${filterText}. Sistem mencatat ${rows.length} kesimpulan kondisi gabungan (pH + PPM + Dithane). Rata-rata pembacaan pada catatan terfilter: pH ${avgPhFiltered} (rentang pengaturan berlaku saat pencatatan), PPM ${avgPpmFiltered}. Status dominan: ${dominantStatus}.`,
      margin, yy, usable, 5.2);
    doc.setFontSize(10.5);
    recommendations.forEach((note, index) => {
      yy = addWrappedText(doc, `${index + 1}. ${note}`, margin, yy + 2, usable, 5.4) + 1;
    });

    /* P2 LANDSCAPE: GRAFIK — konfigurasi sama dengan grafik web.        */
    doc.addPage("a4", "landscape");
    const LW = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF_C.greenDark);
    doc.rect(0, 0, LW, 22, "F");
    doc.setFont("helvetica", "bold"); doc.setFontSize(13); doc.setTextColor(255, 255, 255);
    doc.text(`Grafik Monitoring pH dan PPM — ${monthName} ${trendYear}`, margin, 12);
    doc.setFont("helvetica", "normal"); doc.setFontSize(9);
    doc.text("Data asli sensor (rata-rata harian) · konfigurasi, warna, dan skala sama dengan grafik web", margin, 18);
    doc.setTextColor(...PDF_C.text);

    doc.setFont("helvetica", "bold"); doc.setFontSize(12);
    doc.setTextColor(...PDF_C.greenDark);
    doc.text("Tren Harian pH dan PPM", margin, 34);
    if (trendImage) {
      doc.addImage(trendImage, "PNG", margin, 38, LW - margin * 2, (LW - margin * 2) * (640 / 1680));
    } else {
      doc.setTextColor(180, 70, 70);
      doc.text("Grafik tidak dapat dimuat karena Chart.js belum tersedia saat PDF dibuat.", margin, 48);
      doc.setTextColor(...PDF_C.text);
    }
    doc.setFont("helvetica", "normal"); doc.setFontSize(9.5);
    doc.text(`Periode pengamatan: ${trends.length} hari · Rata-rata pH ${averagePh} · Rata-rata PPM ${averagePpm} PPM`, margin, doc.internal.pageSize.getHeight() - 20);

    /* P3 PORTRAIT: RINGKASAN AKTIVITAS POMPA (data nyata jika ada).     */
    doc.addPage("a4", "portrait");
    doc.setFillColor(...PDF_C.greenDark);
    doc.rect(0, 0, PW, 22, "F");
    doc.setFont("helvetica", "bold"); doc.setFontSize(13); doc.setTextColor(255, 255, 255);
    doc.text("Ringkasan Aktivitas Pompa", margin, 12);
    doc.setFont("helvetica", "normal"); doc.setFontSize(9);
    doc.text("Dithane: penyemprotan terjadwal & manual yang tercatat pada filter aktif.", margin, 18);
    doc.setTextColor(...PDF_C.text);

    doc.autoTable({
      startY: 28,
      theme: "striped",
      headStyles: { fillColor: [39, 93, 41], textColor: 255, fontSize: 9, halign: "center" },
      styles: { fontSize: 9, cellPadding: 2.6, lineColor: [222, 234, 226], textColor: [46, 74, 58] },
      alternateRowStyles: { fillColor: [246, 251, 248] },
      head: [["No", "Hari", "Tanggal", "Jam", "Status", "Keterangan"]],
      body: dithRows.map((row, index) => [
        index + 1, row.day_name || "-", formatTanggalIndo(row.log_date),
        (row.log_time || "-").slice(0, 5), row.status || "-", row.note || "-",
      ]),
      margin: { left: margin, right: margin },
      columnStyles: { 0: { cellWidth: 12, halign: "center" }, 1: { cellWidth: 22 }, 2: { cellWidth: 34 }, 3: { cellWidth: 16, halign: "center" }, 4: { cellWidth: 22, halign: "center" } },
    });
    if (!dithRows.length) {
      doc.setFont("helvetica", "italic"); doc.setFontSize(9.5);
      doc.text("Tidak ada aktivitas pompa Dithane tercatat pada filter aktif.", margin, 40);
      doc.setFont("helvetica", "normal");
    }
    doc.setFont("helvetica", "normal"); doc.setFontSize(8.5);
    doc.setTextColor(...PDF_C.muted);
    doc.text("Catatan: aktivitas dosing pompa pH & PPM berjalan otomatis di perangkat (bertahap) dan tidak menghasilkan catatan per penyalaan.", margin, doc.internal.pageSize.getHeight() - 16);

    /* P4+: PORTRAIT — TABEL LENGKAP RIWAYAT (font besar, berwarna).     */
    doc.addPage("a4", "portrait");
    doc.setFillColor(...PDF_C.greenDark);
    doc.rect(0, 0, PW, 22, "F");
    doc.setFont("helvetica", "bold"); doc.setFontSize(13); doc.setTextColor(255, 255, 255);
    doc.text("Tabel Lengkap Riwayat Aktivitas", margin, 12);
    doc.setFont("helvetica", "normal"); doc.setFontSize(9);
    doc.text(`Seluruh catatan sesuai filter aktif: ${filterText}`, margin, 18);
    doc.setTextColor(...PDF_C.text);

    doc.autoTable({
      startY: 28,
      theme: "striped",
      headStyles: { fillColor: [7, 141, 67], textColor: 255, fontSize: 9.5, halign: "center" },
      styles: { fontSize: 9, cellPadding: 2.8, lineColor: [222, 234, 226], lineWidth: 0.2, overflow: "linebreak", textColor: [46, 74, 58] },
      alternateRowStyles: { fillColor: [246, 251, 248] },
      head: [["No", "Hari", "Tanggal", "Jam", "Status Sistem", "Keterangan"]],
      body: rows.map((row, index) => [
        index + 1, row.day_name || "-", formatTanggalIndo(row.log_date),
        (row.log_time || "-").slice(0, 5), row.status || "-", row.note || "-",
      ]),
      margin: { left: margin, right: margin, top: 12 },
      rowPageBreak: "avoid",
      columnStyles: {
        0: { cellWidth: 12, halign: "center" },
        1: { cellWidth: 20 },
        2: { cellWidth: 34 },
        3: { cellWidth: 15, halign: "center" },
        4: { cellWidth: 26, halign: "center", fontStyle: "bold" },
        5: { cellWidth: usable - 107 },
      },
      didParseCell: function (data) {
        if (data.section === "body" && data.column.index === 4) {
          const c = STATUS_COLOR[data.cell.raw] || [46, 74, 58];
          data.cell.styles.textColor = c;
          data.cell.styles.fontStyle = "bold";
        }
      },
    });

    /* INFORMASI TAMBAHAN: keterangan status & metodologi.               */
    doc.addPage("a4", "portrait");
    doc.setFillColor(...PDF_C.greenDark);
    doc.rect(0, 0, PW, 22, "F");
    doc.setFont("helvetica", "bold"); doc.setFontSize(13); doc.setTextColor(255, 255, 255);
    doc.text("Keterangan Status & Metodologi", margin, 12);
    doc.setTextColor(...PDF_C.text);
    doc.setFont("helvetica", "normal"); doc.setFontSize(10);
    let ky = 36;
    ky = addWrappedText(doc,
      "Status Normal: pH dan PPM berada dalam rentang pengaturan serta Dithane berjalan sesuai jadwal. Status Perhatian: salah satu parameter di luar rentang, data sensor tidak tersedia, atau Dithane melebihi jadwal. Status Tidak Normal: pH dan PPM sekaligus di luar rentang - memerlukan penanganan segera.",
      margin, ky, usable, 5.4);
    doc.setFontSize(9.5); doc.setTextColor(...PDF_C.muted);
    ky = addWrappedText(doc,
      "Metodologi: seluruh nilai berasal dari sensor ESP32 melalui MQTT dan tersimpan apa adanya di database. Status pada setiap catatan dihitung pada waktu pencatatan menggunakan pengaturan yang berlaku saat itu (tidak dihitung ulang dengan pengaturan terbaru). Grafik menggunakan data sensor asli (rata-rata harian) dengan konfigurasi yang sama seperti grafik web. Data yang tidak tersedia ditampilkan dengan keterangan 'belum tersedia', tidak pernah diasumsikan normal.",
      margin, ky + 4, usable, 5);

    addFootersAllPages(doc, `Filter: ${filterText} · Dibuat ${getGeneratedAtText()} WIB`);
    doc.save("data-aktivitas-sistem-hydrotech.pdf");
    console.log("[PDF] laporan selesai dibuat:", rows.length, "catatan,", doc.internal.getNumberOfPages(), "halaman");
  }

  /* CHART CONFIG: sumber data grafik history pH dan PPM dari API (data asli). */
  function getCurrentMonthIndex() {
    return new Date().getMonth();
  }

  const chartRangeLabels = {
    7: "1 minggu",
    14: "2 minggu",
    month: "1 bulan",
  };
  let historyChart;
  let selectedChartRange = "month";

  /* CHART DATA: mengambil rata-rata harian pH/PPM asli untuk bulan terpilih.
     Cache berlaku 30 detik agar grafik & rata-rata tetap mengikuti data
     sensor terbaru tanpa harus me-refresh halaman.                    */
  const trendCache = new Map();
  const TREND_CACHE_TTL = 30000;

  async function fetchMonthlyTrend(monthIndex) {
    const cached = trendCache.get(monthIndex);
    if (cached && Date.now() - cached.at < TREND_CACHE_TTL) return cached.trend;
    try {
      const year = new Date().getFullYear();
      const response = await fetch(
        `../api/history/trend.php?month=${monthIndex + 1}&year=${year}`,
      );
      const result = await response.json();
      const trend =
        result.success && Array.isArray(result.data?.trend)
          ? result.data.trend
          : [];
      trendCache.set(monthIndex, { trend, at: Date.now() });
      return trend;
    } catch (error) {
      console.error("Gagal membaca tren history:", error);
      return cached ? cached.trend : [];
    }
  }

  /* CHART HELPERS: menghitung rata-rata, rentang sumbu, dan periode yang ditampilkan. */
  function getAverage(items, key, digits) {
    const values = items
      .map((item) => item[key])
      .filter((value) => Number.isFinite(value));
    if (!values.length) return "-";
    const total = values.reduce((sum, value) => sum + value, 0);
    return (total / values.length).toFixed(digits);
  }

  function getAxisRange(items, key, padding, roundTo, fallback) {
    const values = items
      .map((item) => item[key])
      .filter((value) => Number.isFinite(value));
    if (!values.length) return fallback || { min: 0, max: 1 };

    const min = Math.min(...values);
    const max = Math.max(...values);

    return {
      min: Math.floor((min - padding) / roundTo) * roundTo,
      max: Math.ceil((max + padding) / roundTo) * roundTo,
    };
  }

  function getChartRangeData(items) {
    if (selectedChartRange === "month") return items;

    return items.slice(0, Number(selectedChartRange));
  }

  function getChartPeriodText(data, monthIndex) {
    const rangeLabel = chartRangeLabels[selectedChartRange] || "1 bulan";
    const startDate = data[0]?.date || monthNames[monthIndex];
    const endDate = data[data.length - 1]?.date || monthNames[monthIndex];

    return `Tren monitoring pH dan PPM periode ${rangeLabel} (${startDate} - ${endDate}), lengkap dengan hari, tanggal, dan jam pengukuran.`;
  }

  /* CHART STYLE: membuat gradasi area pada garis pH dan PPM. */
  function makeHistoryGradient(context, color) {
    const gradient = context.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, color.replace("rgb", "rgba").replace(")", ", 0.18)"));
    gradient.addColorStop(1, color.replace("rgb", "rgba").replace(")", ", 0.02)"));
    return gradient;
  }

  /* CHART RENDER: menggambar ulang grafik, rata-rata, dan teks periode sesuai filter. */
  async function renderHistoryChart() {
    const canvas = document.getElementById("historyLineChart");
    const monthFilter = document.querySelector("[data-history-chart-month]");
    const selectedMonth = monthFilter
      ? Number(monthFilter.value)
      : getCurrentMonthIndex();
    const data = getChartRangeData(await fetchMonthlyTrend(selectedMonth));
    const phRange = getAxisRange(data, "ph", 0.18, 0.1, { min: 5, max: 7 });
    const ppmRange = getAxisRange(data, "ppm", 28, 25, { min: 750, max: 1050 });
    const periodText = document.querySelector("[data-history-chart-period]");
    const averagePh = document.querySelector("[data-chart-average-ph]");
    const averagePpm = document.querySelector("[data-chart-average-ppm]");
    if (periodText) {
      periodText.textContent = getChartPeriodText(data, selectedMonth);
    }
    if (averagePh) {
      averagePh.textContent = data.length ? getAverage(data, "ph", 1) : "-";
    }
    if (averagePpm) {
      averagePpm.textContent = data.length
        ? `${getAverage(data, "ppm", 0)} PPM`
        : "-";
    }
    if (!canvas || typeof Chart === "undefined") return;
    const context = canvas.getContext("2d");

    if (historyChart) {
      historyChart.destroy();
    }

    historyChart = new Chart(canvas, {
      type: "line",
      data: {
        labels: data.map((item) => `${item.day}, ${item.date} ${item.time}`),
        datasets: [
          {
            label: "pH",
            data: data.map((item) => item.ph),
            yAxisID: "yPh",
            borderColor: "#ff7777",
            backgroundColor: makeHistoryGradient(context, "rgb(255, 119, 119)"),
            borderWidth: 4,
            pointRadius: 4,
            pointHoverRadius: 7,
            pointBackgroundColor: "#ffffff",
            pointBorderColor: "#ff7777",
            pointBorderWidth: 3,
            tension: 0.42,
            fill: true,
          },
          {
            label: "PPM",
            data: data.map((item) => item.ppm),
            yAxisID: "yPpm",
            borderColor: "#07a64f",
            backgroundColor: makeHistoryGradient(context, "rgb(7, 166, 79)"),
            borderWidth: 4,
            pointRadius: 4,
            pointHoverRadius: 7,
            pointBackgroundColor: "#ffffff",
            pointBorderColor: "#07a64f",
            pointBorderWidth: 3,
            tension: 0.42,
            fill: true,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
          padding: {
            top: 14,
            right: 8,
          },
        },
        interaction: {
          intersect: false,
          mode: "index",
        },
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            backgroundColor: "rgba(255, 255, 255, 0.96)",
            titleColor: "#203428",
            bodyColor: "#203428",
            borderColor: "#dfe8e2",
            borderWidth: 1,
            padding: 12,
            displayColors: true,
            titleFont: {
              size: 11,
              weight: "800",
            },
            bodyFont: {
              size: 11,
              weight: "800",
            },
            callbacks: {
              label: function (context) {
                const unit = context.dataset.label === "PPM" ? " PPM" : "";
                return `${context.dataset.label}: ${context.parsed.y}${unit}`;
              },
            },
          },
        },
        scales: {
          x: {
            grid: {
              display: false,
            },
            ticks: {
              color: "#6b7c70",
              font: {
                size: 10,
                weight: "700",
              },
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 8,
              callback: function (value) {
                const item = data[value];
                return item
                  ? `${item.date.split(" ").slice(0, 2).join(" ")} ${item.time}`
                  : "";
              },
            },
          },
          yPh: {
            position: "left",
            min: phRange.min,
            max: phRange.max,
            ticks: {
              color: "#d95f5f",
              font: {
                size: 10,
                weight: "800",
              },
            },
            title: {
              display: true,
              text: "pH",
              color: "#d95f5f",
              font: {
                size: 11,
                weight: "900",
              },
            },
            grid: {
              color: "rgba(111, 134, 120, 0.14)",
              borderDash: [4, 8],
            },
          },
          yPpm: {
            position: "right",
            min: ppmRange.min,
            max: ppmRange.max,
            ticks: {
              color: "#078d43",
              font: {
                size: 10,
                weight: "800",
              },
            },
            title: {
              display: true,
              text: "PPM",
              color: "#078d43",
              font: {
                size: 11,
                weight: "900",
              },
            },
            grid: {
              drawOnChartArea: false,
            },
          },
        },
      },
    });
  }

  /* FILTER EVENTS: menghubungkan input filter tabel dengan render history. */
  [
    searchInput, dayFilter, monthFilter, yearFilter, statusFilter,
  ].forEach((control) => {
    control?.addEventListener("input", function () {
      currentPage = 1;
      fetchRows();
    });
    control?.addEventListener("change", function () {
      currentPage = 1;
      fetchRows();
    });
  });

  /* PANEL RENTANG JAM: satu klik membuka jam mulai + jam selesai.      */
  function closeTimePopup() {
    if (timePopup) timePopup.hidden = true;
    document.removeEventListener("click", onDocClickTimePopup);
  }
  function onDocClickTimePopup(event) {
    if (!event.target.closest(".history-time-range")) closeTimePopup();
  }
  timeRangeBtn?.addEventListener("click", function (event) {
    event.stopPropagation();
    if (!timePopup) return;
    timePopup.hidden = !timePopup.hidden;
    if (!timePopup.hidden) document.addEventListener("click", onDocClickTimePopup);
    else document.removeEventListener("click", onDocClickTimePopup);
  });
  timePopup?.querySelector("[data-trp-apply]")?.addEventListener("click", function () {
    const j1 = fromJamSel?.value || "", m1 = fromMenitSel?.value || "";
    const j2 = toJamSel?.value || "", m2 = toMenitSel?.value || "";
    appliedFrom = j1 ? `${j1}:${m1 || "00"}` : "";
    appliedTo = j2 ? `${j2}:${m2 || "59"}` : "";
    updateTimeLabel();
    closeTimePopup();
    currentPage = 1;
    fetchRows();
  });
  timePopup?.querySelector("[data-trp-clear]")?.addEventListener("click", function () {
    [fromJamSel, fromMenitSel, toJamSel, toMenitSel].forEach((sel) => { if (sel) sel.value = ""; });
    appliedFrom = ""; appliedTo = "";
    updateTimeLabel();
    closeTimePopup();
    currentPage = 1;
    fetchRows();
  });

  /* RESET EVENT: menampilkan konfirmasi sebelum filter history dikosongkan. */
  resetButton?.addEventListener("click", async function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();

    if (window.HydrotechConfirm) {
      const confirmed = await window.HydrotechConfirm.open({
        icon: "R",
        title: "Reset filter history?",
        message: "Semua filter history akan dikembalikan ke kondisi awal.",
        confirmText: "Ya, Reset",
        variant: "danger",
      });
      if (!confirmed) return;
    }

    resetFilters();
  });

  /* DELETE EVENT: menghapus data history setelah konfirmasi via API. */
  table.addEventListener("click", async function (event) {
    const deleteButton = event.target.closest(".trash-btn");
    if (!deleteButton) return;

    const row = deleteButton.closest("tr");
    if (!row) return;
    const rowId = row.dataset.rowId;
    if (!rowId) return;

    if (window.HydrotechConfirm) {
      const confirmed = await window.HydrotechConfirm.open({
        icon: "X",
        title: "Hapus data history?",
        message:
          "Data ini akan dihapus dari tabel history dan tetap hilang setelah halaman di-refresh.",
        confirmText: "Ya, Hapus",
        variant: "danger",
      });
      if (!confirmed) return;
    }

    const formData = new FormData();
    formData.append("id", rowId);
    formData.append("csrf_token", token());   // WAJIB: server menolak tanpa token
    fetch("../api/history/delete.php", {
      method: "POST",
      body: formData
    })
    .then(response => response.json())
    .then(result => {
      if (result.success) {
        if (window.HydrotechToast?.success("Data berhasil dihapus.")) {}
        fetchRows();      // muat ulang dari database (sinkron dgn filter aktif)
      } else {
        if (window.HydrotechToast?.error(result.message || "Gagal menghapus data history.")) {}
        else alert(result.message || "Gagal menghapus data history.");
      }
    })
    .catch(e => {
      console.error(e);
      if (window.HydrotechToast?.error("Kesalahan jaringan.")) {}
      else alert("Kesalahan jaringan.");
    });
  });

  /* EXPORT EVENT: membuat PDF dari dataset hasil filter aktif. */
  exportButton?.addEventListener("click", async function () {
    await exportHistoryPdf();
  });

  document
    .querySelector("[data-history-chart-month]")
    ?.addEventListener("change", function () {
      renderHistoryChart();
    });

  document.querySelectorAll("[data-history-chart-range]").forEach((button) => {
    button.addEventListener("click", function () {
      selectedChartRange = button.dataset.historyChartRange || "month";
      document.querySelectorAll("[data-history-chart-range]").forEach((item) => {
        const isActive = item === button;
        item.classList.toggle("active", isActive);
        item.setAttribute("aria-pressed", String(isActive));
      });
      renderHistoryChart();
    });
  });

  /* INITIAL RENDER: memuat data kesimpulan + grafik saat halaman dibuka. */
  populateTimeSelects();
  transformTimeDropdowns();
  fetchRows();
  renderHistoryChart();

  /* AUTO REFRESH: grafik & rata-rata ikuti data sensor terbaru.        */
  window.setInterval(renderHistoryChart, 30000);
})();
