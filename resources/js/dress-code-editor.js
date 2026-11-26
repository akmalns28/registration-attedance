const listMarker = /^[ \t]*(?:([1-9]\d{0,3})\.|[•*-])[ \t]+/;

export function initializeDressCodeEditors(root = document) {
    for (const editor of root.querySelectorAll("[data-dress-code-editor]")) {
        const field = editor.querySelector("textarea");
        const toolbar = editor.querySelector("[data-dress-code-toolbar]");
        toolbar.hidden = false;
        toolbar.classList.add("d-flex");

        for (const button of toolbar.querySelectorAll("[data-list-format]")) {
            button.addEventListener("click", () => formatLines(field, button.dataset.listFormat));
        }

        const handleEnter = (event) => {
            if (!event.isComposing && event.cancelable && continueList(field)) {
                event.preventDefault();
            }
        };
        field.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                handleEnter(event);
            }
        });
        field.addEventListener("beforeinput", (event) => {
            if (event.inputType === "insertLineBreak" || event.inputType === "insertParagraph") {
                handleEnter(event);
            }
        });
        field.form?.addEventListener("submit", () => {
            const cleaned = field.value.replace(/^[ \t]*(?:[1-9]\d{0,3}\.|[•*-])[ \t]+$/gm, "");
            if (cleaned !== field.value) {
                field.value = cleaned;
            }
        });
    }
}

function formatLines(field, format) {
    const { value, selectionStart, selectionEnd } = field;
    const selected = selectionStart !== selectionEnd;
    const start = lineStart(value, selectionStart);
    const lastSelected = selected && value[selectionEnd - 1] === "\n" ? selectionEnd - 1 : selectionEnd;
    const end = lineEnd(value, lastSelected);
    const lines = value.slice(start, end).split("\n");
    const marked = lines.filter((line) => line.trim() !== "");
    const remove = marked.length > 0 && marked.every((line) => {
        const marker = line.match(listMarker);
        return marker && (format === "number") === Boolean(marker[1]);
    });
    let number = 0;
    const formatted = lines.map((line) => {
        const text = line.replace(listMarker, "");
        if (remove || (lines.length > 1 && !line.trim())) {
            return text;
        }
        return (format === "number" ? ++number + ". " : "• ") + text;
    }).join("\n");

    field.focus();
    if (replaceText(field, formatted, start, end, selected ? "select" : "end") && !selected) {
        const previousPrefix = lines[0].match(listMarker)?.[0].length ?? 0;
        const nextPrefix = formatted.match(listMarker)?.[0].length ?? 0;
        const caret = start + nextPrefix + Math.max(0, selectionStart - start - previousPrefix);
        field.setSelectionRange(caret, caret);
    }
}

function continueList(field) {
    const { value, selectionStart, selectionEnd } = field;
    const start = lineStart(value, selectionStart);
    const end = lineEnd(value, selectionStart);
    const line = value.slice(start, end);
    const marker = line.match(listMarker);
    if (!marker || selectionEnd > end || selectionStart < start + marker[0].length) {
        return false;
    }

    if (line.slice(marker[0].length).trim() === "") {
        return replaceText(field, "", start, end);
    }

    if (!marker[1]) {
        return replaceText(field, "\n• ", selectionStart, selectionEnd);
    }

    let number = Number(marker[1]) + 1;
    const prefix = "\n" + number + ". ";
    const following = value.slice(selectionEnd).split("\n");
    for (let index = 1; index < following.length; index++) {
        const nextMarker = following[index].match(listMarker);
        if (!nextMarker?.[1]) {
            break;
        }
        following[index] = ++number + ". " + following[index].slice(nextMarker[0].length);
    }
    if (!replaceText(field, prefix + following.join("\n"), selectionStart, value.length)) {
        return false;
    }
    const caret = selectionStart + prefix.length;
    field.setSelectionRange(caret, caret);
    return true;
}

function replaceText(field, text, start, end, selection = "end") {
    if (field.value.length - (end - start) + text.length > field.maxLength) {
        return false;
    }
    field.setRangeText(text, start, end, selection);
    field.dispatchEvent(new Event("input", { bubbles: true }));
    return true;
}

function lineStart(value, index) {
    return value.slice(0, index).lastIndexOf("\n") + 1;
}

function lineEnd(value, index) {
    const end = value.indexOf("\n", index);
    return end === -1 ? value.length : end;
}
