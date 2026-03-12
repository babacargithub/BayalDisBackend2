import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';

// ---------------------------------------------------------------------------
// Inertia mock — useForm returns a stateful-enough stub for every form in
// the component (actionForm, itemForm, editItemForm, …). Each call gets its
// own instance so independent forms don't interfere.
// ---------------------------------------------------------------------------
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn() },
    useForm: vi.fn((initialValues = {}) => ({
        ...initialValues,
        processing: false,
        errors: {},
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    })),
}));

import Show from '@/Pages/CarLoads/Show.vue';

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

const vuetify = createVuetify({ components, directives });

/** Builds a minimal product object. */
const makeProduct = (overrides = {}) => ({
    id: Math.floor(Math.random() * 100_000),
    name: 'Produit Test',
    parent_id: null,
    ...overrides,
});

/** Builds a minimal car load item. */
const makeItem = (productId, productName, quantityLoaded, quantityLeft, overrides = {}) => ({
    id: Math.floor(Math.random() * 100_000),
    product_id: productId,
    product: makeProduct({ id: productId, name: productName }),
    quantity_loaded: quantityLoaded,
    quantity_left: quantityLeft,
    loaded_at: '2026-03-01T00:00:00.000000Z',
    ...overrides,
});

/** Builds a minimal car load prop. */
const makeCarLoad = (overrides = {}) => ({
    id: 1,
    name: 'Chargement Mbacké',
    status: 'ACTIVE',
    load_date: '2026-03-01T00:00:00.000000Z',
    return_date: '2026-03-08T00:00:00.000000Z',
    comment: null,
    team: { id: 1, name: 'Équipe A' },
    items: [],
    inventory: null,
    ...overrides,
});

/**
 * Mounts Show.vue with sensible defaults.
 * All stubs are kept minimal so Vuetify components still render.
 * route() is provided as a Vue global property (the Ziggy way) so template
 * bindings like :href="route('car-loads.show', id)" don't warn or crash.
 */
const routeStub = vi.fn((_name, _params) => '/stub-route');

const mountShow = (propOverrides = {}) =>
    mount(Show, {
        props: {
            carLoad: makeCarLoad(),
            products: [],
            missingInventoryProducts: [],
            ...propOverrides,
        },
        global: {
            plugins: [vuetify],
            config: {
                globalProperties: { route: routeStub },
            },
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
            },
        },
    });

// ---------------------------------------------------------------------------
// groupedCarLoadItems — groups items by product_id and sums quantities
// ---------------------------------------------------------------------------

describe('groupedCarLoadItems computed', () => {
    it('returns an empty array when the car load has no items', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [] }) });
        expect(wrapper.vm.groupedCarLoadItems).toEqual([]);
    });

    it('returns one group for a single item', () => {
        const item = makeItem(10, 'Eau 1.5L', 30, 20);
        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [item] }) });

        expect(wrapper.vm.groupedCarLoadItems).toHaveLength(1);
        const group = wrapper.vm.groupedCarLoadItems[0];
        expect(group.product_id).toBe(10);
        expect(group.product.name).toBe('Eau 1.5L');
        expect(group.total_quantity_loaded).toBe(30);
        expect(group.total_quantity_left).toBe(20);
        expect(group.items).toHaveLength(1);
    });

    it('sums quantity_loaded and quantity_left across two batches of the same product', () => {
        const productId = 42;
        const batch1 = makeItem(productId, 'Jus Orange 1L', 30, 20);
        const batch2 = makeItem(productId, 'Jus Orange 1L', 20, 15);

        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [batch1, batch2] }) });

        expect(wrapper.vm.groupedCarLoadItems).toHaveLength(1);
        const group = wrapper.vm.groupedCarLoadItems[0];
        expect(group.total_quantity_loaded).toBe(50);  // 30 + 20
        expect(group.total_quantity_left).toBe(35);    // 20 + 15
        expect(group.items).toHaveLength(2);
    });

    it('preserves individual batch items inside the group so inline editing still works', () => {
        const productId = 5;
        const batch1 = makeItem(productId, 'Lait 1L', 10, 8, { id: 101 });
        const batch2 = makeItem(productId, 'Lait 1L', 10, 5, { id: 102 });

        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [batch1, batch2] }) });

        const group = wrapper.vm.groupedCarLoadItems[0];
        expect(group.items[0].id).toBe(101);
        expect(group.items[1].id).toBe(102);
    });

    it('creates separate groups for different products', () => {
        const itemA = makeItem(1, 'Eau Plate 500ml', 24, 10);
        const itemB = makeItem(2, 'Jus Citron 1L', 12, 6);

        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [itemA, itemB] }) });

        expect(wrapper.vm.groupedCarLoadItems).toHaveLength(2);
    });

    it('sorts groups alphabetically by product name', () => {
        const itemZ = makeItem(1, 'Zeste Orange Drink', 10, 5);
        const itemA = makeItem(2, 'Ananas Jus 1L', 20, 10);
        const itemM = makeItem(3, 'Mangue Nectar', 15, 7);

        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [itemZ, itemA, itemM] }) });
        const names = wrapper.vm.groupedCarLoadItems.map((g) => g.product.name);

        expect(names).toEqual(['Ananas Jus 1L', 'Mangue Nectar', 'Zeste Orange Drink']);
    });

    it('handles three batches of the same product correctly', () => {
        const productId = 99;
        const batches = [
            makeItem(productId, 'Multi Lot', 10, 8),
            makeItem(productId, 'Multi Lot', 20, 15),
            makeItem(productId, 'Multi Lot', 30, 25),
        ];

        const wrapper = mountShow({ carLoad: makeCarLoad({ items: batches }) });
        const group = wrapper.vm.groupedCarLoadItems[0];

        expect(group.total_quantity_loaded).toBe(60);  // 10 + 20 + 30
        expect(group.total_quantity_left).toBe(48);    // 8 + 15 + 25
        expect(group.items).toHaveLength(3);
    });
});

