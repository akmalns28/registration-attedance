import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, readFile, writeFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { resolve, join, extname, sep } from "node:path";
import { promisify } from "node:util";
import test from "node:test";
import { build } from "vite";
import { browser, launchBrowser } from "./browser-helpers.mjs";

test("Attendance Reports in Event Detail", { skip: !browser, timeout: 180000 }, async (suite) => {
    const artifacts = await mkdtemp(join(tmpdir(), "attendance-reports-browser-"));
    suite.diagnostic(`Browser artifacts: ${artifacts}`);
    let server;
    let client;
    let processHandle;
    const requests = [];
    try {
        const generated = await promisify(execFile)("php", ["vendor/bin/phpunit", "tests/Feature/AttendanceReportTest.php",
            "--filter=test_browser_fixtures_preserve_multi_day_scope_and_existing_scanner_entry"], {
            cwd: process.cwd(), windowsHide: true, timeout: 30000, env: { ...process.env, REPORT_BROWSER_ARTIFACT_DIR: artifacts },
        });
        assert.match(generated.stdout, /passed/);
        const fixture = JSON.parse(await readFile(join(artifacts, "fixture.json"), "utf8"));
        const source = await readFile(join(artifacts, "event.html"), "utf8");
        const manifest = JSON.parse(await readFile(resolve("public/build/manifest.json"), "utf8"));
        const styles = [manifest["resources/css/app.css"].file, ...(manifest["resources/js/app.js"].css ?? []),
            ...(manifest["resources/js/attendance-reports.js"].css ?? [])];
        const bundle = await build({ configFile: false, logLevel: "silent", root: process.cwd(),
            plugins: [{ name: "report-browser-exports", transform(code, id) {
                if (id.replaceAll("\\", "/") === resolve("resources/js/attendance-reports.js").replaceAll("\\", "/")) {
                    return `${code}
                        export { initializeEventPage } from ${JSON.stringify(resolve("resources/js/events.js").replaceAll("\\", "/"))};
                        export { initializeDataTables } from ${JSON.stringify(resolve("resources/js/plugins/datatables.js").replaceAll("\\", "/"))};
                        export { default as DataTable } from "datatables.net-bs5";`;
                }
            } }],
            build: { write: false, minify: false, lib: { entry: resolve("resources/js/attendance-reports.js"), formats: ["es"], fileName: "reports-test" } } });
        const javascript = (Array.isArray(bundle) ? bundle[0] : bundle).output.find((item) => item.type === "chunk").code;
        const eventPath = new URL(fixture.url).pathname.replace("/reports/data", "");
        let origin;
        server = createServer(async (request, response) => {
            try {
                const url = new URL(request.url, "http://localhost");
                if (url.pathname.endsWith("/reports/data")) {
                    const parameters = Object.fromEntries(url.searchParams);
                    requests.push(parameters);
                    let rows = fixture.rows.filter((row) => (!parameters.event_day || row.event_day_date === fixture.day_date)
                        && (!parameters.participant_type || row.participant_type === parameters.participant_type)
                        && (!parameters.attendance_status || row.attendance_status === parameters.attendance_status)
                        && (!parameters.department_id || row.department_name === "Department Alpha"));
                    const search = (parameters["search[value]"] || "").toLowerCase();
                    rows = rows.filter((row) => row.participant_name.toLowerCase().includes(search) || row.registration_number.toLowerCase().includes(search));
                    if (parameters["order[0][column]"] === "2") {
                        rows.sort((a, b) => a.participant_name.localeCompare(b.participant_name) * (parameters["order[0][dir]"] === "desc" ? -1 : 1));
                    }
                    const expected = rows.length;
                    const present = rows.filter((row) => row.attendance_status === "present").length;
                    const start = Number(parameters.start || 0);
                    const data = rows.slice(start, start + Number(parameters.length || 10)).map((row, index) => ({ ...row, row_number: start + index + 1 }));
                    response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify({
                        draw: Number(parameters.draw), recordsTotal: fixture.rows.length, recordsFiltered: expected, data,
                        summary: { expected, present, not_present: expected - present, percentage: expected ? Math.round(present / expected * 10000) / 100 : 0 },
                    }));
                    return;
                }
                if (url.pathname === eventPath) {
                    const html = source.replaceAll(fixture.origin, origin)
                        .replace(/<script\b(?=[^>]*\btype="module")[^>]*>[\s\S]*?<\/script>/g, "")
                        .replace(/<link\b[^>]*rel="stylesheet"[^>]*>/g, "")
                        .replace("</head>", styles.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join("") + "</head>");
                    response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(html);
                    return;
                }
                if (url.pathname === "/report-module.js") {
                    response.writeHead(200, { "Content-Type": "text/javascript" }).end(javascript);
                    return;
                }
                const publicRoot = resolve("public");
                const file = resolve(publicRoot, `.${url.pathname}`);
                if (!file.startsWith(publicRoot + sep)) { response.writeHead(404).end(); return; }
                const content = await readFile(file);
                response.writeHead(200, { "Content-Type": ({ ".css": "text/css", ".js": "text/javascript", ".woff2": "font/woff2" })[extname(file)] || "application/octet-stream" });
                response.end(content);
            } catch {
                if (!response.headersSent) { response.writeHead(404); }
                response.end();
            }
        });
        server.listen(0, "127.0.0.1");
        await once(server, "listening");
        origin = `http://127.0.0.1:${server.address().port}`;
        ({ client, processHandle } = await launchBrowser(join(artifacts, "profile")));

        async function fresh(query = "", tab = "event-reports") {
            const cleared = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: "about:blank" });
            await cleared;
            const loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: `${origin}${eventPath}${query}#${tab}` });
            await loaded;
            await client.evaluate(`
                window.fixture = ${JSON.stringify(fixture)};
                window.reportModule = await import("/report-module.js");
                reportModule.initializeEventPage(); reportModule.initializeDataTables();
                window.reports = reportModule.initializeAttendanceReports();
                window.waitForReports = async (search = null) => {
                    const deadline = Date.now() + 10000;
                    while (!reports.table || reports.page.getAttribute("aria-busy") === "true"
                        || (search !== null && reports.table.search() !== search)) {
                        if (Date.now() > deadline) { throw new Error("Reports did not load: " + reports.feedback.textContent); }
                        await new Promise((resolve) => setTimeout(resolve, 20));
                    }
                };
                if (${tab === "event-reports"}) {
                    await waitForReports();
                    const pane = document.getElementById("event-reports");
                    const deadline = Date.now() + 5000;
                    while (!pane.classList.contains("show") || getComputedStyle(pane).opacity !== "1") {
                        if (Date.now() > deadline) { throw new Error("Reports tab did not become visible"); }
                        await new Promise(requestAnimationFrame);
                    }
                }
            `);
        }

        await suite.test("Reports initialize only when opened and retain a single server-side DataTable", async () => {
            requests.length = 0;
            await fresh("", "event-overview");
            const initial = await client.evaluate(`return { initialized: Boolean(reports.table), summary: reports.page.querySelector('[data-report-summary="expected"] .h1').textContent.trim() };`);
            assert.deepEqual(initial, { initialized: false, summary: "—" });
            assert.equal(requests.length, 0);
            const result = await client.evaluate(`
                document.getElementById("event-reports-tab").click();
                await waitForReports();
                const settings = reports.table.settings()[0];
                reportModule.initializeAttendanceReports(); reportModule.initializeDataTables(); reports.start();
                return { same: settings === reports.table.settings()[0],
                    rows: reports.table.rows().count(), expected: reports.page.querySelector('[data-report-summary="expected"] .h1').textContent,
                    reportExports: reports.tableElement.closest('.dt-container').querySelectorAll('.dt-buttons').length,
                    existingExports: document.querySelector('#eventParticipantsTable_wrapper .dt-buttons') !== null };
            `);
            assert.deepEqual(result, { same: true, rows: 10, expected: "24", reportExports: 0, existingExports: true });
            assert.equal(requests.length, 1);
            assert.equal(requests[0].start, "0");
            assert.equal(requests[0].length, "10");
        });

        await suite.test("pagination has continuous numbering and summaries cover every filtered page", async () => {
            await fresh();
            const result = await client.evaluate(`
                reports.table.page(1).draw("page"); await waitForReports();
                return { first: reports.table.row(0).data().row_number, rows: reports.table.rows().count(),
                    presentOnPage: reports.table.rows().data().toArray().filter((row) => row.attendance_status === "present").length,
                    summaryPresent: reports.page.querySelector('[data-report-summary="present"] .h1').textContent,
                    query: location.search, hash: location.hash };
            `);
            assert.equal(result.first, 11);
            assert.equal(result.rows, 10);
            assert.equal(result.presentOnPage, 0);
            assert.equal(result.summaryPresent, "4");
            assert.match(result.query, /report_start=10/);
            assert.equal(result.hash, "#event-reports");
        });

        await suite.test("filters combine, External clears Department, and Reset restores the report", async () => {
            await fresh();
            const result = await client.evaluate(`
                const select = async (name, value) => {
                    const field = reports.form.elements.namedItem(name); field.value = String(value);
                    field.dispatchEvent(new Event("change", { bubbles: true })); await waitForReports();
                };
                await select("report_day", fixture.day);
                await select("report_type", "employee");
                await select("report_department", fixture.department);
                await select("report_status", "present");
                const filtered = { rows: reports.table.rows().count(), participant: reports.table.row(0).data().participant_name,
                    percent: reports.page.querySelector('[data-report-summary="percentage"] .h1').textContent };
                await select("report_type", "external");
                const external = { disabled: reports.department.disabled, department: reports.department.value, type: reports.table.row(0).data().participant_type };
                reports.page.querySelector("[data-report-reset]").click(); await waitForReports();
                return { filtered, external, reset: { rows: reports.table.page.info().recordsDisplay, departmentEnabled: !reports.department.disabled,
                    type: reports.type.value, hash: location.hash, day: reports.form.elements.namedItem("report_day").value } };
            `);
            assert.deepEqual(result.filtered, { rows: 1, participant: "Alice Employee", percent: "100%" });
            assert.deepEqual(result.external, { disabled: true, department: "", type: "external" });
            assert.deepEqual(result.reset, { rows: 24, departmentEnabled: true, type: "", hash: "#event-reports", day: "" });
        });

        await suite.test("search is debounced and updates global summary with server-side sorting", async () => {
            await fresh();
            requests.length = 0;
            const result = await client.evaluate(`
                for (const term of ["C", "Car", "Carla"]) { reports.search.value = term; reports.search.dispatchEvent(new Event("input", { bubbles: true })); }
                await waitForReports("Carla");
                const searched = { rows: reports.table.page.info().recordsDisplay,
                    expected: reports.page.querySelector('[data-report-summary="expected"] .h1').textContent,
                    present: reports.page.querySelector('[data-report-summary="present"] .h1').textContent };
                reports.search.value = ""; reports.applyFilters(); await waitForReports("");
                reports.table.order([2, "desc"]).draw(); await waitForReports();
                return { searched, participant: reports.table.row(0).data().participant_name };
            `);
            assert.deepEqual(result.searched, { rows: 3, expected: "3", present: "1" });
            assert.equal(result.participant, "Carla External");
            assert.equal(requests.length, 3);
            assert.equal(requests.at(-1)["order[0][column]"], "2");
        });

        await suite.test("successful filters, ordering, pagination and Reports tab survive refresh", async () => {
            await fresh();
            const query = await client.evaluate(`
                reports.type.value = "employee"; reports.applyFilters(); await waitForReports();
                reports.table.order([2, "desc"]).page.len(10).draw(); await waitForReports();
                reports.table.page(1).draw("page"); await waitForReports(); return location.search;
            `);
            await fresh(query);
            const result = await client.evaluate(`return { active: document.getElementById("event-reports").classList.contains("active"),
                type: reports.type.value, page: reports.table.page(), order: reports.table.order()[0], rows: reports.table.page.info().recordsDisplay };`);
            assert.deepEqual(result, { active: true, type: "employee", page: 1, order: [2, "desc"], rows: 21 });
        });

        await suite.test("stale AJAX responses cannot overwrite a newer filter or its summary", async () => {
            await fresh();
            const result = await client.evaluate(`
                const pending = [];
                reports.request = (url, options) => new Promise((resolve) => pending.push({ resolve, signal: options.signal, draw: Number(new URL(url).searchParams.get("draw")) }));
                reports.search.value = "Alice"; reports.applyFilters();
                reports.search.value = "Carla"; reports.applyFilters();
                const respond = (index, name, present) => {
                    const rows = fixture.rows.filter((row) => row.participant_name.includes(name));
                    pending[index].resolve(new Response(JSON.stringify({ draw: pending[index].draw, recordsTotal: 24, recordsFiltered: rows.length, data: rows,
                        summary: { expected: 3, present, not_present: 3 - present, percentage: present / 3 * 100 } }), { headers: { "content-type": "application/json" } }));
                };
                respond(1, "Carla", 1); await waitForReports();
                respond(0, "Alice", 2); await new Promise(requestAnimationFrame);
                return { aborted: pending[0].signal.aborted, participant: reports.table.row(0).data().participant_name,
                    present: reports.page.querySelector('[data-report-summary="present"] .h1').textContent, query: location.search };
            `);
            assert.equal(result.aborted, true);
            assert.equal(result.participant, "Carla External");
            assert.equal(result.present, "1");
            assert.match(result.query, /report_search=Carla/);
        });

        await suite.test("request failure clears unconfirmed totals, ends loading and supports an explicit retry", async () => {
            await fresh();
            const result = await client.evaluate(`
                const original = reports.request;
                reports.request = async () => new Response(JSON.stringify({ errors: { event_day: ["Event Day tidak ditemukan pada Event ini."] } }),
                    { status: 422, headers: { "content-type": "application/json" } });
                reports.applyFilters(); await waitForReports();
                const failed = { message: reports.feedback.textContent, value: reports.page.querySelector('[data-report-summary="expected"] .h1').textContent,
                    retry: !reports.refreshButton.disabled, rows: reports.table.rows().count() };
                reports.request = original; reports.applyFilters(); await waitForReports();
                return { failed, recovered: reports.table.page.info().recordsDisplay };
            `);
            assert.match(result.failed.message, /Event Day tidak ditemukan/);
            assert.equal(result.failed.value, "—");
            assert.equal(result.failed.retry, true);
            assert.equal(result.failed.rows, 0);
            assert.equal(result.recovered, 24);
        });

        await suite.test("participant text is escaped before DataTables renders it", async () => {
            await fresh();
            const result = await client.evaluate(`
                reports.request = async (url) => new Response(JSON.stringify({ draw: Number(new URL(url).searchParams.get("draw")), recordsTotal: 1, recordsFiltered: 1,
                    data: [{ ...fixture.rows[0], participant_name: '<img src=x onerror="window.reportXss=true">' }],
                    summary: { expected: 1, present: 1, not_present: 0, percentage: 100 } }), { headers: { "content-type": "application/json" } });
                reports.applyFilters(); await waitForReports();
                return { images: reports.tableElement.querySelectorAll("img").length, executed: Boolean(window.reportXss), text: reports.tableElement.textContent };
            `);
            assert.equal(result.images, 0);
            assert.equal(result.executed, false);
            assert.match(result.text, /<img src=x/);
        });

        for (const [width, height] of [[375, 812], [768, 1024], [1440, 900]]) {
            await suite.test(`Reports fit ${width}x${height} without duplicate initialization`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh();
                const result = await client.evaluate(`
                    await new Promise(requestAnimationFrame);
                    let detail = null;
                    if (${width < 600}) {
                        const control = reports.tableElement.querySelector("tbody td.dtr-control");
                        if (!control) { throw new Error("Mobile report rows have no detail control"); }
                        control.click();
                        detail = reports.tableElement.querySelector(".dtr-details")?.textContent;
                    }
                    return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
                        detail,
                        tables: reports.page.querySelectorAll(".dt-container").length,
                        fields: Array.from(reports.form.querySelectorAll("select, input, button"), (field) => ({ left: field.getBoundingClientRect().left, right: field.getBoundingClientRect().right })) };
                `);
                assert.ok(result.scrollWidth <= result.width, JSON.stringify(result));
                assert.equal(result.tables, 1);
                if (width < 600) { assert.match(result.detail, /Waktu Check-in|Petugas/); }
                result.fields.forEach((bounds) => assert.ok(bounds.left >= 0 && bounds.right <= result.width, JSON.stringify(bounds)));
                const screenshot = await client.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `reports-${width}x${height}.png`), Buffer.from(screenshot.data, "base64"));
            });
        }

        await suite.test("page exit aborts a pending report and releases its DataTable", async () => {
            await fresh();
            const result = await client.evaluate(`
                let signal;
                reports.request = (_url, options) => { signal = options.signal; return new Promise(() => {}); };
                reports.applyFilters();
                window.dispatchEvent(new PageTransitionEvent("pagehide"));
                return { aborted: signal.aborted, initialized: reportModule.DataTable.isDataTable(reports.tableElement), destroyed: reports.destroyed };
            `);
            assert.deepEqual(result, { aborted: true, initialized: false, destroyed: true });
        });
        assert.deepEqual(client.errors, []);
    } finally {
        if (client) { await client.send("Page.close").catch(() => {}); client.socket.close(); }
        if (processHandle) { processHandle.kill(); }
        if (server) { await new Promise((resolve) => server.close(resolve)); }
        await new Promise((resolve) => setTimeout(resolve, 300));
        const profile = resolve(artifacts, "profile");
        if (profile.startsWith(resolve(artifacts) + sep)) {
            await rm(profile, { recursive: true, force: true, maxRetries: 3 }).catch(() => {});
        }
    }
});
