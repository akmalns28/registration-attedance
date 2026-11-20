import { Modal } from "@tabler/core";
import DataTable from "datatables.net-bs5";

export function initializeEventDayInvitations(page, root) {
    const element = root.querySelector("[data-event-day-invitation-modal]");
    if (!element) {
        return;
    }

    const form = element.querySelector("form");
    const details = form.querySelector("[data-invitation-details]");
    const loading = form.querySelector("[data-invitation-loading]");
    const feedback = form.querySelector("[data-invitation-error]");
    const options = form.querySelector("[data-invitation-options]");
    const days = form.querySelector("[data-invitation-days]");
    const template = form.querySelector("[data-invitation-day-template]");
    const save = form.querySelector('[type="submit"]');
    const dismiss = form.querySelectorAll('[data-bs-dismiss="modal"]');
    const checkboxes = () => [...days.querySelectorAll('input[type="checkbox"]')];
    let trigger;
    let table;
    let loadRequest;
    let shown = false;
    let editable = false;
    let saving = false;

    function showError(error) {
        feedback.textContent = error.message;
        feedback.hidden = false;
        if (element.classList.contains("show")) feedback.focus();
    }

    function setSaving(active) {
        saving = active;
        save.disabled = active || !shown || !editable;
        options.disabled = active;
        dismiss.forEach((button) => { button.disabled = active; });
        form.setAttribute("aria-busy", String(active));
        form.querySelector("[data-invitation-save]").hidden = active;
        form.querySelector("[data-invitation-saving]").hidden = !active;
    }

    function focusPicker() {
        if (!element.classList.contains("show") || document.activeElement !== element) return;
        if (!feedback.hidden) {
            feedback.focus();
        } else if (!options.hidden) {
            checkboxes().find((input) => !input.disabled)?.focus();
        }
    }

    element.addEventListener("show.bs.modal", async (event) => {
        trigger = event.relatedTarget;
        if (!trigger?.dataset.invitationUrl) {
            event.preventDefault();
            return;
        }
        table = trigger.closest("table");
        loadRequest = new AbortController();
        const request = loadRequest;
        editable = false;
        save.disabled = true;
        feedback.hidden = options.hidden = details.hidden = true;
        loading.hidden = false;
        form.setAttribute("aria-busy", "true");
        days.replaceChildren();

        try {
            const data = await invitationRequest(trigger.dataset.invitationUrl, {
                signal: AbortSignal.any([request.signal, AbortSignal.timeout(20000)]),
            }, "Hari undangan gagal dimuat. Tutup dialog dan coba lagi.");
            if (request.signal.aborted) return;
            form.querySelector("[data-invitation-participant]").textContent = data.participant_name;
            form.querySelector("[data-invitation-event]").textContent = data.event_name;
            details.hidden = false;

            for (const day of data.days) {
                const row = template.content.cloneNode(true);
                const input = row.querySelector("input");
                input.value = day.id;
                input.checked = day.assigned;
                input.disabled = day.protected || (!day.available && !day.assigned);
                row.querySelector("[data-invitation-day-label]").textContent = [`Day ${day.number}`, day.date, day.title].filter(Boolean).join(" · ");
                row.querySelector("[data-invitation-day-note]").textContent = day.protected ? "Kehadiran tercatat" : (day.available ? "" : "Hari tidak tersedia untuk undangan baru");
                days.append(row);
            }
            if (data.days.length === 0) {
                days.textContent = "Belum ada hari pelaksanaan.";
            }
            editable = checkboxes().some((input) => !input.disabled);
            options.hidden = false;
            options.querySelector("[data-invitation-select-all]").disabled = !editable;
            options.querySelector("[data-invitation-clear-all]").disabled = !editable;
            save.disabled = !shown || !editable;
        } catch (error) {
            if (!request.signal.aborted) showError(error);
        } finally {
            if (!request.signal.aborted) {
                loading.hidden = true;
                form.setAttribute("aria-busy", "false");
                focusPicker();
            }
        }
    });

    element.addEventListener("shown.bs.modal", () => {
        shown = true;
        save.disabled = !editable;
        focusPicker();
    });
    element.addEventListener("hide.bs.modal", (event) => {
        if (saving) {
            event.preventDefault();
        } else {
            shown = false;
            loadRequest?.abort();
        }
    });
    element.addEventListener("hidden.bs.modal", () => {
        if (trigger && !trigger.getClientRects().length) {
            table?.closest(".dt-container")?.querySelector('input[type="search"]')?.focus();
        }
    });

    for (const [selector, checked] of [["[data-invitation-select-all]", true], ["[data-invitation-clear-all]", false]]) {
        options.querySelector(selector).addEventListener("click", () => {
            checkboxes().filter((input) => !input.disabled).forEach((input) => { input.checked = checked; });
        });
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (save.disabled) return;
        feedback.hidden = true;
        const dayIds = checkboxes().filter((input) => input.checked).map((input) => Number(input.value));
        setSaving(true);

        let updated;
        try {
            updated = await invitationRequest(trigger.dataset.invitationUpdateUrl, {
                method: "PUT",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": form.elements._token.value },
                body: JSON.stringify({ day_ids: dayIds }),
                signal: AbortSignal.timeout(20000),
            }, "Hari undangan gagal disimpan. Silakan coba lagi.");
        } catch (error) {
            showError(error);
        } finally {
            setSaving(false);
        }
        if (!updated) return;

        updateInvitationRow(page, trigger, table, updated);
        Modal.getOrCreateInstance(element).hide();
        window.notify?.success(updated.message);
    });
}

function updateInvitationRow(page, trigger, table, data) {
    const row = trigger.closest("tr");
    const summary = row.querySelector("[data-invitation-summary]");
    const status = row.querySelector("[data-invitation-status]");
    if (summary) summary.textContent = data.days_label;
    if (status) {
        const invited = data.day_ids.includes(Number(page.dataset.dayId));
        status.textContent = invited ? "Diundang" : "Undangan dicabut";
        status.classList.toggle("bg-primary-lt", invited);
        status.classList.toggle("bg-secondary-lt", !invited);
        const delivery = row.querySelector("[data-invitation-delivery]");
        if (delivery) delivery.hidden = !invited;
    }
    if (table && DataTable.isDataTable(table)) {
        new DataTable(table).row(row).invalidate("dom").draw(false);
    }
    page.dispatchEvent(new Event("invitation:updated"));
}

async function invitationRequest(url, { headers, ...options }, fallback) {
    let response;
    let data;
    try {
        response = await fetch(url, {
            ...options, credentials: "same-origin", redirect: "manual",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...headers },
        });
        data = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
    } catch {
        throw new Error(fallback);
    }
    if (response.type === "opaqueredirect") {
        throw new Error("Masuk kembali untuk mengelola undangan.");
    }
    if (!response.ok || !data) {
        const messages = response.status === 422 && Object.values(data?.errors ?? {}).flat().join(" ");
        const hint = {
            401: "Masuk kembali untuk mengelola undangan.",
            403: "Anda tidak memiliki izin untuk mengelola undangan.",
            404: "Registrasi ini tidak tersedia untuk dikelola.",
            419: "Sesi telah berakhir. Muat ulang halaman dan coba lagi.",
        };
        throw new Error(messages || hint[response.status] || fallback);
    }
    return data;
}
