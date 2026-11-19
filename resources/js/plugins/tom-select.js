import TomSelect from "tom-select";
import "tom-select/dist/css/tom-select.bootstrap5.css";

export function initializeTomSelect(root = document) {
    const elements = root.querySelectorAll("select[data-tom-select]");

    elements.forEach((element) => {
        if (element.tomselect) {
            return;
        }

        const dropdownParent = element.closest("[data-tom-select-dropdown-parent]");

        new TomSelect(element, {
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
    });
}
