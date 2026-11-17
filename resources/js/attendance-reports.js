import DataTable from "datatables.net-bs5";
import "datatables.net-bs5/css/dataTables.bootstrap5.css";
import "datatables.net-responsive-bs5/css/responsive.bootstrap5.css";
import { getDataTableOptions, adjustDataTables } from "./plugins/datatables";

const filters = {
    report_day: "event_day", report_type: "participant_type", report_status: "attendance_status", report_department: "department_id",
};
const number = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });

export class AttendanceReports {
    constructor(page, { request = window.fetch.bind(window) } = {}) {
        this.page = page;
        this.request = request;
        this.form = page.querySelector("[data-report-filters]");
        this.feedback = page.querySelector("[data-report-feedback]");
        this.search = this.form.elements.namedItem("report_search");
        this.department = this.form.elements.namedItem("report_department");
        this.type = this.form.elements.namedItem("report_type");
        this.refreshButton = page.querySelector("[data-report-refresh]");
        this.tableElement = page.querySelector("[data-attendance-report-table]");
        this.table = null;
        this.sequence = 0;
        this.destroyed = false;
        this.listeners = new AbortController();
        this.parameters = new URLSearchParams(location.search);
        for (const name of [...Object.keys(filters), "report_search"]) {
            const field = this.form.elements.namedItem(name);
            const value = this.parameters.get(name) ?? "";
            if (field instanceof HTMLSelectElement && value && !Array.from(field.options).some((option) => option.value === value)) {
                const invalid = new Option("Filter tidak valid", value);
                invalid.dataset.invalidFilter = "true";
                field.add(invalid);
            }
            field.value = value;
        }
        this.department.disabled = this.type.value === "external";
        const options = { signal: this.listeners.signal };
        this.form.addEventListener("submit", (event) => {
            event.preventDefault();
            this.applyFilters();
        }, options);
        this.form.addEventListener("change", (event) => {
            if (event.target === this.type) {
                this.department.disabled = this.type.value === "external";
                if (this.department.disabled) {
                    this.department.value = "";
                }
            }
            this.applyFilters();
        }, options);
        this.search.addEventListener("input", () => {
            window.clearTimeout(this.searchTimer);
            this.searchTimer = window.setTimeout(() => this.applyFilters(), 350);
        }, options);
        page.querySelector("[data-report-reset]").addEventListener("click", () => {
            window.clearTimeout(this.searchTimer);
            this.form.reset();
            this.form.querySelectorAll("[data-invalid-filter]").forEach((option) => option.remove());
            this.department.disabled = false;
            if (this.table) {
                this.table.page.len(10).order([6, "asc"]).search("").draw();
            } else {
                this.start();
            }
        }, options);
        document.getElementById("event-reports-tab")?.addEventListener("shown.bs.tab", () => this.start(), options);
        window.addEventListener("pagehide", (event) => {
            if (event.persisted) {
                this.sequence++;
                this.abort?.abort();
                window.clearTimeout(this.searchTimer);
                this.table?.processing(false);
                this.setLoading(false);
            } else {
                this.destroy();
            }
        }, options);
        if (page.closest(".tab-pane")?.classList.contains("active")) {
            this.start();
        }
    }

    start() {
        if (this.destroyed) {
            return;
        }
        if (this.table) {
            adjustDataTables(this.page);
            return;
        }
        const text = DataTable.render.text();
        const keys = ["row_number", "registration_number", "participant_name", "participant_type", "department_name", "event_day", "event_day_date", "attendance_status", "checkin_time", "operator_name"];
        const sortColumn = Number(this.parameters.get("report_sort"));
        const pageLength = Number(this.parameters.get("report_length"));
        this.table = new DataTable(this.tableElement, {
            ...getDataTableOptions(this.tableElement),
            serverSide: true,
            pageLength: [10, 25, 50, 100].includes(pageLength) ? pageLength : 10,
            displayStart: Math.max(0, Math.min(2147483647, Number.parseInt(this.parameters.get("report_start"), 10) || 0)),
            order: [[sortColumn >= 1 && sortColumn <= 9 ? sortColumn : 6, this.parameters.get("report_direction") === "desc" ? "desc" : "asc"]],
            search: { search: this.search.value },
            layout: { topStart: { pageLength: {} }, topEnd: null, bottomStart: { info: {} }, bottomEnd: { paging: {} } },
            columns: keys.map((key) => ({ data: key, render: key === "attendance_status"
                ? (value) => value === "present" ? "Hadir" : "Belum Hadir" : text,
                orderable: key !== "row_number", responsivePriority: key === "participant_name" ? 1 : key === "attendance_status" ? 2 : undefined })),
            ajax: (data, callback) => { this.load(data, callback); },
        });
        this.tableElement.setAttribute("data-datatable", "");
        this.table.on("draw", () => this.persistFilters());
    }

