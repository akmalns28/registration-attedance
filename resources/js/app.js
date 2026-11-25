import "@tabler/core";

import $ from "jquery";
import { initializeForms } from "./plugins/forms";
import { initializeDataTables } from "./plugins/datatables";
import { initializeNotify } from "./plugins/notify";
import { initializeTomSelect } from "./plugins/tom-select";
import { initializeEventPage } from "./events";
import { initializeEventAssets } from "./event-assets";
import { initializeMobileLayout } from "./mobile-layout";
import { initializeOrganizationForm, initializeOrganizationIndex, initializeOrganizationSelections } from "./organizations";

window.$ = window.jQuery = $;

function initializeApp() {
    initializeNotify($);
    initializeForms();
    initializeOrganizationForm();
    initializeOrganizationIndex();
    initializeOrganizationSelections();
    initializeTomSelect();
    initializeMobileLayout();
    initializeEventPage();
    initializeEventAssets();
    initializeDataTables();

    if (document.querySelector("[data-dashboard-chart]")) {
        import("./dashboard").then(({ initializeDashboardCharts }) => initializeDashboardCharts()).catch(() => {
            document.querySelector("[data-dashboard-chart-error]").classList.remove("d-none");
        });
    }

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

    if (document.querySelector("[data-manual-attendance]")) {
        import("./manual-attendance").then(({ initializeManualAttendance }) => {
            initializeManualAttendance();
        }).catch(() => {
            document.getElementById("manual-status").textContent = "Manual Check-in gagal dimuat. Muat ulang halaman.";
        });
    }

    document.dispatchEvent(new Event("app:plugins-ready", { bubbles: true }));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
} else {
    initializeApp();
}
