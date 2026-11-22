import { initializeCompactFilters } from "./compact-filters";

export function initializeEventParticipantPicker(modal) {
    const type = modal.querySelector("#candidate-type");
    const organization = modal.querySelector("[data-candidate-department-filter]");
    const filterControls = modal.querySelector("[data-compact-filters]");
    const filterToggle = filterControls.querySelector("[data-filter-toggle]");
    const filterChips = filterControls.querySelector("[data-filter-chips]");
    const filters = initializeCompactFilters(filterControls);
    const search = modal.querySelector("#candidate-search");
    const selectAll = modal.querySelector("#candidate-select-all");
    const rows = modal.querySelector("[data-candidate-rows]");
    const results = modal.querySelector("[data-candidate-results]");
    const previous = modal.querySelector("[data-candidate-previous]");
    const next = modal.querySelector("[data-candidate-next]");
    const feedback = modal.querySelector("[data-candidate-feedback]");
    const selectedCount = modal.querySelector("[data-selected-count]");
    const clear = modal.querySelector("[data-clear-participants]");
    const submit = modal.querySelector('button[type="submit"]');
    const coverage = modal.querySelector("[data-invitation-coverage]");
    const days = modal.querySelector("[data-invitation-days]");
    const inputs = modal.querySelector("[data-selected-participant-inputs]");
    const restored = JSON.parse(modal.querySelector("[data-previous-participants]").textContent) ?? {};
    const selected = Object.fromEntries(["employee", "external"].map((group) => [group,
        new Set(Array.isArray(restored[group]) ? restored[group].map(String).filter((id) => /^[1-9]\d*$/.test(id)) : []),
    ]));
    const limit = Number(modal.dataset.selectionLimit);
    let current;
    let pending;
    let searchTimer;

    const selectionSize = () => selected.employee.size + selected.external.size;

    function displayFeedback(message) {
        feedback.textContent = message;
        feedback.hidden = !message;
    }

    function updateSelection() {
        const group = selected[type.value];
        const filtered = current?.selectable_ids?.map(String) ?? [];
        const filteredSelected = filtered.filter((id) => group.has(id)).length;
        selectAll.disabled = filtered.length === 0;
        selectAll.checked = filtered.length > 0 && filteredSelected === filtered.length;
        selectAll.indeterminate = filteredSelected > 0 && !selectAll.checked;
        rows.querySelectorAll("[data-candidate-id]").forEach((check) => {
            check.checked = !check.disabled && group.has(check.dataset.candidateId);
            check.closest("tr").classList.toggle("table-active", check.checked);
        });

        const count = selectionSize();
        selectedCount.textContent = `${count} peserta dipilih`;
        clear.disabled = count === 0;
        const needsDays = coverage?.value === "selected";
        if (days) {
            days.hidden = days.disabled = !needsDays;
        }
        submit.disabled = count === 0 || count > limit || (needsDays && !days.querySelector(":checked"));
    }

    function updateGroup(ids, checked) {
        const group = selected[type.value];
        const additional = ids.filter((id) => !group.has(id)).length;
        if (checked && selectionSize() + additional > limit) {
            displayFeedback(`Maksimal ${limit} peserta per registrasi. Kurangi pilihan atau persempit filter.`);
        } else {
            for (const id of ids) {
                if (checked) {
                    group.add(id);
                } else {
                    group.delete(id);
                }
            }
            displayFeedback("");
        }
        updateSelection();
    }

    function participantRow(participant) {
        const row = document.createElement("tr");
        const check = document.createElement("input");
        check.type = "checkbox";
        check.className = "form-check-input m-0";
        check.dataset.candidateId = participant.id;
        check.disabled = participant.status === "registered";
        check.setAttribute("aria-label", `Pilih ${participant.name}`);
        const target = document.createElement("label");
        target.className = "candidate-check-target";
        target.append(check);
        row.insertCell().append(target);
        const name = row.insertCell();
        name.textContent = participant.name;
        const context = document.createElement("div");
        context.className = "d-md-none small text-secondary";
        context.textContent = [participant.identifier, participant.affiliation].filter(Boolean).join(" · ");
        name.append(context);
        const identifier = row.insertCell();
        identifier.className = "d-none d-md-table-cell";
        identifier.textContent = participant.identifier || "–";
        const affiliation = row.insertCell();
        affiliation.className = "d-none d-md-table-cell";
        affiliation.textContent = participant.affiliation || "–";

        const badge = document.createElement("span");
        const status = {
            registered: ["bg-success-lt", "Terdaftar"],
            cancelled: ["bg-warning-lt", "Dibatalkan"],
        }[participant.status] ?? ["bg-secondary-lt", "Belum terdaftar"];
        badge.className = `badge ${status[0]}`;
        badge.textContent = status[1];
        row.insertCell().append(badge);
        return row;
    }

    function updateType() {
        const isEmployee = type.value === "employee";
        filterToggle.hidden = !isEmployee;
        if (isEmployee) {
            filters.sync();
        } else {
            filters.close();
            filterChips.hidden = true;
        }
        modal.querySelector("[data-candidate-identifier]").textContent = isEmployee ? "NIP" : "Telepon";
        modal.querySelector("[data-candidate-affiliation]").textContent = isEmployee ? "Department" : "Organisasi";
        search.placeholder = isEmployee ? "Nama atau NIP" : "Nama atau telepon";
    }

    function load(page = 1, delay = 0) {
        window.clearTimeout(searchTimer);
        pending?.abort();
        pending = null;
        current = null;
        previous.disabled = next.disabled = true;
        rows.replaceChildren();
        results.textContent = "Memuat peserta…";
        displayFeedback("");
        updateSelection();

        searchTimer = window.setTimeout(async () => {
            const controller = new AbortController();
            pending = controller;
            const query = new URLSearchParams({ type: type.value, page, search: search.value.trim() });
            if (type.value === "employee") {
                for (const select of organization.querySelectorAll("[data-organization-select]")) {
                    if (select.value) {
                        query.set(select.dataset.organizationSelect + "_id", select.value);
                    }
                }
            }

            try {
                const response = await fetch(`${modal.dataset.candidatesUrl}?${query}`, {
                    headers: { Accept: "application/json" }, signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error();
                }
                const candidates = await response.json();
                if (pending !== controller) {
                    return;
                }
                current = candidates;
                rows.replaceChildren(...current.data.map(participantRow));
                results.textContent = current.total
                    ? `Halaman ${current.page} dari ${current.last_page} · ${current.total} peserta`
                    : "Peserta tidak ditemukan.";
                previous.disabled = current.page <= 1;
                next.disabled = current.page >= current.last_page;
                if (current.selectable_ids === null) {
                    displayFeedback(`Lebih dari ${limit} peserta dapat dipilih. Persempit filter untuk menggunakan Select All.`);
                }
                updateSelection();
            } catch {
                if (pending === controller) {
                    results.textContent = "Daftar peserta gagal dimuat. Ubah filter atau buka ulang modal untuk mencoba kembali.";
                }
            }
        }, delay);
    }

    modal.addEventListener("show.bs.modal", () => load());
    type.addEventListener("change", () => {
        updateType();
        load();
    });
    organization.addEventListener("change", () => load());
    filterControls.addEventListener("compact-filters:change", () => load());
    search.addEventListener("input", () => load(1, 250));
    search.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });
    previous.addEventListener("click", () => load(current.page - 1));
    next.addEventListener("click", () => load(current.page + 1));
    selectAll.addEventListener("change", () => updateGroup(current.selectable_ids.map(String), selectAll.checked));
    rows.addEventListener("change", (event) => updateGroup([event.target.dataset.candidateId], event.target.checked));
    clear.addEventListener("click", () => {
        Object.values(selected).forEach((group) => group.clear());
        displayFeedback("");
        updateSelection();
    });
    coverage?.addEventListener("change", updateSelection);
    days?.addEventListener("change", updateSelection);
    modal.querySelector("form").addEventListener("submit", (event) => {
        if (!event.submitter && submit.disabled) {
            event.preventDefault();
        }
        if (event.defaultPrevented) {
            return;
        }
        const fields = Object.entries(selected).flatMap(([group, ids]) => Array.from(ids, (id) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = `participant_groups[${group}][]`;
            input.value = id;
            return input;
        }));
        inputs.replaceChildren(...fields);
    });
    updateType();
    updateSelection();
}
