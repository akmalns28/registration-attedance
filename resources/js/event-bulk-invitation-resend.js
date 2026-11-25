import { Modal } from "@tabler/core";
import $ from "jquery";

const participantCheckbox = "[data-resend-participant]";
const numbers = new Intl.NumberFormat("id-ID");

export function initializeBulkInvitationResend(page, root = document) {
    const table = page.querySelector("#eventParticipantsTable");
    const bar = page.querySelector("[data-resend-bar]");
    const modal = root.getElementById("bulkResendInvitationModal");
    if (!table || !bar || !modal) return;

    const selected = new Map();
    const selectPage = table.querySelector("[data-resend-select-page]");
    const open = bar.querySelector("[data-resend-open]");
    const count = bar.querySelector("[data-resend-count]");
    const form = modal.querySelector("form");
    const submit = form.querySelector("[data-resend-submit]");
    const error = form.querySelector("[data-resend-error]");
    const dialog = Modal.getOrCreateInstance(modal);
    const visibleCheckboxes = () => [...table.tBodies[0].querySelectorAll(participantCheckbox)];
    let submitting = false;
    let requestId;

    function sync() {
        const checkboxes = visibleCheckboxes();
        let checked = 0;
        for (const checkbox of checkboxes) {
            checkbox.checked = selected.has(checkbox.value);
            checked += Number(checkbox.checked);
        }
        selectPage.checked = checked > 0 && checked === checkboxes.length;
        selectPage.indeterminate = checked > 0 && checked < checkboxes.length;
        selectPage.disabled = checkboxes.length === 0;
        count.textContent = numbers.format(selected.size) + " peserta dipilih";
        bar.hidden = selected.size === 0;
    }

    function setSelected(checkbox, checked) {
        if (checked) {
            selected.set(checkbox.value, checkbox.dataset.hasEmail === "true");
        } else {
            selected.delete(checkbox.value);
        }
    }

    function clearSelection() {
        selected.clear();
        requestId = undefined;
        sync();
    }

    $(table).on("draw.dt", sync);
    table.addEventListener("change", (event) => {
        if (event.target === selectPage) {
            visibleCheckboxes().forEach((checkbox) => setSelected(checkbox, selectPage.checked));
        } else if (event.target.matches(participantCheckbox)) {
            setSelected(event.target, event.target.checked);
        }
        sync();
    });
    bar.querySelector("[data-resend-clear]").addEventListener("click", () => {
        clearSelection();
        table.closest(".dt-container")?.querySelector('input[type="search"]')?.focus();
    });
    open.addEventListener("click", () => {
        let withEmail = 0;
        for (const hasEmail of selected.values()) {
            withEmail += Number(hasEmail);
        }
        for (const [key, value] of Object.entries({ total: selected.size, "with-email": withEmail, "without-email": selected.size - withEmail })) {
            modal.querySelector("[data-resend-" + key + "]").textContent = numbers.format(value);
        }
        error.hidden = true;
        dialog.show(open);
    });
    modal.addEventListener("shown.bs.modal", () => {
        submit.disabled = false;
    });
    modal.addEventListener("hide.bs.modal", (event) => {
        if (submitting) {
            event.preventDefault();
        } else {
            submit.disabled = true;
        }
    });
    modal.addEventListener("hidden.bs.modal", () => {
        const focusTarget = selected.size ? open : table.closest(".dt-container")?.querySelector('input[type="search"]');
        focusTarget?.focus();
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (submit.disabled || selected.size === 0) return;

        submitting = true;
        submit.disabled = true;
        error.hidden = true;
        form.setAttribute("aria-busy", "true");

        let counts;
        try {
            requestId ??= Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, "0")).join("");
            counts = await resend(form, [...selected.keys()], requestId);
        } catch (failure) {
            error.textContent = failure.message;
            error.hidden = false;
        } finally {
            submitting = false;
            submit.disabled = false;
            form.removeAttribute("aria-busy");
        }

        if (!counts) return;
        clearSelection();
        dialog.hide();
        showSummary(counts);
    });

    sync();
}

async function resend(form, registrationIds, requestId) {
    const fallback = "Permintaan kirim ulang belum dapat dikonfirmasi. Silakan coba lagi.";
    let response;
    let data;
    try {
        response = await fetch(form.action, {
            method: "POST",
            credentials: "same-origin",
            redirect: "manual",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": form.elements._token.value,
            },
            body: JSON.stringify({ registration_ids: registrationIds, request_id: requestId }),
            signal: AbortSignal.timeout(60000),
        });
        data = response.headers.get("content-type")?.includes("application/json") ? await response.json() : null;
    } catch {
        throw new Error(fallback);
    }
    if (response.type === "opaqueredirect" || response.status === 401 || response.status === 419) {
        throw new Error("Sesi telah berakhir. Muat ulang halaman dan coba lagi.");
    }
    if (response.status === 403) {
        throw new Error("Anda tidak memiliki izin untuk mengirim ulang undangan.");
    }
    if (!response.ok || !data) {
        const messages = response.status === 422 ? Object.values(data?.errors ?? {}).flat() : [];
        throw new Error(messages[0] || fallback);
    }
    return data;
}

function showSummary({ queued, skipped, no_email: noEmail, pending, duplicate }) {
    const summary = [
        numbers.format(queued) + " undangan masuk antrean.",
        numbers.format(skipped) + " peserta dilewati" + (noEmail ? " (" + numbers.format(noEmail) + " tanpa email valid)." : "."),
    ];
    if (pending) summary.push(numbers.format(pending) + " undangan tercatat dan menunggu antrean tersedia.");
    if (duplicate) summary.push(numbers.format(duplicate) + " peserta sudah tercatat dalam permintaan ini.");
    window.notify?.[pending || skipped ? "warning" : "success"](summary.join(" "));
}
