import { Modal, Tab } from "@tabler/core";
import { adjustDataTables } from "./plugins/datatables";

export function initializeEventPage(root = document) {
    const page = root.querySelector("[data-event-details]");

    if (!page || page.dataset.initialized === "true") {
        return;
    }

    page.dataset.initialized = "true";

    const confirmations = new Map([
        [
            "#cancelRegistrationModal",
            { form: "cancelRegistrationForm", name: "cancelRegistrationName", urlKey: "cancelUrl", nameKey: "participantName" },
        ],
        [
            "#reactivateRegistrationModal",
            { form: "reactivateRegistrationForm", name: "reactivateRegistrationName", urlKey: "reactivateUrl", nameKey: "participantName" },
        ],
        [
            "#deleteEventDayModal",
            { form: "deleteEventDayForm", name: "deleteEventDayName", urlKey: "deleteUrl", nameKey: "deleteName" },
        ],
        [
            "#deleteScheduleModal",
            { form: "deleteScheduleForm", name: "deleteScheduleName", urlKey: "deleteUrl", nameKey: "deleteName" },
        ],
        ["#addEmployeeModal", null],
        ["#addExternalModal", null],
    ]);

    root.addEventListener("click", (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-bs-toggle="modal"]')
            : null;
        const target = button?.getAttribute("data-bs-target");

        if (!button || !page.contains(button) || !confirmations.has(target)) {
            return;
        }

        const modal = root.querySelector(target);

        if (!modal) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const confirmation = confirmations.get(target);

        if (confirmation) {
            const form = root.getElementById(confirmation.form);
            const name = root.getElementById(confirmation.name);
            const url = button.dataset[confirmation.urlKey];

            if (!form || !name || !url) {
                return;
            }

            form.action = url;
            name.textContent = button.dataset[confirmation.nameKey] || "";
            delete form.dataset.submitting;

            form.querySelectorAll('button[type="submit"]').forEach((submit) => {
                submit.disabled = false;

                if (submit.dataset.originalHtml) {
                    submit.innerHTML = submit.dataset.originalHtml;
                    delete submit.dataset.originalHtml;
                }
            });
        }

        Modal.getOrCreateInstance(modal).show(button);
    }, true);

    const tabs = page.querySelectorAll('a[data-bs-toggle="tab"]');
    const validTabs = new Set(Array.from(tabs, (tab) => tab.getAttribute("href")));

    tabs.forEach((tab) => {
        tab.addEventListener("shown.bs.tab", () => {
            history.replaceState(null, "", tab.getAttribute("href"));
            requestAnimationFrame(() => adjustDataTables(page));
        });
    });

    const initialTab = page.dataset.participantErrors === "true"
        ? "#event-participants"
        : window.location.hash;

    if (validTabs.has(initialTab)) {
        const tab = Array.from(tabs).find((link) => link.getAttribute("href") === initialTab);
        Tab.getOrCreateInstance(tab).show();
    }

    const participantType = page.dataset.participantType;
    const participantModal = participantType === "employee"
        ? "#addEmployeeModal"
        : participantType === "external"
          ? "#addExternalModal"
          : null;

    if (page.dataset.participantErrors === "true" && participantModal) {
        const modal = root.querySelector(participantModal);

        if (modal) {
            Modal.getOrCreateInstance(modal).show();
        }
    }
}
