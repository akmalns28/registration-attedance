import ApexCharts from "apexcharts";

const number = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });
const percent = (value) => `${number.format(value)}%`;

export class AnalyticPage {
    constructor(page, { Chart = ApexCharts, request = window.fetch.bind(window) } = {}) {
        this.page = page;
        this.Chart = Chart;
        this.request = request;
        this.form = page.querySelector("[data-analytic-filters]");
        this.event = this.form.elements.namedItem("event");
        this.day = this.form.elements.namedItem("event_day");
        this.feedback = page.querySelector("[data-analytic-feedback]");
        this.refreshButton = page.querySelector("[data-analytic-refresh]");
        this.charts = new Map();
        this.version = 0;
        this.destroyed = false;
        this.listeners = new AbortController();
        this.renderQueue = Promise.resolve();
        const initial = JSON.parse(page.querySelector("[data-analytic-initial]").textContent);
        this.ready = this.enqueueRender(initial, 0).catch(() => this.showError("Grafik belum dapat ditampilkan. Tekan Refresh untuk mencoba kembali."));
        const options = { signal: this.listeners.signal };
        this.form.addEventListener("submit", (event) => {
            event.preventDefault();
            this.refresh();
        }, options);
        this.form.addEventListener("change", (event) => {
            if (event.target === this.event) {
                this.day.replaceChildren(new Option(this.event.value ? "Memuat Event Day..." : "Pilih Event terlebih dahulu", ""));
                this.day.disabled = true;
            }
            this.refresh();
        }, options);
        window.addEventListener("pagehide", (event) => {
            if (event.persisted) {
                this.version++;
                this.abort?.abort();
                this.setLoading(false);
            } else {
                this.destroy();
            }
        }, options);
    }

    setLoading(loading) {
        this.page.setAttribute("aria-busy", String(loading));
        this.refreshButton.disabled = loading;
        (this.refreshButton.querySelector("[data-button-label]") ?? this.refreshButton).textContent = loading ? "Memuat..." : "Refresh";
        if (loading) {
            this.feedback.className = "alert alert-info";
            this.feedback.textContent = "Memperbarui ringkasan dan grafik...";
        }
    }

    showError(message) {
        if (this.destroyed) {
            return;
        }
        this.feedback.className = "alert alert-danger";
        this.feedback.textContent = `${message} Ringkasan dan grafik masih menampilkan hasil terakhir yang berhasil dimuat.`;
        window.notify?.error(message);
    }

    async refresh() {
        if (this.destroyed) {
            return;
        }
        const start = this.form.elements.namedItem("start_date");
        const end = this.form.elements.namedItem("end_date");
        end.setCustomValidity(start.value && end.value && end.value < start.value ? "Tanggal selesai harus sama atau setelah tanggal mulai." : "");
        const version = ++this.version;
        this.abort?.abort();
        if (!this.form.reportValidity()) {
            this.setLoading(false);
            this.showError("Periksa kembali filter tanggal.");
            return;
        }
        const parameters = new URLSearchParams(new FormData(this.form));
        for (const [key, value] of Array.from(parameters.entries())) {
            if (!value) {
                parameters.delete(key);
            }
        }
        const abort = new AbortController();
        this.abort = abort;
        const timeout = window.setTimeout(() => abort.abort(), 20000);
        this.setLoading(true);
        try {
            const response = await this.request(`${this.page.dataset.url}?${parameters}`, {
                credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                signal: abort.signal,
            });
            const body = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
            if (version !== this.version || this.destroyed) {
                return;
            }
            if (!response.ok || body?.success !== true || !body.data?.kpis || !body.data?.charts) {
                const errors = Object.values(body?.errors ?? {}).flat().join(" ");
                throw new Error(errors || ([401, 403, 419].includes(response.status) || response.redirected
                    ? "Akses Analytic berakhir. Muat ulang halaman atau login kembali."
                    : "Data Analytic belum dapat dimuat. Coba Refresh kembali."));
            }
            await this.enqueueRender(body.data, version);
            if (version === this.version && !this.destroyed) {
                history.replaceState(null, "", `${window.location.pathname}${parameters.size ? `?${parameters}` : ""}`);
                this.feedback.className = "d-none";
                this.feedback.textContent = "";
            }
        } catch (error) {
            if (version === this.version && !this.destroyed) {
                this.showError(error.name === "AbortError" ? "Permintaan melewati batas waktu. Coba Refresh kembali." : error.message || "Koneksi terputus. Coba Refresh kembali.");
            }
        } finally {
            window.clearTimeout(timeout);
            if (version === this.version && !this.destroyed) {
                this.setLoading(false);
            }
        }
    }

    enqueueRender(data, version) {
        this.renderQueue = this.renderQueue.catch(() => {}).then(async () => {
            if (version !== this.version || this.destroyed) {
                return;
            }
            await this.renderCharts(data, version);
            if (version !== this.version || this.destroyed) {
                return;
            }
            this.renderKpis(data);
            const selectedDay = String(data.filters.event_day ?? "");
            this.day.replaceChildren(new Option(this.event.value ? "Semua Event Day" : "Pilih Event terlebih dahulu", ""));
            data.event_days.forEach((day) => this.day.add(new Option(day.label, day.id)));
            this.day.value = selectedDay;
            this.day.disabled = !this.event.value;
            this.page.querySelector("[data-analytic-empty]").classList.toggle("d-none", data.kpis.total_events !== 0);
        });
        return this.renderQueue;
    }

