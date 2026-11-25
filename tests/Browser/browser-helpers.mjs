import { spawn } from "node:child_process";
import { once } from "node:events";
import { existsSync } from "node:fs";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

export const browser = [process.env.REPORT_BROWSER_BINARY, "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"]
    .find((path) => path && existsSync(path));

export class DevTools {
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
            if (message.method === "Page.javascriptDialogOpening") {
                this.errors.push(`Unexpected browser dialog: ${message.params.message}`);
                this.send("Page.handleJavaScriptDialog", { accept: true }).catch(() => {});
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
                if (JSON.parse(data).method === method) {
                    this.socket.removeEventListener("message", listener);
                    clearTimeout(timeout);
                    resolve();
                }
            };
            const timeout = setTimeout(() => { this.socket.removeEventListener("message", listener); reject(new Error(`Browser event timed out: ${method}`)); }, 15000);
            this.socket.addEventListener("message", listener);
        });
    }
}

export async function launchBrowser(profile) {
    const processHandle = spawn(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
        ...(process.env.REPORT_BROWSER_NO_SANDBOX === "1" ? ["--no-sandbox"] : []),
        "--disable-background-networking", "--no-proxy-server", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"],
    { windowsHide: true, stdio: "ignore" });
    try {
        const deadline = Date.now() + 15000;
        while (!existsSync(join(profile, "DevToolsActivePort"))) {
            if (Date.now() > deadline) { throw new Error("Browser did not start"); }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        const port = (await readFile(join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0];
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        const target = targets.find(({ type }) => type === "page");
        const socket = new WebSocket(target.webSocketDebuggerUrl);
        await once(socket, "open");
        const client = new DevTools(socket);
        await client.send("Page.enable");
        await client.send("Runtime.enable");
        return { client, processHandle };
    } catch (error) {
        processHandle.kill();
        throw error;
    }
}
