import { vi } from 'vitest';

// Ziggy route() helper — stubbed to return a plain string
window.route = vi.fn(() => '/dashboard');

// Inertia router — stubbed to prevent actual navigation
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn() },
}));

// ResizeObserver polyfill — required by Vuetify in jsdom
global.ResizeObserver = vi.fn(() => ({
    observe: vi.fn(),
    unobserve: vi.fn(),
    disconnect: vi.fn(),
}));
