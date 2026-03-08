import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import Dashboard from '@/Pages/Dashboard.vue';

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

const vuetify = createVuetify({ components, directives });

/**
 * Returns a complete stats object with all values set to 0 by default,
 * accepting per-field overrides for precision in each test.
 */
const makeStats = (overrides = {}) => ({
    total_customers: 0,
    total_prospects: 0,
    total_confirmed_customers: 0,
    sales_invoices_count: 0,
    fully_paid_sales_invoices_count: 0,
    partially_paid_sales_invoices_count: 0,
    unpaid_sales_invoices_count: 0,
    total_sales: 0,
    total_estimated_profit: 0,
    total_realized_profit: 0,
    total_payments_received: 0,
    total_expenses: 0,
    ...overrides,
});

/**
 * Mounts Dashboard with sensible defaults, allowing per-prop overrides.
 * AuthenticatedLayout is stubbed so both named slots are rendered.
 */
const mountDashboard = (propOverrides = {}) =>
    mount(Dashboard, {
        props: {
            selectedDate: '2026-03-08',
            dailyStats: makeStats(),
            weeklyStats: makeStats(),
            monthlyStats: makeStats(),
            overallStats: makeStats(),
            ...propOverrides,
        },
        global: {
            plugins: [vuetify],
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
            },
        },
    });

/**
 * Replicates the formatCurrency function used by the component
 * so tests can compute expected strings without hardcoding locale output.
 */
const formatCurrency = (amount) =>
    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF' }).format(amount);

/**
 * Returns the ordered list of all period metric values rendered in the
 * period stats panel. Order matches the template:
 *   0  total_customers
 *   1  total_confirmed_customers
 *   2  total_prospects
 *   3  sales_invoices_count
 *   4  fully_paid_sales_invoices_count
 *   5  partially_paid_sales_invoices_count
 *   6  unpaid_sales_invoices_count
 *   7  total_sales
 *   8  total_payments_received
 *   9  total_estimated_profit
 *   10 total_realized_profit
 *   11 total_expenses
 */
const getPeriodMetricValues = (wrapper) =>
    wrapper.findAll('.period-metric').map((metric) =>
        metric.find('.period-metric-value').text().trim(),
    );

// ---------------------------------------------------------------------------
// Overall Stats — Vue d'ensemble
// ---------------------------------------------------------------------------

describe('Dashboard — Overall Stats (Vue d\'ensemble)', () => {

    describe('Clients KPI card', () => {
        it('displays total_customers as the main figure', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_customers: 142 }) });
            expect(wrapper.find('.kpi-card--blue .kpi-value').text()).toBe('142');
        });

        it('displays total_confirmed_customers in the green badge', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_confirmed_customers: 98 }) });
            expect(wrapper.find('.kpi-card--blue .kpi-badge--green').text()).toContain('98');
        });

        it('displays total_prospects in the amber badge', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_prospects: 44 }) });
            expect(wrapper.find('.kpi-card--blue .kpi-badge--amber').text()).toContain('44');
        });

        it('shows zero when all customer counts are zero', () => {
            const wrapper = mountDashboard({ overallStats: makeStats() });
            expect(wrapper.find('.kpi-card--blue .kpi-value').text()).toBe('0');
        });
    });

    describe('Invoices KPI card', () => {
        it('displays sales_invoices_count as the main figure', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ sales_invoices_count: 37 }) });
            expect(wrapper.find('.kpi-card--indigo .kpi-value').text()).toBe('37');
        });

        it('displays fully_paid count in the first badge', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ fully_paid_sales_invoices_count: 20 }) });
            const badges = wrapper.find('.kpi-card--indigo').findAll('.kpi-badge');
            expect(badges[0].text()).toContain('20');
        });

        it('displays partially_paid count in the second badge', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ partially_paid_sales_invoices_count: 12 }) });
            const badges = wrapper.find('.kpi-card--indigo').findAll('.kpi-badge');
            expect(badges[1].text()).toContain('12');
        });

        it('displays unpaid count in the third badge', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ unpaid_sales_invoices_count: 5 }) });
            const badges = wrapper.find('.kpi-card--indigo').findAll('.kpi-badge');
            expect(badges[2].text()).toContain('5');
        });
    });

    describe('Financial KPI cards', () => {
        it('displays formatted total_sales in the revenue card', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_sales: 5_000_000 }) });
            expect(wrapper.find('.kpi-card--emerald .kpi-value').text()).toContain(formatCurrency(5_000_000));
        });

        it('displays formatted total_estimated_profit in the green card', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_estimated_profit: 750_000 }) });
            expect(wrapper.find('.kpi-card--green .kpi-value').text()).toContain(formatCurrency(750_000));
        });

        it('displays formatted total_realized_profit in the teal card', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_realized_profit: 600_000 }) });
            expect(wrapper.find('.kpi-card--teal .kpi-value').text()).toContain(formatCurrency(600_000));
        });

        it('displays formatted total_payments_received in the amber card', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_payments_received: 4_200_000 }) });
            expect(wrapper.find('.kpi-card--amber .kpi-value').text()).toContain(formatCurrency(4_200_000));
        });

        it('displays formatted total_expenses in the red card', () => {
            const wrapper = mountDashboard({ overallStats: makeStats({ total_expenses: 320_000 }) });
            expect(wrapper.find('.kpi-card--red .kpi-value').text()).toContain(formatCurrency(320_000));
        });
    });

    it('renders all 7 KPI cards in the overall section', () => {
        const wrapper = mountDashboard();
        const kpiCards = [
            '.kpi-card--blue',
            '.kpi-card--indigo',
            '.kpi-card--emerald',
            '.kpi-card--green',
            '.kpi-card--teal',
            '.kpi-card--amber',
            '.kpi-card--red',
        ];
        kpiCards.forEach((selector) => {
            expect(wrapper.find(selector).exists(), `Expected card ${selector} to exist`).toBe(true);
        });
    });
});

