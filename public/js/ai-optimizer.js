/*
 * Primus · ai-optimizer.js
 * AI Resource Optimizer: injects an "AI suggestions" card on the server
 * overview. Live CPU/RAM/disk history is pulled from Pterodactyl's existing
 * websocket (no new backend polling); when the user asks, the window is
 * summarized and sent to /extensions/primus/ai/optimize.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  function serverId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : "";
  }
  function isOverview() { return /\/server\/[a-zA-Z0-9]+\/?$/.test(location.pathname); }

  /* ── websocket hook: capture stats frames from the console socket ─── */
  var statsWindow = { cpu: [], mem: [] };
  var card = null;

  function installSocketHook() {
    if (P._socketHooked) return;
    var NativeWS = window.WebSocket;
    if (!NativeWS) return;
    var Handler = window.PrimusWS = function (url, protocols) {
      var ws = protocols ? new NativeWS(url, protocols) : new NativeWS(url);
      try {
        ws.addEventListener("message", function (ev) {
          var d = ev.data;
          if (typeof d !== "string" || d.charAt(0) !== "{") return;
          try {
            var msg = JSON.parse(d);
            var args = msg.args;
            if (msg.event === "status") P.emit("socket:status", { status: args });
            if (msg.event === "stats" && args) {
              var cpu = parseFloat(args.cpu_absolute);
              var mem = parseFloat(args.memory_bytes);
              if (!isNaN(cpu)) statsWindow.cpu.push(cpu);
              if (!isNaN(mem)) statsWindow.mem.push(mem);
              if (statsWindow.cpu.length > 90) statsWindow.cpu.shift();
              if (statsWindow.mem.length > 90) statsWindow.mem.shift();
              updateCard();
            }
          } catch (e) { /* non-primus frame */ }
        });
      } catch (e) { /* hook must never break the console */ }
      return ws;
    };
    window.WebSocket = Handler;
    P._socketHooked = true;
  }

  function avg(arr) {
    if (!arr.length) return 0;
    return arr.reduce(function (a, b) { return a + b; }, 0) / arr.length;
  }
  function maxVal(arr) {
    return arr.reduce(function (a, b) { return Math.max(a, b); }, 0);
  }

  /* limits scraped from the overview DOM, updated on demand */
  var limits = { cpu: 0, memory: 0 };
  function refreshLimits() {
    var text = document.body.textContent || "";
    var cpuM = text.match(/(\d[\d,]*)\s*%/) ;
    var memM = text.match(/(\d[\d,.]*)\s*(GiB|MiB|KiB|B)/);
    var el = document.currentScript || document.querySelector(".pr-sidecard__limits");
    if (el && el.dataset.cpu) limits.cpu = parseFloat(el.dataset.cpu);
    if (el && el.dataset.mem) limits.memory = parseFloat(el.dataset.mem);
    if (!limits.cpu && cpuM) limits.cpu = parseFloat(cpuM[1].replace(",", "")) || 0;
    if (!limits.memory && memM) {
      var n = parseFloat(memM[1].replace(",", ""));
      var unit = { B: 1, KiB: 1024, MiB: 1048576, GiB: 1073741824 }[memM[2]] || 1;
      limits.memory = n * unit;
    }
  }

  /* ── card rendering ──────────────────────────────────────────────── */
  function mountCard() {
    if (U.q(".pr-sidecard") || !isOverview()) return;
    var aside =
      U.q("#app aside, #app aside[class], aside, div[class*='right'], div[class*='stats']");
    if (!aside) return;

    card = U.el(
      "div",
      "pr-sidecard pr-slide-up",
      '<div class="pr-sidecard__head"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg> Live resources</div>' +
      '<div class="pr-sidecard__body">' +
        metric("CPU", "pr-m-cpu") +
        metric("RAM", "pr-m-mem") +
        metric("Disk", "pr-m-disk") +
        '<button class="pr-opt-btn" type="button">' +
          '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg>' +
          "Get AI suggestions" +
        "</button>" +
      "</div>"
    );
    card.querySelector(".pr-opt-btn").addEventListener("click", runOptimizer);
    aside.appendChild(card);

    /* sparklines */
    drawSpark(card.querySelector(".pr-m-cpu .pr-metric__bar"), "cpu");
    drawSpark(card.querySelector(".pr-m-mem .pr-metric__bar"), "mem");
    var diskBar = card.querySelector(".pr-m-disk .pr-metric__fill");
    if (diskBar) diskBar.style.width = "0%";

    refreshLimits();
    updateCard();
  }

  function metric(label, cls) {
    return '<div class="pr-metric ' + cls + '">' +
      '<span class="pr-metric__name">' + label + "</span>" +
      '<span class="pr-metric__bar"><span class="pr-metric__fill"></span><svg class="pr-metric__spark" viewBox="0 0 90 16" preserveAspectRatio="none"></svg></span>' +
      '<span class="pr-metric__value">—</span></div>';
  }

  function drawSpark(host, key) {
    if (!host) return;
    var svg = host.querySelector(".pr-metric__spark");
    if (!svg) return;
    var arr = key === "cpu" ? statsWindow.cpu : statsWindow.mem;
    var n = arr.length;
    if (n < 2) return;
    var lo = Math.min.apply(null, arr), hi = Math.max.apply(null, arr);
    var pts = arr.map(function (v, i) {
      var x = (i / (n - 1)) * 90;
      var y = hi === lo ? 8 : 15 - ((v - lo) / (hi - lo)) * 14;
      return x.toFixed(1) + "," + y.toFixed(1);
    }).join(" ");
    svg.innerHTML = '<polyline points="' + pts + '" fill="none" stroke="var(--pr-accent)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" opacity="0.55"/>';
  }

  function updateCard() {
    if (!card) return;
    var cpuAvg = avg(statsWindow.cpu.slice(-60));
    var memAvg = avg(statsWindow.mem.slice(-60));
    var memPeak = maxVal(statsWindow.mem.slice(-60));

    setMetric(card, ".pr-m-cpu", cpuAvg, limits.cpu, "", "%");
    setMetric(card, ".pr-m-mem", memAvg, limits.memory, "bytes");

    updateValues(card.querySelector(".pr-m-cpu"), cpuAvg, "%");
    updateByteValues(card.querySelector(".pr-m-mem"), memAvg, memPeak, limits.memory);

    drawSpark(card.querySelector(".pr-m-cpu .pr-metric__bar"), "cpu");
    drawSpark(card.querySelector(".pr-m-mem .pr-metric__bar"), "mem");
  }

  function setMetric(root, sel, value, limit, unit, suffix) {
    var row = root.querySelector(sel);
    if (!row) return;
    var fill = row.querySelector(".pr-metric__fill");
    if (!fill) return;
    var pct = limit > 0 ? Math.min(100, (value / limit) * 100) : Math.min(100, value);
    fill.style.width = pct + "%";
    fill.dataset.warn = pct >= 60 && pct < 85 ? "true" : "false";
    fill.dataset.danger = pct >= 85 ? "true" : "false";
  }

  function updateValues(row, val, suffix) {
    if (!row) return;
    var v = row.querySelector(".pr-metric__value");
    if (v) v.textContent = Math.round(val * 10) / 10 + suffix;
  }

  function updateByteValues(row, val, peak, limit) {
    if (!row) return;
    var v = row.querySelector(".pr-metric__value");
    if (!v) return;
    if (limit > 0) v.textContent = U.fmtBytes(val) + " / " + U.fmtBytes(limit);
    else if (peak > 0) v.textContent = U.fmtBytes(peak) + " peak";
    else v.textContent = U.fmtBytes(val);
  }

  /* ── optimizer request ───────────────────────────────────────────── */
  function buildStatsPayload() {
    refreshLimits();
    var cpu = statsWindow.cpu.slice(-90);
    var mem = statsWindow.mem.slice(-90);
    return {
      window_seconds: Math.round(cpu.length * (cpu.length > 1 ? 2 : 1)),
      cpu_avg: round1(avg(cpu)),
      cpu_peak: round1(maxVal(cpu)),
      cpu_limit_pct: limits.cpu || null,
      mem_avg_bytes: Math.round(avg(mem)),
      mem_peak_bytes: Math.round(maxVal(mem)),
      mem_limit_bytes: limits.memory || null,
    };
  }
  function round1(n) { return Math.round(n * 10) / 10; }

  function runOptimizer() {
    var btn = card.querySelector(".pr-opt-btn");
    if (btn.disabled) return;
    btn.disabled = true;
    var original = btn.innerHTML;
    btn.innerHTML = '<span class="pr-typing-dots"><span></span><span></span><span></span></span>&nbsp;Analyzing…';
    var existing = card.querySelector(".pr-opt-result");
    if (existing) existing.remove();

    P.api("ai/optimize", { method: "POST", json: { server: serverId(), stats: buildStatsPayload() } })
      .then(function (res) {
        var text =
          (res && (res.suggestions || res.advice || res.text)) ||
          (typeof res === "string" ? res : "");
        if (!text) throw new Error("Empty AI response");
        var box = U.el("div", "pr-opt-result", miniMd(text));
        card.querySelector(".pr-sidecard__body").appendChild(box);
        P.notify("AI suggestion ready", text.slice(0, 110), "success");
        P.store.set("opt:last:" + serverId(), { ts: Date.now(), text: text });
      })
      .catch(function (err) {
        var box = U.el("div", "pr-opt-result", U.esc(err.status === 429
          ? "AI rate limit reached — try again in a minute."
          : "Optimizer failed: " + (err.message || "unknown error")));
        card.querySelector(".pr-sidecard__body").appendChild(box);
      })
      .then(function () {
        btn.disabled = false;
        btn.innerHTML = original;
      });
  }

  function miniMd(text) {
    return U.esc(text)
      .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
      .replace(/`([^`]+)`/g, "<code>$1</code>")
      .replace(/\n/g, "<br>");
  }

  /* ── wiring ──────────────────────────────────────────────────────── */
  P.ready.then(function () {
    installSocketHook();
    setTimeout(mountCard, 900);
  });
  P.on("page:view", function () {
    setTimeout(mountCard, 900);
  });
})();