// ---------------------------------------------------------------------------
// filteredProducts computed — parent-only filter toggle
// ---------------------------------------------------------------------------

describe('filteredProducts computed', () => {
    const parentA = makeProduct({ id: 1, name: 'Carton Café 12x250g', parent_id: null });
    const parentB = makeProduct({ id: 2, name: 'Pack Lait 6x1L', parent_id: null });
    const child1 = makeProduct({ id: 3, name: 'Café 250g', parent_id: 1 });
    const child2 = makeProduct({ id: 4, name: 'Lait 1L', parent_id: 2 });

    it('returns all products when the parent-only filter is off (default)', () => {
        const wrapper = mountShow({ products: [parentA, parentB, child1, child2] });
        expect(wrapper.vm.filteredProducts).toHaveLength(4);
    });

    it('returns only parent products (parent_id === null) when filter is toggled on', async () => {
        const wrapper = mountShow({ products: [parentA, parentB, child1, child2] });

        // The filter toggle button lives inside the collapsed add-items form.
        // Toggle the exposed reactive ref directly so we don't depend on the
        // collapsed UI being open.
        wrapper.vm.showParentProductsOnly = true;
        await wrapper.vm.$nextTick();

        const filtered = wrapper.vm.filteredProducts;
        expect(filtered.every((p) => p.parent_id === null)).toBe(true);
        expect(filtered).toHaveLength(2);
        expect(filtered.map((p) => p.name)).toContain('Carton Café 12x250g');
        expect(filtered.map((p) => p.name)).toContain('Pack Lait 6x1L');
    });

    it('shows all products again when the filter is toggled off a second time', async () => {
        const wrapper = mountShow({ products: [parentA, child1] });

        wrapper.vm.showParentProductsOnly = true;
        await wrapper.vm.$nextTick();
        wrapper.vm.showParentProductsOnly = false;
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.filteredProducts).toHaveLength(2);
    });
});

// ---------------------------------------------------------------------------
// inventoryResultValue — financial formula: sold + returned − loaded
// ---------------------------------------------------------------------------

describe('inventoryResultValue helper', () => {
    it('returns 0 when sold + returned exactly equals loaded (no discrepancy)', () => {
        const wrapper = mountShow();
        const item = { total_sold: 7, total_returned: 3, total_loaded: 10 };
        expect(wrapper.vm.inventoryResultValue(item)).toBe(0);
    });

    it('returns a positive number when sold + returned exceeds loaded (phantom stock)', () => {
        const wrapper = mountShow();
        const item = { total_sold: 8, total_returned: 4, total_loaded: 10 };
        expect(wrapper.vm.inventoryResultValue(item)).toBe(2);
    });

    it('returns a negative number when sold + returned is less than loaded (missing stock)', () => {
        const wrapper = mountShow();
        const item = { total_sold: 5, total_returned: 3, total_loaded: 10 };
        expect(wrapper.vm.inventoryResultValue(item)).toBe(-2);
    });

    it('returns 0 when all values are zero', () => {
        const wrapper = mountShow();
        expect(wrapper.vm.inventoryResultValue({ total_sold: 0, total_returned: 0, total_loaded: 0 })).toBe(0);
    });

    it('handles large quantities correctly', () => {
        const wrapper = mountShow();
        const item = { total_sold: 500, total_returned: 100, total_loaded: 600 };
        expect(wrapper.vm.inventoryResultValue(item)).toBe(0);
    });
});

