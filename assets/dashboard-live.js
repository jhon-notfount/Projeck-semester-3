(function () {
  const chart = document.querySelector(".chart");
  const ppmStat = document.querySelector(".stat-ppm .stat-num");
  const phStat = document.querySelector(".stat-ph .stat-num");
  const ppmBar = document.querySelector(".stat-ppm .bar span");
  const phBar = document.querySelector(".stat-ph .bar span");
  const phSummary = document.querySelector(".chart-summary div:first-child b");
  const ppmSummary = document.querySelector(".chart-summary div:last-child b");
  const phLine = document.querySelector(".ph-line");
  const ppmLine = document.querySelector(".ppm-line");
  const phArea = document.querySelector(".ph-area");
  const ppmArea = document.querySelector(".ppm-area");
  const phDots = Array.from(document.querySelectorAll(".ph-dot"));
  const ppmDots = Array.from(document.querySelectorAll(".ppm-dot"));
  const tooltipTexts = document.querySelectorAll(".chart-tooltip text");
  const updateText = document.querySelector(".hero-metrics div:last-child b");
  const notificationItems = Array.from(document.querySelectorAll(".notif-item"));

  if (!chart || !ppmStat || !phStat || !phLine || !ppmLine) return;

  const xs = [56, 118, 180, 242, 304, 366, 428, 488];
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

  function mapValue(value, min, max) {
    const chartTop = 34;
    const chartBottom = 194;
    const ratio = (value - min) / (max - min);
    return chartBottom - clamp(ratio, 0, 1) * (chartBottom - chartTop);
  }

  function makeLinePath(values, min, max) {
    const points = values.map((value, index) => ({
      x: xs[index],
      y: mapValue(value, min, max),
    }));

    return points
      .map((point, index) => {
        if (index === 0) return `M${point.x} ${point.y.toFixed(1)}`;
        const previous = points[index - 1];
        const controlDistance = (point.x - previous.x) / 2;
        return `C${(previous.x + controlDistance).toFixed(1)} ${previous.y.toFixed(1)} ${(point.x - controlDistance).toFixed(1)} ${point.y.toFixed(1)} ${point.x} ${point.y.toFixed(1)}`;
      })
      .join(" ");
  }

  function makeAreaPath(values, min, max) {
    const line = makeLinePath(values, min, max);
    return `${line} L488 194 L56 194 Z`;
  }

  function updateDots(dots, values, min, max) {
    if (!dots.length) return;
    const dotIndexes = [2, 5, 7];

    dots.forEach((dot, index) => {
      const sourceIndex = dotIndexes[index] || values.length - 1;
      dot.setAttribute("cx", xs[sourceIndex]);
      dot.setAttribute("cy", mapValue(values[sourceIndex], min, max).toFixed(1));
    });
  }

  function updateNotification(ppm, ph) {
    const messages = [
      {
        title: ph >= 6.8 ? "pH bergerak naik" : "pH kembali stabil",
        time: "baru saja",
      },
      {
        title: ppm >= 880 ? "PPM naik mengikuti nutrisi" : "PPM turun ke rentang aman",
        time: "1 menit lalu",
      },
      {
        title: tick % 2 ? "Sensor membaca data baru" : "Grafik sensor bergerak live",
        time: "beberapa detik lalu",
      },
    ];

    notificationItems.forEach((item, index) => {
      const title = item.querySelector("b");
      const time = item.querySelector("small");
      if (!title || !time) return;
      title.textContent = messages[index].title;
      time.textContent = messages[index].time;
    });

    const activeItem = notificationItems[tick % notificationItems.length];
    activeItem?.classList.add("live-pulse");
    window.setTimeout(() => activeItem?.classList.remove("live-pulse"), 850);

    const visibleActivityRows = Array.from(
      document.querySelectorAll(".activity-row:not([hidden])"),
    );
    const activeRow = visibleActivityRows[tick % visibleActivityRows.length];
    activeRow?.classList.add("live-pulse");
    window.setTimeout(() => activeRow?.classList.remove("live-pulse"), 850);
  }

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
    chart.classList.add("live-refresh");
    window.setTimeout(() => chart.classList.remove("live-refresh"), 900);

    if (ppmBar) ppmBar.style.width = `${clamp(((latestPpm - 700) / 350) * 100, 8, 100)}%`;
    if (phBar) phBar.style.width = `${clamp(((latestPh - 5) / 3) * 100, 8, 100)}%`;

    ppmLine.setAttribute("d", makeLinePath(ppmValues, 300, 1000));
    phLine.setAttribute("d", makeLinePath(phValues, 4.5, 8));
    ppmArea?.setAttribute("d", makeAreaPath(ppmValues, 300, 1000));
    phArea?.setAttribute("d", makeAreaPath(phValues, 4.5, 8));
    updateDots(ppmDots, ppmValues, 300, 1000);
    updateDots(phDots, phValues, 4.5, 8);

    if (tooltipTexts.length >= 2) {
      tooltipTexts[0].textContent = "Live";
      tooltipTexts[1].textContent = `pH ${latestPh.toFixed(1)} PPM ${latestPpm}`;
    }
    if (updateText) updateText.textContent = "baru saja";

    updateNotification(latestPpm, latestPh);
  }

  render();
  window.setInterval(render, 1800);
})();
