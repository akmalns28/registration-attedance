import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { promisify } from "node:util";
import { browser, launchBrowser } from "./browser-helpers.mjs";

test("poster assignments fit mobile screens and submit only explicit changes", { skip: !browser, timeout: 120000 }, async () => {
    const directory = await mkdtemp(join(tmpdir(), "event-posters-browser-"));
    let server;
    let client;
    let processHandle;
    try {
        await promisify(execFile)("php", ["vendor/bin/phpunit", "tests/Feature/EventPosterTest.php",
            "--filter=test_posters_can_share_days_and_remain_event_wide_until_explicitly_assigned"], {
            windowsHide: true, timeout: 45000, env: { ...process.env, POSTER_BROWSER_ARTIFACT_DIR: directory },
        });
        const manifest = JSON.parse(await readFile("public/build/manifest.json", "utf8"));
        const styles = [manifest["resources/css/app.css"].file, ...(manifest["resources/js/app.js"].css ?? [])];
        const assets = new Map(await Promise.all(styles.map(async (file) => [`/build/${file}`, await readFile(`public/build/${file}`)])));
        const script = await readFile("resources/js/event-assets.js", "utf8");
        const pages = new Map(await Promise.all(["edit", "retry"].map(async (name) => {
            const html = await readFile(join(directory, `${name}.html`), "utf8");
            return [`/${name}`, html.replace("</head>", styles.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join("") + "</head>")];
        })));
        server = createServer((request, response) => {
            if (assets.has(request.url)) {
                response.writeHead(200, { "Content-Type": "text/css" }).end(assets.get(request.url));
            } else if (request.url === "/editor.js") {
                response.writeHead(200, { "Content-Type": "text/javascript" }).end(script);
            } else if (pages.has(request.url)) {
                response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(pages.get(request.url));
            } else {
                response.writeHead(404).end();
            }
        }).listen(0, "127.0.0.1");
        await once(server, "listening");
        ({ client, processHandle } = await launchBrowser(join(directory, "profile")));
        await client.send("Emulation.setDeviceMetricsOverride", {
            width: 320, height: 720, deviceScaleFactor: 1, mobile: true,
        });

        async function open(page) {
            const loaded = client.waitFor("Page.loadEventFired");
            await client.send("Page.navigate", { url: `http://127.0.0.1:${server.address().port}/${page}` });
            await loaded;
            await client.evaluate(`
                (await import("/editor.js")).initializeEventAssets();
                window.editor = document.querySelector("[data-poster-editor]");
                window.assignments = () => Array.from(new FormData(editor.closest("form")))
                    .filter(([name]) => name.startsWith("poster_assignments["));
                window.chooseFiles = (names) => {
                    const transfer = new DataTransfer();
                    names.forEach((name) => transfer.items.add(new File(["jpg"], name, { lastModified: 1234 })));
                    const input = editor.querySelector('input[type="file"]');
                    input.files = transfer.files;
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                };
            `);
        }

        await open("edit");
        const existing = await client.evaluate(`
            const tile = editor.querySelector('[data-poster-name][title="shared.jpg"]').closest("[data-poster-key]");
            const initial = assignments();
            const titleBefore = tile.querySelector("[data-poster-day-count]").textContent;
            tile.querySelector("summary").click();
            const first = tile.querySelector("[data-poster-day]");
            const label = first.closest("label");
            const tileWidth = tile.getBoundingClientRect().right;
            const fits = tileWidth <= document.documentElement.clientWidth && label.scrollWidth <= label.clientWidth;
            first.click();
            const specific = assignments();
            const titleAfter = tile.querySelector("[data-poster-day-count]").textContent;
            tile.querySelector("[data-poster-all-days]").click();
            return { initial, fits, titleBefore, titleAfter, specific, all: assignments(), key: tile.dataset.posterKey,
                date: label.textContent, selected: tile.querySelectorAll("[data-poster-day]:checked").length };
        `);
        assert.deepEqual(existing.initial, []);
        assert.equal(existing.fits, true);
        assert.match(existing.date, /Day 1 · Opening\s+15\/01\/2027/);
        assert.equal(existing.titleBefore, "2 hari");
        assert.equal(existing.titleAfter, "1 hari");
        assert.equal(existing.specific[0][1], "days");
        assert.equal(existing.specific.length, 3);
        assert.deepEqual(existing.all, [
            [`poster_assignments[${existing.key}][scope]`, "all"], [`poster_assignments[${existing.key}][day_count]`, "0"],
        ]);
        assert.equal(existing.selected, 0);

        await open("retry");
        const upload = await client.evaluate(`
            chooseFiles(["different.jpg", "retry.jpg"]);
            const tile = editor.querySelector('[data-poster-name][title="retry.jpg"]').closest("[data-poster-key]");
            const before = assignments();
            tile.querySelector('[data-poster-action="up"]').click();
            return { before, after: assignments(), open: tile.querySelector("details").open,
                count: tile.querySelector("[data-poster-day-count]").textContent };
        `);
        assert.deepEqual(upload.before.map(([name]) => name), ["scope", "day_count", "days", "upload_reference"].map((field) =>
            `poster_assignments[upload:1][${field}]${field === "days" ? "[]" : ""}`));
        assert.deepEqual(upload.after, upload.before.map(([name, value]) => [name.replace("upload:1", "upload:0"), value]));
        assert.equal(upload.open, true);
        assert.equal(upload.count, "1 hari");
        assert.deepEqual(client.errors, []);
    } finally {
        client?.socket.close();
        if (processHandle?.exitCode === null) {
            const closed = once(processHandle, "exit");
            processHandle.kill();
            await closed;
        }
        server?.close();
        await rm(directory, { recursive: true, force: true, maxRetries: 5 });
    }
});
