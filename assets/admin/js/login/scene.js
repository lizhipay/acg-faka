/**
 * Admin sign-in scene: lock-screen clock, wallpaper readiness and ink sampling,
 * focus blur, pointer-lit glass, appearance and language menus.
 * Runs before the common bundle, so it must stay dependency free.
 */
!function () {
    const root = document.documentElement;
    const body = document.body;
    const motion = window.matchMedia('(prefers-reduced-motion: no-preference)');
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
    const darkScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const tallScreen = window.matchMedia('(max-aspect-ratio: 4/5)');
    const WALLPAPERS = '/assets/admin/images/login/';

    const listen = (query, fn) => query.addEventListener ? query.addEventListener('change', fn) : query.addListener(fn);

    function Clock() {
        const box = document.querySelector('[data-lk-clock]');
        if (!box) {
            return;
        }
        const dateEl = box.querySelector('[data-lk-date]');
        const timeEl = box.querySelector('[data-lk-time]');
        let dateFormat;
        let timeFormat;
        try {
            dateFormat = new Intl.DateTimeFormat(root.lang || undefined, {weekday: 'long', month: 'long', day: 'numeric'});
            timeFormat = new Intl.DateTimeFormat(root.lang || undefined, {hour: 'numeric', minute: '2-digit'});
        } catch (e) {
            dateFormat = new Intl.DateTimeFormat(undefined, {weekday: 'long', month: 'long', day: 'numeric'});
            timeFormat = new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'});
        }
        let shown = '';

        // Lock screens show "11:05", never "下午 11:05": keep only hour … minute.
        const clockText = (now) => {
            const parts = timeFormat.formatToParts(now);
            const hour = parts.findIndex(p => p.type === 'hour');
            const minute = parts.findIndex(p => p.type === 'minute');
            if (hour < 0 || minute < 0) {
                return timeFormat.format(now);
            }
            return parts.slice(Math.min(hour, minute), Math.max(hour, minute) + 1).map(p => p.value).join('').trim();
        };

        const render = (animate) => {
            const now = new Date();
            dateEl.textContent = dateFormat.format(now);
            const text = clockText(now);
            if (text === shown) {
                return;
            }
            const previous = shown;
            shown = text;
            const fragment = document.createDocumentFragment();
            for (let i = 0; i < text.length; i++) {
                const glyph = document.createElement('span');
                glyph.textContent = text[i];
                if (!/\d/.test(text[i])) {
                    glyph.className = 'lk-time__sep';
                } else if (animate && previous[i] !== text[i]) {
                    glyph.className = 'is-tick';
                }
                fragment.appendChild(glyph);
            }
            timeEl.textContent = '';
            timeEl.appendChild(fragment);
        };

        const schedule = () => {
            setTimeout(() => {
                render(true);
                schedule();
            }, 60000 - Date.now() % 60000 + 40);
        };

        render(false);
        schedule();
        document.addEventListener('visibilitychange', () => document.hidden || render(true));
    }

    function builtinWallpaper(theme) {
        return WALLPAPERS + 'wall-' + theme + (tallScreen.matches ? '-tall' : '') + '.webp';
    }

    function currentWallpaper(wall) {
        if (body.classList.contains('lk--custom')) {
            return wall.getAttribute('data-lk-src') || '';
        }
        return builtinWallpaper(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
    }

    // Relative luminance of the column behind the clock, name and options (the cover-cropped view).
    function sampleInk(img) {
        const width = img.naturalWidth;
        const height = img.naturalHeight;
        if (!width || !height) {
            return;
        }
        let pixels;
        try {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 32;
            const context = canvas.getContext('2d', {willReadFrequently: true});
            const scale = Math.max(window.innerWidth / width, window.innerHeight / height);
            const viewW = window.innerWidth / scale;
            const viewH = window.innerHeight / scale;
            context.drawImage(img, (width - viewW) / 2 + viewW * .3, (height - viewH) / 2, viewW * .4, viewH, 0, 0, 32, 32);
            pixels = context.getImageData(0, 0, 32, 32).data;
        } catch (e) {
            return; // cross-origin wallpaper without CORS: keep the default ink
        }
        const linear = (c) => (c /= 255) <= .04045 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4);
        let sum = 0;
        for (let i = 0; i < pixels.length; i += 4) {
            sum += .2126 * linear(pixels[i]) + .7152 * linear(pixels[i + 1]) + .0722 * linear(pixels[i + 2]);
        }
        root.setAttribute('data-ink', sum / (pixels.length / 4) > .36 ? 'dark' : 'light');
    }

    function Wallpaper() {
        const wall = document.querySelector('.lk-wall');
        if (!wall) {
            return;
        }
        let revealed = false;
        const reveal = () => {
            if (!revealed) {
                revealed = true;
                body.classList.add('is-wall-ready');
            }
        };
        const src = currentWallpaper(wall);
        if (!src) {
            reveal();
            return;
        }
        const img = new Image();
        img.decoding = 'async';
        img.onload = () => {
            reveal();
            if (body.classList.contains('lk--custom')) {
                (window.requestIdleCallback || setTimeout)(() => sampleInk(img));
            }
        };
        img.onerror = reveal;
        img.src = src;
        setTimeout(reveal, 2500);
    }

    // The wallpaper softens only once the form is really in use, not on the initial autofocus.
    function Focus() {
        const form = document.getElementById('lk-form');
        if (!form) {
            return;
        }
        const engage = () => body.classList.add('is-engaged');
        const release = () => {
            if (form.contains(document.activeElement)) {
                return;
            }
            const inputs = form.querySelectorAll('.lk-input');
            for (let i = 0; i < inputs.length; i++) {
                if (inputs[i].value) {
                    return;
                }
            }
            body.classList.remove('is-engaged');
        };
        form.addEventListener('input', engage);
        form.addEventListener('pointerdown', engage);
        form.addEventListener('focusout', () => setTimeout(release, 0));

        if (finePointer.matches && window.innerWidth > 640) {
            const user = document.getElementById('lk-user');
            user && user.focus({preventScroll: true});
        }
    }

    // Specular highlight eases toward the pointer on the hovered glass and drifts back when it leaves.
    function Light() {
        if (!finePointer.matches || !motion.matches) {
            return;
        }
        const REST = {x: 18, y: -18};
        const lit = new Map();
        let hovered = null;
        let pointer = {x: 0, y: 0};
        let frame = 0;

        const tick = () => {
            frame = 0;
            let moving = false;
            lit.forEach((pos, glass) => {
                let goal = REST;
                if (glass === hovered) {
                    const r = glass.getBoundingClientRect();
                    goal = {x: (pointer.x - r.left) / r.width * 100, y: (pointer.y - r.top) / r.height * 100};
                }
                pos.x += (goal.x - pos.x) * .16;
                pos.y += (goal.y - pos.y) * .16;
                const settled = Math.abs(goal.x - pos.x) < .2 && Math.abs(goal.y - pos.y) < .2;
                if (settled && glass !== hovered) {
                    glass.style.removeProperty('--lk-px');
                    glass.style.removeProperty('--lk-py');
                    lit.delete(glass);
                    return;
                }
                moving = moving || !settled;
                glass.style.setProperty('--lk-px', pos.x.toFixed(2) + '%');
                glass.style.setProperty('--lk-py', pos.y.toFixed(2) + '%');
            });
            if (moving) {
                frame = requestAnimationFrame(tick);
            }
        };
        const wake = () => frame || (frame = requestAnimationFrame(tick));

        document.addEventListener('pointermove', (e) => {
            hovered = e.target.closest ? e.target.closest('.lk-glass') : null;
            pointer = {x: e.clientX, y: e.clientY};
            if (hovered && !lit.has(hovered)) {
                lit.set(hovered, {x: REST.x, y: REST.y});
            }
            wake();
        }, {passive: true});
        root.addEventListener('pointerleave', () => {
            hovered = null;
            wake();
        });
    }

    function Menus() {
        let open = null;
        const itemsOf = (menu) => Array.prototype.slice.call(menu.querySelectorAll('[role^="menuitem"]'));

        const close = (restoreFocus) => {
            if (!open) {
                return;
            }
            open.menu.classList.remove('is-open');
            open.button.setAttribute('aria-expanded', 'false');
            if (restoreFocus) {
                open.button.focus();
            }
            open = null;
        };

        const show = (button, menu) => {
            close(false);
            menu.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            open = {button: button, menu: menu};
            const items = itemsOf(menu);
            const checked = items.filter(item => item.getAttribute('aria-checked') === 'true')[0] || items[0];
            checked && checked.focus({preventScroll: true});
            menu.dispatchEvent(new CustomEvent('lk:open'));
        };

        document.querySelectorAll('[data-lk-menu]').forEach(button => {
            const menu = document.getElementById(button.getAttribute('data-lk-menu'));
            if (!menu) {
                return;
            }
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                open && open.menu === menu ? close(false) : show(button, menu);
            });
            button.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    show(button, menu);
                }
            });
            menu.addEventListener('keydown', (e) => {
                const items = itemsOf(menu);
                const index = items.indexOf(document.activeElement);
                const move = {ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1}[e.key];
                if (move !== undefined) {
                    e.preventDefault();
                    items[(move + items.length) % items.length].focus();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    close(true);
                } else if (e.key === 'Tab') {
                    close(false);
                }
            });
        });

        document.addEventListener('click', (e) => {
            if (open && !open.menu.contains(e.target)) {
                close(false);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && open) {
                close(true);
            }
        });

        return {close: close};
    }

    function Appearance(menus) {
        const menu = document.getElementById('lk-theme-menu');
        if (!menu) {
            return;
        }
        const preference = () => root.getAttribute('data-theme-pref') || 'auto';
        const sync = () => {
            menu.querySelectorAll('[data-lk-theme]').forEach(item => {
                item.setAttribute('aria-checked', String(item.getAttribute('data-lk-theme') === preference()));
            });
        };
        const apply = (next, animate) => {
            const run = () => {
                const dark = next === 'dark' || (next === 'auto' && darkScheme.matches);
                root.setAttribute('data-theme', dark ? 'dark' : 'light');
                root.setAttribute('data-theme-pref', next);
                sync();
            };
            if (animate && motion.matches && document.startViewTransition) {
                document.startViewTransition(run);
            } else {
                run();
            }
        };

        // Warm the other built-in wallpaper so the switch never flashes the base colour.
        menu.addEventListener('lk:open', () => {
            if (body.classList.contains('lk--builtin')) {
                ['light', 'dark'].forEach(theme => (new Image()).src = builtinWallpaper(theme));
            }
        }, {once: true});

        menu.addEventListener('click', (e) => {
            const item = e.target.closest('[data-lk-theme]');
            if (!item) {
                return;
            }
            const next = item.getAttribute('data-lk-theme');
            try {
                localStorage.setItem('admin-theme', next);
            } catch (err) {
            }
            menus.close(true);
            apply(next, true);
        });

        listen(darkScheme, () => preference() === 'auto' && apply('auto', true));
        sync();
    }

    Wallpaper();
    Clock();
    Focus();
    Light();
    Appearance(Menus());
}();
