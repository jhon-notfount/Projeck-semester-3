(function () {
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

  function normalize(value) {
    return String(value || "").trim().toLowerCase();
  }

  function readDeletedRows() {
    try {
      return JSON.parse(localStorage.getItem(storageKey) || "[]");
    } catch (error) {
      return [];
    }
  }

  function writeDeletedRows(ids) {
    localStorage.setItem(storageKey, JSON.stringify(ids));
  }

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

  function render() {
    const filteredRows = getFilteredRows();
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / rowsPerPage));
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

  function resetFilters() {
    if (searchInput) searchInput.value = "";
    if (typeFilter) typeFilter.value = "";
    if (dayFilter) dayFilter.value = "";
    if (monthFilter) monthFilter.value = "";
    if (statusFilter) statusFilter.value = "";
    currentPage = 1;
    render();
  }

  function removeDeletedRows() {
    const deletedRows = readDeletedRows();
    getRows().forEach((row) => {
      if (deletedRows.includes(row.dataset.id)) {
        row.remove();
      }
    });
  }

  function exportCsv() {
    const rows = getFilteredRows();
    const header = ["No", "Hari", "Tanggal", "Jam", "Tipe", "Status", "Keterangan"];
    const lines = rows.map((row, index) => {
      const data = getRowData(row);
      return [index + 1, data.day, data.date, data.time, data.type, data.status, data.note]
        .map((value) => `"${String(value).replace(/"/g, '""')}"`)
        .join(",");
    });

    const csv = [header.join(","), ...lines].join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "history-hydrotech.csv";
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

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
  const dayNames = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
  const chartTimes = ["07:00", "07:15", "07:00", "07:30", "07:10", "07:00", "08:00"];
  let historyChart;

  function getMonthlyChartData(monthIndex) {
    const year = 2026;
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

    return Array.from({ length: daysInMonth }, function (_, index) {
      const dateNumber = index + 1;
      const date = new Date(year, monthIndex, dateNumber);
      const monthOffset = monthIndex * 0.08;
      const wave = Math.sin((dateNumber + monthIndex) * 0.74);
      const ph = Number((6.05 + wave * 0.32 + monthOffset / 5).toFixed(1));
      const ppm = Math.round(905 + wave * 48 + monthIndex * 4 + (index % 5) * 6);

      return {
        day: dayNames[date.getDay()],
        date: `${dateNumber} ${monthNames[monthIndex]} ${year}`,
        time: chartTimes[index % chartTimes.length],
        ph,
        ppm,
      };
    });
  }

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

  function renderHistoryChart() {
    const canvas = document.getElementById("historyLineChart");
    const monthFilter = document.querySelector("[data-history-chart-month]");
    const selectedMonth = Number(monthFilter?.value || 4);
    const data = getMonthlyChartData(selectedMonth);
    const phRange = getAxisRange(data, "ph", 0.18, 0.1);
    const ppmRange = getAxisRange(data, "ppm", 28, 25);
    const periodText = document.querySelector("[data-history-chart-period]");
    const averagePh = document.querySelector("[data-chart-average-ph]");
    const averagePpm = document.querySelector("[data-chart-average-ppm]");
    if (periodText) {
      periodText.textContent = `Tren monitoring pH dan PPM selama ${monthNames[selectedMonth]} 2026, lengkap dengan hari, tanggal, dan jam pengukuran.`;
    }
    if (averagePh) averagePh.textContent = getAverage(data, "ph", 1);
    if (averagePpm) averagePpm.textContent = `${getAverage(data, "ppm", 0)} PPM`;
    if (!canvas || typeof Chart === "undefined") return;

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
            borderColor: "#0b8fff",
            backgroundColor: "#0b8fff",
            borderWidth: 3,
            pointRadius: 4,
            pointHoverRadius: 7,
            pointBackgroundColor: "#102033",
            pointBorderColor: "#0b8fff",
            pointBorderWidth: 2,
            tension: 0,
            fill: false,
          },
          {
            label: "PPM",
            data: data.map((item) => item.ppm),
            yAxisID: "yPpm",
            borderColor: "#16a34a",
            backgroundColor: "#16a34a",
            borderWidth: 3,
            pointRadius: 4,
            pointHoverRadius: 7,
            pointBackgroundColor: "#102033",
            pointBorderColor: "#16a34a",
            pointBorderWidth: 2,
            tension: 0,
            fill: false,
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
            backgroundColor: "rgba(18, 40, 27, 0.94)",
            borderColor: "rgba(255, 255, 255, 0.16)",
            borderWidth: 1,
            padding: 12,
            titleFont: {
              size: 12,
              weight: "700",
            },
            bodyFont: {
              size: 12,
              weight: "700",
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
                return item ? `${item.date.split(" ").slice(0, 2).join(" ")} ${item.time}` : "";
              },
            },
          },
          yPh: {
            position: "left",
            min: phRange.min,
            max: phRange.max,
            ticks: {
              color: "#0b73c9",
              font: {
                size: 10,
                weight: "800",
              },
            },
            title: {
              display: true,
              text: "pH",
              color: "#0b73c9",
              font: {
                size: 11,
                weight: "900",
              },
            },
            grid: {
              color: "rgba(82, 110, 92, 0.1)",
            },
          },
          yPpm: {
            position: "right",
            min: ppmRange.min,
            max: ppmRange.max,
            ticks: {
              color: "#11823f",
              font: {
                size: 10,
                weight: "800",
              },
            },
            title: {
              display: true,
              text: "PPM",
              color: "#11823f",
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

  [searchInput, typeFilter, dayFilter, monthFilter, statusFilter].forEach((control) => {
    control?.addEventListener("input", function () {
      currentPage = 1;
      render();
    });
    control?.addEventListener("change", function () {
      currentPage = 1;
      render();
    });
  });

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

  table.addEventListener("click", async function (event) {
    const deleteButton = event.target.closest(".trash-btn");
    if (!deleteButton) return;

    const row = deleteButton.closest("tr");
    if (!row) return;

    if (window.HydrotechConfirm) {
      const confirmed = await window.HydrotechConfirm.open({
        icon: "X",
        title: "Hapus data history?",
        message: "Data ini akan dihapus dari tabel history dan tetap hilang setelah halaman di-refresh.",
        confirmText: "Ya, Hapus",
        variant: "danger",
      });
      if (!confirmed) return;
    }

    const nextDeletedRows = Array.from(new Set([...readDeletedRows(), row.dataset.id]));
    writeDeletedRows(nextDeletedRows);
    row.remove();
    render();
  });

  exportButton?.addEventListener("click", function () {
    exportCsv();
  });

  document.querySelector("[data-history-chart-month]")?.addEventListener("change", function () {
    renderHistoryChart();
  });

  removeDeletedRows();
  render();
  renderHistoryChart();
})();
