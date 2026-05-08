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

  removeDeletedRows();
  render();
})();
