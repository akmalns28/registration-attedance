import TomSelect from "tom-select";
import "tom-select/dist/css/tom-select.bootstrap5.css";

export function initializeTomSelect(root = document) {
    const elements = root.querySelectorAll("select[data-tom-select]");

    elements.forEach((element) => {
        if (element.tomselect) {
            return;
        }

        const dropdownParent = element.closest("[data-tom-select-dropdown-parent]");

        const select = new TomSelect(element, {
            create: false,
            allowEmptyOption: true,
            maxItems: element.multiple ? null : 1,
            copyClassesToDropdown: false,
            ...(dropdownParent && {
                dropdownParent,
                hideSelected: false,
                clearAfterSelect: true,
            }),
            plugins: {
                ...(element.multiple ? { remove_button: { title: "Hapus peserta" } } : {}),
                clear_button: {
                    title: "Hapus pilihan",
                },
            },
            placeholder: element.dataset.placeholder || "Pilih data...",
        });
        fitMobileDropdown(select);
    });
}

function fitMobileDropdown(select) {
    const viewport = window.visualViewport;
    const mobile = window.matchMedia("(max-width: 991.98px)");
    let listeners;

    const position = () => {
        if (!mobile.matches) {
            delete select.dropdown.dataset.mobilePlacement;
            select.dropdown.style.removeProperty("--select-options-height");
            return;
        }

        const control = select.control.getBoundingClientRect();
        const viewportTop = viewport?.offsetTop ?? 0;
        const viewportHeight = viewport?.height ?? window.innerHeight;
        const below = viewportTop + viewportHeight - control.bottom;
        const above = control.top - viewportTop;
        const openAbove = below < 180 && above > below;
        select.dropdown.dataset.mobilePlacement = openAbove ? "top" : "bottom";
        const available = (openAbove ? above : below) - 8;
        select.dropdown.style.setProperty("--select-options-height", `${Math.max(0, Math.min(224, available))}px`);
    };

    select.on("dropdown_open", () => {
        listeners?.abort();
        listeners = new AbortController();
        const options = { signal: listeners.signal };
        viewport?.addEventListener("resize", position, options);
        viewport?.addEventListener("scroll", position, options);
        window.addEventListener("scroll", position, { ...options, capture: true, passive: true });
        window.addEventListener("resize", position, options);
        position();
    });
    select.on("dropdown_close", () => listeners?.abort());
    select.on("destroy", () => listeners?.abort());
}
