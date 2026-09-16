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

  // Dashboard remains a simulator. Resolve explicit device IDs before saving.
  let simulationSensors = null;
  try {
    const response = await fetch('../api/sensor/list.php');
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Daftar sensor gagal dimuat');
    const demoSensors = result.data.filter(sensor => `${sensor.name} ${sensor.location || ''}`.toLowerCase().includes('simulasi'));
    const ph = demoSensors.filter(sensor => sensor.type === 'ph' && sensor.status === 'aktif');
    const ppm = demoSensors.filter(sensor => sensor.type === 'ppm' && sensor.status === 'aktif');
    if (ph.length !== 1 || ppm.length !== 1) throw new Error('Simulasi memerlukan satu sensor pH dan satu sensor PPM aktif.');
    simulationSensors = { ph: ph[0].id, ppm: ppm[0].id };
  } catch (error) {
    console.error(error);
  }
  let savingReading = false;

  if (!chartCanvas || typeof Chart === "undefined") return;

  const timeLabels = ["08.00", "09.00", "10.00", "11.00", "12.00", "13.00", "14.00", "Live"];
  let ppmValues = [845, 858, 874, 862, 892, 876, 864, 863];
  let phValues = [6.4, 6.6, 6.9, 7.0, 6.8, 6.7, 6.9, 7.1];
  let tick = 0;
  let displayedPpm = ppmValues[ppmValues.length - 1];
  let displayedPh = phValues[phValues.length - 1];

  /* VALUE HELPERS: menjaga angka tetap dalam batas dan membuat perubahan kecil acak. */
  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function randomStep(value, step, min, max, decimals) {
    const next = clamp(value + (Math.random() - 0.5) * step, min, max);
    return Number(next.toFixed(decimals));
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
    const visibleActivityRows = Array.from(
      document.querySelectorAll(".activity-row:not([hidden])"),
    );
    if (!visibleActivityRows.length) return;
    const activeRow = visibleActivityRows[tick % visibleActivityRows.length];
    activeRow?.classList.add("live-pulse");
    window.setTimeout(() => activeRow?.classList.remove("live-pulse"), 850);
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
      labels: timeLabels,
      datasets: [
        {
          label: "pH",
          data: phValues,
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
        },
        {
          label: "PPM",
          data: ppmValues,
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
          min: 4.5,
          max: 8,
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
          min: 300,
          max: 1000,
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

  /* LIVE RENDER: memperbarui ringkasan grafik, diagram, dan status update secara berkala. */
  async function render() {
    tick += 1;
    const latestPpm = randomStep(ppmValues[ppmValues.length - 1], 62, 780, 940, 0);
    const latestPh = randomStep(phValues[phValues.length - 1], 0.42, 5.6, 7.4, 1);

    ppmValues = [...ppmValues.slice(1), latestPpm];
    phValues = [...phValues.slice(1), latestPh];

    animateValue(displayedPpm, latestPpm, 760, (value) => {
      if (ppmSummary) ppmSummary.textContent = Math.round(value);
    });
    animateValue(displayedPh, latestPh, 760, (value) => {
      if (phSummary) phSummary.textContent = value.toFixed(1);
    });
    displayedPpm = latestPpm;
    displayedPh = latestPh;

    chartCanvas.classList.add("live-refresh");
    window.setTimeout(() => chartCanvas.classList.remove("live-refresh"), 900);

    chart.data.datasets[0].data = phValues;
    chart.data.datasets[1].data = ppmValues;
    chart.update();

    if (updateText) updateText.textContent = "baru saja";
    updateActivityPulse();

    if (!simulationSensors) {
      if (updateText) updateText.textContent = "simulasi tidak tersimpan";
      return;
    }
    if (savingReading) return;
    savingReading = true;
    try {
      const formData = new FormData();
      formData.append("ph_sensor_id", simulationSensors.ph);
      formData.append("ppm_sensor_id", simulationSensors.ppm);
      formData.append("ph_value", latestPh);
      formData.append("ppm_value", latestPpm);
      const response = await fetch("../api/sensor/save.php", { method: "POST", body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || "Penyimpanan gagal");
      if (updateText) updateText.textContent = "simulasi tersimpan";
    } catch (error) {
      if (updateText) updateText.textContent = "gagal menyimpan simulasi";
      console.error("Gagal menyimpan data sensor:", error);
    } finally {
      savingReading = false;
    }
  }

  render();
  window.setInterval(render, 1800);
})();
