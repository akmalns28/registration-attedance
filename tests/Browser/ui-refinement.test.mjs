import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, mkdir, readFile, writeFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { resolve, join, extname, sep } from "node:path";
import { promisify } from "node:util";
import test from "node:test";
import JSZip from "jszip";
import { browser, launchBrowser } from "./browser-helpers.mjs";

function csvRows(content) {
    const rows = [];
    let row = [], field = "", quoted = false;
    const text = content.replace(/^\uFEFF/, "");
    for (let index = 0; index < text.length; index++) {
        const character = text[index];
        if (character === '"') {
            if (quoted && text[index + 1] === '"') { field += '"'; index++; }
            else { quoted = !quoted; }
        } else if (character === "," && !quoted) {
            row.push(field); field = "";
        } else if (["\n", "\r"].includes(character) && !quoted) {
            if (character === "\r" && text[index + 1] === "\n") { index++; }
            row.push(field); rows.push(row); row = []; field = "";
        } else { field += character; }
    }
    if (field || row.length) { row.push(field); rows.push(row); }
    return rows;
}

test("Sprint 4R with production Vite assets", { skip: !browser, timeout: 240000 }, async (suite) => {
    const artifacts = await mkdtemp(join(tmpdir(), "ui-refinement-browser-"));
    suite.diagnostic(`Browser artifacts: ${artifacts}`);
    let server, client, processHandle;
    const candidateRequests = [];
    try {
        const generated = await promisify(execFile)("php", ["vendor/bin/phpunit", "tests/Feature/UiRefinementTest.php",
            "--filter=test_browser_fixtures_cover_management_tables_and_navigation"], {
            cwd: process.cwd(), windowsHide: true, timeout: 30000, env: { ...process.env, UI_BROWSER_ARTIFACT_DIR: artifacts },
        });
        assert.match(generated.stdout, /passed/);
        const fixture = JSON.parse(await readFile(join(artifacts, "fixture.json"), "utf8"));
        const galleryPhoto = await readFile(join(artifacts, "gallery-photo.png"));
        const galleryClip = await readFile(new URL("./event-gallery-clip.webm", import.meta.url));
        const pages = new Map();
        for (const [key, url] of Object.entries(fixture.pages)) {
            pages.set(new URL(url).pathname, await readFile(join(artifacts, `${key}.html`), "utf8"));
        }
        let origin;
        server = createServer(async (request, response) => {
            try {
                const url = new URL(request.url, "http://localhost");
                if (pages.has(url.pathname)) {
                    response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(pages.get(url.pathname).replaceAll(fixture.origin, origin));
                    return;
                }
                if ([fixture.media.poster, fixture.media.photo].some((path) => new URL(path).pathname === url.pathname)) {
                    response.writeHead(200, { "Content-Type": "image/png" }).end(galleryPhoto);
                    return;
                }
                if (url.pathname === new URL(fixture.media.clip).pathname) {
                    if (request.headers.range === "bytes=0-") {
                        response.writeHead(206, { "Content-Type": "video/webm", "Content-Length": galleryClip.length,
                            "Content-Range": `bytes 0-${galleryClip.length - 1}/${galleryClip.length}` }).end(galleryClip);
                    } else {
                        response.writeHead(200, { "Content-Type": "video/webm", "Content-Length": galleryClip.length }).end(galleryClip);
                    }
                    return;
                }
                if (url.pathname.endsWith("/participants/candidates")) {
                    candidateRequests.push(Object.fromEntries(url.searchParams));
                    const type = url.searchParams.get("type");
                    const page = Number(url.searchParams.get("page"));
                    const search = url.searchParams.get("search").toLowerCase();
                    const candidates = type === "external"
                        ? [{ id: 1, name: "External Guest", identifier: "0812345", affiliation: "Partner", status: null }]
                        : Array.from({ length: url.searchParams.has("department_id") ? 2 : 17 }, (_, index) => ({
                            id: index + 1, name: `Employee ${String(index + 1).padStart(2, "0")}`, identifier: `NIP-${index + 1}`,
                            affiliation: "Operations", status: index === 16 ? "registered" : null,
                        }));
                    const filtered = candidates.filter(({ name, identifier }) => name.toLowerCase().includes(search) || identifier.toLowerCase().includes(search));
                    response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify({
                        data: filtered.slice((page - 1) * 15, page * 15), page, last_page: Math.max(1, Math.ceil(filtered.length / 15)),
                        total: filtered.length, selectable_ids: filtered.filter(({ status }) => status !== "registered").map(({ id }) => id),
                    }));
                    return;
                }
                if (url.pathname.endsWith("/qr")) { response.writeHead(204).end(); return; }
                const publicRoot = resolve("public");
                const file = resolve(publicRoot, `.${url.pathname}`);
                if (!file.startsWith(publicRoot + sep)) { response.writeHead(404).end(); return; }
                const content = await readFile(file);
                response.writeHead(200, { "Content-Type": ({ ".css": "text/css", ".js": "text/javascript", ".woff2": "font/woff2", ".woff": "font/woff" })[extname(file)] || "application/octet-stream" }).end(content);
            } catch { if (!response.headersSent) { response.writeHead(404); } response.end(); }
        });
        server.listen(0, "127.0.0.1");
        await once(server, "listening");
        origin = `http://127.0.0.1:${server.address().port}`;
        ({ client, processHandle } = await launchBrowser(join(artifacts, "profile")));

        async function fresh(page, hash = "") {
            let loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: "about:blank" }); await loaded;
            loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: origin + new URL(fixture.pages[page]).pathname + hash }); await loaded;
            await client.evaluate(`
                window.until = async (predicate, message = "UI did not become ready") => {
                    const deadline = Date.now() + 12000;
                    while (!predicate()) { if (Date.now() > deadline) { throw new Error(message); } await new Promise((resolve) => setTimeout(resolve, 20)); }
                };
                await until(() => window.notify?.system === "attendance" && (!${["events", "employees", "external", "users", "roles", "event", "day", "organizations"].includes(page)} || document.querySelector(".dt-container")));
                if (!document.querySelector('script[type="module"][src*="/build/"]') || document.querySelector('script[src*="@vite/client"]')) {
                    throw new Error("UI verification must use production Vite assets");
                }
                const breadcrumbs = document.querySelectorAll('nav[aria-label="breadcrumb"]');
                if (breadcrumbs.length !== 1 || !breadcrumbs[0].querySelector('li[aria-current="page"]:not(:has(a))')
                    || document.querySelectorAll('footer a[href="#contactUsModal"]').length !== 1) {
                    throw new Error("Shared breadcrumbs and footer did not render correctly");
                }
            `);
        }
        async function downloadDirectory(page, suffix = "all") {
            const directory = join(artifacts, page, suffix);
            await mkdir(directory, { recursive: true });
            await client.send("Browser.setDownloadBehavior", { behavior: "allow", downloadPath: directory });
            return directory;
        }
        async function exported(format, directory, filename) {
            await client.evaluate(`
                const container = document.querySelector("${filename === "event-participants" ? "#eventParticipantsTable_wrapper" : ".dt-container"}");
                const main = container.querySelector(".buttons-collection"); main.scrollIntoView({ block: "center" }); main.click();
                await until(() => document.querySelector(".buttons-${format} a, a.buttons-${format}, button.buttons-${format}"));
                const option = document.querySelector(".buttons-${format} a, a.buttons-${format}, button.buttons-${format}");
                const rect = option.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0 || getComputedStyle(option).visibility === "hidden") { throw new Error("Export action is not visible"); }
                option.click();
            `);
            const extension = format === "excel" ? "xlsx" : format;
            const path = join(directory, `${filename}.${extension}`);
            const deadline = Date.now() + 15000;
            while (true) {
                try { return await readFile(path); }
                catch (error) { if (Date.now() > deadline) { throw error; } await new Promise((resolve) => setTimeout(resolve, 40)); }
            }
        }

        await suite.test("compact table criteria compose with search and preserve filtered exports", async () => {
            await fresh("employees");
            const matching = await client.evaluate(`
                const find = (selector) => document.querySelector("#employeesTable_wrapper " + selector);
                const search = find('input[type="search"]');
                search.value = "Participant"; search.dispatchEvent(new Event("input", { bubbles: true }));
                find("[data-filter-toggle]").click();
                const entity = find("#employees-entity");
                entity.value = entity.options[1].value; entity.dispatchEvent(new Event("change", { bubbles: true }));
                const department = find("#employees-department");
                department.value = [...department.options].find((option) => option.textContent.includes("Participant Nine")).value;
                department.dispatchEvent(new Event("change", { bubbles: true }));
                await until(() => find(".dt-info").textContent.includes("dari 1 data"));
                find("[data-filter-close]").click();
                return { text: find("tbody").textContent, chips: find("[data-filter-chips]").children.length, search: search.value };
            `);
            assert.match(matching.text, /Participant Nine/);
            assert.doesNotMatch(matching.text, /Participant Ten/);
            assert.equal(matching.chips, 2);
            assert.equal(matching.search, "Participant");
            const rows = csvRows((await exported("csv", await downloadDirectory("employees", "organization-filter"), "employee-management")).toString("utf8"));
            assert.equal(rows.length, 2);
            assert.ok(rows[1].includes("Participant Nine"));
            await client.evaluate(`
                const wrapper = document.querySelector("#employeesTable_wrapper");
                wrapper.querySelector("[data-filter-toggle]").click();
                wrapper.querySelector("[data-filter-reset]").click();
                await until(() => wrapper.querySelector(".dt-info").textContent.includes("dari 4 data"));
                if (wrapper.querySelector('input[type="search"]').value !== "Participant") { throw new Error("Reset cleared search"); }
            `);
        });

        await suite.test("participant and invitee filters keep contextual status available", async () => {
            for (const [page, hash, selector, status] of [
                ["event", "#event-participants", "#eventParticipantsTable", "registered"],
                ["day", "#day-invitations", "[data-day-invitations-table]", "invited"],
            ]) {
                await fresh(page, hash);
                const result = await client.evaluate(`
                    const table = document.querySelector(${JSON.stringify(selector)});
                    const controls = table.closest(".dt-container").querySelector("[data-compact-filters]");
                    await until(() => controls.querySelector("[data-filter-toggle]").getBoundingClientRect().width > 0);
                    controls.querySelector("[data-filter-toggle]").click();
                    const type = controls.querySelector('[data-filter-key="type"]');
                    const status = controls.querySelector('[data-filter-key="status"]');
                    type.value = "external"; type.dispatchEvent(new Event("change", { bubbles: true }));
                    status.value = ${JSON.stringify(status)}; status.dispatchEvent(new Event("change", { bubbles: true }));
                    const rows = [...table.tBodies[0].querySelectorAll("tr[data-filter-status]")];
                    return { rows: rows.length, statuses: rows.map((row) => row.dataset.filterStatus),
                        types: rows.map((row) => row.dataset.filterType), headers: table.tHead.textContent,
                        search: Boolean(controls.querySelector('input[type="search"]').getBoundingClientRect().width) };
                `);
                assert.ok(result.rows > 0);
                assert.ok(result.statuses.every((rowStatus) => rowStatus === status));
                assert.ok(result.types.every((type) => type === "external"));
                assert.match(result.headers, /Status/);
                assert.equal(result.search, true);
                if (page === "day") {
                    assert.match(result.headers, /Kehadiran/);
                }
            }
        });

        await suite.test("bulk resend preserves selection across pages, filtering and searching on mobile", async () => {
            await client.send("Emulation.setDeviceMetricsOverride", { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            try {
                await fresh("event", "#event-participants");
                const result = await client.evaluate(`
                    const table = document.getElementById("eventParticipantsTable");
                    const wrapper = table.closest(".dt-container");
                    const bar = document.querySelector("[data-resend-bar]");
                    const open = bar.querySelector("[data-resend-open]");
                    const count = bar.querySelector("[data-resend-count]");
                    const modal = document.getElementById("bulkResendInvitationModal");
                    const form = modal.querySelector("form");
                    const checkbox = (selector) => table.querySelector(selector + " [data-resend-participant]");
                    await until(() => document.getElementById("event-participants").classList.contains("show"), "Participants tab did not open");
                    const employee = table.querySelector('[aria-label="Pilih Participant Twelve"]');
                    if (!bar.hidden || !employee.getBoundingClientRect().width) throw new Error("Bulk controls did not initialize");
                    employee.click();
                    wrapper.querySelector('[data-dt-idx="next"]').click();
                    await until(() => checkbox('tr[data-filter-type="external"]'), "External participant did not appear on page two");
                    const external = checkbox('tr[data-filter-type="external"]');
                    external.click();
                    if (count.textContent !== "2 peserta dipilih") throw new Error("Pagination lost a selection");

                    const filters = wrapper.querySelector("[data-compact-filters]");
                    filters.querySelector("[data-filter-toggle]").click();
                    const type = filters.querySelector('[data-filter-key="type"]');
                    type.value = "external";
                    type.dispatchEvent(new Event("change", { bubbles: true }));
                    if (!external.checked || table.tBodies[0].querySelectorAll("tr[data-filter-type]").length !== 1) {
                        throw new Error("Filtering did not preserve the external selection");
                    }
                    const search = wrapper.querySelector('input[type="search"]');
                    search.value = "no participant matches this search";
                    search.dispatchEvent(new Event("input", { bubbles: true }));
                    await until(() => !table.querySelector("tbody [data-resend-participant]"), "Search did not remove the visible participant rows");
                    const bounds = bar.firstElementChild.getBoundingClientRect();
                    if (count.textContent !== "2 peserta dipilih" || bounds.left < 0 || bounds.right > innerWidth || bounds.width === 0) {
                        throw new Error("Search lost the selection or the bulk actions left the viewport");
                    }
                    open.click();
                    const submit = modal.querySelector("[data-resend-submit]");
                    await until(() => modal.classList.contains("show") && !submit.disabled, "Resend confirmation did not open");
                    const confirmation = ["total", "with-email", "without-email"].map(key => modal.querySelector("[data-resend-" + key + "]").textContent);
                    const requests = [];
                    let finish;
                    window.fetch = (url, options) => {
                        requests.push({ url, payload: JSON.parse(options.body), csrf: options.headers["X-CSRF-TOKEN"] });
                        if (requests.length === 1) return Promise.reject(new Error("Connection lost"));
                        return new Promise(resolve => { finish = resolve; });
                    };
                    submit.click();
                    await until(() => !modal.querySelector("[data-resend-error]").hidden, "Submission error was not shown");
                    submit.click();
                    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
                    if (!submit.disabled || requests.length !== 2) throw new Error("In-flight submission was repeated");
                    finish(new Response(JSON.stringify({ queued: 1, skipped: 1, no_email: 1, pending: 0, duplicate: 0 }),
                        { headers: { "Content-Type": "application/json" } }));
                    await until(() => bar.hidden && !modal.classList.contains("show") && document.querySelector(".app-toast"),
                        "Successful resend did not clear selection and display a summary");

                    search.value = "";
                    search.dispatchEvent(new Event("input", { bubbles: true }));
                    await until(() => checkbox('tr[data-filter-type="external"]'), "Clearing search did not restore the external participant");
                    if (external.checked) throw new Error("The completed batch retained its selections");
                    external.click();
                    bar.querySelector("[data-resend-clear]").click();
                    return { requests, confirmation, selectedIds: [employee.value, external.value],
                        cleared: bar.hidden && !external.checked, summary: document.querySelector(".app-toast").textContent };
                `);
                assert.deepEqual(result.confirmation, ["2", "1", "1"]);
                assert.ok(result.cleared);
                assert.match(result.summary, /1 undangan masuk antrean.*1 peserta dilewati/);
                assert.equal(result.requests.length, 2);
                assert.match(result.requests[0].url, /\/participants\/invitations\/resend$/);
                assert.ok(result.requests[0].csrf);
                assert.match(result.requests[0].payload.request_id, /^[a-f0-9]{32}$/);
                assert.deepEqual(result.requests[0].payload.registration_ids, result.selectedIds);
                assert.deepEqual(result.requests[1], result.requests[0]);
            } finally {
                await client.send("Emulation.clearDeviceMetricsOverride");
            }
        });

        for (const [page, filename] of Object.entries({ events: "event-management", employees: "employee-management", external: "external-participants",
            users: "user-management", roles: "role-management", event: "event-participants" })) {
            await suite.test(`${page}: real Excel, CSV, PDF and Print exports include every intended row and omit actions`, async () => {
                await fresh(page, page === "event" ? "#event-participants" : "");
                const directory = await downloadDirectory(page);
                const csv = (await exported("csv", directory, filename)).toString("utf8");
                assert.ok(csv.startsWith("\uFEFF"));
                const rows = csvRows(csv);
                assert.ok(rows.length > 11, `Export contains only ${rows.length - 1} rows`);
                assert.ok(!rows[0].some((heading) => /^(Action|Actions|Tindakan|QR Code)$/i.test(heading)));
                if (page !== "roles") {
                    for (const name of ["=1+1", "+SUM(1,2)", "-1+1", "@SUM(1,2)"]) {
                        assert.ok(rows.slice(1).some((row) => row.some((cell) => cell.startsWith("'" + name))), `${page}: formula not protected: ${name}`);
                    }
                    assert.match(csv, /café/);
                    assert.ok(rows.slice(1).some((row) => row.some((cell) => cell.includes('Doe, "Jane"; café'))));
                }
                const workbook = await JSZip.loadAsync(await exported("excel", directory, filename));
                const xml = await workbook.file("xl/worksheets/sheet1.xml").async("string");
                assert.doesNotMatch(xml, /<f[\s>]/);
                assert.doesNotMatch(xml, /<t[^>]*>Actions?<\/t>/);
                assert.ok((xml.match(/<row /g) || []).length >= rows.length);
                const pdf = await exported("pdf", directory, filename);
                assert.ok(pdf.toString("latin1").startsWith("%PDF-"));
                assert.ok(pdf.toString("latin1").includes("%%EOF"));
                assert.match(pdf.toString("latin1"), /\/MediaBox\s*\[\s*0\s+0\s+841\.\d+\s+595\.\d+/);
                const printed = await client.evaluate(`
                    window.printPopup = null;
                    const original = window.open; window.open = (...args) => (window.printPopup = original.apply(window, args));
                    const container = document.querySelector("${page === "event" ? "#eventParticipantsTable_wrapper" : ".dt-container"}");
                    container.querySelector(".buttons-collection").click();
                    await until(() => document.querySelector(".buttons-print a, a.buttons-print, button.buttons-print"));
                    document.querySelector(".buttons-print a, a.buttons-print, button.buttons-print").click();
                    await until(() => window.printPopup?.document.querySelector("tbody tr"));
                    const result = { rows: printPopup.document.querySelectorAll("tbody tr").length,
                        headings: printPopup.document.querySelector("thead").textContent, controls: printPopup.document.querySelectorAll(".print-controls").length,
                        injected: printPopup.document.querySelectorAll("img, script").length };
                    printPopup.close(); window.open = original; return result;
                `);
                assert.equal(printed.rows, rows.length - 1);
                assert.equal(printed.controls, 1);
                assert.equal(printed.injected, 0);
                assert.doesNotMatch(printed.headings, /Actions?/);
                assert.equal(await client.evaluate("return Boolean(window.uiXss);"), false);
                await client.evaluate(`
                    const container = document.querySelector("${page === "event" ? "#eventParticipantsTable_wrapper" : ".dt-container"}");
                    const input = container.querySelector('input[type="search"]'); input.value = ${JSON.stringify(page === "roles" ? "ui-role-" : "Participant")};
                    input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true }));
                    await until(() => container.querySelector(".dt-info").textContent.includes("difilter") && container.querySelector(".dt-info").textContent.includes("${page === "roles" ? "12" : "4"}"));
                `);
                const filtered = csvRows((await exported("csv", await downloadDirectory(page, "filtered"), filename)).toString("utf8"));
                assert.equal(filtered.length - 1, page === "roles" ? 12 : 4);
            });
        }

        await suite.test("Dashboard renders all three charts from server data", async () => {
            const errorCount = client.errors.length;
            await fresh("dashboard");
            const result = await client.evaluate(`
                await until(() => document.querySelectorAll("[data-dashboard-chart] .apexcharts-svg").length === 3);
                const data = JSON.parse(document.querySelector("[data-dashboard-chart-data]").textContent);
                return { charts: Object.keys(data), registrations: data.registrations.find((event) => event.label === "UI Refinement").count,
                    present: data.attendance[0].present, absent: data.attendance[0].absent, participants: data.participants,
                    visibleError: !document.querySelector("[data-dashboard-chart-error]").classList.contains("d-none") };
            `);
            assert.deepEqual(result, { charts: ["registrations", "attendance", "participants"], registrations: 14,
                present: 0, absent: 14, participants: [13, 1], visibleError: false });
            assert.deepEqual(client.errors.slice(errorCount), []);
        });

        await suite.test("toast variants are escaped, deduplicated, announced and individually dismissible", async () => {
            await fresh("dashboard");
            await client.evaluate(`
                await until(() => document.querySelector(".app-toast")?.textContent.includes("Pengaturan berhasil disimpan."));
                document.querySelector(".app-toast-dismiss").click(); await until(() => !document.querySelector(".app-toast"));
            `);
            const result = await client.evaluate(`
                notify.success("Data tersimpan.", { persistent: true }); notify.success("Data tersimpan.", { persistent: true });
                notify.error('<img src=x onerror="window.toastXss=true">', { persistent: true });
                notify.warning("Periksa filter.", { persistent: true }); notify.info("Proses berjalan.", { persistent: true });
                await until(() => document.querySelectorAll(".app-toast").length === 4);
                return { count: document.querySelectorAll(".app-toast").length, errors: document.querySelectorAll('.app-toast[role="alert"][aria-live="assertive"]').length,
                    images: document.querySelectorAll(".app-toast img").length, executed: Boolean(window.toastXss),
                    close: document.querySelectorAll('.app-toast-dismiss[aria-label="Tutup notifikasi"]').length,
                    icons: [...document.querySelectorAll(".app-toast-symbol")].map((node) => [...node.querySelectorAll("svg")].filter((svg) => getComputedStyle(svg).display !== "none").length) };
            `);
            assert.deepEqual(result, { count: 4, errors: 1, images: 0, executed: false, close: 4, icons: [1, 1, 1, 1] });
            await client.evaluate(`document.querySelectorAll(".app-toast-dismiss").forEach((button) => button.click()); await until(() => !document.querySelector(".app-toast"));`);
            await client.send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            await client.evaluate(`notify.success("Selesai otomatis.", { duration: 100 }); await until(() => !document.querySelector(".app-toast"));`);
        });

        await suite.test("forms prevent repeated submission and recover loading state from the back-forward cache", async () => {
            await fresh("employeeForm");
            const result = await client.evaluate(`
                const form = document.querySelector("form[data-form]");
                form.querySelectorAll("[required]").forEach((field) => {
                    if (field instanceof HTMLSelectElement) { field.value = [...field.options].find((option) => option.value)?.value ?? ""; }
                    else { field.value = field.type === "email" ? "test@example.test" : "UI Test"; }
                });
                let submissions = 0;
                form.addEventListener("submit", (event) => { event.preventDefault(); submissions++; });
                const button = form.querySelector('[type="submit"]'); const original = button.textContent.trim();
                button.click(); button.click();
                const loading = { disabled: button.disabled, busy: form.getAttribute("aria-busy"), submissions, label: button.textContent.trim() };
                window.dispatchEvent(new PageTransitionEvent("pageshow", { persisted: true }));
                return { loading, restored: !button.disabled && button.textContent.trim() === original && !form.hasAttribute("aria-busy") };
            `);
            assert.deepEqual(result.loading, { disabled: true, busy: "true", submissions: 1, label: "Menyimpan karyawan..." });
            assert.equal(result.restored, true);
        });

        await suite.test("SVG action clicks open Employee confirmation with the correct row and support Escape", async () => {
            await fresh("employees");
            const result = await client.evaluate(`
                const button = document.querySelector('[data-row-action][data-delete-url]');
                button.querySelector("svg path").dispatchEvent(new MouseEvent("click", { bubbles: true }));
                await until(() => document.getElementById("deleteEmployeeModal").classList.contains("show"));
                return { name: document.getElementById("deleteEmployeeName").textContent, expectedName: button.dataset.deleteName,
                    url: document.getElementById("deleteEmployeeForm").action, expectedUrl: button.dataset.deleteUrl,
                    csrf: Boolean(document.querySelector('#deleteEmployeeForm input[name="_token"]')), injected: Boolean(window.uiXss) };
            `);
            assert.equal(result.name, result.expectedName);
            assert.equal(result.url, result.expectedUrl);
            assert.equal(result.csrf, true);
            assert.equal(result.injected, false);
            await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
            await client.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
            await client.evaluate(`await until(() => !document.getElementById("deleteEmployeeModal").classList.contains("show"));`);
        });

        for (const [width, height] of [[1440, 900], [375, 620]]) {
            await suite.test(`The employee form Department selector has a readable dropdown at ${width}px`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh("employeeForm");
                const errorCount = client.errors.length;
                const style = await client.evaluate(`
                        const entity = document.querySelector("#entity_id");
                        entity.tomselect.setValue([...entity.options].find((option) => option.textContent.includes("UI Entity")).value);
                        window.picker = document.querySelector("#department_id").tomselect;
                        picker.control.scrollIntoView({ block: "center" });
                        picker.control.click(); await until(() => picker.isOpen);
                        const option = picker.dropdown.querySelector(".option.active");
                        const box = option.getBoundingClientRect();
                        return { background: getComputedStyle(picker.dropdown).backgroundColor, color: getComputedStyle(option).color,
                            active: getComputedStyle(option).backgroundColor !== "rgba(0, 0, 0, 0)",
                            hit: option.contains(document.elementFromPoint(box.left + 5, box.top + box.height / 2)),
                            inside: picker.dropdown.parentElement === picker.wrapper,
                            controlClass: picker.dropdown.classList.contains("form-select") };
                    `);
                assert.deepEqual(style, { background: "rgb(255, 255, 255)", color: "rgb(24, 36, 51)", active: true, hit: true,
                    inside: true, controlClass: false });
                await client.send("Input.insertText", { text: "Participant Nine" });
                const id = await client.evaluate(`
                        await until(() => picker.dropdown.querySelectorAll(".option").length === 1
                            && picker.dropdown.querySelector(".option").textContent.includes("Participant Nine"));
                        return picker.dropdown.querySelector(".option").dataset.value;
                    `);
                await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 });
                assert.equal(await client.evaluate("return picker.input.value;"), id);
                assert.deepEqual(client.errors.slice(errorCount), []);
            });

            await suite.test(`footer and Contact Us support keyboard and fit at ${width}px`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh("notifications");
                const layout = await client.evaluate(`
                    const footer = document.querySelector("footer");
                    const notice = footer.querySelector("p").getBoundingClientRect();
                    window.contactLink = footer.querySelector("a");
                    const link = contactLink.getBoundingClientRect();
                    contactLink.focus();
                    return { aligned: ${width < 800} ? link.top >= notice.bottom : link.left >= notice.right && link.top < notice.bottom,
                        atBottom: footer.getBoundingClientRect().bottom >= innerHeight - 1,
                        breadcrumbs: Array.from(document.querySelectorAll(".app-breadcrumb li"), (item) => item.textContent.trim()),
                        overflow: document.documentElement.scrollWidth > innerWidth };
                `);
                assert.deepEqual(layout, { aligned: true, atBottom: true, breadcrumbs: ["Home", "Notifications"], overflow: false });
                await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 });
                const content = await client.evaluate(`
                    window.contactModal = document.querySelector("#contactUsModal");
                    await until(() => contactModal.contains(document.activeElement));
                    const dialog = contactModal.querySelector(".modal-dialog");
                    const bounds = dialog.getBoundingClientRect();
                    const chat = contactModal.querySelector('a[href^="https://wa.me/"]');
                    const button = chat.getBoundingClientRect();
                    return { whatsapp: chat.getAttribute("href"), email: contactModal.querySelector(".modal-footer a[href^='mailto:']").getAttribute("href"),
                        visible: chat.contains(document.elementFromPoint(button.x + button.width / 2, button.y + button.height / 2)),
                        fits: bounds.left >= 0 && bounds.right <= innerWidth && bounds.top >= 0 && bounds.bottom <= innerHeight };
                `);
                assert.deepEqual(content, { whatsapp: "https://wa.me/6281111189864", email: "mailto:it@ptaun.com", visible: true, fits: true });
                await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
                await client.evaluate("await until(() => !contactModal.classList.contains('show') && document.activeElement === contactLink);");
            });

            await suite.test(`participant picker preserves selections across pages, filters and types at ${width}px`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh("event", "#event-participants");
                const result = await client.evaluate(`
                    const modal = document.querySelector("#addParticipantsModal");
                    const shown = new Promise((resolve) => modal.addEventListener("shown.bs.modal", resolve, { once: true }));
                    document.querySelector('[data-bs-target="#addParticipantsModal"]').click(); await shown;
                    const find = (selector) => modal.querySelector(selector);
                    const form = find("form");
                    let submitted;
                    form.addEventListener("submit", (event) => {
                        event.preventDefault();
                        const data = new FormData(form);
                        submitted = { employees: data.getAll("participant_groups[employee][]"),
                            externals: data.getAll("participant_groups[external][]"), days: data.getAll("day_ids[]") };
                    });
                    const change = (element, value, type = "change") => {
                        element.value = value;
                        element.dispatchEvent(new Event(type, { bubbles: true }));
                    };
                    const bounds = find(".modal-dialog").getBoundingClientRect();
                    const search = find("#candidate-search");
                    const type = find("#candidate-type");
                    const toolbarAligned = Math.abs(search.getBoundingClientRect().bottom - type.getBoundingClientRect().bottom) < 1;
                    const coverage = find("[data-invitation-coverage]");
                    const invitationVisible = coverage.getBoundingClientRect().bottom < innerHeight;
                    const entity = find("#candidate-entity");
                    const department = find("#candidate-department");
                    const division = find("#candidate-division");
                    const toggle = find("[data-filter-toggle]");
                    const panel = find("[data-filter-panel]");
                    const submit = find('button[type="submit"]');
                    await until(() => find('[data-candidate-id="15"]'));
                    find("#candidate-select-all").click();
                    find("[data-candidate-next]").click();
                    await until(() => find('[data-candidate-id="16"]:checked'));
                    const alreadyRegisteredDisabled = find('[data-candidate-id="17"]').disabled;
                    const zeroChipsHidden = find("[data-filter-chips]").hidden;
                    const initiallyDisabled = division.disabled;
                    toggle.click();
                    await new Promise(requestAnimationFrame);
                    const panelBounds = panel.getBoundingClientRect();
                    const hit = document.elementFromPoint(panelBounds.x + panelBounds.width / 2, panelBounds.y + 20);
                    const unclipped = panelBounds.left >= 0 && panelBounds.right <= innerWidth && panel.contains(hit);
                    const filterFocus = document.activeElement === entity;
                    change(entity, entity.options[1].value);
                    change(department, department.options[1].value);
                    change(division, division.options[1].value);
                    await until(() => find("[data-candidate-results]").textContent.includes("2 peserta"));
                    const chipCount = find("[data-filter-chips]").children.length;
                    document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }));
                    const escapeRestored = panel.hidden && document.activeElement === toggle && modal.classList.contains("show");
                    const chip = find('[data-filter-chips] button[aria-label^="Hapus filter Divisi"]');
                    chip.click();
                    const divisionCleared = !division.value && Boolean(department.value);
                    change(search, "NIP-2", "input");
                    await until(() => find('[data-candidate-id="2"]:checked') && !find('[data-candidate-id="1"]'));
                    toggle.click();
                    find("[data-filter-reset]").click();
                    await until(() => find('[data-candidate-id="2"]:checked'));
                    const resetKeptSearch = search.value === "NIP-2" && find("[data-filter-chips]").hidden && division.disabled;
                    change(type, "external");
                    change(search, "0812345", "input");
                    await until(() => find('[data-candidate-id="1"]')?.closest("tr").textContent.includes("External Guest"));
                    find('[data-candidate-id="1"]').click();
                    const count = find("[data-selected-count]").textContent;
                    change(coverage, "selected");
                    const checkedBeforeSubmit = Boolean(submitted);
                    const requiresDay = submit.disabled;
                    const day = find('input[name="day_ids[]"]');
                    day.click();
                    submit.click();
                    return { submitted, day: day.value, count, alreadyRegisteredDisabled, checkedBeforeSubmit,
                        requiresDay, filterHidden: toggle.hidden && panel.hidden,
                        zeroChipsHidden, initiallyDisabled, unclipped, filterFocus, chipCount, escapeRestored, divisionCleared, resetKeptSearch, toolbarAligned, invitationVisible,
                        fits: bounds.left >= 0 && bounds.right <= innerWidth && bounds.bottom <= innerHeight };
                `);
                assert.deepEqual(result.submitted, { employees: Array.from({ length: 16 }, (_, index) => String(index + 1)),
                    externals: ["1"], days: [result.day] });
                assert.equal(result.count, "17 peserta dipilih");
                assert.equal(result.checkedBeforeSubmit, false);
                assert.ok(result.alreadyRegisteredDisabled && result.requiresDay && result.filterHidden && result.fits);
                assert.equal(result.chipCount, 3);
                for (const key of ["zeroChipsHidden", "initiallyDisabled", "unclipped", "filterFocus", "escapeRestored", "divisionCleared", "resetKeptSearch", "toolbarAligned", "invitationVisible"]) {
                    assert.equal(result[key], true, key);
                }
                assert.ok(candidateRequests.some((query) => query.entity_id && query.department_id && query.division_id));
                assert.ok(candidateRequests.filter((query) => query.type === "external").every((query) => !query.entity_id && !query.department_id && !query.division_id));
            });
        }

        await suite.test("poster editing and gallery previews fit and work on a mobile viewport", async () => {
            await client.send("Emulation.setDeviceMetricsOverride", { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            await fresh("eventEdit");
            const posters = await client.evaluate(`
                const list = document.querySelector("[data-poster-list]");
                const form = list.closest("form");
                const existing = list.firstElementChild.dataset.posterKey;
                const input = form.querySelector("#event-posters");
                const image = await (await fetch(list.querySelector("[data-poster-preview]").href)).blob();
                const select = (name, date) => {
                    const files = new DataTransfer();
                    files.items.add(new File([image], name, { type: "image/png", lastModified: date }));
                    input.files = files.files;
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                };
                select("first.png", 1);
                select("second.png", 2);
                const last = list.lastElementChild;
                last.scrollIntoView({ block: "center" });
                await until(() => last.querySelector("img").naturalWidth > 0, "Poster preview did not load");
                last.querySelector('[data-poster-action="primary"]').click();
                last.querySelector('[data-poster-action="up"]').click();
                last.querySelector('[data-poster-action="up"]').click();
                const ordered = new FormData(form);
                list.querySelector('[data-poster-key="' + existing + '"] [data-poster-action="remove"]').click();
                const saved = new FormData(form);
                return { order: ordered.getAll("poster_order[]"), selected: ordered.get("poster_primary"),
                    uploads: saved.getAll("posters[]").map(file => file.name), existing, removed: saved.getAll("poster_remove[]"),
                    fits: document.documentElement.scrollWidth <= innerWidth };
            `);
            assert.deepEqual(posters.order, ["upload:0", posters.existing, "upload:1"]);
            assert.deepEqual(posters.uploads, ["second.png", "first.png"]);
            assert.deepEqual(posters.removed, [posters.existing]);
            assert.equal(posters.selected, "upload:0");
            assert.equal(posters.fits, true);

            await fresh("event", "#event-gallery");
            const gallery = await client.evaluate(`
                const pane = document.querySelector("#event-gallery");
                await until(() => pane.classList.contains("active"));
                const clip = pane.querySelector("video");
                clip.muted = true;
                await clip.play();
                const card = clip.closest("article");
                card.querySelector('[data-bs-toggle="dropdown"]').click();
                card.querySelector("[data-media-delete]").click();
                await until(() => document.querySelector("#deleteGalleryMedia").classList.contains("show"));
                return { plays: clip.videoWidth === 16, controls: clip.controls, cards: pane.querySelectorAll("article").length,
                    deleteName: document.querySelector("[data-media-delete-name]").textContent,
                    fits: document.documentElement.scrollWidth <= innerWidth };
            `);
            assert.deepEqual(gallery, { plays: true, controls: true, cards: 2, deleteName: "Klip dokumentasi", fits: true });
        });

        await suite.test("small visual viewport keeps the participant footer and search reachable", async () => {
            await client.send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
            await fresh("event", "#event-participants");
            const result = await client.evaluate(`
                const modal = document.querySelector("#addParticipantsModal");
                document.querySelector('[data-bs-target="#addParticipantsModal"]').click();
                await until(() => modal.classList.contains("show") && modal.contains(document.activeElement));
                Object.defineProperties(visualViewport, {
                    height: { value: 425, configurable: true },
                    offsetTop: { value: 15, configurable: true },
                });
                visualViewport.dispatchEvent(new Event("resize"));
                const search = modal.querySelector("#candidate-search");
                search.focus();
                await new Promise(requestAnimationFrame);
                const searchBounds = search.getBoundingClientRect();
                const footer = modal.querySelector('.modal-footer').getBoundingClientRect();
                const checkbox = modal.querySelector('.candidate-check-target').getBoundingClientRect();
                const portrait = { footerVisible: footer.bottom <= 440, searchVisible: searchBounds.top >= 15 && searchBounds.bottom < footer.top,
                    size: parseFloat(getComputedStyle(search).fontSize), target: checkbox.height >= 44 && checkbox.width >= 44 };
                Object.defineProperty(visualViewport, "height", { value: 225, configurable: true });
                visualViewport.dispatchEvent(new Event("resize"));
                search.blur();
                await new Promise(requestAnimationFrame);
                const content = modal.querySelector(".modal-content");
                content.scrollTop = content.scrollHeight;
                const shortFooter = modal.querySelector(".modal-footer").getBoundingClientRect();
                return { ...portrait, shortFooterReachable: content.scrollTop > 0 && shortFooter.bottom <= 240 };
            `);
            assert.deepEqual(result, { footerVisible: true, searchVisible: true, size: 16, target: true, shortFooterReachable: true });

            await fresh("employeeEdit");
            const dropdown = await client.evaluate(`
                const picker = document.querySelector("#department_id").tomselect;
                picker.control.scrollIntoView({ block: "center", behavior: "instant" });
                picker.control.click();
                await until(() => picker.isOpen && picker.dropdown.querySelector(".option"));
                const control = picker.control.getBoundingClientRect();
                const keyboardTop = Math.ceil(control.bottom + 30);
                Object.defineProperty(visualViewport, "height", { value: keyboardTop, configurable: true });
                visualViewport.dispatchEvent(new Event("resize"));
                await until(() => picker.dropdown.dataset.mobilePlacement === "top");
                const bounds = picker.dropdown.getBoundingClientRect();
                return { fits: bounds.top >= 0 && bounds.bottom <= control.top + 1 && bounds.right <= innerWidth,
                    optionsVisible: Boolean(picker.dropdown.querySelector(".option")?.getBoundingClientRect().height),
                    menu: bounds.toJSON(), control: control.toJSON() };
            `);
            assert.ok(dropdown.fits && dropdown.optionsVisible, JSON.stringify(dropdown));
        });

        await suite.test("Kelola Hari Undangan saves through one accessible modal and updates the participant table", async () => {
            await client.send("Emulation.setDeviceMetricsOverride", { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            await fresh("event", "#event-participants");
            await client.evaluate(`
                const originalFetch = window.fetch;
                window.invitationButton = document.querySelector("#eventParticipantsTable [data-invitation-url]");
                window.invitationModal = document.querySelector("#eventDayInvitationModal");
                window.invitationCheckboxes = () => [...invitationModal.querySelectorAll('input[type="checkbox"]')];
                window.invitationSave = invitationModal.querySelector('[type="submit"]');
                window.invitationPage = location.href;
                window.invitationData = {
                    participant_name: "Employee <Test>", event_name: "UI Refinement",
                    days: [
                        { id: 1, number: 1, date: "15/01/2027", title: "Opening", assigned: true, available: true, protected: true },
                        { id: 2, number: 2, date: "16/01/2027", title: "Workshop", assigned: false, available: true, protected: false },
                        { id: 3, number: 3, date: "17/01/2027", title: "Unavailable", assigned: false, available: false, protected: false }
                    ],
                };
                window.jsonResponse = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { "Content-Type": "application/json" } });
                window.fetch = (url, options) => {
                    if (!url.endsWith("/invitations")) return originalFetch(url, options);
                    if (options.method === "PUT") {
                        window.invitationSubmission = { url, dayIds: JSON.parse(options.body).day_ids, csrf: options.headers["X-CSRF-TOKEN"] };
                        return new Promise((resolve) => { window.finishSave = resolve; });
                    }
                    return new Promise((resolve) => { window.finishLoad = resolve; });
                };
                await until(() => invitationButton.getClientRects().length && document.querySelector("#event-participants").classList.contains("show"));
                invitationButton.focus();
                if (document.activeElement !== invitationButton) throw new Error("Invitation action did not receive focus");
            `);
            await client.evaluate("invitationButton.click();");
            const loading = await client.evaluate(`
                await until(() => invitationModal.classList.contains("show") && window.finishLoad).catch(() => {
                    throw new Error(JSON.stringify({ visible: invitationModal.classList.contains("show"), requested: Boolean(window.finishLoad),
                        focused: document.activeElement?.getAttribute("aria-label"), error: invitationModal.querySelector("[data-invitation-error]").textContent }));
                });
                const pending = !invitationModal.querySelector("[data-invitation-loading]").hidden && invitationSave.disabled;
                finishLoad(jsonResponse(invitationData));
                await until(() => invitationCheckboxes().length === 3);
                const [protectedDay, editableDay, unavailableDay] = invitationCheckboxes();
                const initial = protectedDay.checked && protectedDay.disabled && !editableDay.checked && unavailableDay.disabled;
                invitationModal.querySelector("[data-invitation-clear-all]").click();
                const afterClear = protectedDay.checked && !editableDay.checked;
                invitationModal.querySelector("[data-invitation-select-all]").click();
                const afterSelect = protectedDay.checked && editableDay.checked && !unavailableDay.checked;
                const bounds = invitationModal.querySelector(".modal-dialog").getBoundingClientRect();
                return { pending, initial, afterClear, afterSelect, fits: bounds.left >= 0 && bounds.right <= innerWidth && bounds.bottom <= innerHeight,
                    title: invitationModal.querySelector("[data-invitation-participant]").textContent, date: invitationModal.textContent.includes("16/01/2027"),
                    oneModal: document.querySelectorAll("#eventDayInvitationModal").length === 1, stays: location.href === invitationPage };
            `);
            assert.deepEqual(loading, { pending: true, initial: true, afterClear: true, afterSelect: true, fits: true,
                title: "Employee <Test>", date: true, oneModal: true, stays: true });
            const failed = await client.evaluate(`
                await until(() => !invitationSave.disabled);
                invitationSave.click();
                await until(() => window.finishSave);
                const busy = invitationSave.disabled && !invitationModal.querySelector("[data-invitation-saving]").hidden;
                finishSave(jsonResponse({ errors: { day_ids: ["Hari dengan kehadiran tercatat tidak dapat dicabut."] } }, 422));
                const alert = invitationModal.querySelector("[data-invitation-error]");
                await until(() => !alert.hidden && !invitationSave.disabled);
                return { busy, message: alert.textContent, open: invitationModal.classList.contains("show"), focused: document.activeElement === alert,
                    ids: invitationSubmission.dayIds, csrf: Boolean(invitationSubmission.csrf) };
            `);
            assert.deepEqual(failed, { busy: true, message: "Hari dengan kehadiran tercatat tidak dapat dicabut.", open: true,
                focused: true, ids: [1, 2], csrf: true });
            const updated = await client.evaluate(`
                window.finishSave = null;
                invitationSave.click();
                await until(() => window.finishSave);
                finishSave(jsonResponse({ message: "Hari undangan peserta diperbarui.", day_ids: [1, 2], days_label: "Day 1, Day 2" }));
                await until(() => !invitationModal.classList.contains("show") && document.activeElement === invitationButton);
                await until(() => document.querySelector(".app-toast")?.textContent.includes("Hari undangan peserta diperbarui."));
                const row = invitationButton.closest("tr");
                const summary = row.querySelector("[data-invitation-summary]");
                const qr = row.querySelector('a[href$="/qr"]');
                const pdf = row.querySelector('a[href$="/invitation"]');
                const container = document.querySelector("#eventParticipantsTable_wrapper");
                const search = container.querySelector('input[type="search"]');
                search.value = '"Day 1, Day 2"';
                search.dispatchEvent(new Event("input", { bubbles: true }));
                search.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true }));
                await until(() => container.querySelector(".dt-info").textContent.includes("difilter"));
                return { summary: summary.textContent, foundAfterFiltering: container.querySelector("tbody tr") === row, qr: Boolean(qr), pdf: Boolean(pdf),
                    stillOnPage: location.href === invitationPage };
            `);
            assert.deepEqual(updated, { summary: "Day 1, Day 2", foundAfterFiltering: true, qr: true, pdf: true, stillOnPage: true });
            await client.evaluate(`
                const search = document.querySelector("#eventParticipantsTable_wrapper input[type=search]");
                search.value = "External";
                search.dispatchEvent(new Event("input", { bubbles: true }));
                search.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true }));
                await until(() => document.querySelector("#eventParticipantsTable tbody tr")?.textContent.includes("External"));
                window.externalButton = document.querySelector("#eventParticipantsTable [data-invitation-url]");
                window.finishLoad = null;
                externalButton.click();
                await until(() => window.finishLoad);
                finishLoad(jsonResponse({ ...invitationData, participant_name: "External Guest", days: [invitationData.days[1]] }));
                await until(() => invitationCheckboxes().length === 1 && invitationModal.contains(document.activeElement));
                if (invitationModal.querySelector("[data-invitation-participant]").textContent !== "External Guest" || invitationCheckboxes()[0].checked) {
                    throw new Error("The modal retained the previous participant's assignments");
                }
            `);
            await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
            await client.evaluate("await until(() => !invitationModal.classList.contains('show') && document.activeElement === externalButton);");

            await fresh("day", "#day-invitations");
            const dayUpdate = await client.evaluate(`
                const trigger = document.querySelector("[data-day-invitations-table] [data-invitation-url]");
                const row = trigger.closest("tr");
                const modal = document.querySelector("#eventDayInvitationModal");
                const asJson = (data) => new Response(JSON.stringify(data), { headers: { "Content-Type": "application/json" } });
                const originalFetch = window.fetch;
                window.fetch = (url, options) => {
                    if (url.endsWith("/attendance")) return Promise.resolve(asJson({
                        success: true, attendees: [], summary: { invited: 0, checked_in: 0, not_checked_in: 0, percentage: 0 },
                    }));
                    if (!url.endsWith("/invitations")) return originalFetch(url, options);
                    if (options.method === "PUT") return Promise.resolve(asJson({
                        message: "Hari undangan peserta diperbarui.", day_ids: [], days_label: "Belum ditetapkan",
                    }));
                    return Promise.resolve(asJson({ participant_name: "Employee", event_name: "UI Refinement", days: [
                        { id: 1, number: 1, date: "15/01/2027", title: "Opening", assigned: true, available: true, protected: false },
                    ] }));
                };
                await until(() => trigger.getClientRects().length && document.querySelector("#day-invitations").classList.contains("show"));
                trigger.click();
                await until(() => modal.classList.contains("show") && modal.contains(document.activeElement) && !modal.querySelector('[type="submit"]').disabled);
                modal.querySelector("[data-invitation-clear-all]").click();
                modal.querySelector('[type="submit"]').click();
                const status = row.querySelector("[data-invitation-status]");
                await until(() => status.textContent === "Undangan dicabut" && document.activeElement === trigger);
                return { status: status.textContent, stays: location.hash === "#day-invitations" };
            `);
            assert.deepEqual(dayUpdate, { status: "Undangan dicabut", stays: true });
        });

        for (const [width, height] of [[375, 812], [768, 1024], [1440, 900]]) {
            await suite.test(`responsive navigation, actions, export columns and toasts at ${width}px`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh("event", "#event-participants");
                const result = await client.evaluate(`
                    notify.error("Pesan panjang untuk memeriksa tampilan notifikasi pada layar kecil. ".repeat(3), { persistent: true });
                    await until(() => document.querySelector(".app-toast"));
                    const bell = document.querySelector("[data-notification-entry]");
                    const toast = document.querySelector(".app-toast").getBoundingClientRect();
                    const primary = document.querySelector(".page-header .page-title").getBoundingClientRect();
                    const participantHeader = [...document.querySelectorAll("#eventParticipantsTable thead th")].find((node) => node.textContent.includes("Nama Peserta"));
                    const actions = [...document.querySelectorAll("[data-row-action]")].filter((node) => node.getBoundingClientRect().width > 0);
                    return { width: innerWidth, scroll: document.documentElement.scrollWidth,
                        title: { width: primary.width, left: primary.left, right: primary.right }, participantColumnVisible: participantHeader.getBoundingClientRect().width > 0,
                        bell: { left: bell.getBoundingClientRect().left, right: bell.getBoundingClientRect().right },
                        toast: { left: toast.left, right: toast.right }, actions: actions.map((node) => ({ width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height })) };
                `);
                assert.ok(result.scroll <= result.width, JSON.stringify(result));
                assert.ok(result.title.width > 150, JSON.stringify(result));
                assert.equal(result.participantColumnVisible, true);
                assert.ok(result.toast.left >= 0 && result.toast.right <= width, JSON.stringify(result));
                assert.ok(result.bell.left >= 0 && result.bell.right <= width, JSON.stringify(result));
                assert.ok(result.actions.length > 0);
                result.actions.forEach((bounds) => assert.ok(bounds.width >= (width < 768 ? 44 : 36) && bounds.height >= (width < 768 ? 44 : 36), JSON.stringify(bounds)));
                const screenshot = await client.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(join(artifacts, `ui-${width}.png`), Buffer.from(screenshot.data, "base64"));
                const csv = csvRows((await exported("csv", await downloadDirectory("responsive", String(width)), "event-participants")).toString("utf8"));
                assert.ok(csv[0].includes("Registered At"));
                for (const page of ["events", "employees", "external", "departments", "users", "roles", "notifications", "day", "employeeForm"]) {
                    await fresh(page);
                    const bounds = await client.evaluate(`
                        const bell = document.querySelector("[data-notification-entry]").getBoundingClientRect();
                        return { scroll: document.documentElement.scrollWidth, width: innerWidth, bellLeft: bell.left, bellRight: bell.right,
                            emptyIcons: [...document.querySelectorAll("svg.icon-tabler")].filter((icon) => !icon.children.length).length };
                    `);
                    assert.ok(bounds.scroll <= bounds.width, `${page} at ${width}: ${JSON.stringify(bounds)}`);
                    assert.ok(bounds.bellLeft >= 0 && bounds.bellRight <= width, `${page}: ${JSON.stringify(bounds)}`);
                    assert.equal(bounds.emptyIcons, 0, page);
                    const screenshot = await client.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                    await writeFile(join(artifacts, `ui-${page}-${width}.png`), Buffer.from(screenshot.data, "base64"));
                }
            });
        }

        for (const [width, height] of [[320, 568], [375, 667], [390, 844], [430, 932], [768, 1024], [844, 390], [1366, 900]]) {
            await suite.test(`primary pages and navigation fit ${width}x${height}`, async () => {
                const mobile = width < 992;
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile });
                await fresh("dashboard");
                const menu = await client.evaluate(`
                    window.navToggle = document.querySelector('[data-bs-toggle="offcanvas"][aria-controls="sidebar-menu"]');
                    window.sidebar = document.querySelector("#sidebar-menu");
                    if (!${mobile}) {
                        return { toggleHidden: !navToggle.getClientRects().length, linksVisible: Boolean(sidebar.querySelector("a").getClientRects().length) };
                    }
                    const hidden = getComputedStyle(sidebar).visibility === "hidden";
                    navToggle.click();
                    await until(() => sidebar.classList.contains("show"));
                    const button = navToggle.getBoundingClientRect();
                    return { hidden, backdrop: Boolean(document.querySelector(".offcanvas-backdrop.show")),
                        focusInside: sidebar.contains(document.activeElement), target: button.width >= 44 && button.height >= 44 };
                `);
                if (mobile) {
                    assert.deepEqual(menu, { hidden: true, backdrop: true, focusInside: true, target: true });
                    await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
                    await client.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
                    await client.evaluate('await until(() => !sidebar.classList.contains("show") && document.activeElement === navToggle);');
                } else {
                    assert.deepEqual(menu, { toggleHidden: true, linksVisible: true });
                }

                for (const [page, hash] of [
                    ["dashboard"], ["events"], ["eventCreate"], ["eventEdit"], ["event", "#event-participants"],
                    ["event", "#event-reports"], ["dayCreate"], ["dayEdit"], ["day", "#day-invitations"],
                    ["day", "#day-attendees"], ["day", "#day-manual"], ["scanner"], ["organizations"], ["organizationForm"],
                    ["organizationEdit"], ["employees"], ["employeeForm"], ["employeeEdit"], ["external"], ["externalForm"],
                    ["departments"], ["import"], ["importPreview"], ["notifications"], ["analytic"],
                ]) {
                    await fresh(page, hash);
                    const bounds = await client.evaluate(`
                        if (location.hash && document.querySelector(".app-tabs")) {
                            const panel = document.getElementById(location.hash.slice(1));
                            await until(() => panel?.classList.contains("show"));
                        }
                        await new Promise(requestAnimationFrame);
                        const title = document.querySelector("h1.page-title, h2.page-title, .page-pretitle");
                        const badInputs = [...document.querySelectorAll(".page-body :is(.form-control,.form-select,.ts-control > input)")]
                            .filter((input) => input.getBoundingClientRect().width > 10 && parseFloat(getComputedStyle(input).fontSize) < 16)
                            .map((input) => input.id || input.name);
                        const tabs = document.querySelector(".app-tabs");
                        const active = tabs?.querySelector("a.active")?.getBoundingClientRect();
                        const strip = tabs?.getBoundingClientRect();
                        return { scroll: document.documentElement.scrollWidth, width: innerWidth, pageTitle: Boolean(title?.getClientRects().length),
                            badInputs, activeTabVisible: !active || (active.left >= strip.left - 1 && active.right <= strip.right + 1),
                            ...(tabs && { tabOffset: tabs.scrollLeft, tabWidth: tabs.scrollWidth, active: [active?.left, active?.right], strip: [strip.left, strip.right],
                                activeName: tabs.querySelector("a.active")?.textContent.trim() }) };
                    `);
                    assert.ok(bounds.scroll <= bounds.width, `${page} ${hash ?? ""} at ${width}: ${JSON.stringify(bounds)}`);
                    assert.equal(bounds.pageTitle, true, page);
                    assert.equal(bounds.activeTabVisible, true, `${page} ${hash ?? ""} at ${width}: ${JSON.stringify(bounds)}`);
                    if (mobile) assert.deepEqual(bounds.badInputs, [], `${page} at ${width}`);
                    if (width === 320 && page === "employees") {
                        await client.evaluate(`
                            const cell = document.querySelector("#employeesTable tbody td.dtr-control");
                            cell.click();
                            await until(() => document.querySelector("#employeesTable tr.child .dtr-data")?.getClientRects().length);
                        `);
                    }
                    if (width === 320 && page === "importPreview") {
                        const preview = await client.evaluate(`
                            const details = document.querySelector(".import-row-data details");
                            const closed = !details.open;
                            details.querySelector("summary").click();
                            return { closed, expanded: details.open, text: details.textContent };
                        `);
                        assert.ok(preview.closed && preview.expanded);
                        assert.match(preview.text, /Mobile Preview/);
                    }
                }
            });
        }
        await suite.test("Dress Code formats and continues lists on mobile forms", async () => {
            await client.send("Emulation.setDeviceMetricsOverride", { width: 320, height: 720, deviceScaleFactor: 1, mobile: true });
            await client.send("Emulation.setTouchEmulationEnabled", { enabled: true });
            try {
                const values = {
                    eventCreate: "",
                    eventEdit: "Selama kegiatan\n\n• Batik\n• Sepatu tertutup",
                    dayCreate: "",
                    dayEdit: "Hari pertama\n\n1. Kemeja putih\n2. Sepatu hitam",
                };
                for (const [page, value] of Object.entries(values)) {
                    await fresh(page);
                    assert.deepEqual(await client.evaluate(`
                        const field = document.querySelector("[data-dress-code-editor] textarea");
                        const toolbar = document.querySelector("[data-dress-code-toolbar]");
                        return {
                            value: field.value,
                            buttons: toolbar.querySelectorAll("button").length,
                            fits: field.getBoundingClientRect().right <= innerWidth &&
                                document.documentElement.scrollWidth <= innerWidth &&
                                [...toolbar.querySelectorAll("button")].every((button) =>
                                    button.getBoundingClientRect().right <= innerWidth && button.getBoundingClientRect().height >= 44),
                        };
                    `), { value, buttons: 2, fits: true }, page);
                }

                await client.evaluate(`
                    window.dressField = document.querySelector("[data-dress-code-editor] textarea");
                    dressField.value = "Batik";
                    dressField.setSelectionRange(dressField.value.length, dressField.value.length);
                    document.querySelector('[data-list-format="bullet"]').click();
                `);
                await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 });
                await client.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 });
                assert.equal(await client.evaluate("return dressField.value;"), "• Batik\n• ");

                await client.evaluate(`
                    const enter = () => dressField.dispatchEvent(new InputEvent("beforeinput", { inputType: "insertLineBreak", cancelable: true }));
                    enter();
                    dressField.value = "Kemeja putih\\nSepatu hitam";
                    dressField.select();
                    document.querySelector('[data-list-format="number"]').click();
                    dressField.setSelectionRange("1. Kemeja putih".length, "1. Kemeja putih".length);
                    enter();
                `);
                assert.equal(await client.evaluate("return dressField.value;"), "1. Kemeja putih\n2. \n3. Sepatu hitam");
                await client.evaluate(`
                    dressField.setRangeText("Ikat pinggang", dressField.selectionStart, dressField.selectionEnd, "end");
                    dressField.form.addEventListener("submit", (event) => event.preventDefault(), { once: true });
                    dressField.form.dispatchEvent(new Event("submit", { cancelable: true }));
                `);
                assert.equal(await client.evaluate('return new FormData(dressField.form).get("dress_code");'),
                    "1. Kemeja putih\n2. Ikat pinggang\n3. Sepatu hitam");

                for (const [page, tag, style] of [["event", "ul", "disc"], ["day", "ol", "decimal"]]) {
                    await fresh(page);
                    const list = await client.evaluate(`
                        const list = document.querySelector("[data-dress-code-content] ${tag}");
                        return {
                            items: list.querySelectorAll("li").length,
                            style: getComputedStyle(list).listStyleType,
                            fits: list.getBoundingClientRect().width > 0 && list.getBoundingClientRect().right <= innerWidth,
                        };
                    `);
                    assert.deepEqual(list, { items: 2, style, fits: true }, page);
                }
            } finally {
                await client.send("Emulation.setTouchEmulationEnabled", { enabled: false });
                await client.send("Emulation.clearDeviceMetricsOverride");
            }
        });

        assert.deepEqual(client.errors, []);
    } finally {
        if (client) { await client.send("Page.close").catch(() => {}); client.socket.close(); }
        if (processHandle) { processHandle.kill(); }
        if (server) { await new Promise((resolve) => server.close(resolve)); }
        const profile = resolve(artifacts, "profile");
        if (profile.startsWith(resolve(artifacts) + sep)) {
            await rm(profile, { recursive: true, force: true, maxRetries: 3 }).catch(() => {});
        }
    }
});