    renderKpis(data) {
        this.page.querySelectorAll("[data-analytic-kpi]").forEach((card) => {
            const key = card.dataset.analyticKpi;
            card.querySelector(".h1").textContent = key.endsWith("rate") ? percent(data.kpis[key]) : number.format(data.kpis[key]);
        });
        const participants = this.page.querySelector('[data-analytic-kpi="participants_attended"]');
        const unique = data.kpis.participant_metric === "participants";
        participants.querySelector(".subheader").textContent = unique ? "Peserta Hadir Unik" : "Registrations Attended";
        participants.querySelector(".badge").textContent = !unique ? "Identitas peserta belum lengkap" : data.filters.event
            ? "Unik berdasarkan registrasi event" : "Unik per data master Employee/External";
        this.page.querySelector('[data-analytic-kpi="attendance_rate"] .badge').textContent =
            `${number.format(data.kpis.total_checkins)} dari ${number.format(data.kpis.expected_attendance)} potensi kehadiran`;
        this.page.querySelector('[data-analytic-kpi="average_event_rate"] .badge').textContent =
            `${number.format(data.kpis.eligible_events)} event dengan potensi kehadiran`;
    }

    tooltip(point, distribution = false) {
        const element = document.createElement("div");
        element.className = "p-2 text-wrap";
        const title = document.createElement("strong");
        title.textContent = point?.label ?? point?.date ?? "";
        const description = document.createElement("div");
        description.textContent = distribution ? `${number.format(point?.count ?? 0)} check-in`
            : `Hadir: ${number.format(point?.checked_in ?? 0)} · Potensi: ${number.format(point?.expected ?? 0)} · ${percent(point?.percentage ?? 0)}`;
        element.append(title, description);
        return element.outerHTML;
    }

    async renderCharts(data, version) {
        for (const [key, rows] of Object.entries(data.charts)) {
            if (version !== this.version || this.destroyed) {
                return;
            }
            const element = this.page.querySelector(`[data-analytic-chart="${key}"]`);
            const distribution = key === "participant_types";
            const hasData = distribution ? rows.some((row) => row.count > 0) : rows.length > 0;
            element.classList.toggle("d-none", !hasData);
            this.page.querySelector(`[data-analytic-chart-empty="${key}"]`).classList.toggle("d-none", hasData);
            if (!hasData) {
                continue;
            }
            const options = {
                chart: { type: distribution ? "donut" : key === "attendance_trend" ? "line" : "bar", height: 280,
                    animations: { enabled: false }, toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0,
                    fontFamily: "inherit" },
                colors: ["#206bc4", "#2fb344"],
                dataLabels: { enabled: false },
                legend: { position: "bottom" },
                stroke: { width: key === "attendance_trend" ? 3 : 0, curve: "straight" },
                tooltip: { custom: ({ dataPointIndex, seriesIndex }) => this.tooltip(rows[distribution ? seriesIndex : dataPointIndex], distribution) },
            };
            if (distribution) {
                options.labels = rows.map((row) => row.label);
                options.series = rows.map((row) => row.count);
            } else {
                const byDay = key === "by_day";
                options.xaxis = { categories: rows.map((row) => row.label ?? row.date), tooltip: { enabled: false },
                    labels: { trim: true, hideOverlappingLabels: true, maxHeight: 60 } };
                options.yaxis = byDay ? { min: 0, forceNiceScale: true, decimalsInFloat: 0 }
                    : { min: 0, max: 100, labels: { formatter: percent } };
                options.plotOptions = { bar: { columnWidth: "55%", borderRadius: 3 } };
                options.series = byDay ? [
                    { name: "Hadir", data: rows.map((row) => row.checked_in) },
                    { name: "Potensi Kehadiran", data: rows.map((row) => row.expected) },
                ] : [{ name: "Kehadiran", data: rows.map((row) => row.percentage) }];
                if (key === "attendance_trend") {
                    options.markers = { size: 4 };
                }
            }
            if (this.charts.has(key)) {
                await this.charts.get(key).updateOptions(options, false, false, false);
            } else {
                const chart = new this.Chart(element, options);
                this.charts.set(key, chart);
                await chart.render();
            }
        }
    }

    async destroy() {
        this.destroyed = true;
        this.version++;
        this.abort?.abort();
        this.listeners.abort();
        await this.renderQueue.catch(() => {});
        this.charts.forEach((chart) => chart.destroy());
        this.charts.clear();
    }
}

export function initializeAnalyticPage(root = document) {
    const page = root.querySelector("[data-analytic-page]");
    if (!page || page.dataset.initialized === "true") {
        return;
    }
    page.dataset.initialized = "true";
    return new AnalyticPage(page);
}
