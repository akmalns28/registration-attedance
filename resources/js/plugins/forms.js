/**
 * Global Form Handler
 *
 * Features:
 * - Automatic submit loading
 * - Prevent double submit
 * - Native HTML validation support
 * - Configurable loading text
 * - Supports multiple forms
 * - Works with Laravel Blade forms
 */

export function initializeForms(root = document) {
    const forms = root.querySelectorAll("form[data-form]");

    forms.forEach((form) => {
        if (form.dataset.initialized === "true") {
            return;
        }

        form.dataset.initialized = "true";

        form.addEventListener("submit", function (event) {
            if (form.dataset.submitting === "true") {
                event.preventDefault();
                return;
            }

            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            const submitButton = event.submitter;

            if (!submitButton) {
                return;
            }

            // For standard HTML form submissions only.
            // AJAX forms should manage their own lifecycle.
            if (form.dataset.ajax === "true") {
                return;
            }

            form.dataset.submitting = "true";
            form.setAttribute("aria-busy", "true");

            const loadingText = form.dataset.loadingText || "Menyimpan...";

            const originalHtml = submitButton.innerHTML;

            submitButton.dataset.originalHtml = originalHtml;

            submitButton.disabled = true;

            submitButton.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-2"
                    aria-hidden="true"
                ></span>
                ${escapeHtml(loadingText)}
            `;
        });
    });
}

window.addEventListener("pageshow", (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
        delete form.dataset.submitting;
        form.removeAttribute("aria-busy");
        form.querySelectorAll("[data-original-html]").forEach((button) => {
            button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
            button.disabled = false;
        });
    });
});

function escapeHtml(value) {
    const element = document.createElement("div");
    element.textContent = value;

    return element.innerHTML;
}
