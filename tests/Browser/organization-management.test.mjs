import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { dirname, extname, join, resolve, sep } from "node:path";
import test from "node:test";
import { promisify } from "node:util";
import JSZip from "jszip";
import { browser, launchBrowser } from "./browser-helpers.mjs";

test("Organization index searches, lists and exports complete Entity relationships", { skip: !browser, timeout: 90000 }, async () => {
    const temp = resolve(tmpdir());
    const artifacts = await mkdtemp(join(temp, "organization-management-browser-"));
    let server, client, processHandle;
    try {
        await promisify(execFile)("php", ["vendor/bin/phpunit", "tests/Feature/OrganizationManagementTest.php",
            "--filter=test_index_search_actions_and_browser_fixtures"], {
            env: { ...process.env, ORGANIZATION_BROWSER_ARTIFACT_DIR: artifacts }, windowsHide: true, timeout: 30000,
        });
        const pages = {
            "/organizations": await readFile(join(artifacts, "organizations.html"), "utf8"),
            "/organizations/create": await readFile(join(artifacts, "organization-form.html"), "utf8"),
            "/employees/create": await readFile(join(artifacts, "employee-form.html"), "utf8"),
        };
        const originalOrigin = new URL(pages["/organizations/create"].match(/<form method="POST" action="([^"]+\/organizations)"/)[1]).origin;
        const publicRoot = resolve("public");
        let origin;
        server = createServer(async (request, response) => {
            const path = new URL(request.url, origin).pathname;
            if (Object.hasOwn(pages, path)) {
                response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(pages[path].replaceAll(originalOrigin, origin));
                return;
            }
            const file = resolve(publicRoot, `.${path}`);
            try {
                if (!file.startsWith(publicRoot + sep)) {
                    response.writeHead(404).end();
                    return;
                }
                const payload = await readFile(file);
                const types = { ".css": "text/css", ".js": "text/javascript", ".woff2": "font/woff2" };
                response.writeHead(200, { "Content-Type": types[extname(file)] ?? "application/octet-stream" }).end(payload);
            } catch {
                response.writeHead(404).end();
            }
        });
        server.listen(0, "127.0.0.1");
        await once(server, "listening");
        origin = `http://127.0.0.1:${server.address().port}`;
        ({ client, processHandle } = await launchBrowser(join(artifacts, "profile")));
        await client.send("Emulation.setDeviceMetricsOverride", { width: 1600, height: 900, deviceScaleFactor: 1, mobile: false });

        async function visit(path) {
            const loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: origin + path });
            await loaded;
        }

        await visit("/organizations");
        await client.send("Page.bringToFront");
        const listing = await client.evaluate(`
            window.until = async (predicate) => {
                const deadline = Date.now() + 8000;
                while (!predicate()) {
                    if (Date.now() > deadline) throw new Error("Organization index did not reach the expected state");
                    await new Promise((done) => setTimeout(done, 20));
                }
            };
            const field = () => document.querySelector('#organizationsTable_wrapper input[type="search"]');
            await until(field);
            const search = async (term) => {
                field().value = term;
                field().dispatchEvent(new Event("input", { bubbles: true }));
                await new Promise((done) => setTimeout(done, 25));
            };
            const rows = () => [...document.querySelectorAll("#organizationsTable tbody tr[data-organization-entity]")];
            const matches = [];
            for (const term of ["ZEB", "Zebra Department", "ZEN", "Zenith Division"]) {
                await search(term);
                matches.push(rows().map((row) => row.cells[0].textContent.trim()));
            }
            await search("");
            const employees = document.querySelector("#organizationsTable thead th:nth-child(5)");
            employees.querySelector(".dt-column-order").click();
            await until(() => employees.hasAttribute("aria-sort"));
            if (employees.getAttribute("aria-sort") !== "descending") {
                employees.querySelector(".dt-column-order").click();
                await until(() => employees.getAttribute("aria-sort") === "descending");
            }
            const first = rows()[0];
            const result = {
                matches,
                first: first.cells[0].textContent.trim(),
                departments: [...first.cells[2].querySelectorAll(".badge")].map((badge) => badge.textContent.trim()),
                divisions: [...first.cells[3].querySelectorAll(".badge")].map((badge) => badge.textContent.trim()),
                editVisible: first.querySelector('[aria-label="Edit Organisasi"]').getBoundingClientRect().width > 0,
                firstPageRows: rows().length,
            };
            document.querySelector('#organizationsTable_wrapper [data-dt-idx="next"]').click();
            result.remainingRows = rows().length;
            document.querySelector('#organizationsTable_wrapper [data-dt-idx="previous"]').click();
            window.organizationDialogOpens = 0;
            document.querySelector("#organization-related-modal").addEventListener("shown.bs.modal", () => window.organizationDialogOpens++);
            const overflow = rows()[0].querySelector('[data-organization-list="departments"]');
            overflow.focus();
            result.overflowFocused = document.activeElement === overflow;
            return result;
        `);
        assert.deepEqual(listing, {
            matches: [["HQ"], ["HQ"], ["HQ"], ["HQ"]],
            first: "HQ",
            departments: ["ENG — Engineering", "FIN — Finance", "OPS — Operations"],
            divisions: ["DEV — Development", "PAY — Payroll", "QA — Quality"],
            editVisible: true, firstPageRows: 10, remainingRows: 3, overflowFocused: true,
        });
        await client.send("Input.dispatchKeyEvent", { type: "rawKeyDown", key: " ", code: "Space", windowsVirtualKeyCode: 32 });
        await client.send("Input.dispatchKeyEvent", { type: "keyUp", key: " ", code: "Space", windowsVirtualKeyCode: 32 });
        const modal = await client.evaluate(`
            const modal = document.querySelector("#organization-related-modal");
            await until(() => window.organizationDialogOpens === 1);
            const departments = modal.querySelector('[data-organization-modal-section="departments"]');
            const divisions = modal.querySelector('[data-organization-modal-section="divisions"]');
            const departmentButton = document.querySelector('#organizationsTable [data-organization-list="departments"]');
            const shownDepartments = !departments.hidden && divisions.hidden && departments.querySelectorAll("li").length === 4
                && departments.innerText.includes("Zebra Department");
            const label = departmentButton.textContent.trim();
            const close = async () => {
                let hidden = false;
                modal.addEventListener("hidden.bs.modal", () => { hidden = true; }, { once: true });
                modal.querySelector(".btn-close").click();
                await until(() => hidden);
            };
            await close();
            const focusReturned = document.activeElement === departmentButton;
            document.querySelector('#organizationsTable [data-organization-list="divisions"]').click();
            await until(() => window.organizationDialogOpens === 2);
            const lastDivision = divisions.querySelector("li:last-child");
            const shownDivisions = !divisions.hidden && departments.hidden && divisions.querySelectorAll("li").length === 4
                && lastDivision.innerText.includes("Zenith Division") && lastDivision.querySelector("small").textContent.includes("Zebra Department");
            await close();
            document.querySelector('#organizationsTable [data-organization-list="all"]').click();
            await until(() => window.organizationDialogOpens === 3);
            const shownEntity = !divisions.hidden && !departments.hidden
                && modal.querySelector("[data-organization-modal-employees]").textContent === "2 Karyawan";
            await close();
            return { shownDepartments, shownDivisions, shownEntity, focusReturned, label,
                modalCount: document.querySelectorAll("#organization-related-modal").length };
        `);
        assert.deepEqual(modal, { shownDepartments: true, shownDivisions: true, shownEntity: true,
            focusReturned: true, label: "+1 lainnya", modalCount: 1 });

        await client.send("Browser.setDownloadBehavior", { behavior: "allow", downloadPath: artifacts });
        async function download(format, extension) {
            await client.evaluate(`
                const menuButton = document.querySelector("#organizationsTable_wrapper .buttons-collection");
                menuButton.click();
                await until(() => document.querySelector(".dt-button-collection .buttons-${format}"));
                document.querySelector(".dt-button-collection .buttons-${format}").click();
            `);
            const deadline = Date.now() + 12000;
            while (true) {
                try {
                    return await readFile(join(artifacts, "organization-management." + extension));
                } catch (error) {
                    if (Date.now() > deadline) throw error;
                    await new Promise((done) => setTimeout(done, 40));
                }
            }
        }
        const csv = (await download("csv", "csv")).toString("utf8");
        assert.equal(csv.trim().split(/\r?\n/).length, 14);
        assert.match(csv, /ZEB — Zebra Department/);
        assert.match(csv, /ZEN — Zenith Division.+\(ZEB — Zebra Department\)/);
        assert.doesNotMatch(csv, /lainnya|Lihat Organisasi/);
        const book = await JSZip.loadAsync(await download("excel", "xlsx"));
        const worksheet = await book.file("xl/worksheets/sheet1.xml").async("string");
        assert.match(worksheet, /Zenith Division/);
        assert.match(worksheet, /Zebra Department/);
        assert.ok((await download("pdf", "pdf")).toString("latin1").startsWith("%PDF-"));

        const printed = await client.evaluate(`
            window.printPopup = null;
            const open = window.open;
            window.open = (...args) => (window.printPopup = open.apply(window, args));
            document.querySelector("#organizationsTable_wrapper .buttons-collection").click();
            await until(() => document.querySelector(".dt-button-collection .buttons-print"));
            document.querySelector(".dt-button-collection .buttons-print").click();
            await until(() => printPopup?.document.querySelector("tbody tr"));
            const body = printPopup.document.querySelector("tbody");
            const result = { rows: body.rows.length, includesHiddenItems: body.textContent.includes("Zebra Department")
                && body.textContent.includes("Zenith Division") };
            printPopup.close();
            window.open = open;
            return result;
        `);
        assert.deepEqual(printed, { rows: 13, includesHiddenItems: true });

        await client.send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        assert.equal(await client.evaluate(`
            await until(() => document.querySelector("#organizationsTable.collapsed"));
            return document.documentElement.scrollWidth <= document.documentElement.clientWidth;
        `), true);

        await visit("/organizations/create");
        const form = await client.evaluate(`
            const page = document.querySelector("[data-organization-form]");
            const fill = (row, code, name) => {
                row.querySelector("[data-organization-code]").value = code;
                row.querySelector("[data-organization-name]").value = name;
            };
            const open = page.querySelector("[data-organization-review-open]");
            const review = page.querySelector("[data-organization-review]");
            const confirm = page.querySelector("[data-organization-review-confirm]");
            let submitted = 0;
            page.addEventListener("submit", (event) => { submitted++; event.preventDefault(); });
            open.click();
            const invalidStayedOnForm = review.hidden && submitted === 0;

            fill(page, "HQ", "Head Office");
            page.querySelector("[data-organization-root] [data-organization-status]").value = "0";
            page.querySelector("[data-add-department]").click();
            const department = page.querySelector("[data-department]");
            fill(department, "OPS", "Operations");
            department.querySelector("[data-add-division]").click();
            fill(department.querySelector("[data-division]"), "QA", "Quality");
            department.querySelector("[data-add-division]").click();
            [...department.querySelectorAll("[data-remove-division]")].at(-1).click();
            page.querySelector("[data-add-department]").click();
            fill([...page.querySelectorAll("[data-department]")].at(-1), "IT", "Technology");
            page.querySelector("[data-add-department]").click();
            [...page.querySelectorAll("[data-remove-department]")].at(-1).click();
            open.click();
            const reviewBeforeSave = !review.hidden && submitted === 0 && !confirm.disabled
                && review.querySelector("[data-organization-review-name]").textContent === "HQ — Head Office"
                && review.querySelector("[data-organization-review-status]").textContent === "Tidak Aktif"
                && review.querySelector("[data-organization-review-count]").textContent === "2 Departemen · 1 Divisi";
            review.querySelector("[data-organization-review-back]").click();
            const returnedToDraft = review.hidden && open === document.activeElement && department.querySelector("[data-organization-name]").value === "Operations";
            open.click();
            const field = department.querySelector("[data-organization-name]");
            field.value = "Operations and Support";
            field.dispatchEvent(new Event("input", { bubbles: true }));
            const editingClearedReview = review.hidden;
            open.click();
            confirm.click();
            confirm.click();
            const data = Object.fromEntries(new FormData(page));
            return { invalidStayedOnForm, reviewBeforeSave, returnedToDraft, editingClearedReview,
                submitted, disabled: confirm.disabled, departments: page.querySelectorAll("[data-department]").length,
                divisions: page.querySelectorAll("[data-division]").length, values: Object.values(data),
                childStatuses: [...page.querySelectorAll("[data-department] [data-organization-status]")].map((field) => field.value),
                fits: document.documentElement.scrollWidth <= document.documentElement.clientWidth };
        `);
        assert.equal(form.invalidStayedOnForm, true);
        assert.equal(form.reviewBeforeSave, true);
        assert.equal(form.returnedToDraft, true);
        assert.equal(form.editingClearedReview, true);
        assert.equal(form.submitted, 1);
        assert.equal(form.disabled, true);
        assert.equal(form.departments, 2);
        assert.equal(form.divisions, 1);
        assert.deepEqual(form.childStatuses, ["1", "1", "1"]);
        assert.ok(form.values.includes("Quality"));
        assert.ok(form.values.includes("Operations and Support"));
        assert.equal(form.fits, true);

        for (const width of [768, 1440]) {
            await client.send("Emulation.setDeviceMetricsOverride", { width, height: 900, deviceScaleFactor: 1, mobile: false });
            assert.equal(await client.evaluate("return document.documentElement.scrollWidth <= document.documentElement.clientWidth;"), true);
        }

        await visit("/employees/create");
        const cascade = await client.evaluate(`
            const [entity, department, division] = ["entity", "department", "division"]
                .map((level) => document.querySelector("#" + level + "_id"));
            const selectCode = (select, code) => {
                const options = Object.values(select.tomselect.options);
                const option = options.find(({ text }) => text.trim().startsWith("[" + code + "]"));
                if (!option) throw new Error("Missing " + code + " in " + select.name + ": " + options.map(({ text }) => text.trim()));
                select.tomselect.setValue(option.value);
            };
            selectCode(entity, "HQ");
            const departmentScoped = !Object.values(department.tomselect.options).some(({ text }) => text.includes("[SUP]"));
            selectCode(department, "OPS");
            selectCode(division, "QA");
            const assigned = Boolean(department.value && division.value);
            selectCode(entity, "BR");
            return { departmentScoped, assigned, cleared: !department.value && !division.value,
                available: Object.values(department.tomselect.options).some(({ text }) => text.trim().startsWith("[SUP]")) };
        `);
        assert.deepEqual(cascade, { departmentScoped: true, assigned: true, cleared: true, available: true });
        assert.deepEqual(client.errors, []);
    } finally {
        client?.socket.close();
        const exited = processHandle && processHandle.exitCode === null ? once(processHandle, "exit") : null;
        processHandle?.kill();
        await exited;
        if (server?.listening) {
            await new Promise((done) => server.close(done));
        }
        if (dirname(artifacts) === temp && artifacts.startsWith(join(temp, "organization-management-browser-"))) {
            await rm(artifacts, { recursive: true, force: true, maxRetries: 5 });
        }
    }
});
