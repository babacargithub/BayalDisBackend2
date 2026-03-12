import { vi } from 'vitest';

// Ziggy route() helper — stubbed to return a plain string
window.route = vi.fn(() => '/dashboard');

// Inertia router — stubbed to prevent actual navigation
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn() },
}));

// ResizeObserver polyfill — required by Vuetify in jsdom.
// Must be a real class constructor because components like VSlideGroup (used
// internally by VTabs) call `new ResizeObserver(callback)`, not a plain function.
global.ResizeObserver = class {
    observe = vi.fn();
    unobserve = vi.fn();
    disconnect = vi.fn();
    constructor(_callback) {}
};
