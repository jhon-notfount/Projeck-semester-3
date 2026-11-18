(function () {
  /* HISTORY SETUP: Data Aktivitas Sistem = riwayat KESIMPULAN gabungan
     (pH + PPM + Dithane, satu baris per kejadian, type='sistem').
     Semua filter dieksekusi di DATABASE (api/history/list.php); halaman
     hanya merender hasilnya. Pagination tetap di sisi klien. */
  const rowsPerPage = 5;
  const table = document.querySelector(".history-table");
  if (!table) return;
  const tableBody = table.querySelector("tbody");

  const searchInput = document.querySelector("[data-history-search]");
  const dayFilter = document.querySelector("[data-history-day]");
  const monthFilter = document.querySelector("[data-history-month]");
  const yearFilter = document.querySelector("[data-history-year]");
  const statusFilter = document.querySelector("[data-history-status]");
  const timeFromInput = document.querySelector("[data-history-time-from]");
  const timeToInput = document.querySelector("[data-history-time-to]");
  const resetButton = document.querySelector("[data-history-reset]");
  const exportButton = document.querySelector("[data-history-export]");
  const showing = document.querySelector("[data-history-showing]");
  const pagination = document.querySelector("[data-history-pagination]");
  const emptyState = document.querySelector("[data-history-empty]");
  let currentPage = 1;
  let historyRows = [];          // dataset hasil filter terkini (dari database)

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
    if (timeFromInput?.value) params.set("time_from", timeFromInput.value);
    if (timeToInput?.value) params.set("time_to", timeToInput.value);
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
    if (timeFromInput) timeFromInput.value = "";
    if (timeToInput) timeToInput.value = "";
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
    if (timeFromInput?.value || timeToInput?.value) {
      parts.push(`jam ${timeFromInput?.value || "00.00"} - ${timeToInput?.value || "23.59"}`);
    }
    if (statusFilter?.value) parts.push(`status ${statusFilter.value}`);
    return parts.length ? parts.join(", ") : "semua data";
  }

  /* PDF DATA HELPERS: membungkus teks, membaca status, dan membuat detail tabel bulanan. */
  function addWrappedText(doc, text, x, y, maxWidth, lineHeight) {
    const lines = doc.splitTextToSize(text, maxWidth);
    doc.text(lines, x, y);
    return y + lines.length * lineHeight;
  }

  /* PDF STYLE: membuat kotak metrik dan footer di setiap halaman laporan. */
  function drawMetricBox(doc, x, y, width, title, value, note, color) {
    doc.setDrawColor(222, 234, 226);
    doc.setFillColor(248, 252, 250);
    doc.roundedRect(x, y, width, 24, 3, 3, "FD");
    doc.setTextColor(...color);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(14);
    doc.text(String(value), x + 5, y + 10);
    doc.setTextColor(85, 105, 92);
    doc.setFontSize(7.5);
    doc.text(title, x + 5, y + 16);
    doc.setTextColor(118, 133, 123);
    doc.setFont("helvetica", "normal");
    doc.text(note, x + 5, y + 21);
  }

  function addReportFooters(doc, monthName, margin, reportYear) {
    const pageCount = doc.internal.getNumberOfPages();
    const pageHeight = doc.internal.pageSize.getHeight();
    for (let page = 1; page <= pageCount; page++) {
      doc.setPage(page);
      doc.setDrawColor(224, 235, 229);
      doc.line(margin, pageHeight - 12, doc.internal.pageSize.getWidth() - margin, pageHeight - 12);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8);
      doc.setTextColor(110, 124, 112);
      doc.text(`Hydrotech | Laporan ${monthName} ${reportYear}`, margin, pageHeight - 7);
      doc.text(`Halaman ${page} dari ${pageCount}`, doc.internal.pageSize.getWidth() - margin, pageHeight - 7, {
        align: "right",
      });
    }
  }

  /* CHART IMAGE: membuat gambar grafik sementara untuk dimasukkan ke PDF. */
  async function createMonthlyChartImage(trends, monthName, reportYear) {
    if (typeof Chart === "undefined") return null;

    const canvas = document.createElement("canvas");
    canvas.width = 1400;
    canvas.height = 620;
    canvas.style.position = "fixed";
    canvas.style.left = "-9999px";
    canvas.style.top = "0";
    document.body.appendChild(canvas);

    const context = canvas.getContext("2d");
    const whiteBackground = {
      id: "whiteBackground",
      beforeDraw(chart) {
        const chartContext = chart.canvas.getContext("2d");
        chartContext.save();
        chartContext.globalCompositeOperation = "destination-over";
        chartContext.fillStyle = "#ffffff";
        chartContext.fillRect(0, 0, chart.width, chart.height);
        chartContext.restore();
      },
    };

    const chart = new Chart(canvas, {
      type: "line",
      data: {
        labels: trends.map((item) => item.date.split(" ").slice(0, 2).join(" ")),
        datasets: [
          {
            label: "pH",
            data: trends.map((item) => item.ph),
            yAxisID: "yPh",
            borderColor: "#ff7777",
            backgroundColor: "rgba(255, 119, 119, 0.12)",
            borderWidth: 4,
            pointRadius: 3,
            pointBackgroundColor: "#ffffff",
            pointBorderColor: "#ff7777",
            pointBorderWidth: 2,
            tension: 0.38,
            fill: true,
          },
          {
            label: "PPM",
            data: trends.map((item) => item.ppm),
            yAxisID: "yPpm",
            borderColor: "#07a64f",
            backgroundColor: "rgba(7, 166, 79, 0.1)",
            borderWidth: 4,
            pointRadius: 3,
            pointBackgroundColor: "#ffffff",
            pointBorderColor: "#07a64f",
            pointBorderWidth: 2,
            tension: 0.38,
            fill: true,
          },
        ],
      },
      plugins: [whiteBackground],
      options: {
        responsive: false,
        animation: false,
        layout: { padding: { top: 24, right: 28, bottom: 8, left: 12 } },
        plugins: {
          title: {
            display: true,
            text: `Grafik Tren Harian pH dan PPM - ${monthName} ${reportYear}`,
            color: "#203428",
            font: { size: 22, weight: "bold" },
            padding: { bottom: 18 },
          },
          legend: {
            labels: { color: "#203428", font: { size: 14, weight: "bold" } },
          },
        },
        scales: {
          x: {
            ticks: { color: "#68786d", maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
            grid: { display: false },
          },
          yPh: {
            position: "left",
            min: 4.8,
            max: 7.5,
            title: { display: true, text: "pH", color: "#d95f5f" },
            ticks: { color: "#d95f5f" },
            grid: { color: "rgba(111, 134, 120, 0.12)" },
          },
          yPpm: {
            position: "right",
            min: 760,
            max: 1030,
            title: { display: true, text: "PPM", color: "#078d43" },
            ticks: { color: "#078d43" },
            grid: { drawOnChartArea: false },
          },
        },
      },
    });

    await new Promise((resolve) => requestAnimationFrame(resolve));
    const image = canvas.toDataURL("image/png", 1);
    chart.destroy();
    canvas.remove();
    return image;
  }

  /* PDF EXPORT: menyusun laporan bulanan lengkap berisi ringkasan, grafik, tabel, dan kesimpulan. */
  async function exportHistoryPdf() {
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

    /* EKSPOR MENGIKUTI FILTER AKTIF: dataset = hasil filter database. */
    const rows = historyRows;
    const filterText = getFilterDescription();

    /* Bulan tren untuk grafik = bulan catatan terbaru pada filter aktif. */
    const newest = rows[0];
    const newestDate = new Date(String(newest.created_at || "").replace(" ", "T"));
    const trendMonth = Number.isFinite(newestDate.getMonth()) && newest.created_at
      ? newestDate.getMonth()
      : new Date().getMonth();
    const trendYear = newest.created_at ? newestDate.getFullYear() : new Date().getFullYear();
    const monthName = monthNames[trendMonth];

    const trends = await fetchMonthlyTrend(trendMonth);
    const averagePh = getAverage(trends, "ph", 1);
    const averagePpm = getAverage(trends, "ppm", 0);
    const phRange = getAxisRange(trends, "ph", 0, 0.1, { min: 5, max: 7 });
    const ppmRange = getAxisRange(trends, "ppm", 0, 1, { min: 750, max: 1050 });
    const statusCounts = countBy(rows, "status");
    const recommendations = getFilteredRecommendation(rows);
    const dominantStatus = Object.entries(statusCounts).sort((a, b) => b[1] - a[1])[0]?.[0] || "-";

    /* Rata-rata nilai sensor dari baris kesimpulan terfilter (kolom baru). */
    const phValues = rows.map((r) => Number(r.ph_value)).filter(Number.isFinite);
    const ppmValues = rows.map((r) => Number(r.ppm_value)).filter(Number.isFinite);
    const avgPhFiltered = phValues.length ? (phValues.reduce((s, v) => s + v, 0) / phValues.length).toFixed(2) : "-";
    const avgPpmFiltered = ppmValues.length ? Math.round(ppmValues.reduce((s, v) => s + v, 0) / ppmValues.length) : "-";

    const chartImage = await createMonthlyChartImage(trends, monthName, trendYear);
    const doc = new jsPdf({ orientation: "landscape", unit: "mm", format: "a4" });
    if (typeof doc.autoTable !== "function") {
      alert("Plugin tabel PDF belum berhasil dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
      return;
    }

    const pageWidth = doc.internal.pageSize.getWidth();
    const margin = 14;
    const usableWidth = pageWidth - margin * 2;

    doc.setProperties({
      title: "Laporan Data Aktivitas Sistem Hydrotech",
      subject: "Kesimpulan kondisi pH, PPM, dan Dithane",
      author: "Hydrotech Dashboard Admin",
    });

    doc.setFillColor(7, 141, 67);
    doc.rect(0, 0, pageWidth, 28, "F");
    doc.setTextColor(255, 255, 255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(17);
    doc.text("Laporan Data Aktivitas Sistem Hydrotech", margin, 13);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.text(`Filter: ${filterText} | Dibuat: ${getGeneratedAtText()} WIB`, margin, 21);

    doc.setTextColor(35, 52, 40);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.text("Ringkasan Untuk Pemilik", margin, 40);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    let y = addWrappedText(
      doc,
      `Laporan ini merupakan kesimpulan gabungan kondisi pH, PPM, dan Dithane sebagaimana tampil pada halaman Data Aktivitas Sistem, dengan filter aktif: ${filterText}. Setiap baris mewakili kondisi keseluruhan sistem pada satu waktu pencatatan.`,
      margin,
      47,
      usableWidth,
      5,
    );

    const metricY = y + 5;
    const metricWidth = (usableWidth - 18) / 4;
    drawMetricBox(doc, margin, metricY, metricWidth, "Catatan terfilter", rows.length, "kesimpulan sistem", [7, 141, 67]);
    drawMetricBox(doc, margin + metricWidth + 6, metricY, metricWidth, "Rata-rata pH", avgPhFiltered, "dari nilai terfilter", [217, 95, 95]);
    drawMetricBox(doc, margin + (metricWidth + 6) * 2, metricY, metricWidth, "Rata-rata PPM", avgPpmFiltered, "dari nilai terfilter", [7, 141, 67]);
    drawMetricBox(doc, margin + (metricWidth + 6) * 3, metricY, metricWidth, "Status dominan", dominantStatus, "berdasarkan filter", [22, 118, 164]);
    y = metricY + 30;

    doc.autoTable({
      startY: y,
      theme: "grid",
      pageBreak: "avoid",
      rowPageBreak: "avoid",
      styles: { fontSize: 7.3, cellPadding: 1.8, lineColor: [222, 234, 226] },
      headStyles: { fillColor: [7, 141, 67], textColor: 255 },
      head: [["Indikator", "Nilai / Penjelasan"]],
      body: [
        ["Total kesimpulan terfilter", `${rows.length} catatan`],
        ["Komposisi status sistem", formatCounts(statusCounts)],
        ["Rata-rata pH (terfilter)", `${avgPhFiltered} pH (acuan aman 5.5 - 6.5)`],
        ["Rata-rata PPM (terfilter)", `${avgPpmFiltered} PPM (target 800 - 1000 PPM)`],
        ["Rentang pH tren harian", `${phRange.min.toFixed(1)} - ${phRange.max.toFixed(1)} pH`],
        ["Rentang PPM tren harian", `${ppmRange.min} - ${ppmRange.max} PPM`],
      ],
      margin: { left: margin, right: margin },
      columnStyles: { 0: { cellWidth: 64 }, 1: { cellWidth: usableWidth - 64 } },
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text("Analisis Kondisi & Rekomendasi", margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y = addWrappedText(
      doc,
      "Status Normal: pH dan PPM dalam rentang serta Dithane berjalan sesuai jadwal. Status Perhatian: salah satu parameter di luar rentang, data sensor tidak tersedia, atau Dithane melebihi jadwal. Status Tidak Normal: pH dan PPM sekaligus di luar rentang - perlu penanganan segera.",
      margin,
      26,
      usableWidth,
      4.5,
    );

    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Rekomendasi Tindakan", margin, y + 8);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    let ry = y + 15;
    recommendations.forEach((note, index) => {
      ry = addWrappedText(doc, `${index + 1}. ${note}`, margin, ry, usableWidth, 4.5) + 1;
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text(`Grafik Tren Harian - ${monthName} ${trendYear}`, margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.text("Grafik pH dan PPM asli dari data sensor (bukan kesimpulan), bulan catatan terbaru pada filter aktif.", margin, 25);
    if (chartImage) {
      doc.addImage(chartImage, "PNG", margin, 33, usableWidth, 105);
    } else {
      doc.setTextColor(180, 70, 70);
      doc.text("Grafik tidak dapat dimuat karena Chart.js belum tersedia saat PDF dibuat.", margin, 45);
      doc.setTextColor(35, 52, 40);
    }

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text("Data Aktivitas Sistem (Hasil Filter)", margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.text("Tabel berisi kesimpulan gabungan yang sama dengan tabel halaman - mengikuti filter yang sedang digunakan.", margin, 25);

    doc.autoTable({
      startY: 31,
      theme: "striped",
      styles: { fontSize: 7, cellPadding: 1.8, overflow: "linebreak" },
      headStyles: { fillColor: [39, 93, 41], textColor: 255 },
      head: [["No", "Hari", "Tanggal", "Jam", "Status Sistem", "Keterangan"]],
      body: rows.map((row, index) => [
        index + 1,
        row.day_name || "-",
        formatTanggalIndo(row.log_date),
        (row.log_time || "-").slice(0, 5),
        row.status || "-",
        row.note || "-",
      ]),
      margin: { left: margin, right: margin },
      columnStyles: {
        0: { cellWidth: 10 },
        1: { cellWidth: 20 },
        2: { cellWidth: 32 },
        3: { cellWidth: 16 },
        4: { cellWidth: 26 },
        5: { cellWidth: usableWidth - 104 },
      },
    });

    addReportFooters(doc, `Aktivitas Sistem (${filterText})`, margin, trendYear);
    doc.save("data-aktivitas-sistem-hydrotech.pdf");
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

  /* CHART DATA: mengambil rata-rata harian pH/PPM asli untuk bulan terpilih. */
  const trendCache = new Map();

  async function fetchMonthlyTrend(monthIndex) {
    if (trendCache.has(monthIndex)) return trendCache.get(monthIndex);
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
      trendCache.set(monthIndex, trend);
      return trend;
    } catch (error) {
      console.error("Gagal membaca tren history:", error);
      return [];
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
    timeFromInput, timeToInput,
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
    fetch("../api/history/delete.php", {
      method: "POST",
      body: formData
    })
    .then(response => response.json())
    .then(result => {
      if (result.success) {
        row.remove();
        fetchRows();      // muat ulang dari database (sinkron dgn filter aktif)
      } else {
        alert("Gagal menghapus data history.");
      }
    })
    .catch(e => {
      console.error(e);
      alert("Kesalahan jaringan.");
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
  fetchRows();
  renderHistoryChart();
})();
