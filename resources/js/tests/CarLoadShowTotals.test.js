/**
 * CarLoadShow — Totals accuracy tests
 *
 * These tests verify that the articles table in CarLoads/Show.vue renders the
 * correct values in every cell, specifically:
 *
 *   • A single batch shows its own quantity_loaded / quantity_left unchanged.
 *   • Multiple batches of the same product are GROUPED into one summary row
 *     whose cells show the SUMMED totals (never an individual batch value).
 *   • Different products each have their own correct summary row.
 *   • Exhausted stock (quantity_left = 0) is shown as "0", not blank.
 *   • Expanding a group reveals sub-rows with individual batch values.
 *
 * Column indices in a summary row (matches groupedItemTableHeaders):
 *   [0] expand toggle  (data-table-expand)
 *   [1] product name   (product.name)
 *   [2] total loaded   (total_quantity_loaded)   ← LOADED_COL
 *   [3] total left     (total_quantity_left)     ← LEFT_COL
 *   [4] lots badge     (lots_count)
 *
 * Expanded sub-rows share the same column layout:
 *   [0] spacer  [1] load date  [2] batch quantity_loaded  [3] batch quantity_left  [4] actions
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';

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

// ─── Test infrastructure ──────────────────────────────────────────────────────

const vuetify = createVuetify({ components, directives });
const routeStub = vi.fn(() => '/stub-route');

let nextId = 1;
const makeItem = (productId, productName, quantityLoaded, quantityLeft, overrides = {}) => ({
    id: nextId++,
    product_id: productId,
    product: { id: productId, name: productName, parent_id: null },
    quantity_loaded: quantityLoaded,
    quantity_left: quantityLeft,
    loaded_at: '2026-03-01T00:00:00.000000Z',
    ...overrides,
});

const makeCarLoad = (items = []) => ({
    id: 1,
    name: 'Test',
    status: 'ACTIVE',
    load_date: '2026-03-01T00:00:00.000000Z',
    return_date: '2026-03-08T00:00:00.000000Z',
    comment: null,
    team: { id: 1, name: 'Équipe A' },
    inventory: null,
    items,
});

const mountShow = (items = []) =>
    mount(Show, {
        props: { carLoad: makeCarLoad(items), products: [], missingInventoryProducts: [] },
        global: {
            plugins: [vuetify],
            config: { globalProperties: { route: routeStub } },
            stubs: { AuthenticatedLayout: { template: '<div><slot name="header"/><slot/></div>' } },
        },
    });

// Column indices — keep in sync with groupedItemTableHeaders in Show.vue
const EXPAND_COL = 0;
const NAME_COL = 1;
const LOADED_COL = 2;
const LEFT_COL = 3;
const LOTS_COL = 4;

/** Returns all <tr> elements inside the article table's <tbody>. */
const getTableRows = (wrapper) => wrapper.findAll('tbody tr');

/** Returns cell text values for a given row. */
const cellTexts = (row) => row.findAll('td').map((td) => td.text().trim());

// ─── Single batch ─────────────────────────────────────────────────────────────

describe('Single-batch product', () => {
    it('renders exactly one summary row', () => {
        const wrapper = mountShow([makeItem(1, 'Eau 1.5L', 24, 18)]);
        expect(getTableRows(wrapper)).toHaveLength(1);
    });

    it('shows the exact quantity_loaded unchanged', () => {
        const wrapper = mountShow([makeItem(1, 'Eau 1.5L', 24, 18)]);
        expect(cellTexts(getTableRows(wrapper)[0])[LOADED_COL]).toBe('24');
    });

    it('shows the exact quantity_left unchanged', () => {
        const wrapper = mountShow([makeItem(1, 'Eau 1.5L', 24, 18)]);
        expect(cellTexts(getTableRows(wrapper)[0])[LEFT_COL]).toBe('18');
    });

    it('shows "1 lot" in the lots column', () => {
        const wrapper = mountShow([makeItem(1, 'Eau 1.5L', 24, 18)]);
        expect(cellTexts(getTableRows(wrapper)[0])[LOTS_COL]).toBe('1 lot');
    });

    it('shows the product name in the name column', () => {
        const wrapper = mountShow([makeItem(1, 'Jus Citron 1L', 12, 8)]);
        expect(cellTexts(getTableRows(wrapper)[0])[NAME_COL]).toBe('Jus Citron 1L');
    });
});

