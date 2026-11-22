export function initializeMobileLayout() {
    const tabStrips = document.querySelectorAll(".app-tabs");
    if (tabStrips.length) {
        const revealActiveTabs = () => requestAnimationFrame(() => {
            tabStrips.forEach((tabs) => revealSelectedTab(tabs.querySelector('[aria-selected="true"]')));
        });

        window.addEventListener("pageshow", revealActiveTabs);
        window.addEventListener("resize", revealActiveTabs);
    }

    const viewport = window.visualViewport;
    if (!viewport) {
        return;
    }

    document.querySelectorAll(".app-mobile-modal").forEach((modal) => {
        let listeners;
        const resize = () => {
            modal.style.setProperty("--modal-viewport-height", `${viewport.height}px`);
            modal.style.setProperty("--modal-viewport-top", `${viewport.offsetTop}px`);
            modal.toggleAttribute("data-short-viewport", viewport.height < 360);
        };
        modal.addEventListener("show.bs.modal", () => {
            listeners?.abort();
            listeners = new AbortController();
            viewport.addEventListener("resize", resize, { signal: listeners.signal });
            viewport.addEventListener("scroll", resize, { signal: listeners.signal });
            resize();
        });
        modal.addEventListener("hidden.bs.modal", () => listeners?.abort());
    });
}

export function revealSelectedTab(tab) {
    const tabs = tab?.closest(".app-tabs");
    if (!tabs || tabs.scrollWidth <= tabs.clientWidth) {
        return;
    }

    const item = tab.getBoundingClientRect();
    const strip = tabs.getBoundingClientRect();
    const offset = item.left < strip.left ? item.left - strip.left : Math.max(0, item.right - strip.right);
    tabs.scrollTo({ left: tabs.scrollLeft + offset, behavior: "instant" });
}
