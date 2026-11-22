import { Modal } from "@tabler/core";

export function initializeOrganizationIndex(index = document.querySelector("[data-organization-index]")) {
    if (!index) {
        return;
    }

    const modal = index.querySelector("#organization-related-modal");
    const records = JSON.parse(index.querySelector("[data-organization-details]").textContent);
    const title = document.getElementById(modal.getAttribute("aria-labelledby"));
    const name = modal.querySelector("[data-organization-modal-name]");
    const status = modal.querySelector("[data-organization-modal-status]");
    const employees = modal.querySelector("[data-organization-modal-employees]");
    const sections = [...modal.querySelectorAll("[data-organization-modal-section]")];
    const headings = { departments: "Departemen", divisions: "Divisi" };
    let trigger;

    modal.addEventListener("hidden.bs.modal", () => trigger?.focus());
    index.addEventListener("click", (event) => {
        const button = event.target.closest("[data-organization-record]");
        const record = records[button?.dataset.organizationRecord];
        if (!record) {
            return;
        }

        trigger = button;
        const selectedList = button.dataset.organizationList;
        title.textContent = headings[selectedList] ?? (record.departments ? "Detail Organisasi" : "Detail Departemen");
        name.textContent = record.label;
        status.textContent = record.active ? "Aktif" : "Tidak Aktif";
        status.classList.toggle("bg-success-lt", record.active);
        status.classList.toggle("bg-secondary-lt", !record.active);
        employees.textContent = `${record.employees} Karyawan`;
        for (const section of sections) {
            const type = section.dataset.organizationModalSection;
            const items = record[type];
            section.hidden = !items || (selectedList !== "all" && selectedList !== type);
            if (section.hidden) {
                continue;
            }
            section.querySelector("h3").textContent = `${headings[type]} (${items.length})`;
            const rows = (items.length ? items : [{ label: "—" }]).map((item) => {
                const row = document.createElement("li");
                row.className = "list-group-item text-break px-0 py-2";
                row.textContent = item.label;
                if (item.active === false) {
                    const inactive = document.createElement("span");
                    inactive.className = "badge bg-secondary-lt ms-2";
                    inactive.textContent = "Tidak Aktif";
                    row.append(inactive);
                }
                if (item.department) {
                    const parent = document.createElement("small");
                    parent.className = "d-block text-secondary";
                    parent.textContent = item.department;
                    row.append(parent);
                }
                return row;
            });
            section.querySelector("ul").replaceChildren(...rows);
        }
        Modal.getOrCreateInstance(modal).show(button);
    });
}

function initializeOrganizationReview(form) {
    const actions = form.querySelector("[data-organization-actions]");
    const review = form.querySelector("[data-organization-review]");
    const open = form.querySelector("[data-organization-review-open]");
    const confirm = form.querySelector("[data-organization-review-confirm]");
    const root = form.querySelector("[data-organization-root]");
    const reviewName = review.querySelector("[data-organization-review-name]");
    const reviewStatus = review.querySelector("[data-organization-review-status]");
    const reviewCount = review.querySelector("[data-organization-review-count]");

    function closeReview() {
        if (!form.dataset.submitting) {
            review.hidden = true;
            actions.hidden = false;
        }
    }

    form.querySelector("[data-organization-review-back]").addEventListener("click", () => {
        if (!form.dataset.submitting) {
            closeReview();
            open.focus();
        }
    });
    form.addEventListener("input", closeReview);
    form.addEventListener("change", closeReview);
    form.addEventListener("submit", (event) => {
        if (event.submitter === confirm && !review.hidden) {
            return;
        }

        // Open the review before the shared submit handler sets the loading state.
        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.submitting || !form.reportValidity()) {
            return;
        }
        const code = root.querySelector("[data-organization-code]").value.trim().toUpperCase();
        const name = root.querySelector("[data-organization-name]").value.trim();
        const active = root.querySelector("[data-organization-status]").value === "1";
        reviewName.textContent = [code, name].filter(Boolean).join(" — ");
        reviewStatus.textContent = active ? "Aktif" : "Tidak Aktif";
        reviewStatus.classList.toggle("bg-success-lt", active);
        reviewStatus.classList.toggle("bg-secondary-lt", !active);
        if (reviewCount) {
            const departments = form.querySelectorAll("[data-department]").length;
            const divisions = form.querySelectorAll("[data-division]").length;
            reviewCount.textContent = `${departments} Departemen · ${divisions} Divisi`;
        }
        actions.hidden = true;
        review.hidden = false;
        review.querySelector("h2").focus();
    }, { capture: true });

    if (form.querySelector("[data-organization-validation]")) {
        window.notify?.error("Periksa data organisasi sebelum menyimpan.");
    }

    return closeReview;
}