// ─── Two batches of the same product ─────────────────────────────────────────

describe('Two batches of the same product', () => {
    it('collapses to a single summary row', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        expect(getTableRows(wrapper)).toHaveLength(1);
    });

    it('total_quantity_loaded is the SUM of both batches (23 + 17 = 40)', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        expect(cellTexts(getTableRows(wrapper)[0])[LOADED_COL]).toBe('40');
    });

    it('total_quantity_left is the SUM of both batches (15 + 12 = 27)', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        expect(cellTexts(getTableRows(wrapper)[0])[LEFT_COL]).toBe('27');
    });

    it('lots column shows "2 lots"', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        expect(cellTexts(getTableRows(wrapper)[0])[LOTS_COL]).toBe('2 lots');
    });

    it('individual batch loaded values (23, 17) do NOT appear in the summary row', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        const summaryLoaded = cellTexts(getTableRows(wrapper)[0])[LOADED_COL];
        expect(summaryLoaded).not.toBe('23');
        expect(summaryLoaded).not.toBe('17');
    });

    it('individual batch left values (15, 12) do NOT appear in the summary row', () => {
        const items = [makeItem(10, 'Café 250g', 23, 15), makeItem(10, 'Café 250g', 17, 12)];
        const wrapper = mountShow(items);
        const summaryLeft = cellTexts(getTableRows(wrapper)[0])[LEFT_COL];
        expect(summaryLeft).not.toBe('15');
        expect(summaryLeft).not.toBe('12');
    });
});

// ─── Three batches of the same product ───────────────────────────────────────

describe('Three batches of the same product', () => {
    // Batch totals: loaded = 11+13+16 = 40, left = 9+8+11 = 28
    const items = () => [
        makeItem(7, 'Jus Mangue', 11, 9),
        makeItem(7, 'Jus Mangue', 13, 8),
        makeItem(7, 'Jus Mangue', 16, 11),
    ];

    it('collapses to exactly one summary row', () => {
        expect(getTableRows(mountShow(items()))).toHaveLength(1);
    });

    it('total_quantity_loaded = 11 + 13 + 16 = 40', () => {
        expect(cellTexts(getTableRows(mountShow(items()))[0])[LOADED_COL]).toBe('40');
    });

    it('total_quantity_left = 9 + 8 + 11 = 28', () => {
        expect(cellTexts(getTableRows(mountShow(items()))[0])[LEFT_COL]).toBe('28');
    });

    it('lots column shows "3 lots"', () => {
        expect(cellTexts(getTableRows(mountShow(items()))[0])[LOTS_COL]).toBe('3 lots');
    });
});

// ─── Multiple different products ─────────────────────────────────────────────

describe('Multiple different products', () => {
    it('renders one summary row per product', () => {
        const items = [
            makeItem(1, 'Eau Plate', 24, 18),
            makeItem(2, 'Jus Ananas', 12, 9),
            makeItem(3, 'Lait 1L', 36, 30),
        ];
        expect(getTableRows(mountShow(items))).toHaveLength(3);
    });

    it('sorts products alphabetically and each row shows its own correct totals', () => {
        // Provide items in reverse alphabetical order — table should sort them
        const items = [
            makeItem(3, 'Zeste Orange', 12, 9),
            makeItem(1, 'Ananas Jus', 24, 18),
        ];
        const wrapper = mountShow(items);
        const rows = getTableRows(wrapper);

        // Ananas Jus comes first (alphabetically)
        expect(cellTexts(rows[0])[NAME_COL]).toBe('Ananas Jus');
        expect(cellTexts(rows[0])[LOADED_COL]).toBe('24');
        expect(cellTexts(rows[0])[LEFT_COL]).toBe('18');

        // Zeste Orange comes second
        expect(cellTexts(rows[1])[NAME_COL]).toBe('Zeste Orange');
        expect(cellTexts(rows[1])[LOADED_COL]).toBe('12');
        expect(cellTexts(rows[1])[LEFT_COL]).toBe('9');
    });

    it('multi-batch product and single-batch product each show correct independent totals', () => {
        const items = [
            makeItem(1, 'Biberon Lait', 30, 22),        // single batch
            makeItem(2, 'Yaourt Nature', 15, 10),        // batch 1 of 2
            makeItem(2, 'Yaourt Nature', 20, 14),        // batch 2 of 2
        ];
        const wrapper = mountShow(items);
        const rows = getTableRows(wrapper);

        expect(rows).toHaveLength(2); // Biberon Lait + Yaourt Nature

        // Biberon Lait — alphabetically first, single batch unchanged
        expect(cellTexts(rows[0])[NAME_COL]).toBe('Biberon Lait');
        expect(cellTexts(rows[0])[LOADED_COL]).toBe('30');
        expect(cellTexts(rows[0])[LEFT_COL]).toBe('22');

        // Yaourt Nature — summed across 2 batches: 15+20=35, 10+14=24
        expect(cellTexts(rows[1])[NAME_COL]).toBe('Yaourt Nature');
        expect(cellTexts(rows[1])[LOADED_COL]).toBe('35');
        expect(cellTexts(rows[1])[LEFT_COL]).toBe('24');
    });
});

