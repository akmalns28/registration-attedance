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
        this.exportButtons = [...page.querySelectorAll("[data-report-export]")];
        this.exportFeedback = page.querySelector("[data-report-export-feedback]");
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
        this.exportButtons.forEach((button) => {
            button.dataset.label = button.querySelector("span").textContent;
            button.addEventListener("click", () => this.exportReport(button), options);
        });
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
            this.updateExportButtons();
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
                this.exportAbort?.abort();
                this.printWindow?.close();
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
        this.loading = loading;
        this.updateExportButtons();
        this.page.setAttribute("aria-busy", String(loading));
        this.refreshButton.disabled = loading;
        (this.refreshButton.querySelector("[data-button-label]") ?? this.refreshButton).textContent = loading ? "Memuat..." : "Perbarui";
        if (loading) {
            this.feedback.className = "alert alert-info";
            this.feedback.textContent = "Memuat laporan dan ringkasan kehadiran...";
        }
    }

    updateExportButtons() {
        const changed = !this.lastFilters || [...Object.keys(filters), "report_search"].some((name) =>
            this.form.elements.namedItem(name).value.trim() !== String(this.lastFilters[name] ?? "").trim());
        this.exportButtons.forEach((button) => {
            button.disabled = this.destroyed || this.loading || Boolean(this.exporting) || changed;
            button.querySelector("span").textContent = this.exporting === button.dataset.reportExport ? "Menyiapkan..." : button.dataset.label;
        });
    }

    async exportReport(button) {
        if (button.disabled || this.exporting || this.destroyed) {
            return;
        }
        const format = button.dataset.reportExport;
        const parameters = new URLSearchParams();
        for (const [name, parameter] of Object.entries(filters)) {
            const value = this.form.elements.namedItem(name).value;
            if (value) {
                parameters.set(parameter, value);
            }
        }
        parameters.set("search[value]", this.search.value.trim());
        const [column, direction] = this.table.order()[0];
        parameters.set("order[0][column]", column);
        parameters.set("order[0][dir]", direction);
        let popup;
        if (format === "print") {
            popup = window.open("about:blank", "_blank");
            if (!popup) {
                this.showExportMessage("Izinkan popup untuk membuka halaman Print laporan.", true);
                return;
            }
            popup.opener = null;
            popup.document.title = "Menyiapkan laporan";
            popup.document.body.textContent = "Menyiapkan laporan untuk dicetak...";
            this.printWindow = popup;
        }
        this.exporting = format;
        this.updateExportButtons();
        this.showExportMessage("Menyiapkan seluruh hasil filter untuk ekspor...");
        const abort = new AbortController();
        this.exportAbort = abort;
        const timeout = window.setTimeout(() => abort.abort(), 120000);
        const mime = { excel: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", csv: "text/csv", pdf: "application/pdf", print: "text/html" }[format];
        try {
            const response = await this.request(`${button.dataset.url}?${parameters}`, {
                credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, signal: abort.signal,
            });
            const contentType = response.headers.get("content-type") || "";
            if (!response.ok || response.redirected || !contentType.includes(mime)) {
                const body = contentType.includes("application/json") ? await response.json() : null;
                throw new Error(Object.values(body?.errors ?? {}).flat().join(" ") ||
                    (response.redirected || [401, 403, 419].includes(response.status) ? "Akses ekspor berakhir. Muat ulang halaman atau login kembali." : "Ekspor belum dapat dibuat. Silakan coba kembali."));
            }
            const blob = await response.blob();
            if (this.destroyed || abort.signal.aborted) {
                return;
            }
            const url = URL.createObjectURL(blob);
            window.setTimeout(() => URL.revokeObjectURL(url), 120000);
            if (popup) {
                if (popup.closed) {
                    throw new Error("Halaman Print telah ditutup. Tekan Print untuk membuka kembali.");
                }
                popup.location.replace(url);
                this.printWindow = null;
            } else {
                const disposition = response.headers.get("content-disposition") || "";
                const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1];
                const link = document.createElement("a");
                link.href = url;
                link.download = filename?.replace(/[\\/\x00-\x1f]/g, "_") || `attendance-report.${format === "excel" ? "xlsx" : format}`;
                document.body.append(link);
                link.click();
                link.remove();
            }
            this.showExportMessage(popup ? "Laporan siap dicetak pada tab baru." : "Unduhan laporan telah disiapkan.");
        } catch (error) {
            popup?.close();
            if (!this.destroyed) {
                this.showExportMessage(error.name === "AbortError" ? "Ekspor melewati batas waktu. Persempit filter atau coba kembali." : error.message, true);
            }
        } finally {
            window.clearTimeout(timeout);
            this.exporting = null;
            this.printWindow = null;
            this.updateExportButtons();
        }
    }

    showExportMessage(message, failed = false) {
        this.exportFeedback.className = failed ? "alert alert-danger" : "alert alert-info";
        this.exportFeedback.textContent = message;
        if (failed) {
            window.notify?.error(message);
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
        this.exportAbort?.abort();
        this.printWindow?.close();
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
