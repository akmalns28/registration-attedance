import DataTable from "datatables.net-bs5";

import "datatables.net-buttons-bs5";
import "datatables.net-responsive-bs5";

import "datatables.net-buttons/js/buttons.html5.mjs";
import "datatables.net-buttons/js/buttons.print.mjs";
import "datatables.net-buttons/js/buttons.colVis.mjs";

import JSZip from "jszip";

import pdfMake from "pdfmake/build/pdfmake";
import pdfFonts from "pdfmake/build/vfs_fonts";

DataTable.Buttons.jszip(JSZip);

pdfMake.vfs = pdfFonts.pdfMake?.vfs ?? pdfFonts;

DataTable.Buttons.pdfMake(pdfMake);

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

function getExportButtons(filename) {
    const exportOptions = {
        columns: ":visible:not(.no-export)",
        modifier: {
            search: "applied",
            order: "applied",
        },
    };

    return [
        {
            extend: "excelHtml5",
            text: "Excel",
            filename,
            title: filename,
            exportOptions,
        },
        {
            extend: "csvHtml5",
            text: "CSV",
            filename,
            exportOptions,
        },
        {
            extend: "pdfHtml5",
            text: "PDF",
            filename,
            title: filename,
            orientation: "landscape",
            pageSize: "A4",
            exportOptions,
        },
        {
            extend: "copyHtml5",
            text: "Copy",
            exportOptions,
        },
        {
            extend: "print",
            text: "Print",
            title: filename,
            exportOptions,
        },
    ];
}

export function initializeDataTables() {
    document.querySelectorAll("[data-datatable]").forEach((table) => {
        if (DataTable.isDataTable(table)) {
            return;
        }

        const filename = table.dataset.filename || "export-data";
        const enableExport = table.dataset.export === "true";

        const buttons = enableExport
            ? [
                  {
                      extend: "collection",
                      text: "Export",
                      className: "btn btn-primary",
                      buttons: getExportButtons(filename),
                  },
              ]
            : [];

        new DataTable(table, {
            processing: true,
            responsive: true,
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
        });
    });
}