// ─── Edge cases ───────────────────────────────────────────────────────────────

describe('Edge cases', () => {
    it('shows "0" (not blank) when quantity_left is 0 for a single batch', () => {
        const wrapper = mountShow([makeItem(1, 'Eau Plate', 30, 0)]);
        expect(cellTexts(getTableRows(wrapper)[0])[LEFT_COL]).toBe('0');
    });

    it('shows "0" when all batches have quantity_left = 0 (fully sold out)', () => {
        const items = [makeItem(1, 'Pack Eau', 24, 0), makeItem(1, 'Pack Eau', 12, 0)];
        const wrapper = mountShow(items);
        expect(cellTexts(getTableRows(wrapper)[0])[LOADED_COL]).toBe('36'); // 24 + 12
        expect(cellTexts(getTableRows(wrapper)[0])[LEFT_COL]).toBe('0');    // 0 + 0
    });

    it('shows "0" when a partially-sold batch leaves 0 remaining', () => {
        // First batch: 24 loaded, 0 left (sold out)
        // Second batch: 12 loaded, 5 left (partially sold)
        // Expected totals: 36 loaded, 5 left
        const items = [makeItem(1, 'Jus Citron', 24, 0), makeItem(1, 'Jus Citron', 12, 5)];
        const wrapper = mountShow(items);
        expect(cellTexts(getTableRows(wrapper)[0])[LOADED_COL]).toBe('36');
        expect(cellTexts(getTableRows(wrapper)[0])[LEFT_COL]).toBe('5');
    });

    it('shows "0" for loaded column when a single item has quantity_loaded = 0', () => {
        const wrapper = mountShow([makeItem(1, 'Retour', 0, 0)]);
        expect(cellTexts(getTableRows(wrapper)[0])[LOADED_COL]).toBe('0');
    });

    it('total_quantity_left can never exceed total_quantity_loaded (sanity check)', () => {
        const items = [
            makeItem(1, 'Eau 1L', 50, 30),
            makeItem(1, 'Eau 1L', 30, 20),
        ];
        const wrapper = mountShow(items);
        const row = getTableRows(wrapper)[0];
        const loaded = parseInt(cellTexts(row)[LOADED_COL], 10);
        const left = parseInt(cellTexts(row)[LEFT_COL], 10);
        expect(left).toBeLessThanOrEqual(loaded);
    });
});

// ─── Expanded rows — individual batch values ──────────────────────────────────

