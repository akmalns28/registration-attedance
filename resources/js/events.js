import { Modal, Tab } from "@tabler/core";
import { adjustDataTables } from "./plugins/datatables";
import DataTable from "datatables.net-bs5";
import { initializeBulkInvitationResend } from "./event-bulk-invitation-resend";
import { initializeEventDayInvitations } from "./event-day-invitations";
import { initializeEventParticipantPicker } from "./event-participant-picker";
import { revealSelectedTab } from "./mobile-layout";

export function initializeEventPage(root = document) {
    const page = root.querySelector("[data-event-details]");

    if (!page || page.dataset.initialized === "true") {
        return;
    }

    page.dataset.initialized = "true";
    initializeEventDayInvitations(page, root);
    initializeBulkInvitationResend(page, root);
    const participantModal = root.querySelector("[data-participant-picker]");
    if (participantModal) {
        initializeEventParticipantPicker(participantModal);
    }
    const refreshButton = page.querySelector("[data-attendance-refresh]");
    let refreshingAttendance = false;
    let attendanceRefreshPending = false;
    const updateSummary = (summary) => {
        page.querySelectorAll("[data-attendance-summary]").forEach((card) => {
            const key = card.dataset.attendanceSummary;
            if (typeof summary?.[key] === "number") {
                card.querySelector(".h1").textContent = `${summary[key]}${key === "percentage" ? "%" : ""}`;
            }
        });
    };
    const refreshAttendance = async () => {
        if (!page.dataset.attendanceRefreshUrl) {
            return;
        }
        if (refreshingAttendance) {
            attendanceRefreshPending = true;
            return;
        }
        refreshingAttendance = true;
        refreshButton.disabled = true;
        const feedback = page.querySelector("[data-attendance-refresh-feedback]");
        feedback.className = "alert alert-info";
        feedback.textContent = "Memperbarui data kehadiran...";
        try {
            const response = await fetch(page.dataset.attendanceRefreshUrl, {
                credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                signal: AbortSignal.timeout(20000),
            });
            const data = await response.json();
            if (!response.ok || data.success !== true || !Array.isArray(data.attendees)) {
                throw new Error("Refresh failed");
            }
            if (attendanceRefreshPending) {
                return;
            }
            updateSummary(data.summary);
            const invitedTable = page.querySelector("[data-day-invitations-table]");
            if (invitedTable) {
                const present = new Set(data.attendees.map((attendee) => attendee.registration_number));
                const updateInvitationStatus = (row) => {
                    const cell = row.querySelector("[data-invitation-attendance]");
                    if (cell) {
                        cell.textContent = present.has(cell.dataset.invitationAttendance) ? "Hadir" : "Belum hadir";
                    }
                };
                if (DataTable.isDataTable(invitedTable)) {
                    new DataTable(invitedTable).rows().every(function () {
                        updateInvitationStatus(this.node());
                        this.invalidate("dom");
                    });
                } else {
                    invitedTable.querySelectorAll("tbody tr").forEach(updateInvitationStatus);
                }
            }
            const table = page.querySelector("[data-attendance-table]");
            const rows = data.attendees.map((attendee) => {
                const row = document.createElement("tr");
                ["registration_number", "participant_name", "participant_type", "registration_status", "checkin_time", "operator_name", "attendance_status"].forEach((key) => {
                    const cell = document.createElement("td");
                    cell.textContent = key === "attendance_status" ? "Hadir" : attendee[key];
                    row.append(cell);
                });
                return row;
            });
            if (DataTable.isDataTable(table)) {
                new DataTable(table).clear().rows.add(rows).draw(false);
                adjustDataTables(page);
            } else {
                table.querySelector("tbody").replaceChildren(...rows);
            }
            feedback.className = "d-none";
            feedback.textContent = "";
        } catch {
            feedback.className = "alert alert-warning";
            feedback.textContent = "Data kehadiran belum dapat diperbarui. Tekan Perbarui Kehadiran untuk mencoba kembali.";
        } finally {
            refreshingAttendance = false;
            refreshButton.disabled = false;
            if (attendanceRefreshPending) {
                attendanceRefreshPending = false;
                refreshAttendance();
            }
        }
    };
    refreshButton?.addEventListener("click", refreshAttendance);
    page.addEventListener("invitation:updated", refreshAttendance);
    page.addEventListener("attendance:updated", (event) => {
        if (String(event.detail.eventId) === page.dataset.eventId && String(event.detail.dayId) === page.dataset.dayId) {
            updateSummary(event.detail.summary);
            refreshAttendance();
        }
    });

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
        ["#addParticipantsModal", null],
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
            requestAnimationFrame(() => {
                adjustDataTables(page);
                revealSelectedTab(tab);
            });
        });
    });

    const initialTab = page.dataset.participantErrors === "true"
        ? "#event-participants"
        : window.location.hash;

    if (validTabs.has(initialTab)) {
        const tab = Array.from(tabs).find((link) => link.getAttribute("href") === initialTab);
        Tab.getOrCreateInstance(tab).show();
    }

    if (participantModal?.hasAttribute("data-reopen")) {
        Modal.getOrCreateInstance(participantModal).show();
    }
}