export function initializeOrganizationForm(form = document.querySelector("[data-organization-form]")) {
    if (!form) {
        return;
    }

    const closeReview = initializeOrganizationReview(form);
    const departments = form.querySelector("[data-departments]");
    if (!departments) {
        return;
    }
    const departmentTemplate = form.querySelector("[data-department-template]");
    const divisionTemplate = form.querySelector("[data-division-template]");
    const legacySelect = form.querySelector("[data-legacy-department]");
    const attach = form.querySelector("[data-attach-department]");
    const legacyRecords = JSON.parse(legacySelect?.dataset.records ?? "{}");
    const usedIndexes = new Set([...departments.querySelectorAll("[data-department-index], [data-division-index]")]
        .map((row) => row.dataset.departmentIndex ?? row.dataset.divisionIndex));
    let index = 0;

    function nextIndex() {
        while (usedIndexes.has(String(index))) {
            index++;
        }
        return String(index++);
    }

    function cloneRow(template, departmentIndex, divisionIndex = "") {
        const row = template.content.firstElementChild.cloneNode(true);
        for (const element of [row, ...row.querySelectorAll("*")]) {
            for (const attribute of element.attributes) {
                attribute.value = attribute.value.replaceAll("__department__", departmentIndex).replaceAll("__division__", divisionIndex);
            }
        }
        return row;
    }

    function fillRow(row, record, idSelector) {
        row.querySelector(idSelector).value = record.id;
        const code = row.querySelector("[data-organization-code]");
        code.value = record.code ?? "";
        code.required = record.code !== null;
        code.labels[0].classList.toggle("required", code.required);
        row.querySelector("[data-organization-name]").value = record.name;
        row.querySelector("[data-organization-status]").value = record.is_active ? "1" : "0";
    }

    function addDivision(department, record = null) {
        const row = cloneRow(divisionTemplate, department.dataset.departmentIndex, nextIndex());
        if (record) {
            fillRow(row, record, "[data-division-id]");
            row.querySelector("[data-division-actions]").hidden = true;
        }
        department.querySelector("[data-divisions]").append(row);
        if (!record) {
            row.querySelector("[data-organization-code]").focus();
        }
    }

    function addDepartment(record = null) {
        const row = cloneRow(departmentTemplate, nextIndex());
        if (record) {
            row.dataset.legacyId = record.id;
            fillRow(row, record, "[data-department-id]");
            row.querySelector("[data-department-description]").value = record.description ?? "";
            row.querySelector("[data-remove-department]").textContent = "Lepas dari formulir";
            for (const division of record.divisions) {
                addDivision(row, division);
            }
        }
        departments.append(row);
        row.querySelector("[data-organization-code]").focus();
    }

    function refreshRows() {
        form.querySelector("[data-empty-departments]").hidden = departments.children.length > 0;
        for (const [position, row] of [...departments.children].entries()) {
            row.querySelector("[data-department-label]").textContent = "Departemen " + (position + 1);
        }
        if (legacySelect) {
            const attached = new Set([...departments.querySelectorAll("[data-legacy-id]")].map((row) => row.dataset.legacyId));
            for (const option of legacySelect.options) {
                option.disabled = attached.has(option.value);
            }
            attach.disabled = !legacySelect.value || attached.has(legacySelect.value);
        }
    }

    legacySelect?.addEventListener("change", refreshRows);
    refreshRows();

    form.addEventListener("click", (event) => {
        const button = event.target.closest("button");
        if (!button || button.closest("[hidden]") || form.dataset.submitting) {
            return;
        }
        const department = button.closest("[data-department]");
        if (button.matches("[data-add-department]")) {
            addDepartment();
        } else if (button === attach) {
            addDepartment(legacyRecords[legacySelect.value]);
            legacySelect.value = "";
        } else if (button.matches("[data-remove-department]")) {
            department.remove();
            form.querySelector("[data-add-department], [data-legacy-department]")?.focus();
        } else if (button.matches("[data-add-division]")) {
            addDivision(department);
        } else if (button.matches("[data-remove-division]")) {
            button.closest("[data-division]").remove();
            department.querySelector("[data-add-division]")?.focus();
        } else {
            return;
        }
        closeReview();
        refreshRows();
    });
}

export function initializeOrganizationSelections(root = document) {
    root.querySelectorAll("[data-organization-selects]").forEach((group) => {
        const entity = group.querySelector('[data-organization-select="entity"]');
        const department = group.querySelector('[data-organization-select="department"]');
        const division = group.querySelector('[data-organization-select="division"]');
        const departmentOptions = [...department.options];
        const divisionOptions = [...division.options];
        const isFilter = group.hasAttribute("data-organization-filter");

        function filterOptions(select, options, parent, allowAll = false) {
            const selected = select.value;
            const matching = options.filter((option) => !option.value || option.dataset.parent === parent || allowAll);
            const available = matching.length > 1;
            const empty = group.querySelector('[data-organization-empty="' + select.dataset.organizationSelect + '"]');
            const tomSelect = select.tomselect;
            tomSelect?.clear(true);
            tomSelect?.clearOptions();
            select.replaceChildren(...matching.map((option) => option.cloneNode(true)));
            select.value = matching.some((option) => option.value === selected) ? selected : "";
            select.disabled = !available;
            tomSelect?.sync();
            if (empty) {
                empty.hidden = !parent || available;
            }
        }

        function updateDepartments() {
            filterOptions(department, departmentOptions, entity.value, isFilter && !entity.value);
            updateDivisions();
        }

        function updateDivisions() {
            filterOptions(division, divisionOptions, department.value);
        }

        updateDepartments();
        group.querySelector("[data-organization-loading]")?.remove();
        entity.addEventListener("change", updateDepartments);
        department.addEventListener("change", updateDivisions);
        group.addEventListener("organization:refresh", updateDepartments);
    });
}
