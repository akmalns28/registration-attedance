import TomSelect from "tom-select";
import "tom-select/dist/css/tom-select.bootstrap5.css";

export function initializeTomSelect(root = document) {
    const elements = root.querySelectorAll("select[data-tom-select]");

    elements.forEach((element) => {
        if (element.tomselect) {
            return;
        }

        new TomSelect(element, {
            create: false,
            allowEmptyOption: true,
            maxItems: 1,
            plugins: {
                clear_button: {
                    title: "Hapus pilihan",
                },
            },
            placeholder: element.dataset.placeholder || "Pilih data...",
        });
    });
}
