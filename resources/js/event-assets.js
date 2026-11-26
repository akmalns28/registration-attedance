export function initializeEventAssets() {
    const editor = document.querySelector("[data-poster-editor]");
    if (editor && typeof DataTransfer !== "undefined") {
        initializePosterEditor(editor);
    }

    const modal = document.getElementById("deleteGalleryMedia");
    modal?.addEventListener("show.bs.modal", (event) => {
        const button = event.relatedTarget;
        modal.querySelector("[data-media-delete-form]").setAttribute("action", button.dataset.mediaDelete);
        modal.querySelector("[data-media-delete-name]").textContent = button.dataset.mediaName;
    });

    for (const row of document.querySelectorAll("[data-export-status]")) {
        if (["pending", "processing"].includes(row.dataset.exportStatus)) {
            pollInvitationExport(row);
        }
    }
}

function pollInvitationExport(row) {
    const message = row.querySelector("[data-export-message]");
    const download = row.querySelector("[data-export-download]");
    const again = () => window.setTimeout(check, 4000);

    async function check() {
        if (document.hidden) {
            again();
            return;
        }

        try {
            const response = await fetch(row.dataset.exportUrl, { headers: { Accept: "application/json" } });
            if ([401, 403, 404].includes(response.status)) {
                message.textContent = "Status ekspor tidak tersedia. Muat ulang halaman untuk melanjutkan.";
                return;
            }
            if (!response.ok) {
                again();
                return;
            }

            const data = await response.json();
            const labels = {
                pending: "Menunggu antrean.",
                processing: `Menyiapkan undangan (${data.processed_count}/${data.participant_count}).`,
                completed: `Siap diunduh (${data.processed_count} undangan).`,
                failed: "Ekspor gagal. Pilih Export Invitations untuk mencoba kembali.",
            };
            if (message.textContent.trim() !== labels[data.status]) {
                message.textContent = labels[data.status];
            }
            if (data.download_url) {
                download.href = data.download_url;
                download.classList.remove("d-none");
            }
            if (["pending", "processing"].includes(data.status)) {
                again();
            }
        } catch {
            again();
        }
    }

    again();
}

