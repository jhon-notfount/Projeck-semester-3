(function () {
  /* HISTORY SETUP: konfigurasi tabel, filter, pagination, dan tombol export history. */
  const storageKey = "hydrotech.history.deletedRows";
  const rowsPerPage = 5;
  const table = document.querySelector(".history-table");
  if (!table) return;

  const searchInput = document.querySelector("[data-history-search]");
  const typeFilter = document.querySelector("[data-history-type]");
  const dayFilter = document.querySelector("[data-history-day]");
  const monthFilter = document.querySelector("[data-history-month]");
  const statusFilter = document.querySelector("[data-history-status]");
  const resetButton = document.querySelector("[data-history-reset]");
  const exportButton = document.querySelector("[data-history-export]");
  const showing = document.querySelector("[data-history-showing]");
  const pagination = document.querySelector("[data-history-pagination]");
  const emptyState = document.querySelector("[data-history-empty]");
  let currentPage = 1;

  /* TEXT NORMALIZER: menyeragamkan teks supaya pencarian dan filter lebih mudah. */
  function normalize(value) {
    return String(value || "")
      .trim()
      .toLowerCase();
  }

  /* DELETE STORAGE: sekarang ditangani oleh backend via API. */

  /* ROW HELPERS: mengambil baris tabel dan mengubah isi kolom menjadi data terstruktur. */
  function getRows() {
    return Array.from(table.querySelectorAll("tbody tr"));
  }

  function getRowData(row) {
    const cells = row.querySelectorAll("td");
    const date = cells[2]?.textContent.trim() || "";
    return {
      day: cells[1]?.textContent.trim() || "",
      date,
      month: date.split(" ")[1] || "",
      time: cells[3]?.textContent.trim() || "",
      type: cells[4]?.textContent.trim() || "",
      status: cells[5]?.textContent.trim() || "",
      note: cells[6]?.textContent.trim() || "",
    };
  }

  /* FILTER DATA: memilih baris yang cocok dengan keyword, tipe, hari, bulan, dan status. */
  function getFilteredRows() {
    const keyword = normalize(searchInput?.value);
    const type = normalize(typeFilter?.value);
    const day = normalize(dayFilter?.value);
    const month = normalize(monthFilter?.value);
    const status = normalize(statusFilter?.value);

    return getRows().filter((row) => {
      const data = getRowData(row);
      const rowText = normalize(
        `${data.day} ${data.date} ${data.time} ${data.type} ${data.status} ${data.note}`,
      );

      return (
        (!keyword || rowText.includes(keyword)) &&
        (!type || normalize(data.type) === type) &&
        (!day || normalize(data.day) === day) &&
        (!month || normalize(data.month) === month) &&
        (!status || normalize(data.status) === status)
      );
    });
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
        render();
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
        render();
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
        render();
      }
    });
    pagination.appendChild(next);
  }

  /* TABLE RENDER: menampilkan baris sesuai filter dan halaman yang sedang aktif. */
  function render() {
    const filteredRows = getFilteredRows();
    const totalPages = Math.max(
      1,
      Math.ceil(filteredRows.length / rowsPerPage),
    );
    if (currentPage > totalPages) currentPage = totalPages;

    const pageStart = (currentPage - 1) * rowsPerPage;
    const pageRows = filteredRows.slice(pageStart, pageStart + rowsPerPage);

    getRows().forEach((row) => {
      row.style.display = "none";
    });

    pageRows.forEach((row, index) => {
      row.style.display = "";
      row.querySelector("td").textContent = pageStart + index + 1;
    });

    if (showing) {
      const from = filteredRows.length ? pageStart + 1 : 0;
      const to = pageStart + pageRows.length;
      showing.textContent = `Menampilkan ${from}-${to} dari ${filteredRows.length} data`;
    }

    if (emptyState) {
      emptyState.classList.toggle("show", filteredRows.length === 0);
    }

    renderPagination(totalPages);
  }

  /* RESET FILTER: mengosongkan semua filter dan kembali ke halaman pertama. */
  function resetFilters() {
    if (searchInput) searchInput.value = "";
    if (typeFilter) typeFilter.value = "";
    if (dayFilter) dayFilter.value = "";
    if (monthFilter) monthFilter.value = "";
    if (statusFilter) statusFilter.value = "";
    currentPage = 1;
    render();
  }

  /* DELETED ROWS: baris dihapus langsung oleh backend, tidak perlu disembunyikan via JS lagi. */

  const monthNames = [
    "Januari",
    "Februari",
    "Maret",
    "April",
    "Mei",
    "Juni",
    "Juli",
    "Agustus",
    "September",
    "Oktober",
    "November",
    "Desember",
  ];
  const dayNames = [
    "Minggu",
    "Senin",
    "Selasa",
    "Rabu",
    "Kamis",
    "Jumat",
    "Sabtu",
  ];

  /* MONTH DATA: memilih data aktual dan tren berdasarkan bulan laporan. */
  function getCurrentMonthIndex() {
    return new Date().getMonth();
  }

  function getRowsByMonth(monthIndex) {
    const monthName = normalize(monthNames[monthIndex]);
    return getRows()
      .map(getRowData)
      .filter((data) => normalize(data.month) === monthName);
  }

  /* SUMMARY HELPERS: menghitung jumlah data, rata-rata, dan teks ringkasan laporan. */
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

  /* RECOMMENDATION: membuat catatan tindakan untuk pemilik berdasarkan data bulanan. */
  function getMonthlyRecommendation(rows, trends) {
    const statusCounts = countBy(rows, "status");
    const averagePh = Number(getAverage(trends, "ph", 1));
    const averagePpm = Number(getAverage(trends, "ppm", 0));
    const notes = [];

    if (statusCounts.Tinggi) {
      notes.push("Ada status Tinggi, pemilik perlu mengecek penyebab kenaikan parameter dan melakukan penyesuaian bertahap.");
    }
    if (statusCounts.Rendah) {
      notes.push("Ada status Rendah, pemilik perlu memastikan nutrisi, pH, dan jadwal penyemprotan tidak berada di bawah kebutuhan tanaman.");
    }
    if (averagePh < 5.5 || averagePh > 6.5) {
      notes.push(`Rata-rata pH ${averagePh} berada di luar rentang aman 5.5 - 6.5, sehingga kalibrasi larutan disarankan.`);
    }
    if (averagePpm < 800 || averagePpm > 1000) {
      notes.push(`Rata-rata PPM ${averagePpm} berada di luar target 800 - 1000 PPM, sehingga komposisi nutrisi perlu dievaluasi.`);
    }
    if (!notes.length) {
      notes.push("Kondisi bulanan relatif stabil. Pemilik tetap disarankan memantau tren harian agar perubahan kecil tidak terlambat ditangani.");
    }

    return notes;
  }

  /* EXPORT PROMPT: menyiapkan tanggal laporan dan meminta pilihan bulan PDF. */
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

  function getMonthSelectionOptions() {
    const currentMonth = getCurrentMonthIndex();
    return {
      current: `Sekarang (${monthNames[currentMonth]})`,
      ...monthNames.reduce((options, month, index) => {
        options[String(index)] = month;
        return options;
      }, {}),
    };
  }

  async function requestExportMonth() {
    const currentMonth = getCurrentMonthIndex();
    if (window.Swal) {
      const result = await Swal.fire({
        title: "Pilih bulan laporan",
        text: "History bulanan akan dibuat sebagai PDF lengkap untuk bahan pertimbangan pemilik.",
        input: "select",
        inputOptions: getMonthSelectionOptions(),
        inputValue: "current",
        showCancelButton: true,
        confirmButtonText: "Download PDF",
        cancelButtonText: "Batal",
        reverseButtons: true,
        focusCancel: true,
        buttonsStyling: false,
        customClass: {
          popup: "hydrotech-swal history-export-swal",
          title: "hydrotech-swal-title",
          htmlContainer: "hydrotech-swal-text",
          actions: "hydrotech-swal-actions",
          confirmButton: "hydrotech-swal-confirm",
          cancelButton: "hydrotech-swal-cancel",
        },
      });

      if (!result.isConfirmed) return null;
      return result.value === "current" ? currentMonth : Number(result.value);
    }

    const answer = window.prompt(
      `Pilih bulan laporan (1-12). Kosongkan untuk bulan sekarang: ${monthNames[currentMonth]}`,
      String(currentMonth + 1),
    );
    if (answer === null) return null;
    const monthNumber = Number(answer || currentMonth + 1);
    if (!Number.isFinite(monthNumber) || monthNumber < 1 || monthNumber > 12) {
      alert("Bulan tidak valid.");
      return null;
    }
    return monthNumber - 1;
  }

  /* PDF DATA HELPERS: membungkus teks, membaca status, dan membuat detail tabel bulanan. */
  function addWrappedText(doc, text, x, y, maxWidth, lineHeight) {
    const lines = doc.splitTextToSize(text, maxWidth);
    doc.text(lines, x, y);
    return y + lines.length * lineHeight;
  }

  function getOutOfRangeSummary(trends) {
    return trends.reduce(
      (summary, item) => {
        if (item.ph < 5.5) summary.phLow += 1;
        if (item.ph > 6.5) summary.phHigh += 1;
        if (item.ppm < 800) summary.ppmLow += 1;
        if (item.ppm > 1000) summary.ppmHigh += 1;
        return summary;
      },
      { phLow: 0, phHigh: 0, ppmLow: 0, ppmHigh: 0 },
    );
  }

  function getMostFrequent(counts) {
    const entries = Object.entries(counts);
    if (!entries.length) return "-";
    return entries.sort((a, b) => b[1] - a[1])[0][0];
  }

  function getTrendStatus(type, value) {
    if (type === "pH") {
      if (value < 5.5) return "Rendah";
      if (value > 6.5) return "Tinggi";
      return "Normal";
    }

    if (value < 800) return "Rendah";
    if (value > 1000) return "Tinggi";
    return "Normal";
  }

  function getDetailedMonthlyRows(actualRows, trends) {
    const actualByDateType = actualRows.reduce((result, row) => {
      result[`${row.date}|${row.type}`] = row;
      return result;
    }, {});

    return trends.flatMap((item) => {
      const phActual = actualByDateType[`${item.date}|pH`];
      const ppmActual = actualByDateType[`${item.date}|PPM`];
      const dithaneActual = actualByDateType[`${item.date}|Dithane`];
      const phStatus = phActual?.status || getTrendStatus("pH", item.ph);
      const ppmStatus = ppmActual?.status || getTrendStatus("PPM", item.ppm);

      return [
        {
          day: item.day,
          date: item.date,
          time: phActual?.time || item.time,
          type: "pH",
          value: `${item.ph} pH`,
          status: phStatus,
          note:
            phActual?.note ||
            (phStatus === "Normal"
              ? "Nilai pH berada pada rentang aman."
              : "Nilai pH perlu dikoreksi agar kembali ke rentang aman."),
          source: phActual ? "Aktual + tren" : "Tren bulanan",
        },
        {
          day: item.day,
          date: item.date,
          time: ppmActual?.time || item.time,
          type: "PPM",
          value: `${item.ppm} PPM`,
          status: ppmStatus,
          note:
            ppmActual?.note ||
            (ppmStatus === "Normal"
              ? "Konsentrasi nutrisi berada pada target."
              : "Konsentrasi nutrisi perlu disesuaikan."),
          source: ppmActual ? "Aktual + tren" : "Tren bulanan",
        },
        {
          day: item.day,
          date: item.date,
          time: dithaneActual?.time || "14:00",
          type: "Dithane",
          value: "Jadwal semprot",
          status: dithaneActual?.status || "Terjadwal",
          note:
            dithaneActual?.note ||
            "Penyemprotan mengikuti jadwal kontrol harian.",
          source: dithaneActual ? "Aktual" : "Jadwal sistem",
        },
      ];
    });
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

  function addReportFooters(doc, monthName, margin) {
    const pageCount = doc.internal.getNumberOfPages();
    const pageHeight = doc.internal.pageSize.getHeight();
    for (let page = 1; page <= pageCount; page += 1) {
      doc.setPage(page);
      doc.setDrawColor(224, 235, 229);
      doc.line(margin, pageHeight - 12, doc.internal.pageSize.getWidth() - margin, pageHeight - 12);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8);
      doc.setTextColor(110, 124, 112);
      doc.text(`Hydrotech | Laporan ${monthName} 2026`, margin, pageHeight - 7);
      doc.text(`Halaman ${page} dari ${pageCount}`, doc.internal.pageSize.getWidth() - margin, pageHeight - 7, {
        align: "right",
      });
    }
  }

  /* CHART IMAGE: membuat gambar grafik sementara untuk dimasukkan ke PDF. */
  async function createMonthlyChartImage(trends, monthName) {
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
            text: `Grafik Tren Harian pH dan PPM - ${monthName} 2026`,
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
  async function exportMonthlyPdf(monthIndex) {
    const jsPdf = window.jspdf?.jsPDF;
    if (!jsPdf) {
      alert("Generator PDF belum berhasil dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
      return;
    }

    const monthName = monthNames[monthIndex];
    const rows = getRowsByMonth(monthIndex);
    const trends = getMonthlyChartData(monthIndex);
    const averagePh = getAverage(trends, "ph", 1);
    const averagePpm = getAverage(trends, "ppm", 0);
    const phRange = getAxisRange(trends, "ph", 0, 0.1);
    const ppmRange = getAxisRange(trends, "ppm", 0, 1);
    const typeCounts = countBy(rows, "type");
    const statusCounts = countBy(rows, "status");
    const recommendations = getMonthlyRecommendation(rows, trends);
    const outOfRange = getOutOfRangeSummary(trends);
    const dominantStatus = getMostFrequent(statusCounts);
    const dominantType = getMostFrequent(typeCounts);
    const detailedMonthlyRows = getDetailedMonthlyRows(rows, trends);
    const chartImage = await createMonthlyChartImage(trends, monthName);
    const doc = new jsPdf({ orientation: "landscape", unit: "mm", format: "a4" });
    if (typeof doc.autoTable !== "function") {
      alert("Plugin tabel PDF belum berhasil dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
      return;
    }

    const pageWidth = doc.internal.pageSize.getWidth();
    const margin = 14;
    const usableWidth = pageWidth - margin * 2;

    doc.setProperties({
      title: `Laporan History Hydrotech - ${monthName} 2026`,
      subject: "Laporan monitoring pH, PPM, dan Dithane",
      author: "Hydrotech Dashboard Admin",
    });

    doc.setFillColor(7, 141, 67);
    doc.rect(0, 0, pageWidth, 28, "F");
    doc.setTextColor(255, 255, 255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(17);
    doc.text("Laporan History Monitoring Hydrotech", margin, 13);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.text(`Periode: ${monthName} 2026 | Dibuat: ${getGeneratedAtText()} WIB`, margin, 21);

    doc.setTextColor(35, 52, 40);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.text("Ringkasan Untuk Pemilik", margin, 40);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    let y = addWrappedText(
      doc,
      `Laporan ini merangkum kondisi sistem hidroponik selama bulan ${monthName} 2026. Data diambil dari tabel history dan dilengkapi analisis tren harian pH serta PPM agar pemilik dapat menilai stabilitas nutrisi, kondisi keasaman larutan, dan aktivitas Dithane secara lebih utuh.`,
      margin,
      47,
      usableWidth,
      5,
    );

    const metricY = y + 5;
    const metricWidth = (usableWidth - 18) / 4;
    drawMetricBox(doc, margin, metricY, metricWidth, "Data history aktual", rows.length, "catatan bulan ini", [7, 141, 67]);
    drawMetricBox(doc, margin + metricWidth + 6, metricY, metricWidth, "Rata-rata pH", averagePh, "target 5.5 - 6.5", [217, 95, 95]);
    drawMetricBox(doc, margin + (metricWidth + 6) * 2, metricY, metricWidth, "Rata-rata PPM", averagePpm, "target 800 - 1000", [7, 141, 67]);
    drawMetricBox(doc, margin + (metricWidth + 6) * 3, metricY, metricWidth, "Status dominan", dominantStatus, "berdasarkan history", [22, 118, 164]);
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
        ["Total data history bulan ini", `${rows.length} data tercatat`],
        ["Komposisi tipe data", formatCounts(typeCounts)],
        ["Komposisi status", formatCounts(statusCounts)],
        ["Tipe dan status dominan", `${dominantType} | ${dominantStatus}`],
        ["Rata-rata pH tren harian", `${averagePh} pH (acuan aman 5.5 - 6.5)`],
        ["Rentang pH tren harian", `${phRange.min.toFixed(1)} - ${phRange.max.toFixed(1)} pH`],
        ["Rata-rata PPM tren harian", `${averagePpm} PPM (target 800 - 1000 PPM)`],
        ["Rentang PPM tren harian", `${ppmRange.min} - ${ppmRange.max} PPM`],
        ["Hari di luar batas", `pH rendah ${outOfRange.phLow}, pH tinggi ${outOfRange.phHigh}, PPM rendah ${outOfRange.ppmLow}, PPM tinggi ${outOfRange.ppmHigh}`],
      ],
      margin: { left: margin, right: margin },
      columnStyles: {
        0: { cellWidth: 64 },
        1: { cellWidth: usableWidth - 64 },
      },
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text(`Analisis Kondisi Bulanan - ${monthName} 2026`, margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y = addWrappedText(
      doc,
      "Halaman ini menjelaskan arti data, risiko operasional, dan tindakan yang dapat diprioritaskan oleh pemilik berdasarkan rangkuman history serta tren harian.",
      margin,
      26,
      usableWidth,
      4.5,
    );

    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Penjelasan Parameter", margin, y + 6);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y = addWrappedText(
      doc,
      "pH menunjukkan tingkat keasaman larutan. PPM menunjukkan konsentrasi nutrisi terlarut. Dithane menunjukkan aktivitas penyemprotan yang dijadwalkan untuk menjaga perlindungan tanaman. Status Normal berarti parameter berada dalam kondisi aman, Rendah berarti perlu penambahan atau pemeriksaan, dan Tinggi berarti perlu penurunan atau evaluasi penyebab kenaikan.",
      margin,
      y + 13,
      usableWidth,
      4.5,
    );

    doc.autoTable({
      startY: y + 6,
      theme: "grid",
      styles: { fontSize: 8, cellPadding: 2.8, lineColor: [222, 234, 226] },
      headStyles: { fillColor: [22, 118, 164], textColor: 255 },
      head: [["Area", "Temuan", "Dampak Jika Diabaikan", "Prioritas"]],
      body: [
        [
          "pH",
          `Rata-rata ${averagePh}; ${outOfRange.phLow} hari rendah dan ${outOfRange.phHigh} hari tinggi.`,
          "Akar lebih sulit menyerap nutrisi dan pertumbuhan dapat melambat.",
          outOfRange.phLow + outOfRange.phHigh > 0 ? "Tinggi" : "Pantau",
        ],
        [
          "PPM",
          `Rata-rata ${averagePpm} PPM; ${outOfRange.ppmLow} hari rendah dan ${outOfRange.ppmHigh} hari tinggi.`,
          "Tanaman dapat kekurangan nutrisi atau mengalami kelebihan garam terlarut.",
          outOfRange.ppmLow + outOfRange.ppmHigh > 0 ? "Tinggi" : "Pantau",
        ],
        [
          "Dithane",
          `Data aktual: ${typeCounts.Dithane || 0} catatan.`,
          "Jadwal penyemprotan yang tidak konsisten dapat mengurangi perlindungan tanaman.",
          typeCounts.Dithane ? "Pantau" : "Periksa jadwal",
        ],
        [
          "History",
          `${rows.length} catatan aktual, status dominan: ${dominantStatus}.`,
          "Data yang minim membuat keputusan pemilik kurang kuat.",
          rows.length ? "Cukup" : "Perlu data",
        ],
      ],
      margin: { left: margin, right: margin },
    });

    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    y = doc.lastAutoTable.finalY + 9;
    doc.text("Rekomendasi Tindakan", margin, y);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y += 7;
    recommendations.forEach((note, index) => {
      y = addWrappedText(doc, `${index + 1}. ${note}`, margin, y, usableWidth, 4.5) + 1;
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text(`Grafik Tren Harian - ${monthName} 2026`, margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.text("Grafik ini memperlihatkan perubahan pH dan PPM harian untuk membantu pemilik membaca pola kenaikan atau penurunan parameter.", margin, 25);
    if (chartImage) {
      doc.addImage(chartImage, "PNG", margin, 33, usableWidth, 105);
    } else {
      doc.setTextColor(180, 70, 70);
      doc.text("Grafik tidak dapat dimuat karena Chart.js belum tersedia saat PDF dibuat.", margin, 45);
      doc.setTextColor(35, 52, 40);
    }
    doc.autoTable({
      startY: chartImage ? 145 : 55,
      theme: "grid",
      styles: { fontSize: 8, cellPadding: 2.8, lineColor: [222, 234, 226] },
      headStyles: { fillColor: [7, 141, 67], textColor: 255 },
      head: [["Analisis Grafik", "Catatan"]],
      body: [
        ["Arah pH", `Rata-rata ${averagePh} pH dengan rentang ${phRange.min.toFixed(1)} - ${phRange.max.toFixed(1)}.`],
        ["Arah PPM", `Rata-rata ${averagePpm} PPM dengan rentang ${ppmRange.min} - ${ppmRange.max} PPM.`],
        ["Periode pengamatan", `${trends.length} hari data tren dalam bulan ${monthName} 2026.`],
        ["Kegunaan grafik", "Membantu pemilik melihat pola sebelum mengambil keputusan koreksi nutrisi, pH, atau jadwal kontrol."],
      ],
      margin: { left: margin, right: margin },
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text(`Data History Bulanan - ${monthName} 2026`, margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.text("Tabel ini berisi detail harian satu bulan penuh: pH, PPM, dan Dithane. Data aktual dari halaman history digabung dengan tren bulanan dan jadwal sistem.", margin, 25);

    doc.autoTable({
      startY: 31,
      theme: "striped",
      styles: { fontSize: 6.8, cellPadding: 1.6, overflow: "linebreak" },
      headStyles: { fillColor: [39, 93, 41], textColor: 255 },
      head: [["No", "Hari", "Tanggal", "Jam", "Tipe", "Nilai", "Status", "Keterangan", "Sumber"]],
      body: detailedMonthlyRows.map((data, index) => [
            index + 1,
            data.day,
            data.date,
            data.time,
            data.type,
            data.value,
            data.status,
            data.note,
            data.source,
          ]),
      margin: { left: margin, right: margin },
      columnStyles: {
        0: { cellWidth: 10 },
        1: { cellWidth: 20 },
        2: { cellWidth: 31 },
        3: { cellWidth: 16 },
        4: { cellWidth: 18 },
        5: { cellWidth: 24 },
        6: { cellWidth: 21 },
        7: { cellWidth: 82 },
        8: { cellWidth: 32 },
      },
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.text(`Lampiran Tren Harian pH dan PPM - ${monthName} 2026`, margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.text(
      "Lampiran ini memperlihatkan estimasi tren harian lengkap selama satu bulan sebagai bahan pembanding terhadap data history utama.",
      margin,
      25,
    );

    doc.autoTable({
      startY: 31,
      theme: "grid",
      styles: { fontSize: 7.5, cellPadding: 2 },
      headStyles: { fillColor: [7, 141, 67], textColor: 255 },
      head: [["No", "Hari", "Tanggal", "Jam", "pH", "PPM", "Interpretasi"]],
      body: trends.map((item, index) => {
        const phStatus = item.ph < 5.5 ? "pH rendah" : item.ph > 6.5 ? "pH tinggi" : "pH normal";
        const ppmStatus = item.ppm < 800 ? "PPM rendah" : item.ppm > 1000 ? "PPM tinggi" : "PPM normal";
        return [
          index + 1,
          item.day,
          item.date,
          item.time,
          item.ph,
          `${item.ppm} PPM`,
          `${phStatus}; ${ppmStatus}`,
        ];
      }),
      margin: { left: margin, right: margin },
      columnStyles: { 6: { cellWidth: 58 } },
      didDrawPage: function () {
        const pageCount = doc.internal.getNumberOfPages();
        doc.setFontSize(8);
        doc.setTextColor(110, 124, 112);
        doc.text(
          `Hydrotech | Laporan ${monthName} 2026 | Halaman ${pageCount}`,
          margin,
          doc.internal.pageSize.getHeight() - 8,
        );
      },
    });

    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.setTextColor(35, 52, 40);
    doc.text("Metodologi, Catatan, dan Kesimpulan", margin, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y = addWrappedText(
      doc,
      "Sumber data laporan berasal dari tabel history pada dashboard Hydrotech dan data tren harian pH/PPM yang digunakan oleh grafik monitoring. Laporan ini ditujukan sebagai bahan pertimbangan pemilik untuk melihat kestabilan sistem, bukan sebagai pengganti inspeksi langsung di instalasi hidroponik.",
      margin,
      27,
      usableWidth,
      4.5,
    );

    doc.autoTable({
      startY: y + 6,
      theme: "grid",
      styles: { fontSize: 8, cellPadding: 3, lineColor: [222, 234, 226] },
      headStyles: { fillColor: [39, 93, 41], textColor: 255 },
      head: [["Bagian", "Isi"]],
      body: [
        ["Metode pembacaan", "Data aktual dikelompokkan berdasarkan bulan, tipe monitoring, status, dan keterangan."],
        ["Batas acuan pH", "Rentang aman yang digunakan: 5.5 - 6.5 pH."],
        ["Batas acuan PPM", "Target nutrisi yang digunakan: 800 - 1000 PPM."],
        ["Interpretasi status", "Normal berarti aman, Rendah berarti perlu penambahan/pemeriksaan, Tinggi berarti perlu penurunan/evaluasi."],
        ["Catatan validasi", "Jika data aktual bulan tertentu masih sedikit, keputusan akhir sebaiknya dikombinasikan dengan pengecekan fisik tanaman dan sensor."],
      ],
      margin: { left: margin, right: margin },
    });

    y = doc.lastAutoTable.finalY + 10;
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Kesimpulan Pemilik", margin, y);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    y = addWrappedText(
      doc,
      `Pada bulan ${monthName} 2026, sistem mencatat ${rows.length} data history aktual dengan status dominan ${dominantStatus}. Tren harian menunjukkan rata-rata pH ${averagePh} dan rata-rata PPM ${averagePpm}. Prioritas utama pemilik adalah menjaga pH tetap pada 5.5 - 6.5, menjaga PPM di kisaran 800 - 1000, dan memastikan jadwal Dithane tetap konsisten.`,
      margin,
      y + 7,
      usableWidth,
      4.5,
    );

    doc.setDrawColor(215, 233, 222);
    doc.setFillColor(248, 252, 250);
    doc.roundedRect(margin, y + 8, usableWidth, 26, 3, 3, "FD");
    doc.setFont("helvetica", "bold");
    doc.setFontSize(9);
    doc.setTextColor(7, 141, 67);
    doc.text("Checklist tindak lanjut:", margin + 5, y + 16);
    doc.setTextColor(65, 82, 70);
    doc.setFont("helvetica", "normal");
    doc.text("1. Cek sensor pH dan PPM.  2. Bandingkan kondisi tanaman.  3. Simpan PDF sebagai arsip bulanan.  4. Tindak lanjuti status Rendah/Tinggi.", margin + 5, y + 23);

    addReportFooters(doc, monthName, margin);
    doc.save(`laporan-history-hydrotech-${monthName.toLowerCase()}-2026.pdf`);
  }
  /* CHART CONFIG: data dasar untuk grafik history pH dan PPM di halaman. */
  const chartTimes = [
    "07:00",
    "07:15",
    "07:00",
    "07:30",
    "07:10",
    "07:00",
    "08:00",
  ];
  const chartRangeLabels = {
    7: "1 minggu",
    14: "2 minggu",
    month: "1 bulan",
  };
  let historyChart;
  let selectedChartRange = "month";

  /* CHART DATA: membuat data tren harian pH dan PPM untuk bulan terpilih. */
  function getMonthlyChartData(monthIndex) {
    const year = 2026;
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

    return Array.from({ length: daysInMonth }, function (_, index) {
      const dateNumber = index + 1;
      const date = new Date(year, monthIndex, dateNumber);
      const monthOffset = monthIndex * 0.08;
      const wave = Math.sin((dateNumber + monthIndex) * 0.74);
      const ph = Number((6.05 + wave * 0.32 + monthOffset / 5).toFixed(1));
      const ppm = Math.round(
        905 + wave * 48 + monthIndex * 4 + (index % 5) * 6,
      );

      return {
        day: dayNames[date.getDay()],
        date: `${dateNumber} ${monthNames[monthIndex]} ${year}`,
        time: chartTimes[index % chartTimes.length],
        ph,
        ppm,
      };
    });
  }

  /* CHART HELPERS: menghitung rata-rata, rentang sumbu, dan periode yang ditampilkan. */
  function getAverage(items, key, digits) {
    const total = items.reduce((sum, item) => sum + item[key], 0);
    return (total / items.length).toFixed(digits);
  }

  function getAxisRange(items, key, padding, roundTo) {
    const values = items.map((item) => item[key]);
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
  function renderHistoryChart() {
    const canvas = document.getElementById("historyLineChart");
    const monthFilter = document.querySelector("[data-history-chart-month]");
    const selectedMonth = Number(monthFilter?.value || 4);
    const data = getChartRangeData(getMonthlyChartData(selectedMonth));
    const phRange = getAxisRange(data, "ph", 0.18, 0.1);
    const ppmRange = getAxisRange(data, "ppm", 28, 25);
    const periodText = document.querySelector("[data-history-chart-period]");
    const averagePh = document.querySelector("[data-chart-average-ph]");
    const averagePpm = document.querySelector("[data-chart-average-ppm]");
    if (periodText) {
      periodText.textContent = getChartPeriodText(data, selectedMonth);
    }
    if (averagePh) averagePh.textContent = getAverage(data, "ph", 1);
    if (averagePpm)
      averagePpm.textContent = `${getAverage(data, "ppm", 0)} PPM`;
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
  [searchInput, typeFilter, dayFilter, monthFilter, statusFilter].forEach(
    (control) => {
      control?.addEventListener("input", function () {
        currentPage = 1;
        render();
      });
      control?.addEventListener("change", function () {
        currentPage = 1;
        render();
      });
    },
  );

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
        render();
      } else {
        alert("Gagal menghapus data history.");
      }
    })
    .catch(e => {
      console.error(e);
      alert("Kesalahan jaringan.");
    });
  });

  /* EXPORT EVENT: membuat PDF history bulanan setelah pengguna memilih bulan. */
  exportButton?.addEventListener("click", async function () {
    const selectedMonth = await requestExportMonth();
    if (selectedMonth === null) return;
    await exportMonthlyPdf(selectedMonth);
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

  /* INITIAL RENDER: menerapkan data tersimpan, tabel, dan grafik saat halaman dibuka. */
  render();
  renderHistoryChart();
})();
