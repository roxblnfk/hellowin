// Tabs on the WAI-ARIA Tabs pattern. Markup carries the roles; without this script every panel stays visible.
// The panel id is the URL fragment, so a tab is a link and works as a plain anchor without JS.
(() => {
    'use strict';

    for (const list of document.querySelectorAll('[role="tablist"]')) {
        initTabs(list);
    }

    function initTabs(list) {
        const tabs = [...list.querySelectorAll('[role="tab"]')];
        const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
        if (tabs.length === 0 || panels.includes(null)) {
            return;
        }

        function select(index, focus) {
            tabs.forEach((tab, i) => {
                const active = i === index;
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
                panels[i].hidden = !active;
            });
            if (focus) {
                tabs[index].focus();
            }
            revealTab(tabs[index]);
        }

        // A fragment inside a panel (not just the panel itself) opens that panel too.
        function targetFromHash() {
            const id = decodeURIComponent(location.hash.slice(1));
            const target = id === '' ? null : document.getElementById(id);
            const index = target ? panels.findIndex((panel) => panel.contains(target)) : -1;
            return index === -1 ? null : {target, index};
        }

        function syncWithHash() {
            const found = targetFromHash();
            if (found) {
                select(found.index, false);
            } else if (location.hash === '') {
                select(0, false);
            }
        }

        // pushState instead of location.hash: assigning the hash would jump the page to the panel.
        function activate(index) {
            const fragment = '#' + panels[index].id;
            if (location.hash !== fragment) {
                history.pushState(null, '', fragment);
            }
            select(index, true);

            // Once the sticky bar has scrolled past the panel top, the new panel would open mid-way.
            if (panels[index].getBoundingClientRect().top < list.getBoundingClientRect().bottom) {
                panels[index].scrollIntoView({block: 'start'});
            }
        }

        list.addEventListener('click', (event) => {
            const index = tabs.indexOf(event.target.closest('[role="tab"]'));
            if (index === -1 || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }
            event.preventDefault();
            activate(index);
        });

        list.addEventListener('keydown', (event) => {
            const current = tabs.indexOf(event.target);
            if (current === -1) {
                return;
            }
            const last = tabs.length - 1;
            const next = {
                ArrowLeft: current === 0 ? last : current - 1,
                ArrowRight: current === last ? 0 : current + 1,
                Home: 0,
                End: last,
            }[event.key];
            if (next === undefined) {
                return;
            }
            event.preventDefault();
            activate(next);
        });

        // Back/forward over pushState entries fires popstate, a typed or linked fragment fires hashchange.
        window.addEventListener('popstate', syncWithHash);
        window.addEventListener('hashchange', syncWithHash);

        list.closest('.tabs')?.classList.add('tabs--ready');
        const initial = targetFromHash();
        select(initial ? initial.index : 0, false);

        // The browser aims its fragment scroll at the page with every panel still shown, so it lands off target
        // once the others hide. A reload keeps its own restored position.
        if (initial && performance.getEntriesByType('navigation')[0]?.type !== 'reload') {
            initial.target.scrollIntoView({block: 'start'});
        }
    }

    // Scrolls only the tab strip: scrollIntoView would also move the page. offsetLeft counts from the strip
    // because the stylesheet makes the strip positioned.
    function revealTab(tab) {
        const strip = tab.closest('[role="tablist"]');
        const left = tab.offsetLeft;
        if (left < strip.scrollLeft) {
            strip.scrollLeft = left;
        } else if (left + tab.offsetWidth > strip.scrollLeft + strip.clientWidth) {
            strip.scrollLeft = left + tab.offsetWidth - strip.clientWidth;
        }
    }
})();
