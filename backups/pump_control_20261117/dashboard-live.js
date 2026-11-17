(async function () {
  /* DASHBOARD ELEMENTS: elemen rentang setting, grafik, dan teks update live. */
  const chartCanvas = document.getElementById("dashboardNutrientChart");
  const ppmStat = document.querySelector(".stat-ppm .stat-num");
  const phStat = document.querySelector(".stat-ph .stat-num");
  const ppmBar = document.querySelector(".stat-ppm .bar span");
  const phBar = document.querySelector(".stat-ph .bar span");
  const phSummary = document.querySelector(".chart-summary div:first-child b");
  const ppmSummary = document.querySelector(".chart-summary div:last-child b");
  const updateText = document.querySelector(".hero-metrics div:last-child b");

  const settingDefaults = {
    ppm: { min: "800", max: "1000" },
    ph: { min: "5,5", max: "6,5" },
  };

  /* SETTING RANGE: menampilkan rentang PPM dan pH dari API. */
  async function readSettingRange(type) {
    const setting = settingDefaults[type];
    return fetch(`../api/settings/get.php?type=${type}`)
      .then(response => response.json())
      .then(result => {
        if (result.success && result.data && result.data.config) {
          return {
            min: setting.min,
            max: setting.max,
            ...result.data.config
          };
        }
        return { min: setting.min, max: setting.max };
      })
      .catch(error => {
        console.error(error);
        return { min: setting.min, max: setting.max };
      });
  }

  async function applySettingRanges() {
    const ppmRange = await readSettingRange("ppm");
    const phRange = await readSettingRange("ph");

    if (ppmStat) ppmStat.textContent = `${ppmRange.min} - ${ppmRange.max}`;
    if (phStat) phStat.textContent = `${phRange.min} - ${phRange.max}`;
    if (ppmBar) ppmBar.style.width = "100%";
    if (phBar) phBar.style.width = "100%";
  }

  await applySettingRanges();

  /* LIVE DATA: membaca pengukuran asli dari database (diisi perangkat ESP). */
  const MAX_POINTS = 10;
  const POLL_INTERVAL = 5000;

  if (!chartCanvas || typeof Chart === "undefined") return;

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  /* TIME HELPERS: menampilkan jam pengukuran dan selisih waktu sejak data terakhir. */
  function formatClock(recordedAt) {
    const match = String(recordedAt || "").match(/(\d{2}:\d{2})/);
    return match ? match[1].replace(":", ".") : "-";
  }

  function relativeTime(recordedAt) {
    if (!recordedAt) return "belum ada data";
    const then = new Date(String(recordedAt).replace(" ", "T")).getTime();
    const diffSeconds = Math.floor((Date.now() - then) / 1000);
    if (!Number.isFinite(diffSeconds) || diffSeconds < 0) return "-";
    if (diffSeconds < 60) return "baru saja";
    if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)} menit lalu`;
    if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} jam lalu`;
    return `${Math.floor(diffSeconds / 86400)} hari lalu`;
  }

  /* NUMBER ANIMATION: membuat perubahan angka terlihat halus di kartu dashboard. */
  function animateValue(from, to, duration, onUpdate) {
    const startedAt = performance.now();

    function frame(now) {
      const progress = clamp((now - startedAt) / duration, 0, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      onUpdate(from + (to - from) * eased);
      if (progress < 1) requestAnimationFrame(frame);
    }

    requestAnimationFrame(frame);
  }

  /* CHART STYLE: membuat gradasi area di bawah garis pH dan PPM. */
  function makeGradient(context, color) {
    const gradient = context.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, color.replace(")", ", 0.26)").replace("rgb", "rgba"));
    gradient.addColorStop(1, color.replace(")", ", 0.02)").replace("rgb", "rgba"));
    return gradient;
  }

  /* ACTIVITY PULSE: memberi efek sorot pada aktivitas terbaru yang sedang aktif. */
  function updateActivityPulse() {
    const activeRow = document.querySelector(".activity-row:not([hidden])");
    if (!activeRow) return;
    activeRow.classList.add("live-pulse");
    window.setTimeout(() => activeRow.classList.remove("live-pulse"), 850);
  }

  /* RESPONSIVE CHART: menyesuaikan tinggi grafik agar tetap rapi di layar kecil. */
  function syncChartHeight() {
    const wrapper = chartCanvas.closest(".dashboard-chart-wrap");
    if (!wrapper) return;

    if (window.matchMedia("(max-width: 760px)").matches) {
      const targetHeight = window.matchMedia("(max-width: 380px)").matches
        ? 176
        : Math.min(190, Math.max(168, Math.round(window.innerWidth * 0.48)));

      wrapper.style.height = `${targetHeight}px`;
      wrapper.style.maxHeight = `${targetHeight}px`;
      chartCanvas.style.height = "100%";
      chartCanvas.style.maxHeight = `${targetHeight}px`;
      return;
    }

    wrapper.style.height = "";
    wrapper.style.maxHeight = "";
    chartCanvas.style.height = "";
    chartCanvas.style.maxHeight = "";
  }

  syncChartHeight();

  /* CHART INIT: membuat grafik garis live untuk pH dan PPM dengan Chart.js. */
  const context = chartCanvas.getContext("2d");
  const chart = new Chart(chartCanvas, {
    type: "line",
    data: {
      labels: [],
      datasets: [
        {
          label: "pH",
          data: [],
          yAxisID: "yPh",
          borderColor: "#ff7777",
          backgroundColor: makeGradient(context, "rgb(255, 119, 119)"),
          borderWidth: 4,
          pointRadius: 4,
          pointHoverRadius: 7,
          pointBackgroundColor: "#ffffff",
          pointBorderColor: "#ff7777",
          pointBorderWidth: 3,
          tension: 0.42,
          fill: true,
          spanGaps: true,
        },
        {
          label: "PPM",
          data: [],
          yAxisID: "yPpm",
          borderColor: "#07a64f",
          backgroundColor: makeGradient(context, "rgb(7, 166, 79)"),
          borderWidth: 4,
          pointRadius: 4,
          pointHoverRadius: 7,
          pointBackgroundColor: "#ffffff",
          pointBorderColor: "#07a64f",
          pointBorderWidth: 3,
          tension: 0.42,
          fill: true,
          spanGaps: true,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: {
        duration: 760,
        easing: "easeOutCubic",
      },
      interaction: {
        intersect: false,
        mode: "index",
      },
      layout: {
        padding: {
          top: 8,
          right: 8,
          bottom: 0,
        },
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
            label: function (tooltipItem) {
              const unit = tooltipItem.dataset.label === "PPM" ? " PPM" : "";
              return `${tooltipItem.dataset.label}: ${tooltipItem.parsed.y}${unit}`;
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
            color: "#7b8a81",
            font: {
              size: 10,
              weight: "700",
            },
          },
        },
        yPh: {
          position: "left",
          suggestedMin: 4.5,
          suggestedMax: 8,
          ticks: {
            color: "#d95f5f",
            font: {
              size: 10,
              weight: "800",
            },
          },
          grid: {
            color: "rgba(111, 134, 120, 0.14)",
            borderDash: [4, 8],
          },
          title: {
            display: true,
            text: "pH",
            color: "#d95f5f",
            font: {
              size: 10,
              weight: "900",
            },
          },
        },
        yPpm: {
          position: "right",
          suggestedMin: 300,
          suggestedMax: 1000,
          ticks: {
            color: "#078d43",
            font: {
              size: 10,
              weight: "800",
            },
          },
          grid: {
            drawOnChartArea: false,
          },
          title: {
            display: true,
            text: "PPM",
            color: "#078d43",
            font: {
              size: 10,
              weight: "900",
            },
          },
        },
      },
    },
  });

  window.addEventListener("resize", () => {
    syncChartHeight();
    chart.resize();
  });

  /* SERIES FETCH: mengambil riwayat pengukuran asli per jenis sensor. */
  async function fetchSeries(type) {
    try {
      const response = await fetch(`../api/sensor/latest.php?type=${type}`);
      const result = await response.json();
      if (!result.success || !Array.isArray(result.data)) return [];
      return result.data.map((row) => ({
        at: row.recorded_at,
        value: Number(row.value),
      }));
    } catch (error) {
      console.error(`Gagal membaca data ${type}:`, error);
      return [];
    }
  }

  /* SERIES MERGE: menggabungkan pH dan PPM pada sumbu waktu yang sama. */
  function mergeSeries(phSeries, ppmSeries) {
    const byTime = new Map();
    phSeries.forEach((point) => {
      byTime.set(point.at, { ph: point.value });
    });
    ppmSeries.forEach((point) => {
      const entry = byTime.get(point.at) || {};
      entry.ppm = point.value;
      byTime.set(point.at, entry);
    });

    const points = Array.from(byTime.entries())
      .sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
      .slice(-MAX_POINTS);

    return {
      labels: points.map(([at]) => formatClock(at)),
      ph: points.map(([, entry]) => (entry.ph === undefined ? null : entry.ph)),
      ppm: points.map(([, entry]) => (entry.ppm === undefined ? null : entry.ppm)),
    };
  }

  /* LIVE RENDER: memperbarui ringkasan grafik dan status update dari data asli.
     Sequence guard: respons dari siklus lama tidak boleh menimpa data terbaru. */
  let renderSeq = 0;

  async function render() {
    const seq = ++renderSeq;
    const [phSeries, ppmSeries] = await Promise.all([
      fetchSeries("ph"),
      fetchSeries("ppm"),
    ]);

    if (seq !== renderSeq) return;

    const series = mergeSeries(phSeries, ppmSeries);
    const latestPh = phSeries.length ? phSeries[phSeries.length - 1] : null;
    const latestPpm = ppmSeries.length ? ppmSeries[ppmSeries.length - 1] : null;

    if (phSummary) {
      if (latestPh) {
        const previous = Number(phSummary.textContent);
        animateValue(previous, latestPh.value, 600, (value) => {
          phSummary.textContent = value.toFixed(1);
        });
      } else {
        phSummary.textContent = "-";
      }
    }
    if (ppmSummary) {
      if (latestPpm) {
        const previous = Number(ppmSummary.textContent) || latestPpm.value;
        animateValue(previous, latestPpm.value, 600, (value) => {
          ppmSummary.textContent = Math.round(value);
        });
      } else {
        ppmSummary.textContent = "-";
      }
    }

    chartCanvas.classList.add("live-refresh");
    window.setTimeout(() => chartCanvas.classList.remove("live-refresh"), 900);

    chart.data.labels = series.labels;
    chart.data.datasets[0].data = series.ph;
    chart.data.datasets[1].data = series.ppm;
    chart.update();

    if (updateText) {
      const newest = [latestPh, latestPpm]
        .filter(Boolean)
        .sort((a, b) => (a.at > b.at ? -1 : 1))[0];
      updateText.textContent = newest ? relativeTime(newest.at) : "belum ada data";
    }

    if (latestPh || latestPpm) updateActivityPulse();
  }

  render();
  window.setInterval(render, POLL_INTERVAL);
})();