    applyFilters() {
        window.clearTimeout(this.searchTimer);
        if (!this.table) {
            this.start();
        } else {
            this.table.search(this.search.value.trim()).draw();
        }
    }

    setLoading(loading) {
        this.page.setAttribute("aria-busy", String(loading));
        this.refreshButton.disabled = loading;
        this.refreshButton.textContent = loading ? "Memuat..." : "Perbarui";
        if (loading) {
            this.feedback.className = "alert alert-info";
            this.feedback.textContent = "Memuat laporan dan ringkasan kehadiran...";
        }
    }

    summary(values) {
        this.page.querySelectorAll("[data-report-summary]").forEach((card) => {
            const key = card.dataset.reportSummary;
            card.querySelector(".h1").textContent = typeof values?.[key] === "number"
                ? `${number.format(values[key])}${key === "percentage" ? "%" : ""}` : "—";
        });
    }

    async load(data, callback) {
        const sequence = ++this.sequence;
        this.abort?.abort();
        const abort = new AbortController();
        this.abort = abort;
        const parameters = new URLSearchParams({ draw: data.draw, start: data.start, length: data.length, "search[value]": data.search.value });
        const selected = {};
        for (const [name, parameter] of Object.entries(filters)) {
            selected[name] = this.form.elements.namedItem(name).value;
            if (selected[name]) {
                parameters.set(parameter, selected[name]);
            }
        }
        if (data.order[0]) {
            parameters.set("order[0][column]", data.order[0].column);
            parameters.set("order[0][dir]", data.order[0].dir);
        }
        this.setLoading(true);
        const timeout = window.setTimeout(() => abort.abort(), 20000);
        try {
            const response = await this.request(`${this.page.dataset.url}?${parameters}`, {
                credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, signal: abort.signal,
            });
            const body = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
            if (sequence !== this.sequence || this.destroyed) {
                return;
            }
            if (!response.ok || !Array.isArray(body?.data) || !body.summary || body.draw !== data.draw) {
                throw new Error(Object.values(body?.errors ?? {}).flat().join(" ") ||
                    (response.redirected || [401, 403, 419].includes(response.status) ? "Akses laporan berakhir. Muat ulang halaman atau login kembali." : "Laporan belum dapat dimuat. Tekan Perbarui untuk mencoba kembali."));
            }
            this.lastFilters = { ...selected, report_search: data.search.value };
            this.summary(body.summary);
            callback(body);
            this.feedback.className = "d-none";
            this.feedback.textContent = "";
        } catch (error) {
            if (sequence === this.sequence && !this.destroyed) {
                this.lastFilters = null;
                this.summary(null);
                callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                const message = error.name === "AbortError" ? "Permintaan melewati batas waktu. Tekan Perbarui untuk mencoba kembali." : error.message;
                this.feedback.className = "alert alert-danger";
                this.feedback.textContent = message;
                window.notify?.error(message);
            }
        } finally {
            window.clearTimeout(timeout);
            if (sequence === this.sequence && !this.destroyed) {
                this.setLoading(false);
            }
        }
    }

    persistFilters() {
        if (!this.lastFilters || this.destroyed) {
            return;
        }
        const url = new URL(location.href);
        for (const [name, value] of Object.entries(this.lastFilters)) {
            value ? url.searchParams.set(name, value) : url.searchParams.delete(name);
        }
        const info = this.table.page.info();
        const [column, direction] = this.table.order()[0];
        const state = { report_start: info.start, report_length: info.length, report_sort: column, report_direction: direction };
        for (const [name, value] of Object.entries(state)) {
            url.searchParams.set(name, String(value));
        }
        url.hash = "event-reports";
        history.replaceState(null, "", url);
    }

    destroy() {
        this.destroyed = true;
        this.sequence++;
        this.abort?.abort();
        window.clearTimeout(this.searchTimer);
        this.listeners.abort();
        this.table?.destroy();
    }
}

export function initializeAttendanceReports(root = document) {
    const page = root.querySelector("[data-attendance-reports]");
    if (!page || page.dataset.initialized === "true") {
        return;
    }
    page.dataset.initialized = "true";
    return new AttendanceReports(page);
}