function initializePosterEditor(editor) {
    const input = editor.querySelector('input[type="file"]');
    const list = editor.querySelector("[data-poster-list]");
    const template = editor.querySelector("[data-poster-template]");
    const primaryField = editor.querySelector("[data-poster-primary]");
    const removed = editor.querySelector("[data-poster-removed]");
    const error = editor.querySelector("[data-poster-error]");
    const limit = Number(editor.dataset.limit);
    const fileLimit = Number(editor.dataset.fileLimit);
    const postLimit = Number(editor.dataset.postLimit);
    const form = input.form;
    const pending = new Map();
    const savedUploads = new Map(JSON.parse(editor.dataset.posterUploadAssignments)
        .map((assignment) => [assignment.upload_reference, assignment]));
    let nextKey = 0;
    let changed = false;
    let primary = list.querySelector('[aria-pressed="true"]')?.closest("[data-poster-key]").dataset.posterKey;

    function sync() {
        const tiles = Array.from(list.children);
        const transfer = new DataTransfer();

        if (!tiles.some((tile) => tile.dataset.posterKey === primary)) {
            primary = tiles[0]?.dataset.posterKey;
        }

        tiles.forEach((tile, index) => {
            const key = tile.dataset.posterKey;
            const upload = pending.get(key);
            const orderField = tile.querySelector("[data-poster-order]");
            orderField.disabled = !changed;
            orderField.value = upload ? `upload:${transfer.items.length}` : key;
            if (upload) {
                transfer.items.add(upload.file);
            }

            tile.querySelector("[data-poster-controls]").classList.remove("d-none");
            tile.querySelector("[data-poster-badge]").classList.toggle("d-none", key !== primary);
            tile.querySelector('[data-poster-action="primary"]').setAttribute("aria-pressed", String(key === primary));
            tile.querySelector('[data-poster-action="up"]').disabled = index === 0;
            tile.querySelector('[data-poster-action="down"]').disabled = index === tiles.length - 1;
            if (key === primary) {
                primaryField.value = orderField.value;
            }
            syncPosterAssignment(tile, orderField.value, upload?.reference);
        });

        primaryField.disabled = !changed || !primary;
        input.files = transfer.files;
    }

    input.addEventListener("change", () => {
        error.textContent = "";
        let selectedBytes = Array.from(pending.values()).reduce((sum, { file }) => sum + file.size, 0);
        for (const file of input.files) {
            if (!/\.(jpe?g|png|webp)$/i.test(file.name)) {
                error.textContent = "Poster harus berformat JPG, JPEG, PNG, atau WebP.";
                continue;
            }
            if (file.size > fileLimit) {
                error.textContent = `Ukuran setiap poster maksimal ${editor.dataset.fileLimitLabel}.`;
                continue;
            }
            const reference = JSON.stringify([file.name, file.size, file.lastModified]);
            const duplicate = Array.from(pending.values()).some((upload) => upload.reference === reference);
            if (duplicate) {
                continue;
            }
            if (list.children.length >= limit) {
                error.textContent = `Maksimal ${limit} poster per event.`;
                break;
            }
            if (selectedBytes + file.size > postLimit) {
                error.textContent = `Total ukuran unggahan maksimal ${postLimit / 1048576} MB per permintaan.`;
                continue;
            }

            const key = `pending:${nextKey++}`;
            const url = URL.createObjectURL(file);
            const tile = template.content.firstElementChild.cloneNode(true);
            tile.dataset.posterKey = key;
            tile.querySelector("[data-poster-preview]").href = url;
            const image = tile.querySelector("img");
            image.src = url;
            image.alt = file.name;
            const name = tile.querySelector("[data-poster-name]");
            name.textContent = file.name;
            name.title = file.name;
            pending.set(key, { file, url, reference });
            restorePosterAssignment(tile, savedUploads.get(reference));
            list.append(tile);
            selectedBytes += file.size;
            changed = true;
        }
        sync();
    });

    form.addEventListener("submit", (event) => {
        const total = Array.from(form.querySelectorAll('input[type="file"]'))
            .flatMap((field) => Array.from(field.files))
            .reduce((sum, file) => sum + file.size, 0);
        if (total > postLimit) {
            event.preventDefault();
            event.stopImmediatePropagation();
            error.textContent = `Total ukuran unggahan maksimal ${postLimit / 1048576} MB per permintaan.`;
            input.focus();
        }
    }, true);

    list.addEventListener("change", (event) => {
        const checkbox = event.target;
        const assignment = checkbox.closest("[data-poster-assignment]");
        if (!assignment) {
            return;
        }

        if (checkbox.matches("[data-poster-all-days]") && checkbox.checked) {
            for (const day of assignment.querySelectorAll("[data-poster-day]")) {
                day.checked = false;
            }
        } else if (checkbox.matches("[data-poster-day]") && checkbox.checked) {
            assignment.querySelector("[data-poster-all-days]").checked = false;
        }
        assignment.setAttribute("data-poster-assignment-dirty", "");
        const tile = assignment.closest("[data-poster-key]");
        syncPosterAssignment(tile, tile.querySelector("[data-poster-order]").value, pending.get(tile.dataset.posterKey)?.reference);
    });

    list.addEventListener("click", (event) => {
        const button = event.target.closest("[data-poster-action]");
        if (!button) {
            return;
        }

        const tile = button.closest("[data-poster-key]");
        const key = tile.dataset.posterKey;
        changed = true;
        switch (button.dataset.posterAction) {
            case "primary":
                primary = key;
                break;
            case "up":
                tile.previousElementSibling?.before(tile);
                break;
            case "down":
                tile.nextElementSibling?.after(tile);
                break;
            case "remove": {
                const upload = pending.get(key);
                if (upload) {
                    URL.revokeObjectURL(upload.url);
                    pending.delete(key);
                } else {
                    const field = document.createElement("input");
                    field.type = "hidden";
                    field.name = "poster_remove[]";
                    field.value = key;
                    removed.append(field);
                }
                tile.remove();
                error.textContent = "";
                input.focus();
                break;
            }
        }
        sync();
    });

    sync();
}

function syncPosterAssignment(tile, key, uploadReference) {
    const assignment = tile.querySelector("[data-poster-assignment]");
    if (!assignment) {
        return;
    }

    assignment.hidden = false;
    const allDays = assignment.querySelector("[data-poster-all-days]").checked;
    const days = Array.from(assignment.querySelectorAll("[data-poster-day]:checked"));
    tile.querySelector("[data-poster-day-count]").textContent = allDays ? "Semua Hari" : `${days.length} hari`;
    const fields = assignment.querySelector("[data-poster-assignment-fields]");
    fields.replaceChildren();
    if (!assignment.hasAttribute("data-poster-assignment-dirty")) {
        return;
    }

    const scope = allDays ? "all" : "days";
    const values = [["[scope]", scope], ["[day_count]", days.length], ...days.map((day) => ["[days][]", day.value])];
    if (uploadReference) {
        values.push(["[upload_reference]", uploadReference]);
    }

    for (const [field, value] of values) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `poster_assignments[${key}]${field}`;
        input.value = value;
        fields.append(input);
    }
}

function restorePosterAssignment(tile, saved) {
    const assignment = tile.querySelector("[data-poster-assignment]");
    if (!assignment || !["all", "days"].includes(saved?.scope)) {
        return;
    }

    assignment.open = true;
    assignment.setAttribute("data-poster-assignment-dirty", "");
    assignment.querySelector("[data-poster-all-days]").checked = saved.scope === "all";
    const selectedDays = new Set(Array.isArray(saved.days) ? saved.days.map(String) : []);
    for (const day of assignment.querySelectorAll("[data-poster-day]")) {
        day.checked = saved.scope === "days" && selectedDays.has(day.value);
    }
}
