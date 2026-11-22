import DataTable from "datatables.net-bs5";
import { createPopper } from "@popperjs/core";
import { initializeCompactFilters } from "../compact-filters";

import "datatables.net-buttons-bs5";
import "datatables.net-responsive-bs5";
import "datatables.net-bs5/css/dataTables.bootstrap5.css";
import "datatables.net-buttons-bs5/css/buttons.bootstrap5.css";
import "datatables.net-responsive-bs5/css/responsive.bootstrap5.css";

import JSZip from "jszip";

import pdfMake from "pdfmake/build/pdfmake";
import pdfFonts from "pdfmake/build/vfs_fonts";

DataTable.Buttons.jszip(JSZip);

pdfMake.addVirtualFileSystem(pdfFonts.pdfMake?.vfs ?? pdfFonts);

DataTable.Buttons.pdfMake(pdfMake);

function exportCollection(event, table, button, config) {
    const wasOpen = button.attr("aria-expanded") === "true";
    DataTable.ext.buttons.collection.action.call(this, event, table, button, config);
    if (wasOpen) {
        return;
    }
    const menu = document.querySelector("div.dt-button-collection[role=dialog]");
    const trigger = button.get(0);
    if (!menu || !trigger) {
        return;
    }
    menu.dataset.exportMenu = "true";
    document.body.append(menu);
    const popper = createPopper(trigger, menu, {
        strategy: "fixed", placement: "bottom-start",
        modifiers: [{ name: "offset", options: { offset: [0, 6] } },
            { name: "preventOverflow", options: { rootBoundary: "viewport", padding: 8 } },
            { name: "flip", options: { padding: 8 } }],
    });
    const observer = new MutationObserver(() => {
        if (!menu.isConnected) {
            popper.destroy();
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true });
}

const language = {
    emptyTable: "Tidak ada data tersedia",
    info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
    infoEmpty: "Menampilkan 0 sampai 0 dari 0 data",
    infoFiltered: "(difilter dari _MAX_ total data)",
    lengthMenu: "Tampilkan _MENU_ data",
    loadingRecords: "Memuat data...",
    processing: "Memproses...",
    search: "Cari:",
    zeroRecords: "Data tidak ditemukan",

    paginate: {
        first: "Pertama",
        last: "Terakhir",
        next: "Selanjutnya",
        previous: "Sebelumnya",
    },
};

function exportOptions(format) {
    const text = (value, _row, _column, node) => {
        let plain = node?.dataset.exportValue;
        if (plain === undefined) {
            const copy = node?.cloneNode(true);
            copy?.querySelectorAll("svg, .avatar, .no-export-content").forEach((element) => element.remove());
            plain = DataTable.Buttons.stripData(copy?.innerHTML ?? String(value ?? ""), {
                stripHtml: true, trim: false, stripNewlines: false, decodeEntities: true, escapeExcelFormula: false,
            });
        }
        const normalized = plain.replace(/\s+/g, " ").trim();
        if (format === "print") {
            const escaped = document.createElement("span");
            escaped.textContent = normalized;
            return escaped.innerHTML;
        }
        if (["excel", "csv"].includes(format) && /^[\s\p{Z}\uFEFF]*[=+@-]/u.test(normalized)) {
            return `'${normalized}`;
        }
        return normalized;
    };
    return {
        columns: (_index, _data, header) => !header.classList.contains("no-export"),
        modifier: {
            search: "applied", order: "applied", page: "all", selected: null,
        },
        escapeExcelFormula: false,
        format: { header: (value, column, node) => text(value, null, column, node), body: text,
            footer: (value, column, node) => text(value, null, column, node) },
    };
}

function clientExport(type) {
    return function (event, table, button, config, complete) {
        if (table.init().serverSide) {
            window.notify?.warning("Gunakan tombol ekspor laporan untuk mengunduh seluruh hasil filter dari server.");
            complete?.();
            return;
        }
        try {
            return DataTable.ext.buttons[type].action.call(this, event, table, button, config, complete);
        } catch {
            window.notify?.error("Ekspor belum dapat dibuat. Muat ulang halaman dan coba kembali.");
            complete?.();
        }
    };
}

function getExportButtons(filename) {
    return [
        {
            extend: "excelHtml5",
            text: "Excel",
            filename,
            title: filename,
            exportOptions: exportOptions("excel"),
            action: clientExport("excelHtml5"),
        },
        {
            extend: "csvHtml5",
            text: "CSV",
            filename,
            bom: true,
            exportOptions: exportOptions("csv"),
            action: clientExport("csvHtml5"),
        },
        {
            extend: "pdfHtml5",
            text: "PDF",
            filename,
            title: filename,
            orientation: "landscape",
            pageSize: "A4",
            exportOptions: exportOptions("pdf"),
            action: clientExport("pdfHtml5"),
            customize(document) {
                document.defaultStyle.fontSize = 9;
                document.pageMargins = [24, 30, 24, 30];
                const table = document.content.find((item) => item.table)?.table;
                if (table) {
                    table.widths = table.body[0].map(() => "*");
                }
            },
        },
        {
            extend: "copyHtml5",
            text: "Copy",
            exportOptions: exportOptions("copy"),
            action: clientExport("copyHtml5"),
        },
        {
            extend: "print",
            text: "Print",
            title: filename,
            exportOptions: exportOptions("print"),
            action: clientExport("print"),
            autoPrint: false,
            customize(window) {
                const style = window.document.createElement("style");
                style.textContent = "@page{size:A4 landscape;margin:12mm}body{background:white!important;padding:16px;font-size:11px}table{width:100%;table-layout:fixed;border-collapse:collapse}th,td{overflow-wrap:anywhere;padding:6px}thead{display:table-header-group}tr{break-inside:avoid}@media print{.print-controls{display:none!important}body{padding:0}}";
                window.document.head.append(style);
                const control = window.document.createElement("button");
                control.className = "btn btn-primary print-controls mb-3";
                control.textContent = "Print";
                control.type = "button";
                control.onclick = () => window.print();
                window.document.body.prepend(control);
            },
        },
    ].map((button) => ({ ...button, footer: false }));
}

export function initializeDataTables() {
    document.querySelectorAll("[data-datatable]").forEach((table) => {
        if (DataTable.isDataTable(table)) {
            return;
        }

        const invalidRow = table.querySelector(".is-invalid")?.closest("tr");
        const filters = document.querySelector(`[data-filter-table="${CSS.escape(table.id)}"]`);
        const instance = new DataTable(table, { ...getDataTableOptions(table), ...(filters && { searchDelay: 250 }) });
        if (filters) {
            attachTableFilters(instance, filters);
        }

        if (invalidRow) {
            instance.page(Math.floor(instance.row(invalidRow).index() / instance.page.len())).draw("page");
        }
    });
}

function attachTableFilters(table, controls) {
    const search = table.table().container().querySelector(".dt-search");
    const searchInput = search.querySelector("input");
    search.parentElement.append(controls);
    controls.querySelector(".compact-filter-toolbar").prepend(search);
    const { fields } = initializeCompactFilters(controls);

    function filterRows() {
        const criteria = fields.filter((field) => field.value && !field.disabled).map((field) => [
            field.dataset.organizationSelect ?? field.dataset.filterKey, field.value,
        ]);
        const matches = (_text, _data, index) => {
            const row = table.row(index).node();
            return criteria.every(([key, value]) => row.getAttribute(`data-filter-${key}`) === value);
        };
        table.search.fixed("compact-filters", criteria.length ? matches : null);
        if (searchInput.value !== table.search()) {
            table.search(searchInput.value);
        }
        table.draw();
    }

    controls.addEventListener("change", (event) => {
        if (fields.includes(event.target)) {
            filterRows();
        }
    });
    controls.addEventListener("compact-filters:change", filterRows);
}

export function getDataTableOptions(table) {
    const filename = table.dataset.filename || "export-data";
    const enableExport = table.dataset.export === "true";

    const buttons = enableExport
        ? [
              {
                  extend: "collection",
                  text: "Export",
                  className: "btn btn-primary",
                  action: exportCollection,
                  fade: 0,
                  autoClose: true,
                  buttons: getExportButtons(filename),
              },
          ]
        : [];

    return {
        processing: true,
        responsive: table.dataset.responsive !== "false",
        pageLength: 10,

        lengthMenu: [
            [10, 25, 50, 100],
            [10, 25, 50, 100],
        ],

        order: [],

        language,

        layout: {
            topStart: {
                pageLength: {},
            },

            topEnd: {
                search: {},
            },

            bottomStart: {
                info: {},
            },

            bottomEnd: {
                paging: {},
            },
        },

        columnDefs: [
            {
                targets: "no-sort",
                orderable: false,
            },
        ],

        ...(enableExport
            ? {
                  layout: {
                      topStart: {
                          buttons,
                      },

                      topEnd: {
                          search: {},
                      },

                      bottomStart: {
                          pageLength: {},
                          info: {},
                      },

                      bottomEnd: {
                          paging: {},
                      },
                  },
              }
            : {}),
    };
}

export function adjustDataTables(root = document) {
    root.querySelectorAll("[data-datatable]").forEach((table) => {
        if (!DataTable.isDataTable(table)) {
            return;
        }

        const instance = new DataTable(table);
        instance.columns.adjust();
        instance.responsive?.recalc();
    });
}
