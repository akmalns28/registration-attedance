import "@tabler/core/dist/js/tabler.min.js";

import $ from "jquery";
import { initializeForms } from "./plugins/forms";

function initializeApp() {
    initializeForms();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
} else {
    initializeApp();
}

window.$ = window.jQuery = $;

import { initializeDataTables } from "./plugins/datatables";
import { initializeNotify } from "./plugins/notify";

async function initializePlugins() {
    // Notify.js harus dimuat setelah jQuery tersedia secara global.
    await import("notifyjs-browser");

    initializeNotify($);

    initializeDataTables();

    window.dispatchEvent(new Event("app:plugins-ready"));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializePlugins);
} else {
    initializePlugins();
}
