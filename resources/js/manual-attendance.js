const participantDetails = (participant) => participant.participant_type === "employee"
    ? ["Internal", participant.nip && `NIK/NIP ${participant.nip}`, participant.department].filter(Boolean).join(" · ")
    : ["External", participant.phone && `Telepon ${participant.phone}`].filter(Boolean).join(" · ");

function attendanceBadge(participant) {
    const present = participant.attendance_status === "present";
    const badge = document.createElement("span");
    badge.className = `badge ${present ? "bg-green-lt" : "bg-secondary-lt"}`;
    badge.textContent = present ? "Sudah check-in" : "Belum check-in";
    return badge;
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: "same-origin", redirect: "manual", ...options,
        headers: { Accept: "application/json", ...options.headers },
    });
    if (response.type === "opaqueredirect" || [401, 419].includes(response.status)) {
        throw new Error("Sesi Anda berakhir. Muat ulang halaman dan login kembali.");
    }
    const data = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
    if (!response.ok || !data) {
        throw new Error(data?.message || "Permintaan belum dapat diproses. Silakan coba kembali.");
    }
    return data;
}

export function initializeManualAttendance(root = document) {
    const page = root.querySelector("[data-manual-attendance]");
    if (!page || page.dataset.initialized === "true") {
        return;
    }
    page.dataset.initialized = "true";

    const events = JSON.parse(root.querySelector("[data-scanner-events]").textContent);
    const event = page.querySelector("#manual-event");
    const day = page.querySelector("#manual-day");
    const search = page.querySelector("#manual-search");
    const status = page.querySelector("#manual-status");
    const loading = page.querySelector("[data-manual-loading]");
    const results = page.querySelector("[data-manual-results]");
    const controls = page.querySelector("[data-manual-controls]");
    const confirmation = page.querySelector("[data-manual-confirmation]");
    const confirm = page.querySelector("[data-manual-confirm]");
    const cancel = page.querySelector("[data-manual-cancel]");
    let selected;
    let timer;
    let pendingSearch;
    let submitting = false;

    function resetResults() {
        window.clearTimeout(timer);
        pendingSearch?.abort();
        pendingSearch = null;
        loading.classList.add("d-none");
        selected = null;
        confirmation.hidden = true;
        results.hidden = false;
        results.replaceChildren();
        search.disabled = !event.value || !day.value;
        status.textContent = search.disabled ? "Pilih event dan hari pelaksanaan." : "Ketik minimal 2 karakter.";
    }

    function fillDays() {
        if (!day.hasAttribute("data-fixed-day")) {
            const days = events.find((item) => String(item.id) === event.value)?.days ?? [];
            day.replaceChildren(new Option("Pilih hari pelaksanaan", ""), ...days.map((item) => new Option(item.label, item.id)));
            day.disabled = days.length === 0;
        }
        search.value = "";
        resetResults();
    }

    function selectParticipant(participant) {
        selected = participant;
        page.querySelector("[data-manual-name]").textContent = participant.participant_name;
        page.querySelector("[data-manual-details]").textContent = participantDetails(participant);
        page.querySelector("[data-manual-attendance-status]").replaceChildren(attendanceBadge(participant));
        results.hidden = true;
        confirmation.hidden = false;
        confirm.disabled = participant.attendance_status === "present";
        (confirm.disabled ? cancel : confirm).focus();
    }

    search.addEventListener("input", () => {
        resetResults();
        const term = search.value.trim();
        if (search.disabled || submitting || term.length < 2) {
            return;
        }

        timer = window.setTimeout(async () => {
            const controller = new AbortController();
            pendingSearch = controller;
            loading.classList.remove("d-none");
            status.textContent = "Mencari peserta…";
            const parameters = new URLSearchParams({
                event_id: event.value, event_day_id: day.value, search: term,
                participant_type: page.querySelector('[name="manual_type"]:checked').value,
            });
            try {
                const { data } = await requestJson(`${page.dataset.searchUrl}?${parameters}`, { signal: controller.signal });
                if (pendingSearch !== controller) {
                    return;
                }
                status.textContent = data.length ? "Pilih peserta untuk melihat konfirmasi." : "Peserta tidak ditemukan.";
                results.replaceChildren(...data.map((participant) => {
                    const button = document.createElement("button");
                    button.type = "button";
                    button.className = "list-group-item list-group-item-action text-break";
                    const name = document.createElement("strong");
                    name.textContent = participant.participant_name;
                    const details = document.createElement("span");
                    details.className = "d-block text-secondary small mb-1";
                    details.textContent = participantDetails(participant);
                    button.append(name, details, attendanceBadge(participant));
                    button.addEventListener("click", () => selectParticipant(participant));
                    return button;
                }));
            } catch (error) {
                if (pendingSearch === controller) {
                    status.textContent = error instanceof TypeError ? "Koneksi gagal. Coba cari kembali." : error.message;
                }
            } finally {
                if (pendingSearch === controller) {
                    pendingSearch = null;
                    loading.classList.add("d-none");
                }
            }
        }, 300);
    });

    controls.addEventListener("keydown", (keyboardEvent) => {
        const options = Array.from(results.querySelectorAll("button"));
        const index = options.indexOf(keyboardEvent.target);
        if (!confirmation.hidden || options.length === 0 || (keyboardEvent.target !== search && index === -1)) {
            return;
        }
        switch (keyboardEvent.key) {
            case "ArrowDown":
                results.hidden = false;
                options[Math.min(index + 1, options.length - 1)].focus();
                break;
            case "ArrowUp":
                (options[index - 1] ?? search).focus();
                break;
            case "Escape":
                results.hidden = true;
                search.focus();
                break;
            default:
                return;
        }
        keyboardEvent.preventDefault();
    });

    event.addEventListener("change", fillDays);
    day.addEventListener("change", () => {
        search.value = "";
        resetResults();
    });
    page.querySelectorAll('[name="manual_type"]').forEach((option) => option.addEventListener("change", () => {
        search.placeholder = option.value === "employee" ? "Cari NIK/NIP atau nama karyawan" : "Cari nama atau nomor telepon peserta";
        search.value = "";
        resetResults();
    }));
    cancel.addEventListener("click", () => {
        selected = null;
        confirmation.hidden = true;
        results.hidden = false;
        search.focus();
    });
    confirmation.addEventListener("keydown", (keyboardEvent) => {
        if (keyboardEvent.key === "Escape" && !submitting) {
            keyboardEvent.preventDefault();
            cancel.click();
        }
    });

    confirm.addEventListener("click", async () => {
        if (!selected || submitting || confirm.disabled) {
            return;
        }
        submitting = true;
        controls.disabled = confirm.disabled = cancel.disabled = true;
        let completed = false;
        try {
            const response = await requestJson(page.dataset.checkinUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ event_id: event.value, event_day_id: day.value, registration_id: selected.registration_id }),
            });
            completed = true;
            search.value = "";
            resetResults();
            status.textContent = response.message;
            window.notify?.[response.created ? "success" : "info"](response.message);
            page.dispatchEvent(new CustomEvent("attendance:updated", { bubbles: true, detail: {
                eventId: event.value, dayId: day.value, summary: response.summary,
            } }));
        } catch (error) {
            status.textContent = error instanceof TypeError ? "Koneksi gagal. Silakan coba kembali." : error.message;
            window.notify?.error(status.textContent);
        } finally {
            submitting = false;
            controls.disabled = confirm.disabled = cancel.disabled = false;
            if (completed) {
                search.focus();
            }
        }
    });

    fillDays();
}
