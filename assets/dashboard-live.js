(function () {
  const chartCanvas = document.getElementById("dashboardNutrientChart");
  const ppmStat = document.querySelector(".stat-ppm .stat-num");
  const phStat = document.querySelector(".stat-ph .stat-num");
  const ppmBar = document.querySelector(".stat-ppm .bar span");
  const phBar = document.querySelector(".stat-ph .bar span");
  const phSummary = document.querySelector(".chart-summary div:first-child b");
  const ppmSummary = document.querySelector(".chart-summary div:last-child b");
  const updateText = document.querySelector(".hero-metrics div:last-child b");

  if (!chartCanvas || !ppmStat || !phStat || typeof Chart === "undefined") return;

  const timeLabels = ["08.00", "09.00", "10.00", "11.00", "12.00", "13.00", "14.00", "Live"];
  let ppmValues = [845, 858, 874, 862, 892, 876, 864, 863];
  let phValues = [6.4, 6.6, 6.9, 7.0, 6.8, 6.7, 6.9, 7.1];
  let tick = 0;
  let displayedPpm = ppmValues[ppmValues.length - 1];
  let displayedPh = phValues[phValues.length - 1];

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function randomStep(value, step, min, max, decimals) {
    const next = clamp(value + (Math.random() - 0.5) * step, min, max);
    return Number(next.toFixed(decimals));
  }

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

  function makeGradient(context, color) {
    const gradient = context.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, color.replace(")", ", 0.26)").replace("rgb", "rgba"));
    gradient.addColorStop(1, color.replace(")", ", 0.02)").replace("rgb", "rgba"));
    return gradient;
  }

  function updateActivityPulse() {
    const visibleActivityRows = Array.from(
      document.querySelectorAll(".activity-row:not([hidden])"),
    );
    if (!visibleActivityRows.length) return;
    const activeRow = visibleActivityRows[tick % visibleActivityRows.length];
    activeRow?.classList.add("live-pulse");
    window.setTimeout(() => activeRow?.classList.remove("live-pulse"), 850);
  }

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

  function render() {
    tick += 1;
    const latestPpm = randomStep(ppmValues[ppmValues.length - 1], 62, 780, 940, 0);
    const latestPh = randomStep(phValues[phValues.length - 1], 0.42, 5.6, 7.4, 1);

    ppmValues = [...ppmValues.slice(1), latestPpm];
    phValues = [...phValues.slice(1), latestPh];

    animateValue(displayedPpm, latestPpm, 760, (value) => {
      ppmStat.textContent = Math.round(value);
      if (ppmSummary) ppmSummary.textContent = Math.round(value);
    });
    animateValue(displayedPh, latestPh, 760, (value) => {
      phStat.textContent = value.toFixed(1);
      if (phSummary) phSummary.textContent = value.toFixed(1);
    });
    displayedPpm = latestPpm;
    displayedPh = latestPh;

    [ppmStat, phStat].forEach((number) => {
      number.classList.add("live-number");
      window.setTimeout(() => number.classList.remove("live-number"), 620);
    });
    chartCanvas.classList.add("live-refresh");
    window.setTimeout(() => chartCanvas.classList.remove("live-refresh"), 900);

    if (ppmBar) ppmBar.style.width = `${clamp(((latestPpm - 700) / 350) * 100, 8, 100)}%`;
    if (phBar) phBar.style.width = `${clamp(((latestPh - 5) / 3) * 100, 8, 100)}%`;

    chart.data.datasets[0].data = phValues;
    chart.data.datasets[1].data = ppmValues;
    chart.update();

    if (updateText) updateText.textContent = "baru saja";
    updateActivityPulse();
  }

  render();
  window.setInterval(render, 1800);
})();