// ---------------------------------------------------------------------------
// statusLabel & statusColor — drive the chip shown in the header
// ---------------------------------------------------------------------------

describe('statusLabel computed', () => {
    it('shows "Actif" for ACTIVE status', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'ACTIVE' }) });
        expect(wrapper.vm.statusLabel).toBe('Actif');
    });

    it('shows "En chargement" for LOADING status', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'LOADING' }) });
        expect(wrapper.vm.statusLabel).toBe('En chargement');
    });

    it('shows "Terminé" for UNLOADED status', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'UNLOADED' }) });
        expect(wrapper.vm.statusLabel).toBe('Terminé');
    });
});

describe('statusColor computed', () => {
    it('returns "success" for ACTIVE', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'ACTIVE' }) });
        expect(wrapper.vm.statusColor).toBe('success');
    });

    it('returns "warning" for LOADING', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'LOADING' }) });
        expect(wrapper.vm.statusColor).toBe('warning');
    });

    it('returns "default" for UNLOADED', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'UNLOADED' }) });
        expect(wrapper.vm.statusColor).toBe('default');
    });
});

// ---------------------------------------------------------------------------
// Rendered values — what the user actually sees on screen
// ---------------------------------------------------------------------------

describe('Rendered header', () => {
    it('displays the car load name in the header', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ name: 'Tournée Touba' }) });
        expect(wrapper.text()).toContain('Tournée Touba');
    });

    it('displays the team name in the info card', () => {
        const wrapper = mountShow({
            carLoad: makeCarLoad({ team: { id: 1, name: 'Équipe Diourbel' } }),
        });
        expect(wrapper.text()).toContain('Équipe Diourbel');
    });

    it('displays the status label as a chip in the header', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ status: 'ACTIVE' }) });
        expect(wrapper.text()).toContain('Actif');
    });
});

describe('Articles tab — items count badge', () => {
    it('shows 0 in the badge when there are no items', () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ items: [] }) });
        expect(wrapper.text()).toContain('0');
    });

    it('shows the correct count when there are items', () => {
        const items = [
            makeItem(1, 'Produit A', 10, 5),
            makeItem(2, 'Produit B', 20, 10),
            makeItem(3, 'Produit C', 5, 3),
        ];
        const wrapper = mountShow({ carLoad: makeCarLoad({ items }) });
        expect(wrapper.text()).toContain('3');
    });

    it('counts raw items not grouped items (badge reflects total rows from server)', () => {
        // Same product loaded in 2 batches = 2 raw items even though 1 group is shown
        const productId = 7;
        const items = [
            makeItem(productId, 'Eau 1L', 10, 8),
            makeItem(productId, 'Eau 1L', 20, 15),
        ];
        const wrapper = mountShow({ carLoad: makeCarLoad({ items }) });
        expect(wrapper.text()).toContain('2');
    });
});

/**
 * Switches to the Inventaire tab by clicking the tab button.
 * v-window only renders the active panel, so we must trigger the tab before
 * asserting on inventory-specific content.
 */
const switchToInventoryTab = async (wrapper) => {
    const inventoryTabButton = wrapper.findAll('button').find((b) => b.text().includes('Inventaire'));
    if (inventoryTabButton) {
        await inventoryTabButton.trigger('click');
        await wrapper.vm.$nextTick();
    }
};

describe('Inventaire tab — empty state', () => {
    it('shows the create inventory form when no inventory exists', async () => {
        const wrapper = mountShow({ carLoad: makeCarLoad({ inventory: null }) });
        await switchToInventoryTab(wrapper);
        expect(wrapper.text()).toContain('Aucun inventaire');
    });

    it('shows the inventory name when an inventory exists', async () => {
        const inventory = { id: 1, name: 'Inventaire Thiès', closed: false, items: [] };
        const wrapper = mountShow({ carLoad: makeCarLoad({ inventory }) });
        await switchToInventoryTab(wrapper);
        expect(wrapper.text()).toContain('Inventaire Thiès');
    });
});
