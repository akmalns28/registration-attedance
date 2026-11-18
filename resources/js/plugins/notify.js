import registerNotify from "notifyjs-browser";

export function initializeNotify($) {
    if (window.notify?.system === "attendance") {
        return window.notify;
    }
    // Notify.js 0.4 still uses these helpers, which jQuery 4 removed.
    $.isArray ??= Array.isArray;
    $.type ??= (value) =>
        value == null
            ? String(value)
            : Object.prototype.toString.call(value).slice(8, -1).toLowerCase();

    if (typeof $.notify !== "function") {
        registerNotify(window, $);
    }

    $.notify.addStyle("attendance", {
        html: document.getElementById("notification-toast-template")?.innerHTML ||
            '<div><div class="app-toast" role="status" aria-live="polite" aria-atomic="true"><div class="app-toast-copy"><strong data-notify-text="title"></strong><div data-notify-text="message"></div></div><button type="button" class="app-toast-dismiss" aria-label="Tutup notifikasi">×</button></div></div>',
        classes: { success: {}, error: {}, warning: {}, info: {} },
    });
    $.notify.defaults({ style: "attendance", globalPosition: "top right", clickToHide: false, arrowShow: false, gap: 8 });
    const active = new Map();
    const titles = { success: "Berhasil", error: "Terjadi kesalahan", warning: "Perhatian", info: "Informasi" };
    const durations = { success: 4500, error: 9000, warning: 7000, info: 5500 };
    function show(type, message, options = {}) {
        if (typeof message !== "string" || !message.trim()) {
            return;
        }
        for (const [key, wrapper] of active) {
            if (!wrapper.isConnected) { active.delete(key); }
        }
        const key = `${type}:${message.trim()}`;
        if (active.has(key)) {
            return;
        }
        const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        $.notify({ title: titles[type], message }, {
            className: type, autoHide: !options.persistent, autoHideDelay: options.duration ?? durations[type],
            showAnimation: "fadeIn", hideAnimation: "fadeOut", showDuration: reduced ? 0 : 160, hideDuration: reduced ? 0 : 160,
        });
        const wrapper = document.querySelector(".notifyjs-attendance-base")?.closest(".notifyjs-wrapper");
        const toast = wrapper?.querySelector(".app-toast");
        if (toast) {
            toast.setAttribute("role", type === "error" ? "alert" : "status");
            toast.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
            active.set(key, wrapper);
        }
    }
    $(document).on("click.attendance-notify", ".app-toast-dismiss", function () {
        $(this).closest(".notifyjs-wrapper").trigger("notify-hide");
    });

    const notify = {
        system: "attendance",
        success(message, options) {
            show("success", message, options);
        },

        error(message, options) {
            show("error", message, options);
        },

        warning(message, options) {
            show("warning", message, options);
        },

        info(message, options) {
            show("info", message, options);
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
