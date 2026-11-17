import { Html5Qrcode, Html5QrcodeSupportedFormats } from "html5-qrcode";

export function cameraErrorMessage(error) {
    const detail = `${error?.name || ""} ${error?.message || error || ""}`;

    if (/NotAllowed|PermissionDenied|permission denied/i.test(detail)) {
        return "Izin kamera ditolak. Izinkan akses kamera di pengaturan browser, lalu tekan Mulai Kamera.";
    }
    if (/NotFound|DevicesNotFound|no cameras/i.test(detail)) {
        return "Kamera tidak tersedia. Hubungkan kamera, lalu coba lagi.";
    }
    if (/NotReadable|TrackStart|Could not start|in use/i.test(detail)) {
        return "Kamera sedang digunakan atau tidak dapat dibuka. Tutup aplikasi kamera lain, lalu coba lagi.";
    }

    return "Kamera gagal dimulai. Periksa izin atau pilih kamera lain, lalu coba lagi.";
}

export class AttendanceScanner {
    constructor(page, {
        Scanner = Html5Qrcode,
        request = window.fetch.bind(window),
        autoResumeDelay = Number(page.dataset.autoResumeDelay ?? 1200),
        duplicateSuppressionMs = Number(page.dataset.duplicateSuppressionMs ?? 8000),
    } = {}) {
        this.page = page;
        this.Scanner = Scanner;
        this.request = request;
        this.events = JSON.parse(page.querySelector("[data-scanner-events]").textContent);
        this.fixedEventId = page.dataset.fixedEventId || null;
        this.fixedDayId = page.dataset.fixedDayId || null;
        this.event = page.querySelector("#scanner-event");
        this.day = page.querySelector("#scanner-day");
        this.camera = page.querySelector("#scanner-camera");
        this.feedback = page.querySelector("[data-scanner-feedback]");
        this.result = page.querySelector("[data-scanner-result]");
        this.startButton = page.querySelector("[data-scanner-start]");
        this.stopButton = page.querySelector("[data-scanner-stop]");
        this.restartButton = page.querySelector("[data-scanner-restart]");
        this.againButton = page.querySelector("[data-scanner-again]");
        this.confirmButton = page.querySelector("[data-scanner-confirm]");
        this.pendingCheckin = null;
        this.autoResumeDelay = Number.isFinite(autoResumeDelay) ? Math.max(0, autoResumeDelay) : 1200;
        this.duplicateSuppressionMs = Number.isFinite(duplicateSuppressionMs) ? Math.max(0, duplicateSuppressionMs) : 8000;
        this.autoResumeTimer = null;
        this.suppressedPayload = null;
        this.scanner = new Scanner("qr-reader", {
            formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
            verbose: false,
        });
        this.state = "idle";
        this.running = false;
        this.paused = false;
        this.awaitingScanAgain = false;
        this.stopRequested = false;
        this.accessDenied = false;
        this.sequence = 0;
        this.cameraTask = null;
        this.stopTask = null;
        this.validationAbort = null;
        this.destroyed = false;
        this.resizePending = false;
        this.listeners = new AbortController();

        if (this.fixedEventId && this.event) {
            this.event.value = this.fixedEventId;
            this.populateDays();
        }

        const options = { signal: this.listeners.signal };
        this.startButton.addEventListener("click", () => this.start(), options);
        this.stopButton.addEventListener("click", () => this.stop(), options);
        this.restartButton.addEventListener("click", () => this.restart(), options);
        this.againButton.addEventListener("click", () => this.scanAgain(), options);
        this.confirmButton.addEventListener("click", () => this.confirmCheckin(), options);
        this.event?.addEventListener("change", () => this.changeEvent(), options);
        this.day?.addEventListener("change", () => this.changeContext(), options);
        this.camera.addEventListener("change", () => {
            if (this.running) {
                this.restart();
            }
        }, options);
        window.addEventListener("pagehide", () => this.suspend(), options);
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                this.suspend();
            }
        }, options);
        this.tabPane = page.closest('.tab-pane');
        if (this.tabPane?.id) {
            document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tab) => {
                const target = tab.getAttribute("href") || tab.getAttribute("data-bs-target");
                if (target === `#${this.tabPane.id}`) {
                    tab.addEventListener("hide.bs.tab", () => this.suspend(), options);
                }
            });
        }
        const preview = page.querySelector(".attendance-camera-stage");
        let previewSize = `${preview.clientWidth}:${preview.clientHeight}`;
        const resized = () => {
            const size = `${preview.clientWidth}:${preview.clientHeight}`;
            if (size !== previewSize) {
                previewSize = size;
                this.resizePending = this.running || Boolean(this.cameraTask);
                this.render();
            }
        };
        if (typeof ResizeObserver === "function") {
            this.resizeObserver = new ResizeObserver(resized);
            this.resizeObserver.observe(preview);
        } else {
            window.addEventListener("resize", resized, options);
        }
        this.render();
    }

    hasSelection() {
        return (!this.fixedEventId || !this.event || this.event.value === this.fixedEventId)
            && (!this.fixedDayId || !this.day || this.day.value === this.fixedDayId)
            && this.events.some((event) => String(event.id) === this.eventId
                && event.days.some((day) => String(day.id) === this.dayId));
    }

    get eventId() {
        return this.fixedEventId || this.event?.value;
    }

    get dayId() {
        return this.fixedDayId || this.day?.value;
    }

    setState(state, message, type = "secondary") {
        this.state = state;
        this.page.dataset.scannerState = state;
        if (message) {
            this.feedback.textContent = message;
            this.feedback.className = `alert alert-${type} mb-3`;
        }
        this.render();
    }

    render() {
        const busy = ["starting", "stopping", "validating", "checking-in", "success", "cooldown"].includes(this.state);
        const selected = this.hasSelection();
        if (this.event) {
            this.event.disabled = busy || Boolean(this.fixedEventId);
        }
        if (this.day) {
            this.day.disabled = busy || Boolean(this.fixedDayId) || !this.eventId || this.day.options.length < 2;
        }
        this.camera.disabled = busy || this.camera.options.length < 2;
        this.startButton.disabled = busy || this.running || !selected || this.accessDenied;
        this.stopButton.disabled = this.state === "stopping" || (!this.running && this.state !== "starting");
        this.restartButton.disabled = busy || !selected || this.accessDenied;
        this.againButton.disabled = busy || !this.awaitingScanAgain || !selected || this.accessDenied;
        this.confirmButton.classList.toggle("d-none", !this.pendingCheckin);
        this.confirmButton.disabled = busy || !this.pendingCheckin || !selected || this.accessDenied;
        this.confirmButton.textContent = this.state === "checking-in" ? "Mencatat kehadiran..." : "Konfirmasi Check-in";
        this.page.querySelector("[data-scanner-placeholder]").classList.toggle("d-none", this.running);
        if (this.resizePending && this.running && !busy && !this.accessDenied && !this.destroyed && !this.stopRequested) {
            this.resizePending = false;
            const sequence = this.sequence;
            queueMicrotask(() => {
                if (sequence === this.sequence && !this.stopRequested) {
                    this.restart();
                }
            });
        }
    }

    changeEvent() {
        if (this.fixedEventId) {
            this.event.value = this.fixedEventId;
        }
        this.populateDays();
        return this.changeContext();
    }

    populateDays() {
        if (!this.event || !this.day) {
            return;
        }
        const event = this.events.find((event) => String(event.id) === this.event.value);
        this.day.replaceChildren(new Option(event ? "Pilih hari pelaksanaan" : "Pilih event terlebih dahulu", ""));
        event?.days.forEach((day) => this.day.add(new Option(day.label, day.id)));
        if (this.fixedDayId) {
            this.day.value = this.fixedDayId;
        }
    }

    async changeContext() {
        if (this.fixedEventId && this.event) {
            this.event.value = this.fixedEventId;
        }
        if (this.fixedDayId && this.day) {
            this.day.value = this.fixedDayId;
        }
        this.awaitingScanAgain = false;
        this.suppressedPayload = null;
        this.clearResult();
        await this.stop();
        this.setState("idle", "Pilih event dan hari pelaksanaan, lalu tekan Mulai Kamera.");
    }

    start() {
        if (this.cameraTask || this.stopTask || this.running || !this.hasSelection()
            || this.accessDenied || this.destroyed || document.hidden
            || (this.tabPane && !this.tabPane.classList.contains("active"))) {
            return this.cameraTask || Promise.resolve();
        }

        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            this.setState("error", "Akses kamera memerlukan HTTPS atau localhost dan browser yang mendukung kamera.", "danger");
            return Promise.resolve();
        }

        this.stopRequested = false;
        this.setState("starting", "Meminta izin dan menyiapkan kamera...", "info");
        this.cameraTask = this.startCamera().finally(() => {
            this.cameraTask = null;
            this.render();
        });
        return this.cameraTask;
    }

    async startCamera() {
        try {
            const devices = await this.Scanner.getCameras();
            if (this.stopRequested || this.destroyed) {
                return;
            }
            if (!devices.length) {
                throw new Error("no cameras");
            }

            const preferred = this.camera.value;
            this.camera.replaceChildren(new Option("Otomatis (utamakan kamera belakang)", ""));
            devices.forEach((device, index) => this.camera.add(new Option(device.label || `Kamera ${index + 1}`, device.id)));
            if (devices.some((device) => device.id === preferred)) {
                this.camera.value = preferred;
            }

            const rear = devices.find((device) => /back|rear|environment|belakang/i.test(device.label));
            const camera = this.camera.value || rear?.id || { facingMode: "environment" };
            const config = {
                fps: 10,
                qrbox: (width, height) => {
                    const size = Math.max(50, Math.floor(Math.min(250, Math.min(width, height) * 0.7)));
                    return { width: size, height: size };
                },
            };
            const onSuccess = (text) => this.validate(text);
            const onDecodeFailure = () => {};
            const preview = this.page.querySelector(".attendance-camera-stage");
            const previewSize = `${preview.clientWidth}:${preview.clientHeight}`;
            this.resizePending = false;

            try {
                await this.scanner.start(camera, config, onSuccess, onDecodeFailure);
            } catch (error) {
                if (typeof camera === "string" || !/Overconstrained|NotFound|constraint/i.test(`${error?.name} ${error}`)
                    || this.stopRequested || this.destroyed) {
                    throw error;
                }
                await this.scanner.start(devices[0].id, config, onSuccess, onDecodeFailure);
            }

            this.running = true;
            await this.waitForCameraReady();
            this.resizePending = previewSize !== `${preview.clientWidth}:${preview.clientHeight}`;
            if (this.stopRequested || this.destroyed) {
                await this.release();
                return;
            }

            const deviceId = this.scanner.getRunningTrackSettings().deviceId;
            if (devices.some((device) => device.id === deviceId)) {
                this.camera.value = deviceId;
            }
            this.watchCameraTrack();
            if (this.awaitingScanAgain) {
                this.scanner.pause();
                this.paused = true;
                this.setState("result", "Kamera siap. Tekan Pindai Lagi untuk memvalidasi peserta berikutnya.", "info");
            } else {
                this.setState("scanning", "Kamera siap. Pemindaian berlangsung; arahkan QR undangan ke tengah kamera.", "info");
            }
        } catch (error) {
            if (this.running) {
                try {
                    await this.release();
                } catch {
                    this.stopTracks();
                    this.running = false;
                    this.paused = false;
                }
            }
            if (!this.stopRequested && !this.destroyed) {
                this.setState("error", cameraErrorMessage(error), "danger");
            }
        }
    }

    waitForCameraReady() {
        if (this.scanner.isScanning) {
            return Promise.resolve();
        }
        const video = this.page.querySelector("#qr-reader video");
        if (!video) {
            return Promise.reject(new Error("Camera preview is unavailable"));
        }

        return new Promise((resolve, reject) => {
            const cleanup = () => {
                window.clearTimeout(timeout);
                video.removeEventListener("playing", ready);
                video.removeEventListener("error", failed);
            };
            const ready = () => {
                cleanup();
                this.scanner.isScanning ? resolve() : reject(new Error("Camera decoder is not ready"));
            };
            const failed = () => { cleanup(); reject(new Error("Camera preview failed")); };
            const timeout = window.setTimeout(failed, 10000);
            video.addEventListener("playing", ready, { once: true });
            video.addEventListener("error", failed, { once: true });
        });
    }

    stop() {
        this.cancelAutoResume();
        if (this.stopTask) {
            return this.stopTask;
        }
        this.stopRequested = true;
        this.resizePending = false;
        this.sequence++;
        this.validationAbort?.abort();
        if (this.state === "checking-in") {
            this.pendingCheckin = null;
            this.showResult(false, "Permintaan check-in terputus. Periksa Peserta Hadir atau pindai ulang untuk memastikan status.");
        }
        if (this.state === "validating" && this.awaitingScanAgain) {
            this.showResult(false, "Validasi dihentikan. Tekan Pindai Lagi untuk mencoba kembali.");
        }
        this.setState("stopping", "Menghentikan kamera...");
        this.stopTask = (async () => {
            await this.cameraTask;
            await this.release();
            this.setState(this.awaitingScanAgain ? "result" : "idle", "Kamera dihentikan.");
        })().catch(() => {
            this.stopTracks();
            this.running = false;
            this.paused = false;
            this.setState("error", "Kamera tidak dapat dihentikan dengan normal. Muat ulang halaman sebelum mencoba lagi.", "danger");
        }).finally(() => {
            this.stopTask = null;
            this.render();
        });
        return this.stopTask;
    }

    async release() {
        this.cameraTrack?.removeEventListener("ended", this.cameraDisconnected);
        this.cameraTrack = null;
        if (this.running) {
            await this.scanner.stop();
            this.running = false;
            this.paused = false;
        }
        this.scanner.clear();
    }

    stopTracks() {
        this.page.querySelectorAll("video").forEach((video) => {
            video.srcObject?.getTracks().forEach((track) => track.stop());
        });
    }

    suspend() {
        this.pendingCheckin = null;
        this.stopTracks();
        return this.stop();
    }

    async restart() {
        if (["starting", "stopping", "validating", "checking-in", "success", "cooldown"].includes(this.state)) {
            return;
        }
        await this.stop();
        await this.start();
    }

    clearResult() {
        this.pendingCheckin = null;
        this.page.querySelector("[data-scanner-checkin-details]").classList.add("d-none");
        this.result.classList.add("d-none");
        this.page.querySelectorAll("[data-result-field]").forEach((field) => { field.textContent = ""; });
    }

    showResult(success, message, data = null) {
        this.result.classList.remove("d-none");
        this.page.querySelector("[data-scanner-result-title]").textContent = success ? "Registrasi Valid" : "Validasi Tidak Berhasil";
        this.page.querySelector("[data-scanner-result-message]").textContent = message;
        this.page.querySelector("[data-scanner-result-color]").className = `card-status-top bg-${success ? "success" : "danger"}`;
        this.page.querySelector("[data-scanner-participant]").classList.toggle("d-none", !success);
        this.page.querySelectorAll("[data-result-field]").forEach((field) => {
            field.textContent = success && typeof data?.[field.dataset.resultField] === "string" ? data[field.dataset.resultField] : "";
        });
        window.notify?.[success ? "success" : "error"](message);
        this.page.querySelector("[data-scanner-result-title]").focus({ preventScroll: true });
        this.scrollTo(this.result);
    }

    scrollTo(element) {
        if (window.matchMedia("(max-width: 991.98px)").matches) {
            element.scrollIntoView({
                behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
                block: "nearest",
            });
        }
    }

    async scanAgain() {
        if (["starting", "stopping", "validating", "checking-in", "success", "cooldown"].includes(this.state) || !this.awaitingScanAgain || this.accessDenied) {
            return;
        }
        this.awaitingScanAgain = false;
        this.clearResult();
        if (!this.running) {
            await this.start();
            return;
        }
        try {
            if (this.paused) {
                this.scanner.resume();
                this.paused = false;
            }
            this.setState("scanning", "Pemindaian berlangsung; arahkan QR peserta berikutnya ke kamera.", "info");
            this.scrollTo(this.page.querySelector(".attendance-camera-stage"));
        } catch (error) {
            this.setState("error", cameraErrorMessage(error), "danger");
        }
    }

    async validate(qrCode) {
        if (this.state !== "scanning" || !this.running || !this.hasSelection() || typeof qrCode !== "string") {
            return;
        }
        if (this.suppressedPayload && Date.now() >= this.suppressedPayload.expiresAt) {
            this.suppressedPayload = null;
        }
        if (this.suppressedPayload?.value === qrCode.trim().toLowerCase()) {
            return;
        }
        const sequence = ++this.sequence;
        const eventId = this.eventId;
        const dayId = this.dayId;
        this.awaitingScanAgain = true;
        this.clearResult();
        this.setState("validating", "QR terdeteksi. Memvalidasi registrasi...", "info");
        const abort = new AbortController();
        this.validationAbort = abort;
        const timeout = window.setTimeout(() => abort.abort(), 20000);

        try {
            this.scanner.pause();
            this.paused = true;
            const response = await this.request(this.page.dataset.validateUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ event_id: Number(eventId), event_day_id: Number(dayId), qr_code: qrCode }),
                signal: abort.signal,
            });
            const data = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
            if (sequence !== this.sequence || this.destroyed) {
                return;
            }

            const success = response.ok && data?.success === true && data.data;
            const message = typeof data?.message === "string" ? data.message
                : "Validasi gagal. Periksa koneksi atau muat ulang halaman, lalu coba lagi.";
            if (success) {
                this.pendingCheckin = { event_id: Number(eventId), event_day_id: Number(dayId), qr_code: qrCode };
            }
            this.showResult(Boolean(success), message, success ? data.data : null);
            this.setState("result", message, success ? "success" : "danger");

            if (response.redirected || [401, 403, 419].includes(response.status)) {
                this.accessDenied = true;
                await this.stop();
                this.setState("error", "Akses scanner berakhir. Muat ulang halaman atau login kembali.", "danger");
            }
        } catch (error) {
            if (sequence !== this.sequence || this.destroyed) {
                return;
            }
            const message = error?.name === "AbortError"
                ? "Validasi melewati batas waktu. Periksa koneksi lalu tekan Pindai Lagi."
                : "Tidak dapat menghubungi server. Periksa koneksi lalu tekan Pindai Lagi.";
            this.showResult(false, message);
            this.setState("error", message, "danger");
        } finally {
            window.clearTimeout(timeout);
            if (this.validationAbort === abort) {
                this.validationAbort = null;
            }
        }
    }

    async confirmCheckin() {
        if (!this.pendingCheckin || this.state !== "result" || this.accessDenied || this.destroyed
            || !this.hasSelection()) {
            return;
        }
        const payload = this.pendingCheckin;
        const sequence = ++this.sequence;
        const abort = new AbortController();
        this.validationAbort = abort;
        this.setState("checking-in", "Mencatat kehadiran. Tunggu konfirmasi server...", "info");
        const timeout = window.setTimeout(() => abort.abort(), 20000);
        try {
            const response = await this.request(this.page.dataset.checkinUrl, {
                method: "POST", credentials: "same-origin",
                headers: {
                    Accept: "application/json", "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify(payload), signal: abort.signal,
            });
            const data = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
            if (sequence !== this.sequence || this.destroyed) {
                return;
            }
            const persisted = response.ok && data?.success === true && typeof data.data?.checked_in_at === "string";
            const message = typeof data?.message === "string" ? data.message : "Check-in belum dikonfirmasi server. Periksa Peserta Hadir atau coba konfirmasi kembali.";
            if (persisted) {
                this.pendingCheckin = null;
                this.showResult(true, message, data.data);
                this.page.querySelector("[data-scanner-result-title]").textContent = data.created ? "Check-in Berhasil" : "Sudah Check-in";
                this.page.querySelector("[data-scanner-checkin-details]").classList.remove("d-none");
                this.page.querySelector("[data-checkin-time]").textContent = `${data.data.event_day} ${data.data.checkin_time} (${this.page.dataset.timezone})`;
                this.page.querySelector("[data-checkin-operator]").textContent = data.data.operator_name;
                this.setState("result", message, "success");
                this.page.dispatchEvent(new CustomEvent("attendance:updated", { bubbles: true, detail: {
                    eventId: payload.event_id, dayId: payload.event_day_id, summary: data.summary,
                } }));
                if (data.created === true) {
                    this.setState("success", message, "success");
                    this.scheduleAutoResume(payload.qr_code);
                }
            } else {
                this.page.querySelector("[data-scanner-result-title]").textContent = "Check-in Belum Berhasil";
                this.page.querySelector("[data-scanner-result-message]").textContent = message;
                this.page.querySelector("[data-scanner-result-color]").className = "card-status-top bg-danger";
                if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                    this.pendingCheckin = null;
                }
                this.setState("result", message, "danger");
                window.notify?.error(message);
                if (response.redirected || [401, 403, 419].includes(response.status)) {
                    this.accessDenied = true;
                    await this.stop();
                    this.setState("error", "Akses scanner berakhir. Muat ulang halaman atau login kembali.", "danger");
                }
            }
        } catch {
            if (sequence !== this.sequence || this.destroyed) {
                return;
            }
            const message = "Konfirmasi server belum diterima. Periksa Peserta Hadir atau coba konfirmasi kembali; peserta tidak akan dicatat dua kali.";
            this.page.querySelector("[data-scanner-result-title]").textContent = "Status Check-in Belum Diketahui";
            this.page.querySelector("[data-scanner-result-message]").textContent = message;
            this.page.querySelector("[data-scanner-result-color]").className = "card-status-top bg-warning";
            this.setState("result", message, "warning");
            window.notify?.error(message);
        } finally {
            window.clearTimeout(timeout);
            if (this.validationAbort === abort) {
                this.validationAbort = null;
            }
        }
    }

    cancelAutoResume() {
        window.clearTimeout(this.autoResumeTimer);
        this.autoResumeTimer = null;
    }

    scheduleAutoResume(qrCode) {
        this.cancelAutoResume();
        if (!this.running || this.stopRequested || this.destroyed) {
            return;
        }
        const sequence = this.sequence;
        this.suppressedPayload = {
            value: qrCode.trim().toLowerCase(),
            expiresAt: Date.now() + this.autoResumeDelay + this.duplicateSuppressionMs,
        };
        this.setState("cooldown", "Check-in berhasil. Pemindaian berikutnya segera dimulai...", "success");
        this.autoResumeTimer = window.setTimeout(() => {
            this.autoResumeTimer = null;
            if (sequence !== this.sequence || !this.running || this.stopRequested || this.destroyed
                || this.accessDenied || document.hidden || this.state !== "cooldown"
                || (this.tabPane && !this.tabPane.classList.contains("active"))) {
                return;
            }
            this.suppressedPayload.expiresAt = Date.now() + this.duplicateSuppressionMs;
            this.scanAgainAfterSuccess();
        }, this.autoResumeDelay);
    }

    scanAgainAfterSuccess() {
        this.awaitingScanAgain = false;
        this.clearResult();
        try {
            if (this.paused) {
                this.scanner.resume();
                this.paused = false;
            }
            this.setState("scanning", "Siap memindai peserta berikutnya. QR terakhir diabaikan sementara; arahkan QR berikutnya ke kamera.", "info");
            this.scrollTo(this.page.querySelector(".attendance-camera-stage"));
        } catch (error) {
            this.awaitingScanAgain = true;
            this.setState("error", cameraErrorMessage(error), "danger");
        }
    }

    watchCameraTrack() {
        this.cameraTrack = this.page.querySelector("#qr-reader video")?.srcObject?.getVideoTracks()[0];
        this.cameraDisconnected = async () => {
            if (this.stopRequested || this.destroyed) {
                return;
            }
            await this.stop();
            this.setState("error", "Koneksi kamera terputus. Hubungkan kembali kamera lalu tekan Mulai Kamera.", "danger");
            window.notify?.error(this.feedback.textContent);
        };
        this.cameraTrack?.addEventListener("ended", this.cameraDisconnected, { once: true });
    }

    async destroy() {
        this.destroyed = true;
        this.listeners.abort();
        this.resizeObserver?.disconnect();
        await this.suspend();
    }
}

export function initializeAttendanceScanner(root = document) {
    const page = root.querySelector("[data-attendance-scanner]");
    if (!page || page.dataset.initialized === "true") {
        return;
    }
    page.dataset.initialized = "true";
    return new AttendanceScanner(page);
}