// ---------------------------------------------------------------------------
// Period Stats — tab switching and value rendering
// ---------------------------------------------------------------------------

describe('Dashboard — Period Stats', () => {

    describe('Tab display', () => {
        it('renders three period tab buttons', () => {
            const wrapper = mountDashboard();
            expect(wrapper.findAll('.period-tab')).toHaveLength(3);
        });

        it('marks the daily tab as active by default', () => {
            const wrapper = mountDashboard();
            expect(wrapper.findAll('.period-tab')[0].classes()).toContain('period-tab--active');
        });

        it('marks the weekly tab as active after clicking it', async () => {
            const wrapper = mountDashboard();
            await wrapper.findAll('.period-tab')[1].trigger('click');
            expect(wrapper.findAll('.period-tab')[1].classes()).toContain('period-tab--active');
            expect(wrapper.findAll('.period-tab')[0].classes()).not.toContain('period-tab--active');
        });

        it('marks the monthly tab as active after clicking it', async () => {
            const wrapper = mountDashboard();
            await wrapper.findAll('.period-tab')[2].trigger('click');
            expect(wrapper.findAll('.period-tab')[2].classes()).toContain('period-tab--active');
            expect(wrapper.findAll('.period-tab')[0].classes()).not.toContain('period-tab--active');
        });
    });

    describe('Daily stats (default tab)', () => {
        it('renders all 12 dailyStats fields in order', () => {
            const wrapper = mountDashboard({
                dailyStats: makeStats({
                    total_customers: 10,
                    total_confirmed_customers: 6,
                    total_prospects: 4,
                    sales_invoices_count: 15,
                    fully_paid_sales_invoices_count: 8,
                    partially_paid_sales_invoices_count: 5,
                    unpaid_sales_invoices_count: 2,
                    total_sales: 1_200_000,
                    total_payments_received: 800_000,
                    total_estimated_profit: 240_000,
                    total_realized_profit: 160_000,
                    total_expenses: 45_000,
                }),
            });

            const values = getPeriodMetricValues(wrapper);
            expect(values[0]).toBe('10');
            expect(values[1]).toBe('6');
            expect(values[2]).toBe('4');
            expect(values[3]).toBe('15');
            expect(values[4]).toBe('8');
            expect(values[5]).toBe('5');
            expect(values[6]).toBe('2');
            expect(values[7]).toContain(formatCurrency(1_200_000));
            expect(values[8]).toContain(formatCurrency(800_000));
            expect(values[9]).toContain(formatCurrency(240_000));
            expect(values[10]).toContain(formatCurrency(160_000));
            expect(values[11]).toContain(formatCurrency(45_000));
        });
    });

    describe('Weekly stats', () => {
        it('shows weeklyStats values after clicking the weekly tab', async () => {
            const wrapper = mountDashboard({
                dailyStats: makeStats({ total_customers: 3 }),
                weeklyStats: makeStats({ total_customers: 25 }),
            });

            await wrapper.findAll('.period-tab')[1].trigger('click');

            expect(getPeriodMetricValues(wrapper)[0]).toBe('25');
        });

        it('does not show dailyStats values when weekly tab is active', async () => {
            const wrapper = mountDashboard({
                dailyStats: makeStats({ total_customers: 999 }),
                weeklyStats: makeStats({ total_customers: 1 }),
            });

            await wrapper.findAll('.period-tab')[1].trigger('click');

            expect(getPeriodMetricValues(wrapper)[0]).toBe('1');
            expect(getPeriodMetricValues(wrapper)[0]).not.toBe('999');
        });

        it('renders all 12 weeklyStats fields in order', async () => {
            const wrapper = mountDashboard({
                weeklyStats: makeStats({
                    total_customers: 80,
                    total_confirmed_customers: 55,
                    total_prospects: 25,
                    sales_invoices_count: 60,
                    fully_paid_sales_invoices_count: 40,
                    partially_paid_sales_invoices_count: 15,
                    unpaid_sales_invoices_count: 5,
                    total_sales: 8_000_000,
                    total_payments_received: 6_500_000,
                    total_estimated_profit: 1_600_000,
                    total_realized_profit: 1_300_000,
                    total_expenses: 200_000,
                }),
            });

            await wrapper.findAll('.period-tab')[1].trigger('click');

            const values = getPeriodMetricValues(wrapper);
            expect(values[0]).toBe('80');
            expect(values[1]).toBe('55');
            expect(values[2]).toBe('25');
            expect(values[3]).toBe('60');
            expect(values[4]).toBe('40');
            expect(values[5]).toBe('15');
            expect(values[6]).toBe('5');
            expect(values[7]).toContain(formatCurrency(8_000_000));
            expect(values[8]).toContain(formatCurrency(6_500_000));
            expect(values[9]).toContain(formatCurrency(1_600_000));
            expect(values[10]).toContain(formatCurrency(1_300_000));
            expect(values[11]).toContain(formatCurrency(200_000));
        });
    });

    describe('Monthly stats', () => {
        it('shows monthlyStats values after clicking the monthly tab', async () => {
            const wrapper = mountDashboard({
                dailyStats: makeStats({ total_customers: 3 }),
                monthlyStats: makeStats({ total_customers: 200 }),
            });

            await wrapper.findAll('.period-tab')[2].trigger('click');

            expect(getPeriodMetricValues(wrapper)[0]).toBe('200');
        });

        it('does not show weeklyStats values when monthly tab is active', async () => {
            const wrapper = mountDashboard({
                weeklyStats: makeStats({ total_customers: 50 }),
                monthlyStats: makeStats({ total_customers: 200 }),
            });

            await wrapper.findAll('.period-tab')[1].trigger('click');
            await wrapper.findAll('.period-tab')[2].trigger('click');

            expect(getPeriodMetricValues(wrapper)[0]).toBe('200');
        });

        it('renders all 12 monthlyStats fields in order', async () => {
            const wrapper = mountDashboard({
                monthlyStats: makeStats({
                    total_customers: 350,
                    total_confirmed_customers: 280,
                    total_prospects: 70,
                    sales_invoices_count: 200,
                    fully_paid_sales_invoices_count: 150,
                    partially_paid_sales_invoices_count: 40,
                    unpaid_sales_invoices_count: 10,
                    total_sales: 35_000_000,
                    total_payments_received: 28_000_000,
                    total_estimated_profit: 7_000_000,
                    total_realized_profit: 5_600_000,
                    total_expenses: 850_000,
                }),
            });

            await wrapper.findAll('.period-tab')[2].trigger('click');

            const values = getPeriodMetricValues(wrapper);
            expect(values[0]).toBe('350');
            expect(values[1]).toBe('280');
            expect(values[2]).toBe('70');
            expect(values[3]).toBe('200');
            expect(values[4]).toBe('150');
            expect(values[5]).toBe('40');
            expect(values[6]).toBe('10');
            expect(values[7]).toContain(formatCurrency(35_000_000));
            expect(values[8]).toContain(formatCurrency(28_000_000));
            expect(values[9]).toContain(formatCurrency(7_000_000));
            expect(values[10]).toContain(formatCurrency(5_600_000));
            expect(values[11]).toContain(formatCurrency(850_000));
        });
    });

    it('overallStats values are unaffected by period tab switching', async () => {
        const wrapper = mountDashboard({
            overallStats: makeStats({ total_customers: 555 }),
            dailyStats: makeStats({ total_customers: 1 }),
            weeklyStats: makeStats({ total_customers: 2 }),
            monthlyStats: makeStats({ total_customers: 3 }),
        });

        await wrapper.findAll('.period-tab')[1].trigger('click');
        await wrapper.findAll('.period-tab')[2].trigger('click');
        await wrapper.findAll('.period-tab')[0].trigger('click');

        expect(wrapper.find('.kpi-card--blue .kpi-value').text()).toBe('555');
    });

    it('renders 12 period metrics in total', () => {
        const wrapper = mountDashboard();
        expect(wrapper.findAll('.period-metric')).toHaveLength(12);
    });
});
