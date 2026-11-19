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
const project = process.cwd();
const browser = [
    process.env.SCANNER_BROWSER_BINARY,
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser",
].find((path) => path && existsSync(path));

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
            const request = this.pending.get(message.id);
            if (request) {
                this.pending.delete(message.id);
                clearTimeout(request.timeout);
                message.error ? request.reject(new Error(JSON.stringify(message.error))) : request.resolve(message.result);
            }
        });
    }

    send(method, params = {}) {
        const id = ++this.nextId;
        return new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error(`Browser command timed out: ${method}`));
            }, 20000);
            this.pending.set(id, { resolve, reject, timeout });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const result = await this.send("Runtime.evaluate", {
            expression: `(async () => { ${expression} })()`,
            awaitPromise: true, returnByValue: true, userGesture: true,
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
                    resolve(message.params);
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

test("attendance scanner in a real browser with a virtual webcam", { skip: !browser, timeout: 180000 }, async (suite) => {
    const artifacts = await mkdtemp(join(tmpdir(), "attendance-scanner-browser-"));
    suite.diagnostic(`Browser artifacts: ${artifacts}`);
    const requests = [];
    const checkinRequests = [];
    const manualRequests = [];
    let attendanceRecorded = false;
    let processHandle;
    let server;
    let tools;

    try {
        const fixtureRun = await execute("php", [
            "artisan", "test", "--compact", "tests/Feature/AttendanceScannerTest.php",
            "--filter=test_valid_qr_resolves_only_the_expected_participant_fields",
        ], {
            cwd: project, windowsHide: true, timeout: 30000,
            env: { ...process.env, SCANNER_BROWSER_ARTIFACT_DIR: artifacts },
        });
        assert.match(fixtureRun.stdout, /passed/);
        const fixture = JSON.parse(await readFile(join(artifacts, "fixture.json"), "utf8"));
        const sourceHtml = await readFile(join(artifacts, "scanner.html"), "utf8");
        const eventHtml = await readFile(join(artifacts, "event-scanner.html"), "utf8");
        const dayHtml = await readFile(join(artifacts, "day-detail.html"), "utf8");
        const adminDayHtml = await readFile(join(artifacts, "day-detail-admin.html"), "utf8");
        const manifest = JSON.parse(await readFile(resolve("public/build/manifest.json"), "utf8"));
        const manualModule = `/build/${manifest["resources/js/manual-attendance.js"].file}`;
        const bundle = await build({
            configFile: false, logLevel: "silent", root: project,
            build: { write: false, minify: false, lib: {
                entry: resolve("resources/js/attendance-scanner.js"), formats: ["es"], fileName: "scanner-browser-test",
            } },
        });
        const javascript = (Array.isArray(bundle) ? bundle[0] : bundle).output.find((output) => output.type === "chunk").code;
        const dayBundle = await build({
            configFile: false, logLevel: "silent", root: project,
            plugins: [{
                name: "day-browser-test-entry",
                transform(code, id) {
                    if (id.replaceAll("\\", "/") === resolve("resources/js/events.js").replaceAll("\\", "/")) {
                        const tablesPath = JSON.stringify(resolve("resources/js/plugins/datatables.js").replaceAll("\\", "/"));
                        return `${code}
                            export { initializeDataTables } from ${tablesPath};
                            export { default as DataTable } from "datatables.net-bs5";`;
                    }
                },
            }],
            build: { write: false, minify: false, lib: { entry: resolve("resources/js/events.js"), formats: ["es"], fileName: "day-browser-test" } },
        });
        const dayJavascript = (Array.isArray(dayBundle) ? dayBundle[0] : dayBundle).output.find((output) => output.type === "chunk").code;
        let origin;

        server = createServer(async (request, response) => {
            try {
                const url = new URL(request.url, "http://localhost");
                const path = url.pathname;
                if (path === "/attendance/manual/participants") {
                    const parameters = Object.fromEntries(url.searchParams);
                    manualRequests.push(parameters);
                    response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify({ data: [{
                        registration_id: 73, participant_type: "external", participant_name: "Budi Guest",
                        phone: "0812799999", attendance_status: "not_present",
                    }] }));
                    return;
                }
                if (path === "/attendance/manual/check-in") {
                    let body = "";
                    for await (const chunk of request) { body += chunk; }
                    manualRequests.push({ checkIn: JSON.parse(body), hasCsrfToken: Boolean(request.headers["x-csrf-token"]) });
                    response.writeHead(201, { "Content-Type": "application/json" })
                        .end(JSON.stringify({ ...fixture.checkin_response, message: "Check-in peserta berhasil." }));
                    return;
                }
                if (request.method === "POST" && path === "/attendance/scanner/validate") {
                    let body = "";
                    for await (const chunk of request) { body += chunk; }
                    requests.push({ payload: JSON.parse(body), headers: request.headers });
                    response.writeHead(200, { "Content-Type": "application/json" });
                    response.end(JSON.stringify(fixture.response));
                    return;
                }
                if (request.method === "POST" && path === "/attendance/check-in") {
                    let body = "";
                    for await (const chunk of request) { body += chunk; }
                    checkinRequests.push({ payload: JSON.parse(body), headers: request.headers });
                    response.writeHead(attendanceRecorded ? 200 : 201, { "Content-Type": "application/json" });
                    response.end(JSON.stringify(attendanceRecorded ? fixture.duplicate_response : fixture.checkin_response));
                    attendanceRecorded = true;
                    return;
                }
                if (request.method === "GET" && /\/days\/\d+\/attendance$/.test(path)) {
                    response.writeHead(200, { "Content-Type": "application/json" });
                    response.end(JSON.stringify(fixture.attendance_response));
                    return;
                }
                if (["/", "/event-scanner", "/day-detail", "/day-detail-admin"].includes(path)) {
                    const source = { "/": sourceHtml, "/event-scanner": eventHtml, "/day-detail": dayHtml, "/day-detail-admin": adminDayHtml }[path];
                    const html = source.replaceAll(fixture.origin, origin)
                        .replace(/<script\b(?=[^>]*\btype="module")[^>]*>[\s\S]*?<\/script>/g, "");
                    response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
                    response.end(html);
                    return;
                }
                if (path === "/scanner-module.js") {
                    response.writeHead(200, { "Content-Type": "text/javascript" });
                    response.end(javascript);
                    return;
                }
                if (path === "/day-ui-module.js") {
                    response.writeHead(200, { "Content-Type": "text/javascript" });
                    response.end(dayJavascript);
                    return;
                }
                const publicRoot = resolve("public");
                const fixtureImage = ["/registration.png", "/registration-regression.png"].includes(path)
                    || /^\/registration-pattern-\d+\.png$/.test(path);
                const file = fixtureImage ? join(artifacts, path.slice(1)) : resolve(publicRoot, `.${path}`);
                if (!fixtureImage && !file.startsWith(publicRoot + sep)) {
                    response.writeHead(404).end();
                    return;
                }
                const contentTypes = { ".css": "text/css", ".js": "text/javascript", ".png": "image/png", ".woff2": "font/woff2" };
                response.writeHead(200, { "Content-Type": contentTypes[extname(file)] || "application/octet-stream" });
                response.end(await readFile(file));
            } catch {
                response.writeHead(404).end();
            }
        });
        server.listen(0, "127.0.0.1");
        await once(server, "listening");
        origin = `http://127.0.0.1:${server.address().port}`;
        const profile = join(artifacts, "browser-profile");
        processHandle = spawn(browser, [
            "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
            "--disable-background-networking", "--no-proxy-server", "--remote-debugging-port=0",
            "--use-fake-device-for-media-stream", "--use-fake-ui-for-media-stream",
            `--user-data-dir=${profile}`, "about:blank",
        ], { windowsHide: true, stdio: "ignore" });
        const startTime = Date.now();
        while (!existsSync(join(profile, "DevToolsActivePort"))) {
            if (Date.now() - startTime > 15000) { throw new Error("Browser did not start its debugging endpoint."); }
            await delay(100);
        }
        const port = (await readFile(join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0];
        const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: "PUT" })).json();
        const socket = new WebSocket(target.webSocketDebuggerUrl);
        await once(socket, "open");
        tools = new DevTools(socket);
        await tools.send("Page.enable");
        await tools.send("Runtime.enable");

        async function fresh(fake = false, eventContext = false, dayContext = false) {
            const loaded = tools.waitFor("Page.loadEventFired");
            const path = dayContext ? (dayContext === "admin" ? "/day-detail-admin" : "/day-detail") : (eventContext ? "/event-scanner" : "/");
            await tools.send("Page.navigate", { url: origin + path });
            await loaded;
            await tools.evaluate(`
                while (document.readyState !== "complete" || !document.querySelector("[data-attendance-scanner]")) {
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                window.scannerModule = await import("/scanner-module.js");
                if (${Boolean(dayContext)}) {
                    window.dayUI = await import("/day-ui-module.js");
                    dayUI.initializeEventPage();
                    dayUI.initializeDataTables();
                    window.showDayTab = async (selector) => {
                        const tab = document.querySelector(selector);
                        if (!tab.classList.contains("active")) {
                            await new Promise((resolve) => {
                                tab.addEventListener("shown.bs.tab", resolve, { once: true });
                                tab.click();
                            });
                        }
                        const pane = document.querySelector(tab.getAttribute("href"));
                        const deadline = Date.now() + 5000;
                        while (!pane.classList.contains("show") || getComputedStyle(pane).opacity !== "1") {
                            if (Date.now() > deadline) { throw new Error("Tab content did not become visible"); }
                            await new Promise(requestAnimationFrame);
                        }
                    };
                }
                window.fixture = ${JSON.stringify(fixture)};
                window.FakeScanner = class {
                    static instances = 0; static active = 0; static maximumActive = 0;
                    static cameras = [{ id: "front", label: "Front camera" }, { id: "rear", label: "Back camera" }];
                    static async getCameras() { return this.cameras; }
                    constructor() { this.constructor.instances++; }
                    async start(camera, config, callback) {
                        await window.cameraGate;
                        this.device = typeof camera === "string" ? camera : "front";
                        this.callback = callback;
                        this.isScanning = true;
                        this.constructor.active++;
                        this.constructor.maximumActive = Math.max(this.constructor.maximumActive, this.constructor.active);
                    }
                    pause() {} resume() {} clear() {}
                    async stop() { this.constructor.active--; this.isScanning = false; }
                    getRunningTrackSettings() { return { deviceId: this.device }; }
                };
                window.scanner = new scannerModule.AttendanceScanner(document.querySelector("[data-attendance-scanner]"),
                    ${fake ? "{ Scanner: FakeScanner }" : "{}"});
                if (!${eventContext} && !${Boolean(dayContext)}) {
                    scanner.event.value = String(fixture.payload.event_id);
                    await scanner.changeEvent();
                    scanner.day.value = String(fixture.payload.event_day_id);
                    await scanner.changeContext();
                }
            `);
        }

        await suite.test("manual check-in debounces search, confirms by registration ID and retains event context", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                await import("/day-ui-module.js");
                const { initializeManualAttendance } = await import(${JSON.stringify(manualModule)});
                initializeManualAttendance();
                await scanner.start();
                const activeBefore = FakeScanner.active;
                const tab = document.getElementById("attendance-manual-tab");
                const shown = new Promise((resolve) => tab.addEventListener("shown.bs.tab", resolve, { once: true }));
                tab.click(); await shown;
                const event = document.getElementById("manual-event");
                const day = document.getElementById("manual-day");
                const search = document.getElementById("manual-search");
                const results = document.querySelector("[data-manual-results]");
                const confirm = document.querySelector("[data-manual-confirm]");
                const confirmation = document.querySelector("[data-manual-confirmation]");
                const set = (element, value) => { element.value = value; element.dispatchEvent(new Event("change", { bubbles: true })); };
                const waitUntil = async (check) => {
                    const limit = Date.now() + 5000;
                    while (!check()) {
                        if (Date.now() > limit) throw new Error(document.getElementById("manual-status").textContent);
                        await new Promise((resolve) => setTimeout(resolve, 20));
                    }
                };
                await waitUntil(() => FakeScanner.active === 0);
                set(event, fixture.payload.event_id); set(day, fixture.payload.event_day_id);
                document.querySelector('label[for="manual-external"]').click();
                const placeholder = search.placeholder;
                for (const term of ["B", "Bu", "Budi"]) {
                    search.value = term; search.dispatchEvent(new Event("input", { bubbles: true }));
                }
                await waitUntil(() => results.firstElementChild);
                const row = results.textContent;
                results.firstElementChild.click();
                const name = confirmation.querySelector("[data-manual-name]").textContent;
                let notification;
                window.notify = { success: (message) => { notification = message; } };
                confirm.click(); confirm.click();
                await waitUntil(() => confirmation.hidden && document.activeElement === search);
                return { activeBefore, placeholder, row, name, notification, value: search.value, event: event.value, day: day.value };
            `);
            const { event_id, event_day_id } = fixture.payload;
            assert.equal(result.activeBefore, 1);
            assert.match(result.placeholder, /nama atau nomor telepon/);
            assert.match(result.row, /External.*0812799999.*Belum check-in/);
            assert.equal(result.name, "Budi Guest");
            assert.equal(result.notification, "Check-in peserta berhasil.");
            assert.deepEqual([result.value, result.event, result.day], ["", String(event_id), String(event_day_id)]);
            assert.deepEqual(manualRequests, [
                { event_id: String(event_id), event_day_id: String(event_day_id), search: "Budi", participant_type: "external" },
                { checkIn: { event_id: String(event_id), event_day_id: String(event_day_id), registration_id: 73 }, hasCsrfToken: true },
            ]);
        });

        await suite.test("Event Day manual check-in uses locked selections and refreshes the attendance list", async () => {
            await fresh(true, false, true);
            const result = await tools.evaluate(`
                const { initializeManualAttendance } = await import(${JSON.stringify(manualModule)});
                initializeManualAttendance();
                await showDayTab("#day-scanner-tab"); await scanner.start();
                await showDayTab("#day-manual-tab"); await scanner.stopTask;
                const event = document.getElementById("manual-event");
                const day = document.getElementById("manual-day");
                const search = document.getElementById("manual-search");
                const results = document.getElementById("manual-results");
                const waitUntil = async (check) => {
                    const deadline = Date.now() + 5000;
                    while (!check()) {
                        if (Date.now() > deadline) throw new Error(document.getElementById("manual-status").textContent);
                        await new Promise((resolve) => setTimeout(resolve, 20));
                    }
                };
                document.querySelector('label[for="manual-external"]').click();
                search.focus(); search.value = "Budi";
                search.dispatchEvent(new Event("input", { bubbles: true }));
                await waitUntil(() => results.firstElementChild);
                search.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
                const optionFocused = document.activeElement === results.firstElementChild;
                document.activeElement.click();
                document.querySelector("[data-manual-confirm]").click();
                await waitUntil(() => document.querySelector("[data-attendance-table] tbody").textContent.includes(fixture.response.data.registration_number));
                return { event: event.value, day: day.value, locked: event.disabled && day.disabled, optionFocused,
                    activeCameras: FakeScanner.active, checkedIn: document.querySelector('[data-attendance-summary="checked_in"] .h1').textContent.trim() };
            `);
            assert.deepEqual(result, { event: String(fixture.payload.event_id), day: String(fixture.payload.event_day_id),
                locked: true, optionFocused: true, activeCameras: 0, checkedIn: "1" });
        });

        await suite.test("Event Day derives fixed context without selectors and tab changes release the virtual webcam", async () => {
            const errorCount = tools.errors.length;
            await fresh(false, false, true);
            const result = await tools.evaluate(`
                const initial = { event: scanner.eventId, day: scanner.dayId,
                    eventSelector: Boolean(scanner.event), daySelector: Boolean(scanner.day) };
                await scanner.start();
                const hiddenTabStartedCamera = scanner.running;
                await showDayTab("#day-scanner-tab");
                await scanner.start();
                if (scanner.state !== "scanning") { throw new Error(scanner.feedback.textContent); }
                const track = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                await scanner.validate(fixture.payload.qr_code);
                await showDayTab("#day-attendees-tab");
                await scanner.stopTask;
                const stopped = { track: track.readyState, running: scanner.running };
                await showDayTab("#day-scanner-tab");
                return { initial, hiddenTabStartedCamera, stopped, automaticallyRestarted: scanner.running,
                    hasConfirmCheckin: Array.from(document.querySelectorAll("button")).some((button) => /confirm.*check|konfirmasi.*check/i.test(button.textContent)) };
            `);
            assert.deepEqual(result.initial, { event: String(fixture.payload.event_id), day: String(fixture.payload.event_day_id), eventSelector: false, daySelector: false });
            assert.equal(result.hiddenTabStartedCamera, false);
            assert.deepEqual(result.stopped, { track: "ended", running: false });
            assert.equal(result.automaticallyRestarted, false);
            assert.equal(result.hasConfirmCheckin, true);
            assert.deepEqual(tools.errors.slice(errorCount), []);
        });

        await suite.test("Event Day rundown DataTables support search and remain initialized after tab changes", async () => {
            const errorCount = tools.errors.length;
            await fresh(true, false, true);
            const result = await tools.evaluate(`
                const table = document.querySelector("[data-datatable]");
                const initialized = dayUI.DataTable.isDataTable(table);
                const grid = new dayUI.DataTable(table);
                const allRows = grid.rows().count();
                grid.search("Morning Briefing").draw();
                const filteredRows = grid.rows({ search: "applied" }).count();
                grid.search("").order([0, "desc"]).draw();
                await showDayTab("#day-attendees-tab");
                await showDayTab("#day-rundown-tab");
                return { initialized, allRows, filteredRows, stillInitialized: dayUI.DataTable.isDataTable(table),
                    attendanceRows: new dayUI.DataTable(document.querySelector("[data-attendance-table]")).rows().count() };
            `);
            assert.deepEqual(result, { initialized: true, allRows: 2, filteredRows: 1, stillInitialized: true, attendanceRows: 0 });
            assert.deepEqual(tools.errors.slice(errorCount), []);
        });

        await suite.test("Event Day schedule delete opens the existing authorized confirmation flow", async () => {
            await fresh(true, false, "admin");
            const result = await tools.evaluate(`
                const button = document.querySelector('[data-bs-target="#deleteScheduleModal"]');
                await new Promise((resolve) => {
                    document.querySelector("#deleteScheduleModal").addEventListener("shown.bs.modal", resolve, { once: true });
                    button.click();
                });
                return { visible: document.querySelector("#deleteScheduleModal").classList.contains("show"),
                    action: document.querySelector("#deleteScheduleForm").action,
                    expectedAction: button.dataset.deleteUrl, name: document.querySelector("#deleteScheduleName").textContent };
            `);
            assert.equal(result.visible, true);
            assert.equal(result.action, result.expectedAction);
            assert.equal(result.name, "Morning Briefing");
        });

        await suite.test("authorized rundown tables search editable rows without duplicating forms or hiding the add form", async () => {
            await fresh(true, false, "admin");
            const result = await tools.evaluate(`
                const table = document.querySelector('table[id^="day-schedules-"]');
                const grid = new dayUI.DataTable(table);
                const settings = grid.settings()[0];
                dayUI.initializeDataTables();
                grid.search("Afternoon Workshop").draw();
                const row = grid.rows({ search: "applied" }).nodes()[0];
                const title = row.querySelector('input[name="title"]');
                const form = document.getElementById(title.getAttribute("form"));
                const addForm = document.querySelector('form[id^="schedule-new-"]');
                return { rows: grid.rows().count(), filtered: grid.rows({ search: "applied" }).count(),
                    title: title.value, belongsToForm: Array.from(form.elements).includes(title),
                    oneInstance: settings === new dayUI.DataTable(table).settings()[0],
                    formCopies: document.querySelectorAll('#' + form.id).length,
                    addVisible: Boolean(addForm) && !table.contains(addForm) && addForm.getBoundingClientRect().height > 0 };
            `);
            assert.deepEqual(result, { rows: 2, filtered: 1, title: "Afternoon Workshop", belongsToForm: true,
                oneInstance: true, formCopies: 1, addVisible: true });
        });

        await suite.test("rundown pagination opens the submitted row when backend validation fails", async () => {
            await fresh(true, false, "admin");
            const result = await tools.evaluate(`
                const table = document.querySelector('table[id^="day-schedules-"]');
                new dayUI.DataTable(table).destroy();
                const original = table.querySelector("tbody tr");
                const originalId = original.querySelector("form").id;
                for (let index = 2; index < 12; index++) {
                    const row = original.cloneNode(true);
                    row.querySelectorAll("[id], [for], [form]").forEach((element) => {
                        ["id", "for", "form"].forEach((attribute) => {
                            if (element.hasAttribute(attribute)) {
                                element.setAttribute(attribute, element.getAttribute(attribute).replace(originalId, "schedule-fixture-" + index));
                            }
                        });
                    });
                    table.querySelector("tbody").append(row);
                }
                const invalidInput = table.querySelector("tbody tr:last-child input[name=title]");
                invalidInput.classList.add("is-invalid");
                invalidInput.value = "Unsaved activity";
                dayUI.initializeDataTables();
                const grid = new dayUI.DataTable(table);
                return { page: grid.page(), visible: table.contains(invalidInput), value: invalidInput.value,
                    formExists: Boolean(document.getElementById(invalidInput.getAttribute("form"))) };
            `);
            assert.deepEqual(result, { page: 1, visible: true, value: "Unsaved activity", formExists: true });
        });

        await suite.test("a check-in during attendance refresh queues current server totals and preserves table search and pagination", async () => {
            await fresh(true, false, true);
            const result = await tools.evaluate(`
                const table = document.querySelector("[data-attendance-table]");
                const grid = new dayUI.DataTable(table);
                const settings = grid.settings()[0];
                const attendees = Array.from({ length: 12 }, (_, index) => ({ ...fixture.attendance_response.attendees[0],
                    registration_number: "REG-" + index, participant_name: "Participant " + index }));
                const current = { ...fixture.attendance_response,
                    summary: { active_registrations: 12, checked_in: 12, not_checked_in: 0, percentage: 100 }, attendees };
                const json = (data) => new Response(JSON.stringify(data), { headers: { "content-type": "application/json" } });
                const original = window.fetch;
                window.fetch = async () => json(current);
                document.querySelector("[data-attendance-refresh]").click();
                const deadline = Date.now() + 5000;
                while (grid.rows().count() !== 12) {
                    if (Date.now() > deadline) { throw new Error("Initial attendance refresh failed"); }
                    await new Promise((resolve) => setTimeout(resolve, 10));
                }
                grid.search("Participant").page(1).draw(false);
                let calls = 0;
                window.fetch = () => {
                    calls++;
                    if (calls === 1) { return new Promise((resolve) => { window.finishStaleRefresh = resolve; }); }
                    return Promise.resolve(json(current));
                };
                document.querySelector("[data-attendance-refresh]").click();
                scanner.page.dispatchEvent(new CustomEvent("attendance:updated", { bubbles: true, detail: {
                    eventId: fixture.payload.event_id, dayId: fixture.payload.event_day_id, summary: current.summary,
                } }));
                finishStaleRefresh(json({ success: true, summary: { active_registrations: 12, checked_in: 0,
                    not_checked_in: 12, percentage: 0 }, attendees: [] }));
                while (document.querySelector("[data-attendance-refresh]").disabled) {
                    if (Date.now() > deadline) { throw new Error("Queued attendance refresh failed"); }
                    await new Promise((resolve) => setTimeout(resolve, 10));
                }
                window.fetch = original;
                return { calls, rows: grid.rows().count(), search: grid.search(), page: grid.page(),
                    checkedIn: document.querySelector('[data-attendance-summary="checked_in"] .h1').textContent,
                    notCheckedIn: document.querySelector('[data-attendance-summary="not_checked_in"] .h1').textContent,
                    percentage: document.querySelector('[data-attendance-summary="percentage"] .h1').textContent,
                    sameInstance: settings === new dayUI.DataTable(table).settings()[0] };
            `);
            assert.deepEqual(result, { calls: 2, rows: 12, search: "Participant", page: 1,
                checkedIn: "12", notCheckedIn: "0", percentage: "100%", sameInstance: true });
        });

        await suite.test("Event Detail entry preselects and locks the event while requiring an explicit day selection", async () => {
            await fresh(true, true);
            const result = await tools.evaluate(`
                const initial = {
                    event: scanner.event.value, eventDisabled: scanner.event.disabled,
                    eventOptions: scanner.event.options.length, day: scanner.day.value,
                    dayOptions: Array.from(scanner.day.options, (option) => option.value),
                    startDisabled: scanner.startButton.disabled, activeCameras: FakeScanner.active,
                    back: Array.from(document.querySelectorAll("a")).some((link) => link.textContent.includes("Kembali ke Event")),
                };
                scanner.day.value = String(fixture.payload.event_day_id);
                await scanner.changeContext();
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                await scanner.stop();
                const stillLocked = scanner.event.disabled;
                scanner.event.add(new Option("Forged event", "999999"));
                scanner.event.value = "999999";
                const tamperedSelectionAccepted = scanner.hasSelection();
                await scanner.changeEvent();
                return { initial, stillLocked, tamperedSelectionAccepted,
                    restoredEvent: scanner.event.value, restoredDay: scanner.day.value, startDisabled: scanner.startButton.disabled };
            `);
            assert.deepEqual(result.initial, {
                event: String(fixture.payload.event_id), eventDisabled: true, eventOptions: 1, day: "",
                dayOptions: ["", String(fixture.payload.event_day_id)], startDisabled: true, activeCameras: 0, back: true,
            });
            assert.equal(result.stillLocked, true);
            assert.equal(result.tamperedSelectionAccepted, false);
            assert.equal(result.restoredEvent, String(fixture.payload.event_id));
            assert.equal(result.restoredDay, "");
            assert.equal(result.startDisabled, true);
        });

        for (const context of ["generic", "event", "day"]) {
            await suite.test(`${context} scanner confirms once and refreshes persisted attendance`, async () => {
                attendanceRecorded = false;
                checkinRequests.length = 0;
                await fresh(true, context === "event", context === "day");
                const result = await tools.evaluate(`
                    if (${context === "day"}) { await showDayTab("#day-scanner-tab"); }
                    if (${context === "event"}) {
                        scanner.day.value = String(fixture.payload.event_day_id);
                        await scanner.changeContext();
                    }
                    const disabledBeforeValidation = scanner.confirmButton.disabled;
                    await scanner.confirmCheckin();
                    await scanner.start();
                    await scanner.validate(fixture.payload.qr_code);
                    const readyDeadline = Date.now() + 5000;
                    while (scanner.cameraTask || scanner.stopTask || scanner.confirmButton.disabled) {
                        if (Date.now() > readyDeadline) { throw new Error("Confirmation did not become ready"); }
                        await new Promise((resolve) => setTimeout(resolve, 30));
                    }
                    const validTitle = document.querySelector("[data-scanner-result-title]").textContent;
                    const enabledAfterValidation = !scanner.confirmButton.disabled;
                    const original = scanner.request;
                    window.checkinGate = new Promise((resolve) => { window.releaseCheckin = resolve; });
                    scanner.request = async (...arguments_) => {
                        if (String(arguments_[0]).includes("/attendance/check-in")) { await checkinGate; }
                        return original(...arguments_);
                    };
                    const first = scanner.confirmCheckin();
                    scanner.confirmButton.click();
                    await scanner.confirmCheckin();
                    const loading = { state: scanner.state, disabled: scanner.confirmButton.disabled,
                        successBeforeResponse: document.querySelector("[data-scanner-result-title]").textContent === "Check-in Berhasil" };
                    releaseCheckin();
                    await first;
                    let attendanceRows = null;
                    let checkedIn = null;
                    if (${context === "day"}) {
                        const grid = new dayUI.DataTable(document.querySelector("[data-attendance-table]"));
                        const deadline = Date.now() + 5000;
                        while (grid.rows().count() !== 1) {
                            if (Date.now() > deadline) { throw new Error("Attendance table did not refresh: " + JSON.stringify({
                                feedback: document.querySelector("[data-attendance-refresh-feedback]").outerHTML,
                                root: document.querySelector("[data-event-day-details]").dataset,
                                summary: document.querySelector('[data-attendance-summary="checked_in"] .h1').textContent,
                                scannerInPage: document.querySelector("[data-event-day-details]").contains(scanner.page),
                                state: scanner.state, title: document.querySelector("[data-scanner-result-title]").textContent,
                                pending: Boolean(scanner.pendingCheckin),
                            })); }
                            await new Promise((resolve) => setTimeout(resolve, 30));
                        }
                        attendanceRows = grid.rows().count();
                        checkedIn = document.querySelector('[data-attendance-summary="checked_in"] .h1').textContent;
                        grid.search(fixture.response.data.participant_name).draw();
                        if (grid.rows({ search: "applied" }).count() !== 1) { throw new Error("New attendee is not searchable"); }
                    }
                    const confirmed = { title: document.querySelector("[data-scanner-result-title]").textContent,
                        hidden: scanner.confirmButton.classList.contains("d-none"),
                        time: document.querySelector("[data-checkin-time]").textContent,
                        operator: document.querySelector("[data-checkin-operator]").textContent };
                    await scanner.stop();
                    return { disabledBeforeValidation, enabledAfterValidation, validTitle, loading, confirmed, attendanceRows, checkedIn };
                `);
                assert.equal(result.disabledBeforeValidation, true);
                assert.equal(result.enabledAfterValidation, true);
                assert.equal(result.validTitle, "Registrasi Valid");
                assert.deepEqual(result.loading, { state: "checking-in", disabled: true, successBeforeResponse: false });
                assert.equal(result.confirmed.title, "Check-in Berhasil");
                assert.equal(result.confirmed.hidden, true);
                assert.match(result.confirmed.time, /09:30:00.*Asia\/Jakarta/);
                assert.equal(result.confirmed.operator, fixture.checkin_response.data.operator_name);
                assert.equal(checkinRequests.length, 1);
                assert.deepEqual(checkinRequests[0].payload, fixture.payload);
                assert.ok(checkinRequests[0].headers["x-csrf-token"]);
                if (context === "day") {
                    assert.equal(result.attendanceRows, 1);
                    assert.equal(result.checkedIn, "1");
                }
            });
        }

        await suite.test("duplicate confirmation displays the original persisted check-in", async () => {
            attendanceRecorded = true;
            checkinRequests.length = 0;
            await fresh(true);
            const result = await tools.evaluate(`
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                return { title: document.querySelector("[data-scanner-result-title]").textContent,
                    time: document.querySelector("[data-checkin-time]").textContent,
                    message: document.querySelector("[data-scanner-result-message]").textContent,
                    autoResume: scanner.autoResumeTimer, paused: scanner.paused, againEnabled: !scanner.againButton.disabled };
            `);
            assert.equal(result.title, "Sudah Check-in");
            assert.match(result.message, /sudah check-in/);
            assert.match(result.time, /09:30:00/);
            assert.equal(checkinRequests.length, 1);
            assert.equal(result.autoResume, null);
            assert.equal(result.paused, true);
            assert.equal(result.againEnabled, true);
        });

        for (const context of ["generic", "event", "day"]) {
            await suite.test(`${context} scanner resumes automatically, suppresses the last QR and requires confirmation for the next`, async () => {
                await fresh(true, context === "event", context === "day");
                requests.length = 0;
                checkinRequests.length = 0;
                attendanceRecorded = false;
                const result = await tools.evaluate(`
                    if (${context === "day"}) { await showDayTab("#day-scanner-tab"); }
                    if (${context === "event"}) {
                        scanner.day.value = String(fixture.payload.event_day_id);
                        await scanner.changeContext();
                    }
                    scanner.autoResumeDelay = 60;
                    await scanner.start();
                    await Promise.all(Array.from({ length: 12 }, () => scanner.validate(fixture.payload.qr_code)));
                    const beforeConfirm = scanner.state;
                    await Promise.all(Array.from({ length: 12 }, () => scanner.confirmCheckin()));
                    const successTitle = document.querySelector("[data-scanner-result-title]").textContent;
                    const deadline = Date.now() + 5000;
                    while (scanner.state !== "scanning") {
                        if (Date.now() > deadline) { throw new Error("Scanner did not automatically resume: " + scanner.feedback.textContent); }
                        await new Promise((resolve) => setTimeout(resolve, 10));
                    }
                    const resumed = { running: scanner.running, paused: scanner.paused, hidden: scanner.result.classList.contains("d-none") };
                    await Promise.all(Array.from({ length: 12 }, () => scanner.scanner.callback(fixture.payload.qr_code.toUpperCase())));
                    const afterRepeated = scanner.state;
                    await scanner.scanner.callback(fixture.regression_payload);
                    const next = { state: scanner.state, confirmEnabled: !scanner.confirmButton.disabled,
                        qr: scanner.pendingCheckin?.qr_code };
                    const instances = FakeScanner.instances;
                    const maximum = FakeScanner.maximumActive;
                    await scanner.stop();
                    return { beforeConfirm, successTitle, resumed, afterRepeated, next, instances, maximum };
                `);
                assert.equal(result.beforeConfirm, "result");
                assert.equal(result.successTitle, "Check-in Berhasil");
                assert.deepEqual(result.resumed, { running: true, paused: false, hidden: true });
                assert.equal(result.afterRepeated, "scanning");
                assert.deepEqual(result.next, { state: "result", confirmEnabled: true, qr: fixture.regression_payload });
                assert.equal(result.instances, 1);
                assert.equal(result.maximum, 1);
                assert.equal(requests.length, 2);
                assert.equal(checkinRequests.length, 1);
            });
        }

        await suite.test("suppression expires and a deliberate rescan shows the original check-in", async () => {
            await fresh(true);
            requests.length = 0;
            checkinRequests.length = 0;
            attendanceRecorded = false;
            const result = await tools.evaluate(`
                scanner.autoResumeDelay = 20;
                scanner.duplicateSuppressionMs = 80;
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                const deadline = Date.now() + 5000;
                while (scanner.state !== "scanning") {
                    if (Date.now() > deadline) { throw new Error("No automatic resume"); }
                    await new Promise((resolve) => setTimeout(resolve, 10));
                }
                while (Date.now() <= scanner.suppressedPayload.expiresAt) {
                    await new Promise((resolve) => setTimeout(resolve, 10));
                }
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                return { title: document.querySelector("[data-scanner-result-title]").textContent,
                    time: document.querySelector("[data-checkin-time]").textContent,
                    automatic: scanner.autoResumeTimer, continueEnabled: !scanner.againButton.disabled };
            `);
            assert.equal(requests.length, 2);
            assert.equal(checkinRequests.length, 2);
            assert.equal(result.title, "Sudah Check-in");
            assert.match(result.time, /09:30:00/);
            assert.equal(result.automatic, null);
            assert.equal(result.continueEnabled, true);
        });

        await suite.test("manual Stop during cooldown cancels automatic resume", async () => {
            await fresh(true);
            attendanceRecorded = false;
            const result = await tools.evaluate(`
                scanner.autoResumeDelay = 80;
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                const cooldown = scanner.state;
                scanner.stopButton.click();
                await scanner.stopTask;
                await new Promise((resolve) => setTimeout(resolve, 120));
                return { cooldown, running: scanner.running, active: FakeScanner.active,
                    timer: scanner.autoResumeTimer, stopped: scanner.stopRequested };
            `);
            assert.deepEqual(result, { cooldown: "cooldown", running: false, active: 0, timer: null, stopped: true });
        });

        await suite.test("manual Stop ignores delayed check-in success and never restarts the camera", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                scanner.autoResumeDelay = 20;
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                scanner.request = () => new Promise((resolve) => { window.finishCheckin = resolve; });
                const pending = scanner.confirmCheckin();
                const stopEnabled = !scanner.stopButton.disabled;
                scanner.stopButton.click();
                await scanner.stopTask;
                finishCheckin(new Response(JSON.stringify(fixture.checkin_response), { headers: { "content-type": "application/json" } }));
                await pending;
                await new Promise((resolve) => setTimeout(resolve, 60));
                return { stopEnabled, running: scanner.running, timer: scanner.autoResumeTimer,
                    active: FakeScanner.active, title: document.querySelector("[data-scanner-result-title]").textContent };
            `);
            assert.equal(result.stopEnabled, true);
            assert.equal(result.running, false);
            assert.equal(result.timer, null);
            assert.equal(result.active, 0);
            assert.notEqual(result.title, "Check-in Berhasil");
        });

        await suite.test("leaving the scanner tab during success cancels its timer and releases the same camera track", async () => {
            await fresh(false, false, true);
            attendanceRecorded = false;
            const result = await tools.evaluate(`
                scanner.autoResumeDelay = 1200;
                await showDayTab("#day-scanner-tab");
                await scanner.start();
                const track = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                await showDayTab("#day-rundown-tab");
                await scanner.stopTask;
                await showDayTab("#day-scanner-tab");
                return { running: scanner.running, timer: scanner.autoResumeTimer, track: track.readyState };
            `);
            assert.deepEqual(result, { running: false, timer: null, track: "ended" });
        });

        await suite.test("automatic resume keeps the real html5-qrcode stream and scanner instance", async () => {
            await fresh();
            attendanceRecorded = false;
            const result = await tools.evaluate(`
                scanner.autoResumeDelay = 40;
                await scanner.start();
                const instance = scanner.scanner;
                const track = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                await scanner.validate(fixture.payload.qr_code);
                await scanner.confirmCheckin();
                const deadline = Date.now() + 5000;
                while (scanner.state !== "scanning") {
                    if (Date.now() > deadline) { throw new Error("Scanner did not resume"); }
                    await new Promise((resolve) => setTimeout(resolve, 10));
                }
                const sameInstance = instance === scanner.scanner;
                const sameTrack = track === document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                const activeTrack = track.readyState;
                await scanner.stop();
                return { sameInstance, sameTrack, activeTrack, ended: track.readyState };
            `);
            assert.deepEqual(result, { sameInstance: true, sameTrack: true, activeTrack: "live", ended: "ended" });
        });

        await suite.test("camera disconnection stops scanning with explicit recovery feedback", async () => {
            await fresh();
            const result = await tools.evaluate(`
                await scanner.start();
                const track = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                track.dispatchEvent(new Event("ended"));
                await scanner.stopTask;
                return { running: scanner.running, ended: track.readyState, message: scanner.feedback.textContent };
            `);
            assert.equal(result.running, false);
            assert.equal(result.ended, "ended");
            assert.match(result.message, /Koneksi kamera terputus/);
        });

        await suite.test("network, validation and incomplete responses never claim check-in success", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                scanner.request = async () => { throw new TypeError("Network failed"); };
                await scanner.confirmCheckin();
                const network = { title: document.querySelector("[data-scanner-result-title]").textContent,
                    canRetry: !scanner.confirmButton.disabled, participant: document.querySelector('[data-result-field="participant_name"]').textContent };
                scanner.request = async () => new Response(JSON.stringify({ success: true, data: {} }), { headers: { "content-type": "application/json" } });
                await scanner.confirmCheckin();
                const incomplete = document.querySelector("[data-scanner-result-title]").textContent;
                scanner.request = async () => new Response(JSON.stringify({ success: false, message: "Tanggal check-in tidak sesuai." }),
                    { status: 422, headers: { "content-type": "application/json" } });
                await scanner.confirmCheckin();
                return { network, incomplete, rejected: { title: document.querySelector("[data-scanner-result-title]").textContent,
                    disabled: scanner.confirmButton.disabled, hidden: scanner.confirmButton.classList.contains("d-none"),
                    detailsHidden: document.querySelector("[data-scanner-checkin-details]").classList.contains("d-none") } };
            `);
            assert.equal(result.network.title, "Status Check-in Belum Diketahui");
            assert.equal(result.network.canRetry, true);
            assert.equal(result.network.participant, fixture.response.data.participant_name);
            assert.equal(result.incomplete, "Check-in Belum Berhasil");
            assert.deepEqual(result.rejected, { title: "Check-in Belum Berhasil", disabled: true, hidden: true, detailsHidden: true });
        });

        await suite.test("generated registration PNG decodes to the exact raw RA1 token using html5-qrcode", async () => {
            await fresh();
            const decoded = await tools.evaluate(`
                const blob = await (await fetch("/registration.png")).blob();
                return await scanner.scanner.scanFile(new File([blob], "registration.png", { type: "image/png" }), false);
            `);
            assert.equal(decoded, fixture.payload.qr_code);
            assert.equal(decoded, `RA1:${fixture.payload.qr_code.slice(4)}`);
        });

        await suite.test("the PNG decoding regression payload remains readable by html5-qrcode", async () => {
            await fresh();
            const decoded = await tools.evaluate(`
                const blob = await (await fetch("/registration-regression.png")).blob();
                return await scanner.scanner.scanFile(new File([blob], "registration-regression.png", { type: "image/png" }), false);
            `);
            assert.equal(decoded, fixture.regression_payload);
        });

        await suite.test("generated PNGs decode across payload patterns including previous failures", async () => {
            await fresh();
            const decoded = await tools.evaluate(`
                const results = [];
                for (let index = 0; index < fixture.pattern_payloads.length; index++) {
                    const name = "registration-pattern-" + index + ".png";
                    const blob = await (await fetch("/" + name)).blob();
                    try {
                        results.push(await scanner.scanner.scanFile(new File([blob], name, { type: "image/png" }), false));
                    } catch (error) { throw new Error("Pattern " + index + " could not be decoded: " + error); }
                }
                return results;
            `);
            assert.deepEqual(decoded, fixture.pattern_payloads);
        });

        await suite.test("virtual webcam starts, pauses for one request, resumes explicitly and releases its track", async () => {
            await fresh();
            requests.length = 0;
            const result = await tools.evaluate(`
                const heightBefore = document.querySelector(".attendance-camera-stage").getBoundingClientRect().height;
                const originalStart = scanner.scanner.start.bind(scanner.scanner);
                scanner.scanner.start = async (...arguments_) => {
                    try { return await originalStart(...arguments_); }
                    catch (error) { window.cameraFailure = { name: error?.name, message: error?.message, detail: String(error) }; throw error; }
                };
                const originalPause = scanner.scanner.pause.bind(scanner.scanner);
                scanner.scanner.pause = (...arguments_) => {
                    try { return originalPause(...arguments_); }
                    catch (error) { window.cameraFailure = { operation: "pause", detail: String(error) }; throw error; }
                };
                const originalRequest = scanner.request;
                scanner.request = async (...arguments_) => {
                    try { return await originalRequest(...arguments_); }
                    catch (error) { window.cameraFailure = { operation: "request", detail: String(error) }; throw error; }
                };
                await scanner.start();
                if (scanner.state !== "scanning") { throw new Error(JSON.stringify(window.cameraFailure) || scanner.feedback.textContent); }
                window.cameraTrack = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                const heightAfter = document.querySelector(".attendance-camera-stage").getBoundingClientRect().height;
                await Promise.all(Array.from({ length: 10 }, () => scanner.validate(fixture.payload.qr_code)));
                const stateAfterScan = scanner.state;
                const participant = document.querySelector('[data-result-field="participant_name"]').textContent;
                await scanner.restart();
                const restartKeptPaused = scanner.awaitingScanAgain && scanner.paused;
                await scanner.validate(fixture.payload.qr_code);
                await scanner.scanAgain();
                await scanner.validate(fixture.payload.qr_code);
                await scanner.stop();
                return { stateAfterScan, participant, heightBefore, heightAfter, restartKeptPaused,
                    trackEnded: cameraTrack.readyState, running: scanner.running, failure: window.cameraFailure, message: scanner.feedback.textContent };
            `);
            assert.equal(result.stateAfterScan, "result", JSON.stringify(result));
            assert.equal(result.participant, fixture.response.data.participant_name);
            assert.equal(result.heightBefore, result.heightAfter);
            assert.equal(result.restartKeptPaused, true);
            assert.equal(result.trackEnded, "ended");
            assert.equal(result.running, false);
            assert.equal(requests.length, 2);
            requests.forEach(({ payload, headers }) => {
                assert.deepEqual(payload, fixture.payload);
                assert.ok(headers["x-csrf-token"]);
                assert.equal(headers.accept, "application/json");
            });
        });

        await suite.test("page exit releases the virtual camera without an automatic restart", async () => {
            await fresh();
            const result = await tools.evaluate(`
                await scanner.start();
                const originalStop = scanner.scanner.stop.bind(scanner.scanner);
                scanner.scanner.stop = async () => {
                    try { return await originalStop(); }
                    catch (error) { window.stopFailure = String(error); throw error; }
                };
                const track = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                window.dispatchEvent(new PageTransitionEvent("pagehide"));
                await scanner.stopTask;
                return { state: scanner.state, track: track.readyState, running: scanner.running, failure: window.stopFailure };
            `);
            assert.equal(result.state, "idle", JSON.stringify(result));
            assert.equal(result.track, "ended");
            assert.equal(result.running, false);
        });

        await suite.test("orientation changes rebuild the camera viewport while keeping a completed result paused", async () => {
            await fresh();
            await tools.evaluate(`
                await scanner.start();
                window.previousTrack = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
            `);
            await tools.send("Emulation.setDeviceMetricsOverride", { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            const portrait = await tools.evaluate(`
                const deadline = Date.now() + 10000;
                while (previousTrack.readyState !== "ended" || scanner.state !== "scanning" || scanner.cameraTask || scanner.stopTask) {
                    if (Date.now() > deadline) { throw new Error("Camera did not restart after resizing"); }
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                await scanner.validate(fixture.payload.qr_code);
                window.previousTrack = document.querySelector("#qr-reader video").srcObject.getVideoTracks()[0];
                return { state: scanner.state, paused: scanner.paused };
            `);
            assert.deepEqual(portrait, { state: "result", paused: true });
            await tools.send("Emulation.setDeviceMetricsOverride", { width: 844, height: 390, deviceScaleFactor: 1, mobile: true });
            const landscape = await tools.evaluate(`
                const deadline = Date.now() + 10000;
                while (previousTrack.readyState !== "ended" || scanner.state !== "result" || scanner.cameraTask || scanner.stopTask) {
                    if (Date.now() > deadline) { throw new Error("Camera did not restore its paused result after resizing"); }
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                const result = { state: scanner.state, paused: scanner.paused, awaiting: scanner.awaitingScanAgain };
                await scanner.stop();
                return result;
            `);
            assert.deepEqual(landscape, { state: "result", paused: true, awaiting: true });
            await tools.send("Emulation.clearDeviceMetricsOverride");
        });

        await suite.test("rapid starts and stopping during initialization keep a single camera instance", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                window.cameraGate = new Promise((resolve) => { window.finishCameraStart = resolve; });
                const first = scanner.start();
                const second = scanner.start();
                await new Promise((resolve) => setTimeout(resolve, 0));
                const stop = scanner.stop();
                finishCameraStart();
                await Promise.all([first, second, stop]);
                return { sameTask: first === second, instances: FakeScanner.instances,
                    active: FakeScanner.active, maximum: FakeScanner.maximumActive, state: scanner.state };
            `);
            assert.deepEqual(result, { sameTask: true, instances: 1, active: 0, maximum: 1, state: "idle" });
        });

        await suite.test("switching cameras stops the previous camera and keeps a result paused", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                await scanner.start();
                const preferredRear = scanner.scanner.device;
                scanner.camera.value = "front";
                await scanner.restart();
                const selectedFront = scanner.scanner.device;
                await scanner.validate(fixture.payload.qr_code);
                scanner.camera.value = "rear";
                await scanner.restart();
                const paused = scanner.awaitingScanAgain && scanner.paused;
                await scanner.stop();
                return { preferredRear, selectedFront, paused, maximum: FakeScanner.maximumActive, instances: FakeScanner.instances };
            `);
            assert.deepEqual(result, { preferredRear: "rear", selectedFront: "front", paused: true, maximum: 1, instances: 1 });
        });

        await suite.test("stale validation after changing context cannot show a participant or resume scanning", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                scanner.request = () => new Promise((resolve) => { window.finishValidation = resolve; });
                await scanner.start();
                const pending = scanner.validate(fixture.payload.qr_code);
                await scanner.changeContext();
                finishValidation(new Response(JSON.stringify(fixture.response), { headers: { "content-type": "application/json" } }));
                await pending;
                return { state: scanner.state, running: scanner.running, hidden: scanner.result.classList.contains("d-none"),
                    participant: document.querySelector('[data-result-field="participant_name"]').textContent };
            `);
            assert.deepEqual(result, { state: "idle", running: false, hidden: true, participant: "" });
        });

        await suite.test("stopping a pending validation leaves an explicit Scan Again recovery", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                scanner.request = () => new Promise((resolve) => { window.finishValidation = resolve; });
                await scanner.start();
                const pending = scanner.validate(fixture.payload.qr_code);
                await scanner.stop();
                finishValidation(new Response(JSON.stringify(fixture.response), { headers: { "content-type": "application/json" } }));
                await pending;
                return { state: scanner.state, hidden: scanner.result.classList.contains("d-none"), againDisabled: scanner.againButton.disabled };
            `);
            assert.deepEqual(result, { state: "result", hidden: false, againDisabled: false });
        });

        await suite.test("network and authorization errors do not auto-resume or expose a stale identity", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                scanner.request = async () => { throw new TypeError("Network failed"); };
                await scanner.start();
                await scanner.validate(fixture.payload.qr_code);
                const network = { state: scanner.state, awaiting: scanner.awaitingScanAgain, paused: scanner.paused };
                await scanner.scanAgain();
                scanner.request = async () => new Response(JSON.stringify({ success: false, message: "Access denied" }),
                    { status: 403, headers: { "content-type": "application/json" } });
                await scanner.validate(fixture.payload.qr_code);
                return { network, denied: scanner.accessDenied, running: scanner.running, againDisabled: scanner.againButton.disabled,
                    participant: document.querySelector('[data-result-field="participant_name"]').textContent };
            `);
            assert.deepEqual(result, { network: { state: "error", awaiting: true, paused: true }, denied: true, running: false, againDisabled: true, participant: "" });
        });

        await suite.test("camera failures and insecure origins have explicit retry feedback", async () => {
            await fresh(true);
            const result = await tools.evaluate(`
                FakeScanner.getCameras = async () => { throw new DOMException("Permission denied", "NotAllowedError"); };
                await scanner.start();
                const permission = scanner.feedback.textContent;
                FakeScanner.getCameras = async () => [];
                await scanner.start();
                const missing = scanner.feedback.textContent;
                Object.defineProperty(window, "isSecureContext", { value: false, configurable: true });
                await scanner.start();
                return { permission, missing, insecure: scanner.feedback.textContent, running: scanner.running };
            `);
            assert.match(result.permission, /Izin kamera ditolak/);
            assert.match(result.missing, /Kamera tidak tersedia/);
            assert.match(result.insecure, /HTTPS atau localhost/);
            assert.equal(result.running, false);
        });

        for (const [width, height] of [[375, 812], [768, 1024], [1440, 900], [844, 390]]) {
            await suite.test(`scanner fits ${width}x${height} without overflow or overlapping controls`, async () => {
                await tools.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh(true);
                const result = await tools.evaluate(`
                    scanner.showResult(true, fixture.response.message, { ...fixture.response.data,
                        participant_name: "Participant ".repeat(30), registration_number: "REG-" + "A".repeat(100) });
                    const bounds = Array.from(document.querySelectorAll(".attendance-scanner-controls button"),
                        (button) => { const r = button.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, height: r.height }; });
                    return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
                        preview: document.querySelector(".attendance-camera-stage").getBoundingClientRect().width, bounds };
                `);
                assert.ok(result.scrollWidth <= result.width, JSON.stringify(result));
                assert.ok(result.preview <= result.width);
                for (const bounds of result.bounds) {
                    assert.ok(bounds.left >= 0 && bounds.right <= result.width);
                    assert.ok(bounds.height >= 44);
                }
                for (let a = 0; a < result.bounds.length; a++) {
                    for (let b = a + 1; b < result.bounds.length; b++) {
                        const first = result.bounds[a]; const second = result.bounds[b];
                        assert.ok(first.right <= second.left || second.right <= first.left
                            || first.bottom <= second.top || second.bottom <= first.top);
                    }
                }
                const screenshot = await tools.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `scanner-${width}x${height}.png`), Buffer.from(screenshot.data, "base64"));
                await fresh(true, true);
                const eventLayout = await tools.evaluate(`
                    const back = Array.from(document.querySelectorAll("a")).find((link) => link.textContent.includes("Kembali ke Event"));
                    const bounds = back.getBoundingClientRect();
                    return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, left: bounds.left, right: bounds.right,
                        eventDisabled: scanner.event.disabled, dayRequired: scanner.day.required, day: scanner.day.value };
                `);
                assert.ok(eventLayout.scrollWidth <= eventLayout.width, JSON.stringify(eventLayout));
                assert.ok(eventLayout.left >= 0 && eventLayout.right <= eventLayout.width);
                assert.equal(eventLayout.eventDisabled, true);
                assert.equal(eventLayout.dayRequired, true);
                assert.equal(eventLayout.day, "");
                const eventScreenshot = await tools.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `event-scanner-${width}x${height}.png`), Buffer.from(eventScreenshot.data, "base64"));
                await fresh(true, false, true);
                const dayLayout = await tools.evaluate(`
                    await showDayTab("#day-scanner-tab");
                    const pane = document.querySelector("#day-scanner");
                    return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
                        eventSelector: Boolean(scanner.event), daySelector: Boolean(scanner.day),
                        visible: getComputedStyle(pane).opacity === "1", classes: pane.className };
                `);
                assert.ok(dayLayout.scrollWidth <= dayLayout.width, JSON.stringify(dayLayout));
                assert.equal(dayLayout.eventSelector, false);
                assert.equal(dayLayout.daySelector, false);
                assert.equal(dayLayout.visible, true, JSON.stringify(dayLayout));
                const dayScreenshot = await tools.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `day-scanner-${width}x${height}.png`), Buffer.from(dayScreenshot.data, "base64"));
            });
        }
    } finally {
        if (tools) {
            await tools.send("Page.close").catch(() => {});
            tools.socket.close();
        }
        if (processHandle) { processHandle.kill(); }
        if (server) { await new Promise((resolve) => server.close(resolve)); }
        await delay(300);
        const profile = resolve(artifacts, "browser-profile");
        if (profile.startsWith(resolve(artifacts) + sep)) {
            await rm(profile, { recursive: true, force: true, maxRetries: 3 }).catch(() => {});
        }
    }
});
