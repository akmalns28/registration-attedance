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
    try {
        const generated = await promisify(execFile)("php", ["vendor/bin/phpunit", "tests/Feature/UiRefinementTest.php",
            "--filter=test_browser_fixtures_cover_management_tables_and_navigation"], {
            cwd: process.cwd(), windowsHide: true, timeout: 30000, env: { ...process.env, UI_BROWSER_ARTIFACT_DIR: artifacts },
        });
        assert.match(generated.stdout, /passed/);
        const fixture = JSON.parse(await readFile(join(artifacts, "fixture.json"), "utf8"));
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
                window.until = async (predicate) => {
                    const deadline = Date.now() + 12000;
                    while (!predicate()) { if (Date.now() > deadline) { throw new Error("UI did not become ready"); } await new Promise((resolve) => setTimeout(resolve, 20)); }
                };
                await until(() => window.notify?.system === "attendance" && (${["dashboard", "notifications", "employeeForm"].includes(page)} || document.querySelector(".dt-container")));
                if (!document.querySelector('script[type="module"][src*="/build/"]') || document.querySelector('script[src*="@vite/client"]')) {
                    throw new Error("UI verification must use production Vite assets");
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

        for (const [page, filename] of Object.entries({ events: "event-management", employees: "employee-management", external: "external-participants",
            departments: "department-management", users: "user-management", roles: "role-management", event: "event-participants" })) {
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

        await suite.test("SVG action clicks still open destructive confirmation with the correct row and support Escape", async () => {
            await fresh("departments");
            const result = await client.evaluate(`
                const button = document.querySelector('[data-row-action][data-delete-url]');
                button.querySelector("svg path").dispatchEvent(new MouseEvent("click", { bubbles: true }));
                await until(() => document.getElementById("deleteDepartmentModal").classList.contains("show"));
                return { name: document.getElementById("deleteDepartmentName").textContent, expectedName: button.dataset.deleteName,
                    url: document.getElementById("deleteDepartmentForm").action, expectedUrl: button.dataset.deleteUrl,
                    csrf: Boolean(document.querySelector('#deleteDepartmentForm input[name="_token"]')), injected: Boolean(window.uiXss) };
            `);
            assert.equal(result.name, result.expectedName);
            assert.equal(result.url, result.expectedUrl);
            assert.equal(result.csrf, true);
            assert.equal(result.injected, false);
            await client.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
            await client.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
            await client.evaluate(`await until(() => !document.getElementById("deleteDepartmentModal").classList.contains("show"));`);
        });

        for (const [width, height] of [[1440, 900], [375, 620]]) {
            await suite.test(`employee picker layers, searches and submits multiple employees at ${width}px`, async () => {
                await client.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 800 });
                await fresh("event", "#event-participants");
                const dropdown = await client.evaluate(`
                    const modal = document.querySelector("#addEmployeeModal");
                    const shown = new Promise((resolve) => modal.addEventListener("shown.bs.modal", resolve, { once: true }));
                    document.querySelector('[data-bs-target="#addEmployeeModal"]').click(); await shown;
                    const select = document.querySelector("#employee_ids");
                    window.employeePicker = select.tomselect;
                    const picker = employeePicker;
                    picker.control.click();
                    await until(() => picker.isOpen).catch(() => {
                        throw new Error(JSON.stringify({
                            focus: document.activeElement?.outerHTML.slice(0, 160), focused: picker.isFocused,
                            options: Object.keys(picker.options).length, locked: picker.isLocked, openOnFocus: picker.settings.openOnFocus,
                            input: picker.control_input.outerHTML, display: getComputedStyle(picker.control_input).display,
                        }));
                    });
                    const anchor = select.closest("[data-tom-select-dropdown-parent]");
                    const bounds = picker.dropdown.getBoundingClientRect();
                    const control = picker.wrapper.getBoundingClientRect();
                    const capacity = modal.querySelector(".row.g-3").getBoundingClientRect();
                    const overlapY = Math.max(bounds.top, capacity.top) + 8;
                    const first = picker.dropdown.querySelector(".option");
                    const activeBackground = getComputedStyle(picker.dropdown.querySelector(".option.active")).backgroundColor;
                    return {
                        isMulti: select.multiple && picker.settings.maxItems === null,
                        anchored: picker.settings.dropdownParent === anchor && picker.dropdown.parentElement === anchor,
                        externalDefault: document.querySelector("#addExternalModal select").tomselect.settings.dropdownParent === null,
                        white: getComputedStyle(picker.dropdown).backgroundColor === "rgb(255, 255, 255)",
                        activeVisible: activeBackground !== "rgba(0, 0, 0, 0)",
                        aligned: bounds.top >= control.bottom && bounds.top - control.bottom < 8
                            && Math.abs(bounds.left - control.left) < 1 && Math.abs(bounds.width - control.width) < 1,
                        inViewport: bounds.left >= 0 && bounds.right <= innerWidth && bounds.bottom <= innerHeight,
                        overlaysCards: overlapY < bounds.bottom && overlapY < capacity.bottom && picker.dropdown.contains(document.elementFromPoint(bounds.left + 25, overlapY)),
                        scrollable: picker.dropdown_content.scrollHeight > picker.dropdown_content.clientHeight,
                        readable: getComputedStyle(first).color === "rgb(24, 36, 51)",
                    };
                `);
                assert.deepEqual(dropdown, { isMulti: true, anchored: true, externalDefault: true, white: true, activeVisible: true,
                    aligned: true, inViewport: true, overlaysCards: true, scrollable: true, readable: true });
                const screenshot = await client.send("Page.captureScreenshot", { format: "png" });
                await writeFile(join(artifacts, `employee-picker-${width}.png`), Buffer.from(screenshot.data, "base64"));

                await client.send("Input.insertText", { text: "Kandidat Alfa Picker" });
                const alpha = await client.evaluate(`
                    await until(() => employeePicker.dropdown.querySelectorAll(".option").length === 1
                        && employeePicker.dropdown.querySelector(".option").textContent.includes("0001234501"));
                    return employeePicker.dropdown.querySelector(".option").dataset.value;
                `);
                for (const [key, code] of [["ArrowDown", 40], ["Enter", 13]]) {
                    await client.send("Input.dispatchKeyEvent", { type: "keyDown", key, code: key, windowsVirtualKeyCode: code });
                    await client.send("Input.dispatchKeyEvent", { type: "keyUp", key, code: key, windowsVirtualKeyCode: code });
                }
                await client.evaluate(`await until(() => employeePicker.items.length === 1);
                    await until(() => employeePicker.dropdown.querySelector(".option.selected"));
                    if (getComputedStyle(employeePicker.dropdown.querySelector(".option.selected")).backgroundColor === "rgba(0, 0, 0, 0)") {
                        throw new Error("Selected employee has no visible state");
                    }`);
                await client.send("Input.insertText", { text: "0001234502" });
                const result = await client.evaluate(`
                    await until(() => employeePicker.dropdown.querySelectorAll(".option").length === 1
                        && employeePicker.dropdown.querySelector(".option").textContent.includes("Kandidat Beta Picker"));
                    const option = employeePicker.dropdown.querySelector(".option");
                    option.dispatchEvent(new MouseEvent("click", { bubbles: true }));
                    const form = document.querySelector("#employee_ids").form;
                    return { beta: option.dataset.value, submitted: new FormData(form).getAll("participant_ids[]"),
                        chips: employeePicker.control.querySelectorAll(".item .remove").length };
                `);
                assert.deepEqual(result.submitted, [alpha, result.beta]);
                assert.equal(result.chips, 2);
            });
        }

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
