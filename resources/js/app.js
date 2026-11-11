import "@tabler/core/dist/js/tabler.min.js";

import $ from "jquery";
import { initializeForms } from "./plugins/forms";
import { initializeDataTables } from "./plugins/datatables";
import { initializeNotify } from "./plugins/notify";

window.$ = window.jQuery = $;

function initializeApp() {
    initializeNotify($);
    initializeForms();
    initializeDataTables();

    window.dispatchEvent(new Event("app:plugins-ready"));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
} else {
    initializeApp();
}