describe('Expanded rows show individual batch values', () => {
    it('expanding a group reveals one sub-row per batch', async () => {
        const items = [makeItem(1, 'Lait 1L', 30, 22), makeItem(1, 'Lait 1L', 20, 13)];
        const wrapper = mountShow(items);

        // Before expand: 1 summary row
        expect(getTableRows(wrapper)).toHaveLength(1);

        // Click the expand toggle (first button in first tbody row)
        const expandButton = wrapper.find('tbody tr button');
        expect(expandButton.exists()).toBe(true);
        await expandButton.trigger('click');
        await wrapper.vm.$nextTick();

        // After expand: 1 summary + 2 individual batch rows = 3
        expect(getTableRows(wrapper)).toHaveLength(3);
    });

    it('expanded sub-rows show the individual batch loaded quantity, not the sum', async () => {
        // Batch 1: 30 loaded   Batch 2: 20 loaded   →   sum shown in summary = 50
        const items = [makeItem(1, 'Eau Plate', 30, 22), makeItem(1, 'Eau Plate', 20, 13)];
        const wrapper = mountShow(items);

        await wrapper.find('tbody tr button').trigger('click');
        await wrapper.vm.$nextTick();

        const rows = getTableRows(wrapper);
        // rows[0] = summary row (shows 50)
        // rows[1] and rows[2] = individual batches
        const expandedLoadedValues = [
            cellTexts(rows[1])[LOADED_COL],
            cellTexts(rows[2])[LOADED_COL],
        ].sort();

        // Individual rows show 20 and 30, not 50
        expect(expandedLoadedValues).toEqual(['20', '30']);
    });

    it('expanded sub-rows show the individual batch left quantity, not the sum', async () => {
        // Batch 1: 22 left   Batch 2: 13 left   →   sum shown in summary = 35
        const items = [makeItem(1, 'Eau Plate', 30, 22), makeItem(1, 'Eau Plate', 20, 13)];
        const wrapper = mountShow(items);

        await wrapper.find('tbody tr button').trigger('click');
        await wrapper.vm.$nextTick();

        const rows = getTableRows(wrapper);
        const expandedLeftValues = [
            cellTexts(rows[1])[LEFT_COL],
            cellTexts(rows[2])[LEFT_COL],
        ].sort();

        // Individual rows show 13 and 22, not 35
        expect(expandedLeftValues).toEqual(['13', '22']);
    });

    it('summary row totals remain correct while the group is expanded', async () => {
        const items = [makeItem(1, 'Eau Plate', 30, 22), makeItem(1, 'Eau Plate', 20, 13)];
        const wrapper = mountShow(items);

        await wrapper.find('tbody tr button').trigger('click');
        await wrapper.vm.$nextTick();

        // Summary row (rows[0]) must still show the summed totals
        const summaryRow = getTableRows(wrapper)[0];
        expect(cellTexts(summaryRow)[LOADED_COL]).toBe('50'); // 30 + 20
        expect(cellTexts(summaryRow)[LEFT_COL]).toBe('35');   // 22 + 13
    });

    it('collapsing a group hides the sub-rows again', async () => {
        const items = [makeItem(1, 'Eau Plate', 30, 22), makeItem(1, 'Eau Plate', 20, 13)];
        const wrapper = mountShow(items);

        const expandButton = wrapper.find('tbody tr button');
        await expandButton.trigger('click');  // expand
        await wrapper.vm.$nextTick();
        expect(getTableRows(wrapper)).toHaveLength(3);

        await expandButton.trigger('click');  // collapse
        await wrapper.vm.$nextTick();
        expect(getTableRows(wrapper)).toHaveLength(1);
    });
});

// ─── Totals consistency: computed vs rendered ────────────────────────────────

describe('Computed totals match rendered cell values', () => {
    it('every group\'s rendered loaded value matches groupedCarLoadItems computed', () => {
        const items = [
            makeItem(1, 'Alfa', 11, 8),
            makeItem(2, 'Beta', 15, 10),
            makeItem(2, 'Beta', 20, 14),
            makeItem(3, 'Gamma', 9, 7),
        ];
        const wrapper = mountShow(items);
        const computedGroups = wrapper.vm.groupedCarLoadItems;
        const rows = getTableRows(wrapper);

        computedGroups.forEach((group, index) => {
            const renderedLoaded = cellTexts(rows[index])[LOADED_COL];
            const renderedLeft = cellTexts(rows[index])[LEFT_COL];
            expect(parseInt(renderedLoaded, 10)).toBe(group.total_quantity_loaded);
            expect(parseInt(renderedLeft, 10)).toBe(group.total_quantity_left);
        });
    });
});
