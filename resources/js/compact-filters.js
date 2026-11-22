import { createPopper } from "@popperjs/core";

export function initializeCompactFilters(root) {
    const scope = root.closest("[data-filter-scope]") ?? root;
    const toggle = root.querySelector("[data-filter-toggle]");
    const panel = root.querySelector("[data-filter-panel]");
    const chips = root.querySelector("[data-filter-chips]");
    const reset = root.querySelector("[data-filter-reset]");
    const fields = [...scope.querySelectorAll("[data-filter-label]")];
    const hasPopover = typeof panel.showPopover === "function";
    let listeners;
    let popper;

    panel.hidden = true;
    panel.querySelector("[data-filter-panel-header]").classList.replace("d-none", "d-flex");
    panel.querySelector("[data-filter-panel-footer]").classList.remove("d-none");
    if (hasPopover) {
        panel.setAttribute("popover", "manual");
    }

    function close(restoreFocus = false) {
        if (!listeners) {
            return;
        }
        listeners.abort();
        listeners = null;
        popper?.destroy();
        if (hasPopover) {
            panel.hidePopover();
        }
        panel.hidden = true;
        toggle.setAttribute("aria-expanded", "false");
        if (restoreFocus) {
            toggle.focus();
        }
    }

    function open() {
        panel.hidden = false;
        if (hasPopover) {
            panel.showPopover();
            popper = createPopper(toggle, panel, {
                placement: "bottom-end", strategy: "fixed",
                modifiers: [
                    { name: "offset", options: { offset: [0, 6] } },
                    { name: "flip", options: { padding: 8 } },
                    { name: "preventOverflow", options: { padding: 8 } },
                ],
            });
        }
        toggle.setAttribute("aria-expanded", "true");
        const firstField = panel.querySelector("select:not(:disabled), input:not(:disabled)");
        (firstField ?? panel.querySelector("[data-filter-close]")).focus({ preventScroll: true });
        listeners = new AbortController();
        const options = { capture: true, signal: listeners.signal };
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                event.preventDefault();
                event.stopPropagation();
                close(true);
            }
        }, options);
        document.addEventListener("pointerdown", (event) => {
            if (!panel.contains(event.target) && !toggle.contains(event.target)) {
                close();
            }
        }, options);
        document.addEventListener("focusin", (event) => {
            if (!panel.contains(event.target) && event.target !== toggle) {
                close();
            }
        }, options);
    }

    function sync() {
        const active = fields.filter((field) => field.value && !field.matches(":disabled"));
        chips.replaceChildren(...active.map((field) => {
            const label = field.dataset.filterLabel;
            const value = field.selectedOptions?.[0]?.textContent.trim() ?? field.value;
            const chip = document.createElement("button");
            chip.type = "button";
            chip.className = "btn btn-sm btn-outline-secondary compact-filter-chip";
            chip.textContent = `${label}: ${value} ×`;
            chip.setAttribute("aria-label", `Hapus filter ${label}: ${value}`);
            chip.addEventListener("click", () => {
                clear([field]);
                toggle.focus();
            });
            return chip;
        }));
        reset.disabled = active.length === 0;
        chips.hidden = active.length === 0;
    }

    function clear(criteria) {
        for (const field of criteria) {
            field.value = "";
            field.querySelector("[data-invalid-filter]")?.remove();
            const descendants = {
                entity: '[data-organization-select="department"], [data-organization-select="division"]',
                department: '[data-organization-select="division"]',
            }[field.dataset.organizationSelect];
            if (descendants) {
                field.closest("[data-organization-selects]").querySelectorAll(descendants).forEach((select) => { select.value = ""; });
            }
        }
        root.querySelectorAll("[data-organization-selects]").forEach((group) => {
            group.dispatchEvent(new Event("organization:refresh"));
        });
        sync();
        root.dispatchEvent(new Event("compact-filters:change", { bubbles: true }));
    }

    toggle.hidden = false;
    toggle.addEventListener("click", () => listeners ? close(true) : open());
    panel.querySelector("[data-filter-close]").addEventListener("click", () => close(true));
    reset.addEventListener("click", () => {
        clear(fields);
        close(true);
    });
    scope.addEventListener("change", (event) => {
        if (fields.includes(event.target)) {
            sync();
        }
    });
    root.closest(".modal")?.addEventListener("hide.bs.modal", () => close());
    sync();

    return { close, fields, sync };
}
