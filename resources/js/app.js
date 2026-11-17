import "@tabler/core";

import $ from "jquery";
import { initializeForms } from "./plugins/forms";
import { initializeDataTables } from "./plugins/datatables";
import { initializeNotify } from "./plugins/notify";
import { initializeTomSelect } from "./plugins/tom-select";
import { initializeEventPage } from "./events";

window.$ = window.jQuery = $;

function initializeApp() {
    initializeNotify($);
    initializeForms();
    initializeTomSelect();
    initializeEventPage();
    initializeDataTables();

    if (document.querySelector("[data-attendance-scanner]")) {
        import("./attendance-scanner").then(({ initializeAttendanceScanner }) => {
            initializeAttendanceScanner();
        }).catch(() => {
            document.querySelector("[data-scanner-feedback]").textContent =
                "QR Scanner gagal dimuat. Muat ulang halaman untuk mencoba kembali.";
        });
    }

    document.dispatchEvent(new Event("app:plugins-ready", { bubbles: true }));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
} else {
    initializeApp();
}
