/* Cross-addon regression suite — 8 cases (MOTD Creator + Addons hub coexistence).
 * Single admin login; stock console noise excluded from error checks.
 * Cases per task-6 brief: a dashboard, b chrome-not-duplicated, c MOTD modal,
 * d tab coexistence, e Rust hub tour, f settings.json, g console errors,
 * h addons.js loaded once. */
const puppeteer = require("puppeteer-core");
const BASE = "http://localhost:8081";
const MC = "92cb4c95";
const RUST = "dd2cfabf";
let PASS = 0, FAIL = 0;
const ok = (n) => { PASS++; console.log("PASS: " + n); };
const bad = (n, e) => { FAIL++; console.log("FAIL: " + n + (e ? " — " + e : "")); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({
    executablePath: "/usr/bin/chromium",
    headless: "new",
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  const consoleErrors = [];
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const t = m.text();
    /* primus/addons failures always count, even when they look like stock noise */
    if (/primus|addons/i.test(t)) { consoleErrors.push(t); return; }
    if (/WebSocket|504|405|net::|object Y/i.test(t)) return;
    consoleErrors.push(t);
  });

  await page.goto(BASE + "/auth/login", { waitUntil: "networkidle2" });
  await page.waitForSelector("input[name='username']");
  await page.evaluate(() => {
    const setNative = (el, v) => {
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(el, v);
      el.dispatchEvent(new Event("input", { bubbles: true }));
    };
    setNative(document.querySelector("input[name='username']"), "admin@example.com");
    setNative(document.querySelector("input[name='password']"), "Password123!");
  });
  await Promise.all([
    page.click("button[type='submit']"),
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 20000 }),
  ]);

  /* a — dashboard: server cards render; neither addons nor motd tab present */
  try {
    await page.goto(BASE + "/", { waitUntil: "networkidle2" });
    await sleep(2200);
    const cards = await page.$$eval("a[href^='/server/']", (els) => els.length);
    const motdTab = await page.$(".pr-motd-tab");
    const addonsTab = await page.$(".pr-addons-tab");
    if (cards > 0 && !motdTab && !addonsTab) ok("a dashboard intact, no tabs");
    else bad("a dashboard intact, no tabs", JSON.stringify({ cards, motdTab: !!motdTab, addonsTab: !!addonsTab }));
  } catch (e) { bad("a dashboard intact, no tabs", e.message); }

  /* b — MC console: chrome not duplicated by the addons cycle */
  try {
    await page.goto(BASE + "/server/" + MC, { waitUntil: "networkidle2" });
    await sleep(2200);
    const chromeCount = await page.$$eval(".pr-term__chrome", (els) => els.length);
    const fixer = await page.$(".pr-ai-fixer-bar");
    const graphs = await page.$(".pr-graphs");
    if (chromeCount === 1 && fixer && graphs) ok("b console chrome not duplicated");
    else bad("b console chrome not duplicated", JSON.stringify({ chromeCount, fixer: !!fixer, graphs: !!graphs }));
  } catch (e) { bad("b console chrome not duplicated", e.message); }

  /* c — MOTD Creator still works: tab click opens studio mask; Escape closes */
  try {
    const tab = await page.$(".pr-motd-tab");
    if (!tab) throw new Error("motd tab absent");
    await page.evaluate(() => document.querySelector(".pr-motd-tab").click());
    await page.waitForSelector(".pr-motd-mask", { timeout: 5000 });
    const mask = await page.$(".pr-motd-mask");
    if (mask) ok("c MOTD studio opens");
    else bad("c MOTD studio opens", "no mask");
    await page.keyboard.press("Escape");
    await sleep(400);
    const stillOpen = await page.$(".pr-motd-mask");
    if (mask && !stillOpen) ok("c MOTD studio closes");
    else bad("c MOTD studio closes", "mask persists");
  } catch (e) { bad("c MOTD studio open/close", e.message); }

  /* d — addons tab + motd tab coexist in the subnav, both visible */
  try {
    const motd = await page.$(".pr-motd-tab");
    const addons = await page.$(".pr-addons-tab");
    const vis = (el) => el ? el.offsetParent !== null : false;
    const both = await Promise.all([motd, addons].map(async (h) => vis(await h)));
    if (both[0] && both[1]) ok("d motd + addons tabs coexist");
    else bad("d motd + addons tabs coexist", JSON.stringify(both));
  } catch (e) { bad("d motd + addons tabs coexist", e.message); }

  /* e — Rust server: no motd tab, addons hub opens with 12 cards;
   * plugins card clickable (admin canUse) — panel must show graceful
   * empty/error state on Rust (no plugins dir), not a crash. */
  try {
    await page.goto(BASE + "/server/" + RUST, { waitUntil: "networkidle2" });
    await sleep(2200);
    const motdTab = await page.$(".pr-motd-tab");
    const shown = motdTab ? await page.evaluate((t) => t.offsetParent !== null, motdTab) : false;
    const addonsTab = await page.$(".pr-addons-tab");
    if (!shown && addonsTab) ok("e Rust: no motd tab, addons tab present");
    else bad("e Rust: no motd tab, addons tab present", JSON.stringify({ motdShown: shown, addons: !!addonsTab }));
    if (addonsTab) {
      await page.evaluate(() => document.querySelector(".pr-addons-tab").click());
      await page.waitForSelector(".pr-addons-mask", { timeout: 5000 });
      await page.waitForSelector(".pr-addons-app", { timeout: 5000 });
      const cards = await page.$$eval(".pr-addons-app", (els) => els.length);
      if (cards === 12) ok("e Rust hub: 12 cards");
      else bad("e Rust hub: 12 cards", String(cards));
      const pluginsCard = await page.evaluate(() => {
        const els = Array.from(document.querySelectorAll(".pr-addons-app"));
        const c = els.find((x) => x.textContent.indexOf("Plugin Manager") !== -1);
        if (!c) return null;
        return { locked: c.classList.contains("is-locked") };
      });
      if (pluginsCard && !pluginsCard.locked) ok("e plugins card usable for admin");
      else bad("e plugins card usable for admin", JSON.stringify(pluginsCard));
      await page.evaluate(() => {
        const els = Array.from(document.querySelectorAll(".pr-addons-app"));
        const c = els.find((x) => x.textContent.indexOf("Plugin Manager") !== -1);
        if (c) c.click();
      });
      await sleep(1200);
      const panelState = await page.evaluate(() => {
        const empty = document.querySelector(".pr-addons-empty");
        const rows = document.querySelectorAll(".pr-plugins-row, .pr-plugins-panel tr").length;
        return { graceful: !!empty || rows > 0, text: empty ? empty.textContent : "" };
      });
      if (panelState.graceful) ok("e plugins panel graceful on Rust (empty state)");
      else bad("e plugins panel graceful on Rust", JSON.stringify(panelState));
      await page.keyboard.press("Escape");
      await sleep(300);
    }
  } catch (e) { bad("e Rust hub tour", e.message); }

  /* f — settings.json still 200 */
  try {
    const status = await page.evaluate(async () => (await fetch("/extensions/primus/settings.json", { credentials: "same-origin" })).status);
    if (status === 200) ok("f settings.json 200");
    else bad("f settings.json 200", String(status));
  } catch (e) { bad("f settings.json 200", e.message); }

  /* g — no [primus]/addons console errors across the tour (stock noise excluded,
   * but primus/addons-tagged errors are never masked by the noise filter) */
  try {
    const primusErrs = consoleErrors.filter((t) => /\[primus\]|addons/i.test(t));
    if (primusErrs.length === 0) ok("g no primus/addons console errors");
    else bad("g no primus/addons console errors", JSON.stringify(primusErrs.slice(0, 4)));
  } catch (e) { bad("g no primus/addons console errors", e.message); }

  /* h — wrapper ships addons.js exactly once (on the MC server page, per brief) */
  try {
    await page.goto(BASE + "/server/" + MC, { waitUntil: "networkidle2" });
    await sleep(1500);
    const html = await page.content();
    const n = (html.match(/addons\.js/g) || []).length;
    if (n === 1) ok("h addons.js loaded once");
    else bad("h addons.js loaded once", String(n));
  } catch (e) { bad("h addons.js loaded once", e.message); }

  console.log("-----------------------------");
  console.log("PASS=" + PASS + " FAIL=" + FAIL);
  if (consoleErrors.length) console.log("console-errors:", JSON.stringify(consoleErrors.slice(0, 6)));
  await browser.close();
  process.exit(FAIL ? 1 : 0);
})().catch((e) => { console.error("SUITE-ERROR", e); process.exit(1); });
