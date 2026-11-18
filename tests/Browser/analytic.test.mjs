import assert from "node:assert/strict";
import { spawn, execFile } from "node:child_process";
import { once } from "node:events";
import { existsSync } from "node:fs";
import { mkdtemp, readFile, writeFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { resolve, join, extname, sep } from "node:path";
import { promisify } from "node:util";
import test from "node:test";
import { build } from "vite";

const execute = promisify(execFile);
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const browser = [process.env.ANALYTIC_BROWSER_BINARY, "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"]
    .find((path) => path && existsSync(path));

class DevTools {
    constructor(socket) {
        this.socket = socket;
        this.nextId = 0;
        this.pending = new Map();
        this.errors = [];
        socket.addEventListener("message", ({ data }) => {
            const message = JSON.parse(data);
            if (message.method === "Runtime.exceptionThrown") {
                this.errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
            }
            const pending = this.pending.get(message.id);
            if (pending) {
                this.pending.delete(message.id);
                clearTimeout(pending.timeout);
                message.error ? pending.reject(new Error(JSON.stringify(message.error))) : pending.resolve(message.result);
            }
        });
    }

    send(method, params = {}) {
        const id = ++this.nextId;
        return new Promise((resolve, reject) => {
            const timeout = setTimeout(() => { this.pending.delete(id); reject(new Error(`Browser timed out: ${method}`)); }, 20000);
            this.pending.set(id, { resolve, reject, timeout });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(code) {
        const result = await this.send("Runtime.evaluate", {
            expression: `(async () => { ${code} })()`, awaitPromise: true, returnByValue: true, userGesture: true,
        });
        if (result.exceptionDetails) {
            throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        }
        return result.result.value;
    }

    waitFor(method) {
        return new Promise((resolve, reject) => {
            const listener = ({ data }) => {
                const message = JSON.parse(data);
                if (message.method === method) {
                    this.socket.removeEventListener("message", listener);
                    clearTimeout(timeout);
                    resolve();
                }
            };
            const timeout = setTimeout(() => {
                this.socket.removeEventListener("message", listener);
                reject(new Error(`Browser event timed out: ${method}`));
            }, 15000);
            this.socket.addEventListener("message", listener);
        });
    }
}

test("Analytic in a real browser", { skip: !browser, timeout: 180000 }, async (suite) => {
    const artifacts = await mkdtemp(join(tmpdir(), "analytic-browser-"));
    suite.diagnostic(`Browser artifacts: ${artifacts}`);
    let server;
    let processHandle;
    let client;
    const requests = [];
    try {
        const generated = await execute("php", ["vendor/bin/phpunit", "tests/Feature/AnalyticTest.php",
            "--filter=test_page_preserves_initial_filters_and_provides_safe_browser_fixtures"], {
            cwd: process.cwd(), windowsHide: true, timeout: 30000,
            env: { ...process.env, ANALYTIC_BROWSER_ARTIFACT_DIR: artifacts },
        });
        assert.match(generated.stdout, /passed/);
        const fixture = JSON.parse(await readFile(join(artifacts, "fixture.json"), "utf8"));
        const source = await readFile(join(artifacts, "analytic.html"), "utf8");
        const manifest = JSON.parse(await readFile(resolve("public/build/manifest.json"), "utf8"));
        const styles = [manifest["resources/css/app.css"].file, ...(manifest["resources/js/app.js"].css ?? [])];
        const bundle = await build({ configFile: false, logLevel: "silent", root: process.cwd(),
            build: { write: false, minify: false, lib: { entry: resolve("resources/js/analytic.js"), formats: ["es"], fileName: "analytic-test" } } });
        const javascript = (Array.isArray(bundle) ? bundle[0] : bundle).output.find((item) => item.type === "chunk").code;
        let origin;
        server = createServer(async (request, response) => {
            try {
                const url = new URL(request.url, "http://localhost");
                if (url.pathname === "/analytic/data") {
                    requests.push(Object.fromEntries(url.searchParams));
                    const data = url.searchParams.get("start_date") === "2030-01-01" ? fixture.empty
                        : url.searchParams.get("event") === fixture.first.filters.event
                            ? url.searchParams.has("event_day") ? fixture.day : fixture.first
                            : url.searchParams.get("event") === fixture.second.filters.event ? fixture.second : fixture.all;
                    response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify({ success: true, data }));
                    return;
                }
                if (url.pathname === "/analytic") {
                    const html = source.replaceAll(fixture.origin, origin)
                        .replace(/<script\b(?=[^>]*\btype="module")[^>]*>[\s\S]*?<\/script>/g, "")
                        .replace(/<link\b[^>]*rel="stylesheet"[^>]*>/g, "")
                        .replace("</head>", styles.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join("") + "</head>");
                    response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(html);
                    return;
                }
                if (url.pathname === "/analytic-module.js") {
                    response.writeHead(200, { "Content-Type": "text/javascript" }).end(javascript);
                    return;
                }
                const publicRoot = resolve("public");
                const file = resolve(publicRoot, `.${url.pathname}`);
                if (!file.startsWith(publicRoot + sep)) {
                    response.writeHead(404).end();
                    return;
                }
                response.writeHead(200, { "Content-Type": ({ ".css": "text/css", ".js": "text/javascript", ".woff2": "font/woff2" })[extname(file)] || "application/octet-stream" });
                response.end(await readFile(file));
            } catch { response.writeHead(404).end(); }
        });
        server.listen(0, "127.0.0.1");
        await once(server, "listening");
        origin = `http://127.0.0.1:${server.address().port}`;
        const profile = join(artifacts, "profile");
        processHandle = spawn(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
            "--disable-background-networking", "--no-proxy-server", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"],
        { windowsHide: true, stdio: "ignore" });
        const deadline = Date.now() + 15000;
        while (!existsSync(join(profile, "DevToolsActivePort"))) {
            if (Date.now() > deadline) { throw new Error("Browser did not start"); }
            await delay(100);
        }
        const port = (await readFile(join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0];
        const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: "PUT" })).json();
        const socket = new WebSocket(target.webSocketDebuggerUrl);
        await once(socket, "open");
        client = new DevTools(socket);
        await client.send("Page.enable");
        await client.send("Runtime.enable");

        async function fresh(fake = false) {
            const loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: origin + "/analytic" });
            await loaded;
            await client.evaluate(`
                const deadline = Date.now() + 10000;
                while (!document.querySelector("[data-analytic-initial]") || document.readyState !== "complete") {
                    if (Date.now() > deadline) { throw new Error("Analytic page did not load"); }
                    await new Promise((resolve) => setTimeout(resolve, 20));
                }
                window.fixture = ${JSON.stringify(fixture)};
                window.analyticModule = await import("/analytic-module.js");
                window.FakeChart = class {
                    static created = 0; static destroyed = 0;
                    constructor(element, options) { this.element = element; this.options = options; FakeChart.created++; }
                    async render() {}
                    async updateOptions(options) { await window.updateGate; this.options = options; }
                    destroy() { FakeChart.destroyed++; }
                };
                const page = document.querySelector("[data-analytic-page]");
                if (${fake}) {
                    page.dataset.initialized = "true";
                    window.analytic = new analyticModule.AnalyticPage(page, { Chart: FakeChart });
                } else {
                    window.analytic = analyticModule.initializeAnalyticPage();
                }
                await analytic.ready;
                window.waitForAnalytic = async () => {
                    const deadline = Date.now() + 10000;
                    while (analytic.page.getAttribute("aria-busy") === "true") {
                        if (Date.now() > deadline) { throw new Error("Analytics did not settle: " + analytic.feedback.textContent); }
                        await new Promise((resolve) => setTimeout(resolve, 10));
                    }
                    await analytic.renderQueue;
                };
            `);
        }

        await suite.test("renders six KPIs and four real ApexCharts without duplicate initialization", async () => {
            await fresh();
            const result = await client.evaluate(`
                analyticModule.initializeAnalyticPage();
                return { kpis: document.querySelectorAll("[data-analytic-kpi]").length,
                    charts: analytic.charts.size, canvases: document.querySelectorAll(".apexcharts-canvas").length,
                    rate: document.querySelector('[data-analytic-kpi="attendance_rate"] .h1').textContent,
                    average: document.querySelector('[data-analytic-kpi="average_event_rate"] .h1').textContent };
            `);
            assert.deepEqual(result, { kpis: 6, charts: 4, canvases: 4, rate: "40%", average: "45,83%" });
        });

        await suite.test("event and day changes refresh existing charts and preserve filters", async () => {
            await fresh();
            requests.length = 0;
            const result = await client.evaluate(`
                const charts = Array.from(analytic.charts.values());
                analytic.event.value = fixture.first.filters.event;
                analytic.event.dispatchEvent(new Event("change", { bubbles: true }));
                await waitForAnalytic();
                const days = Array.from(analytic.day.options, (option) => option.value);
                analytic.day.value = String(fixture.day.filters.event_day);
                analytic.day.dispatchEvent(new Event("change", { bubbles: true }));
                await waitForAnalytic();
                await analytic.refresh();
                return { days, selected: analytic.day.value, event: analytic.event.value,
                    rate: document.querySelector('[data-analytic-kpi="attendance_rate"] .h1').textContent,
                    sameCharts: charts.every((chart, index) => chart === Array.from(analytic.charts.values())[index]),
                    canvases: document.querySelectorAll(".apexcharts-canvas").length, query: location.search };
            `);
            assert.deepEqual(result.days, ["", ...fixture.first.event_days.map((day) => String(day.id))]);
            assert.equal(result.selected, String(fixture.day.filters.event_day));
            assert.equal(result.event, fixture.first.filters.event);
            assert.equal(result.rate, "100%");
            assert.equal(result.sameCharts, true);
            assert.equal(result.canvases, 4);
            assert.match(result.query, /event_day=/);
            assert.equal(requests.length, 3);
        });

        await suite.test("switching Events clears a stale day and only offers the new Event days", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                analytic.event.value = fixture.first.filters.event;
                analytic.event.dispatchEvent(new Event("change", { bubbles: true })); await waitForAnalytic();
                analytic.day.value = String(fixture.day.filters.event_day);
                analytic.event.value = fixture.second.filters.event;
                analytic.event.dispatchEvent(new Event("change", { bubbles: true })); await waitForAnalytic();
                return { selected: analytic.day.value, options: Array.from(analytic.day.options, (option) => option.value) };
            `);
            assert.deepEqual(result, { selected: "", options: ["", ...fixture.second.event_days.map((day) => String(day.id))] });
        });

        await suite.test("delayed responses cannot overwrite newer filters or end their loading state", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                const pending = [];
                analytic.request = () => new Promise((resolve) => pending.push(resolve));
                const first = analytic.refresh();
                const second = analytic.refresh();
                const loading = analytic.refreshButton.disabled && analytic.page.getAttribute("aria-busy") === "true";
                const json = (data) => new Response(JSON.stringify({ success: true, data }), { headers: { "content-type": "application/json" } });
                pending[1](json(fixture.second)); await second;
                pending[0](json(fixture.first)); await first;
                return { loading, events: document.querySelector('[data-analytic-kpi="total_events"] .h1').textContent,
                    registrations: document.querySelector('[data-analytic-kpi="total_registrations"] .h1').textContent,
                    rate: document.querySelector('[data-analytic-kpi="attendance_rate"] .h1').textContent,
                    chart: analytic.charts.get("by_event").options.xaxis.categories, created: FakeChart.created };
            `);
            assert.deepEqual(result, { loading: true, events: "1", registrations: "6", rate: "16,67%", chart: ["Beta Event"], created: 4 });
        });

        await suite.test("chart updates are serialized so a slow render cannot restore old metrics", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                const json = (data) => new Response(JSON.stringify({ success: true, data }), { headers: { "content-type": "application/json" } });
                window.updateGate = new Promise((resolve) => { window.finishUpdate = resolve; });
                analytic.request = async () => json(fixture.first);
                const first = analytic.refresh();
                await new Promise((resolve) => setTimeout(resolve, 0));
                analytic.request = async () => json(fixture.second);
                const second = analytic.refresh();
                finishUpdate(); await Promise.all([first, second]);
                return { rate: document.querySelector('[data-analytic-kpi="attendance_rate"] .h1').textContent,
                    chart: analytic.charts.get("by_event").options.xaxis.categories };
            `);
            assert.deepEqual(result, { rate: "16,67%", chart: ["Beta Event"] });
        });

        await suite.test("validation and network failures show feedback without losing filters or retrying automatically", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                let calls = 0;
                analytic.request = async () => { calls++; return new Response(JSON.stringify({ errors: { event_day: ["Event Day tidak ditemukan pada Event yang dipilih."] } }),
                    { status: 422, headers: { "content-type": "application/json" } }); };
                await analytic.refresh();
                const validation = analytic.feedback.textContent;
                analytic.request = async () => { calls++; throw new TypeError("Koneksi terputus"); };
                await analytic.refresh();
                return { calls, validation, network: analytic.feedback.textContent, retry: !analytic.refreshButton.disabled,
                    rate: document.querySelector('[data-analytic-kpi="attendance_rate"] .h1').textContent };
            `);
            assert.equal(result.calls, 2);
            assert.match(result.validation, /Event Day tidak ditemukan/);
            assert.match(result.network, /Koneksi terputus/);
            assert.equal(result.retry, true);
            assert.equal(result.rate, "40%");
        });

        await suite.test("invalid date ordering is caught before requesting data", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                let calls = 0; analytic.request = async () => { calls++; };
                analytic.form.elements.namedItem("start_date").value = "2027-01-18";
                analytic.form.elements.namedItem("end_date").value = "2027-01-15";
                await analytic.refresh();
                return { calls, invalid: !analytic.form.checkValidity(), feedback: analytic.feedback.textContent };
            `);
            assert.equal(result.calls, 0);
            assert.equal(result.invalid, true);
            assert.match(result.feedback, /filter tanggal/);
        });

        await suite.test("empty filters show empty states and restore real chart instances when data returns", async () => {
            await fresh();
            const result = await client.evaluate(`
                analytic.form.elements.namedItem("start_date").value = "2030-01-01";
                await analytic.refresh();
                const empty = { message: !document.querySelector("[data-analytic-empty]").classList.contains("d-none"),
                    charts: document.querySelectorAll("[data-analytic-chart].d-none").length };
                analytic.form.elements.namedItem("start_date").value = "";
                await analytic.refresh();
                return { empty, canvases: document.querySelectorAll(".apexcharts-canvas").length, count: analytic.charts.size };
            `);
            assert.deepEqual(result, { empty: { message: true, charts: 4 }, canvases: 4, count: 4 });
        });

        await suite.test("tooltips safely display participant and Event labels as text", async () => {
            await fresh();
            const result = await client.evaluate(`
                const content = analytic.tooltip({ label: '<img src=x onerror="window.analyticXss=true">', checked_in: 1, expected: 4, percentage: 25 });
                const element = document.createElement("div"); element.innerHTML = content; document.body.append(element);
                return { images: element.querySelectorAll("img").length, text: element.textContent, executed: Boolean(window.analyticXss) };
            `);
            assert.equal(result.images, 0);
            assert.equal(result.executed, false);
            assert.match(result.text, /25%/);
        });

        for (const [width, height] of [[375, 812], [768, 1024], [1440, 900]]) {
            await suite.test(`Analytic fits ${width}x${height} with four real charts`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh();
                const result = await client.evaluate(`
                    await new Promise(requestAnimationFrame);
                    return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
                        charts: document.querySelectorAll(".apexcharts-canvas").length,
                        controls: Array.from(document.querySelectorAll("[data-analytic-filters] input, [data-analytic-filters] select, [data-analytic-refresh]"),
                            (element) => ({ left: element.getBoundingClientRect().left, right: element.getBoundingClientRect().right })) };
                `);
                assert.ok(result.scrollWidth <= result.width, JSON.stringify(result));
                assert.equal(result.charts, 4);
                result.controls.forEach((bounds) => assert.ok(bounds.left >= 0 && bounds.right <= result.width, JSON.stringify(bounds)));
                const screenshot = await client.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `analytic-${width}x${height}.png`), Buffer.from(screenshot.data, "base64"));
            });
        }

        await suite.test("leaving the page aborts requests and destroys all chart instances", async () => {
            await fresh(true);
            const result = await client.evaluate(`
                const pending = [];
                analytic.request = (_url, options) => new Promise((resolve) => { pending.push({ resolve, signal: options.signal }); });
                const refresh = analytic.refresh();
                await analytic.destroy();
                pending[0].resolve(new Response(JSON.stringify({ success: true, data: fixture.first }), { headers: { "content-type": "application/json" } }));
                await refresh;
                return { aborted: pending[0].signal.aborted, charts: analytic.charts.size, destroyed: FakeChart.destroyed };
            `);
            assert.deepEqual(result, { aborted: true, charts: 0, destroyed: 4 });
        });
        assert.equal(await client.evaluate('return Boolean(document.querySelector("[data-analytic-refresh] svg"));'), true);
        assert.deepEqual(client.errors, []);
    } finally {
        if (client) { await client.send("Page.close").catch(() => {}); client.socket.close(); }
        if (processHandle) { processHandle.kill(); }
        if (server) { await new Promise((resolve) => server.close(resolve)); }
        await delay(300);
        const profile = resolve(artifacts, "profile");
        if (profile.startsWith(resolve(artifacts) + sep)) {
            await rm(profile, { recursive: true, force: true, maxRetries: 3 }).catch(() => {});
        }
    }
});
