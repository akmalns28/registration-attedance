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

    if (document.querySelector("[data-attendance-reports]")) {
        import("./attendance-reports").then(({ initializeAttendanceReports }) => {
            initializeAttendanceReports();
        }).catch(() => {
            const feedback = document.querySelector("[data-report-feedback]");
            feedback.className = "alert alert-danger";
            feedback.textContent = "Laporan gagal dimuat. Muat ulang halaman untuk mencoba kembali.";
        });
    }

    if (document.querySelector("[data-analytic-page]")) {
        import("./analytic").then(({ initializeAnalyticPage }) => {
            initializeAnalyticPage();
        }).catch(() => {
            const feedback = document.querySelector("[data-analytic-feedback]");
            feedback.className = "alert alert-danger";
            feedback.textContent = "Grafik Analytic gagal dimuat. Muat ulang halaman untuk mencoba kembali.";
        });
    }

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
