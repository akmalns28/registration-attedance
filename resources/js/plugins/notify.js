export function initializeNotify($) {
    if (typeof $.notify !== "function") {
        console.error("Notify.js belum terinisialisasi.");
        return;
    }

    $.notify.defaults({
        globalPosition: "top right",
        autoHideDelay: 4000,
        clickToHide: true,
    });

    const notify = {
        success(message) {
            $.notify(message, "success");
        },

        error(message) {
            $.notify(message, "error");
        },

        warning(message) {
            $.notify(message, "warn");
        },

        info(message) {
            $.notify(message, "info");
        },
    };

    window.notify = notify;

    return notify;
}
