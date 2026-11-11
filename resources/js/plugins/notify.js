import registerNotify from "notifyjs-browser";

export function initializeNotify($) {
    // Notify.js 0.4 still uses these helpers, which jQuery 4 removed.
    $.isArray ??= Array.isArray;
    $.type ??= (value) =>
        value == null
            ? String(value)
            : Object.prototype.toString.call(value).slice(8, -1).toLowerCase();

    if (typeof $.notify !== "function") {
        registerNotify(window, $);
    }

    $.notify.defaults({
        globalPosition: "top right",
        autoHideDelay: 4000,
        clickToHide: true,
    });

    const notify = {
        success(message) {
            $.notify(message, { className: "success" });
        },

        error(message) {
            $.notify(message, { className: "error" });
        },

        warning(message) {
            $.notify(message, { className: "warn" });
        },

        info(message) {
            $.notify(message, { className: "info" });
        },
    };

    window.notify = notify;
    $(() => showFlashNotifications(notify));

    return notify;
}

function showFlashNotifications(notify) {
    const element = document.getElementById("flash-notifications");

    if (!element) {
        return;
    }

    const notifications = JSON.parse(element.textContent);
    element.remove();

    Object.entries(notifications).forEach(([type, message]) => {
        if (
            typeof message === "string" &&
            message.trim() !== "" &&
            typeof notify[type] === "function"
        ) {
            notify[type](message);
        }
    });
}
