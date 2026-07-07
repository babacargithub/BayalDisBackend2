<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    commerciaux: Array,
    selectedCommercialId: Number,
    performanceData: Object,
    startDate: String,
    endDate: String,
});

// ── Filters ───────────────────────────────────────────────────────────────────

const localStartDate = ref(props.startDate);
const localEndDate = ref(props.endDate);
const localCommercialId = ref(props.selectedCommercialId ?? null);
const showCustomPicker = ref(false);

const applyFilters = () => {
    const params = {
        start_date: localStartDate.value,
        end_date: localEndDate.value,
    };
    if (localCommercialId.value) {
        params.commercial_id = localCommercialId.value;
    }
    router.get(route('performances.index'), params, { preserveState: false });
};

const setQuickPeriod = (period) => {
    const today = new Date();
    let start, end;

    if (period === 'this_month') {
        start = new Date(today.getFullYear(), today.getMonth(), 1);
        end = today;
    } else if (period === 'last_month') {
        start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        end = new Date(today.getFullYear(), today.getMonth(), 0);
    } else if (period === 'this_quarter') {
        const quarter = Math.floor(today.getMonth() / 3);
        start = new Date(today.getFullYear(), quarter * 3, 1);
        end = today;
    } else if (period === 'this_year') {
        start = new Date(today.getFullYear(), 0, 1);
        end = today;
    }

    localStartDate.value = start.toISOString().slice(0, 10);
    localEndDate.value = end.toISOString().slice(0, 10);
    applyFilters();
};

// ── Formatters ────────────────────────────────────────────────────────────────

const formatCurrency = (amount) =>
    new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount ?? 0);

const formatNumber = (value) =>
    new Intl.NumberFormat('fr-FR').format(value ?? 0);

const formatDate = (dateString) =>
    new Intl.DateTimeFormat('fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateString));

const formatRate = (value) => {
    if (value == null) { return '—'; }
    return `${Math.round(value * 10) / 10}%`;
};

// ── Color helpers ─────────────────────────────────────────────────────────────

const rateColor = (rate, lowerIsBetter = false) => {
    if (rate == null || rate === 0) { return 'grey'; }
    const isGood = lowerIsBetter ? rate < 15 : rate >= 75;
    const isMedium = lowerIsBetter ? rate < 30 : rate >= 40;
    if (isGood) { return 'success'; }
    if (isMedium) { return 'warning'; }
    return 'error';
};

const pushScoreColor = (score) => {
    if (!score || score === 0) { return 'grey'; }
    if (score >= 60) { return 'success'; }
    if (score >= 30) { return 'warning'; }
    return 'error';
};

const pushScoreLabel = (score) => {
    if (!score || score === 0) { return 'Aucune donnée'; }
    if (score >= 60) { return 'Excellent'; }
    if (score >= 30) { return 'Moyen'; }
    return 'Faible';
};

// ── Derived metrics ───────────────────────────────────────────────────────────

const collectionRate = computed(() => {
    const s = props.performanceData?.sales;
    if (!s || !s.total_revenue) { return null; }
    return Math.round((s.total_payments / s.total_revenue) * 100);
});

const grossMarginRate = computed(() => {
    const s = props.performanceData?.sales;
    if (!s || !s.total_revenue) { return null; }
    return Math.round((s.profit_generated / s.total_revenue) * 100);
});

const rateClass = (rate, thresholdGood, thresholdMedium, lowerIsBetter = false) => {
    if (rate == null) { return 'text-grey'; }
    const isGood = lowerIsBetter ? rate <= thresholdGood : rate >= thresholdGood;
    const isMedium = lowerIsBetter ? rate <= thresholdMedium : rate >= thresholdMedium;
    if (isGood) { return 'text-success'; }
    if (isMedium) { return 'text-warning'; }
    return 'text-error';
};

// ── Display format & verdict / notes logic ────────────────────────────────────

const displayFormat = ref('widgets');

const getVerdict = (value, [t1, t2, t3], lowerIsBetter = false) => {
    if (value == null) { return { label: '—', color: 'grey', score: null }; }
    const isExcellent = lowerIsBetter ? value <= t1 : value >= t1;
    const isBien = lowerIsBetter ? value <= t2 : value >= t2;
    const isMoyen = lowerIsBetter ? value <= t3 : value >= t3;
    if (isExcellent) { return { label: 'Excellent', color: 'success', score: 10 }; }
    if (isBien) { return { label: 'Bien', color: 'info', score: 7 }; }
    if (isMoyen) { return { label: 'Moyen', color: 'warning', score: 5 }; }
    return { label: 'Insuffisant', color: 'error', score: 2 };
};

const verdictSections = computed(() => {
    if (!props.performanceData) { return []; }
    const d = props.performanceData;
    const hasPaidInvoices = d.collection.fully_paid_invoices_count > 0;

    return [
        {
            section: 'Ventes & Revenus',
            icon: 'mdi-cash-register',
            color: 'indigo',
            metrics: [
                {
                    label: "Taux d'Encaissement",
                    formatted: collectionRate.value != null ? collectionRate.value + '%' : '—',
                    verdict: getVerdict(collectionRate.value, [80, 60, 40]),
                },
                {
                    label: 'Marge Brute / CA',
                    formatted: grossMarginRate.value != null ? grossMarginRate.value + '%' : '—',
                    verdict: getVerdict(grossMarginRate.value, [25, 15, 8]),
                },
            ],
        },
        {
            section: 'Couverture Client',
            icon: 'mdi-map-marker-check',
            color: 'teal',
            metrics: [
                {
                    label: 'Taux de Frappe (Tournées)',
                    formatted: formatRate(d.coverage.visit_strike_rate),
                    verdict: getVerdict(d.coverage.visit_strike_rate, [75, 60, 40]),
                },
                {
                    label: 'Taux de Fidélisation',
                    formatted: formatRate(d.coverage.customer_retention_rate),
                    verdict: getVerdict(d.coverage.customer_retention_rate, [75, 60, 40]),
                },
                {
                    label: "Taux d'Acquisition",
                    formatted: formatRate(d.coverage.acquisition_strike_rate),
                    verdict: getVerdict(d.coverage.acquisition_strike_rate, [75, 50, 30]),
                },
                {
                    label: 'Taux de Churn',
                    formatted: formatRate(d.coverage.churning_rate),
                    verdict: getVerdict(d.coverage.churning_rate, [10, 20, 35], true),
                },
            ],
        },
        {
            section: 'Recouvrement & Créances',
            icon: 'mdi-cash-clock',
            color: 'red',
            metrics: [
                {
                    label: "Indice d'Efficacité de Recouvrement (IER)",
                    formatted: formatRate(d.collection.collection_effectiveness_index),
                    verdict: getVerdict(d.collection.collection_effectiveness_index, [80, 60, 40]),
                },
                {
                    label: 'Paiements dans les Délais',
                    formatted: formatRate(d.collection.on_time_payment_rate),
                    verdict: getVerdict(d.collection.on_time_payment_rate, [75, 50, 30]),
                },
                {
                    label: 'Délai Moyen de Paiement',
                    formatted: hasPaidInvoices ? Math.round(d.collection.average_days_to_payment) + ' j' : '—',
                    verdict: hasPaidInvoices
                        ? getVerdict(d.collection.average_days_to_payment, [7, 14, 30], true)
                        : { label: '—', color: 'grey', score: null },
                },
                {
                    label: 'Retard Moyen de Paiement',
                    formatted: hasPaidInvoices ? Math.round(d.collection.average_days_delinquent) + ' j' : '—',
                    verdict: hasPaidInvoices
                        ? getVerdict(d.collection.average_days_delinquent, [0, 5, 15], true)
                        : { label: '—', color: 'grey', score: null },
                },
            ],
        },
        {
            section: 'Mix Produit',
            icon: 'mdi-chart-bubble',
            color: 'purple',
            metrics: [
                {
                    label: 'Score Push Produit',
                    formatted: d.product_mix.push_score + ' pts',
                    verdict: getVerdict(d.product_mix.push_score, [60, 40, 20]),
                },
                {
                    label: 'Catégories par Facture',
                    formatted: d.product_mix.average_product_categories_per_invoice > 0
                        ? d.product_mix.average_product_categories_per_invoice.toFixed(1)
                        : '—',
                    verdict: getVerdict(d.product_mix.average_product_categories_per_invoice, [3, 2, 1.5]),
                },
                {
                    label: 'Catégories Vendues',
                    formatted: d.product_mix.total_distinct_categories_sold_count + ' catégorie(s)',
                    verdict: getVerdict(d.product_mix.total_distinct_categories_sold_count, [5, 3, 2]),
                },
            ],
        },
    ];
});

const sectionAvg = (sectionGroup) => {
    const scoredMetrics = sectionGroup.metrics.filter((m) => m.verdict.score != null);
    if (scoredMetrics.length === 0) { return null; }
    return Math.round((scoredMetrics.reduce((sum, m) => sum + m.verdict.score, 0) / scoredMetrics.length) * 10) / 10;
};

const sectionAvgColor = (avg) => {
    if (avg == null) { return 'grey'; }
    if (avg >= 8) { return 'success'; }
    if (avg >= 6) { return 'info'; }
    if (avg >= 4) { return 'warning'; }
    return 'error';
};

const globalScore = computed(() => {
    if (!verdictSections.value.length) { return null; }
    const allScored = verdictSections.value.flatMap((s) => s.metrics).filter((m) => m.verdict.score != null);
    if (!allScored.length) { return null; }
    return Math.round((allScored.reduce((sum, m) => sum + m.verdict.score, 0) / allScored.length) * 10) / 10;
});

const globalScoreColor = computed(() => sectionAvgColor(globalScore.value));

const globalScoreLabel = computed(() => {
    const s = globalScore.value;
    if (s == null) { return '—'; }
    if (s >= 8) { return 'Excellent'; }
    if (s >= 6) { return 'Bien'; }
    if (s >= 4) { return 'Moyen'; }
    return 'Insuffisant';
});

// ── Narrative sections (verdict prose view) ───────────────────────────────────

const narrativeSections = computed(() => {
    if (!props.performanceData) { return []; }
    const d = props.performanceData;
    const name = props.performanceData.commercial_name;
    const hasPaidInvoices = d.collection.fully_paid_invoices_count > 0;
    const sections = verdictSections.value;

    const pick = (verdictLabel, templates) =>
        templates[verdictLabel] ?? templates['Insuffisant'];

    return [
        {
            section: sections[0].section,
            icon: sections[0].icon,
            color: sections[0].color,
            avg: sectionAvg(sections[0]),
            intro: `Sur la période analysée, ${name} a réalisé un chiffre d'affaires de ${formatCurrency(d.sales.total_revenue)} sur ${formatNumber(d.sales.total_invoices_count)} facture(s), pour un panier moyen de ${d.sales.average_basket_size > 0 ? formatCurrency(d.sales.average_basket_size) : '—'} par commande. Au total, ${formatNumber(d.sales.unique_customers_served_count)} client(s) ont été servis.`,
            sentences: [
                collectionRate.value != null ? pick(getVerdict(collectionRate.value, [80, 60, 40]).label, {
                    'Excellent': `Son taux d'encaissement de ${collectionRate.value}% est remarquable — la quasi-totalité des ventes est rapidement convertie en cash.`,
                    'Bien':      `Son taux d'encaissement de ${collectionRate.value}% est satisfaisant et témoigne d'un bon suivi des règlements clients.`,
                    'Moyen':     `Son taux d'encaissement de ${collectionRate.value}% laisse une marge de progression — un meilleur suivi des paiements permettrait d'améliorer ce ratio.`,
                    'Insuffisant': `Son taux d'encaissement de ${collectionRate.value}% est insuffisant — un volume important de ventes n'est pas encore converti en cash. Un suivi renforcé des créances s'impose.`,
                }) : null,
                grossMarginRate.value != null ? pick(getVerdict(grossMarginRate.value, [25, 15, 8]).label, {
                    'Excellent': `La marge brute de ${grossMarginRate.value}% du CA témoigne d'une excellente rentabilité des ventes.`,
                    'Bien':      `La marge brute de ${grossMarginRate.value}% du CA reflète une bonne rentabilité commerciale.`,
                    'Moyen':     `La marge brute de ${grossMarginRate.value}% du CA est acceptable mais pourrait être améliorée en favorisant les produits à plus forte valeur ajoutée.`,
                    'Insuffisant': `La marge brute de ${grossMarginRate.value}% du CA est faible — il convient de revoir le mix produits et d'éviter les remises excessives.`,
                }) : null,
            ].filter(Boolean),
        },
        {
            section: sections[1].section,
            icon: sections[1].icon,
            color: sections[1].color,
            avg: sectionAvg(sections[1]),
            intro: `${name} a visité ${formatNumber(d.coverage.visited_customers_count)} client(s), dont ${formatNumber(d.coverage.active_customers_count)} ont passé commande. ${formatNumber(d.coverage.new_confirmed_customers_count)} nouveau(x) client(s) ont été confirmés sur la période, tandis que ${formatNumber(d.coverage.churning_customers_count)} client(s) précédemment actifs n'ont pas commandé.`,
            sentences: [
                pick(getVerdict(d.coverage.visit_strike_rate, [75, 60, 40]).label, {
                    'Excellent': `Le taux de frappe de ${formatRate(d.coverage.visit_strike_rate)} est excellent — l'immense majorité des clients visités passent commande.`,
                    'Bien':      `Le taux de frappe de ${formatRate(d.coverage.visit_strike_rate)} est bon : les visites se transforment régulièrement en ventes.`,
                    'Moyen':     `Le taux de frappe de ${formatRate(d.coverage.visit_strike_rate)} est moyen — certaines visites ne débouchent pas sur une commande, ce qui mérite attention.`,
                    'Insuffisant': `Le taux de frappe de ${formatRate(d.coverage.visit_strike_rate)} est insuffisant — beaucoup de visites restent sans commande. L'argumentation commerciale doit être renforcée.`,
                }),
                pick(getVerdict(d.coverage.customer_retention_rate, [75, 60, 40]).label, {
                    'Excellent': `Le taux de fidélisation de ${formatRate(d.coverage.customer_retention_rate)} est excellent — la clientèle est très fidèle et revient régulièrement.`,
                    'Bien':      `Le taux de fidélisation de ${formatRate(d.coverage.customer_retention_rate)} est bon et montre que le portefeuille client est bien entretenu.`,
                    'Moyen':     `Le taux de fidélisation de ${formatRate(d.coverage.customer_retention_rate)} est moyen — une attention accrue aux clients existants permettrait d'améliorer ce résultat.`,
                    'Insuffisant': `Le taux de fidélisation de ${formatRate(d.coverage.customer_retention_rate)} est préoccupant — trop de clients ne reviennent pas. Une revue du suivi client s'impose.`,
                }),
                pick(getVerdict(d.coverage.churning_rate, [10, 20, 35], true).label, {
                    'Excellent': `Le taux de churn de ${formatRate(d.coverage.churning_rate)} est très faible — la base client est parfaitement maintenue.`,
                    'Bien':      `Le taux de churn de ${formatRate(d.coverage.churning_rate)} reste contenu dans des limites acceptables.`,
                    'Moyen':     `Le taux de churn de ${formatRate(d.coverage.churning_rate)} signale que des clients quittent progressivement le portefeuille — un travail de relance ciblé est conseillé.`,
                    'Insuffisant': `Le taux de churn de ${formatRate(d.coverage.churning_rate)} est élevé et indique une perte significative de clientèle — une action immédiate de rétention est nécessaire.`,
                }),
                pick(getVerdict(d.coverage.acquisition_strike_rate, [75, 50, 30]).label, {
                    'Excellent': `Son taux d'acquisition de ${formatRate(d.coverage.acquisition_strike_rate)} est excellent — la majorité des nouveaux contacts deviennent immédiatement des clients actifs.`,
                    'Bien':      `Son taux d'acquisition de ${formatRate(d.coverage.acquisition_strike_rate)} est satisfaisant et montre une bonne capacité à convertir les prospects.`,
                    'Moyen':     `Son taux d'acquisition de ${formatRate(d.coverage.acquisition_strike_rate)} est moyen — davantage de prospects pourraient être convertis avec un meilleur argumentaire.`,
                    'Insuffisant': `Son taux d'acquisition de ${formatRate(d.coverage.acquisition_strike_rate)} est insuffisant — beaucoup de nouveaux contacts restent à l'état de prospects sans passer commande.`,
                }),
            ],
        },
        {
            section: sections[2].section,
            icon: sections[2].icon,
            color: sections[2].color,
            avg: sectionAvg(sections[2]),
            intro: `L'encours total des créances impayées s'élève à ${formatCurrency(d.collection.total_outstanding_amount)}. Sur ${formatNumber(d.collection.total_invoices_count)} facture(s) émise(s), ${formatNumber(d.collection.fully_paid_invoices_count)} ont été intégralement réglées, dont ${formatNumber(d.collection.on_time_invoices_count)} dans les délais prévus.`,
            sentences: [
                pick(getVerdict(d.collection.collection_effectiveness_index, [80, 60, 40]).label, {
                    'Excellent': `L'indice d'efficacité de recouvrement (IER) de ${formatRate(d.collection.collection_effectiveness_index)} est excellent — les créances exigibles sont quasi-intégralement récupérées.`,
                    'Bien':      `L'IER de ${formatRate(d.collection.collection_effectiveness_index)} est satisfaisant et reflète une bonne discipline de recouvrement.`,
                    'Moyen':     `L'IER de ${formatRate(d.collection.collection_effectiveness_index)} est moyen — une partie des créances exigibles reste non recouvrée.`,
                    'Insuffisant': `L'IER de ${formatRate(d.collection.collection_effectiveness_index)} est insuffisant — une grande part des créances exigibles n'est pas recouvrée, exposant l'entreprise à un risque de liquidité.`,
                }),
                pick(getVerdict(d.collection.on_time_payment_rate, [75, 50, 30]).label, {
                    'Excellent': `Le taux de paiement dans les délais de ${formatRate(d.collection.on_time_payment_rate)} montre que les clients paient de manière très ponctuelle.`,
                    'Bien':      `Le taux de paiement dans les délais de ${formatRate(d.collection.on_time_payment_rate)} est bon — la majorité des clients respectent leurs échéances.`,
                    'Moyen':     `Le taux de paiement dans les délais de ${formatRate(d.collection.on_time_payment_rate)} est moyen — des retards récurrents méritent un suivi proactif.`,
                    'Insuffisant': `Le taux de paiement dans les délais de ${formatRate(d.collection.on_time_payment_rate)} est insuffisant — les retards sont fréquents et impactent la trésorerie.`,
                }),
                hasPaidInvoices ? pick(getVerdict(d.collection.average_days_to_payment, [7, 14, 30], true).label, {
                    'Excellent': `Le délai moyen de paiement de ${Math.round(d.collection.average_days_to_payment)} jour(s) est très court — les clients règlent rapidement leurs factures.`,
                    'Bien':      `Le délai moyen de paiement de ${Math.round(d.collection.average_days_to_payment)} jours est raisonnable.`,
                    'Moyen':     `Le délai moyen de paiement de ${Math.round(d.collection.average_days_to_payment)} jours est acceptable mais pourrait être raccourci avec un suivi plus actif.`,
                    'Insuffisant': `Le délai moyen de paiement de ${Math.round(d.collection.average_days_to_payment)} jours est trop long — les clients tardent à régler leurs factures.`,
                }) : null,
                hasPaidInvoices ? pick(getVerdict(d.collection.average_days_delinquent, [0, 5, 15], true).label, {
                    'Excellent': `Les clients paient sans retard significatif — la ponctualité de paiement est irréprochable.`,
                    'Bien':      `Le retard moyen de ${Math.round(d.collection.average_days_delinquent)} jour(s) est faible et reste dans des limites acceptables.`,
                    'Moyen':     `Le retard moyen de ${Math.round(d.collection.average_days_delinquent)} jours est notable — un suivi proactif des échéances permettrait de réduire ces délais.`,
                    'Insuffisant': `Le retard moyen de ${Math.round(d.collection.average_days_delinquent)} jours est problématique — des actions de relance doivent être mises en place rapidement.`,
                }) : null,
            ].filter(Boolean),
        },
        {
            section: sections[3].section,
            icon: sections[3].icon,
            color: sections[3].color,
            avg: sectionAvg(sections[3]),
            intro: `Sur la période, ${name} a vendu ${formatNumber(d.product_mix.total_distinct_products_sold_count)} référence(s) produit distincte(s) couvrant ${formatNumber(d.product_mix.total_distinct_categories_sold_count)} catégorie(s), avec une moyenne de ${d.product_mix.average_product_categories_per_invoice > 0 ? d.product_mix.average_product_categories_per_invoice.toFixed(1) : '—'} catégorie(s) par facture.`,
            sentences: [
                pick(getVerdict(d.product_mix.push_score, [60, 40, 20]).label, {
                    'Excellent': `Son score push de ${d.product_mix.push_score} pts/100 est excellent — le commercial propose une gamme très diversifiée à chaque client.`,
                    'Bien':      `Son score push de ${d.product_mix.push_score} pts/100 est satisfaisant et indique une bonne diversification des ventes.`,
                    'Moyen':     `Son score push de ${d.product_mix.push_score} pts/100 est moyen — la gamme de produits poussés pourrait être élargie pour chaque commande.`,
                    'Insuffisant': `Son score push de ${d.product_mix.push_score} pts/100 est faible — les ventes sont trop concentrées sur peu de produits. Diversifier l'offre présentée aux clients est une priorité.`,
                }),
                d.product_mix.average_product_categories_per_invoice > 0 ? pick(getVerdict(d.product_mix.average_product_categories_per_invoice, [3, 2, 1.5]).label, {
                    'Excellent': `La moyenne de ${d.product_mix.average_product_categories_per_invoice.toFixed(1)} catégorie(s) par facture est excellente — chaque commande couvre plusieurs familles de produits.`,
                    'Bien':      `La moyenne de ${d.product_mix.average_product_categories_per_invoice.toFixed(1)} catégorie(s) par facture est bonne et reflète un effort de cross-selling.`,
                    'Moyen':     `La moyenne de ${d.product_mix.average_product_categories_per_invoice.toFixed(1)} catégorie(s) par facture est perfectible — proposer davantage de catégories par commande améliorerait la performance globale.`,
                    'Insuffisant': `La moyenne de ${d.product_mix.average_product_categories_per_invoice.toFixed(1)} catégorie(s) par facture est insuffisante — le cross-selling doit être significativement renforcé.`,
                }) : null,
                pick(getVerdict(d.product_mix.total_distinct_categories_sold_count, [5, 3, 2]).label, {
                    'Excellent': `Avec ${d.product_mix.total_distinct_categories_sold_count} catégories vendues, le commercial couvre la quasi-totalité du catalogue produit.`,
                    'Bien':      `Les ${d.product_mix.total_distinct_categories_sold_count} catégories vendues témoignent d'une bonne couverture de la gamme.`,
                    'Moyen':     `Avec ${d.product_mix.total_distinct_categories_sold_count} catégories vendues, la couverture du catalogue est partielle — certaines familles de produits sont insuffisamment proposées.`,
                    'Insuffisant': `Seulement ${d.product_mix.total_distinct_categories_sold_count} catégorie(s) vendue(s) — le portefeuille est très concentré et de nombreuses familles de produits ne sont pas proposées aux clients.`,
                }),
            ].filter(Boolean),
        },
    ];
});
</script>

<template>
    <Head title="Performances" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl leading-tight">Performances Commerciales</h2>
        </template>

        <div class="py-6">
            <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- ── Filters ─────────────────────────────────────────────── -->
                <v-card elevation="1" class="mb-6 pa-4">
                    <v-row align="center" dense>
                        <v-col cols="12" sm="5" md="4">
                            <v-select
                                v-model="localCommercialId"
                                :items="commerciaux"
                                item-value="id"
                                item-title="name"
                                label="Choisir un commercial"
                                density="compact"
                                variant="outlined"
                                hide-details
                                clearable
                                prepend-inner-icon="mdi-account-tie"
                                @update:model-value="applyFilters"
                            />
                        </v-col>

                        <v-col cols="12" sm="7" md="8">
                            <div class="d-flex align-center gap-2 flex-wrap">
                                <v-icon color="primary" size="20">mdi-calendar-range</v-icon>
                                <v-btn size="x-small" variant="tonal" color="primary" @click="setQuickPeriod('this_month')">Ce mois</v-btn>
                                <v-btn size="x-small" variant="tonal" color="grey" @click="setQuickPeriod('last_month')">Mois dernier</v-btn>
                                <v-btn size="x-small" variant="tonal" color="grey" @click="setQuickPeriod('this_quarter')">Ce trimestre</v-btn>
                                <v-btn size="x-small" variant="tonal" color="grey" @click="setQuickPeriod('this_year')">Cette année</v-btn>
                                <v-btn
                                    size="x-small"
                                    :variant="showCustomPicker ? 'flat' : 'outlined'"
                                    color="primary"
                                    prepend-icon="mdi-tune"
                                    @click="showCustomPicker = !showCustomPicker"
                                >
                                    Personnalisé
                                </v-btn>
                                <span class="text-caption text-grey ml-auto">
                                    {{ formatDate(startDate) }} → {{ formatDate(endDate) }}
                                </span>
                            </div>

                            <v-expand-transition>
                                <div v-if="showCustomPicker" class="d-flex align-center gap-3 mt-3 flex-wrap">
                                    <v-text-field
                                        v-model="localStartDate"
                                        label="Date de début"
                                        type="date"
                                        density="compact"
                                        variant="outlined"
                                        hide-details
                                        style="max-width: 180px"
                                    />
                                    <v-icon color="grey">mdi-arrow-right</v-icon>
                                    <v-text-field
                                        v-model="localEndDate"
                                        label="Date de fin"
                                        type="date"
                                        density="compact"
                                        variant="outlined"
                                        hide-details
                                        style="max-width: 180px"
                                    />
                                    <v-btn color="primary" size="small" variant="flat" @click="applyFilters">
                                        Appliquer
                                    </v-btn>
                                </div>
                            </v-expand-transition>
                        </v-col>
                    </v-row>
                </v-card>

                <!-- ── Empty state ─────────────────────────────────────────── -->
                <div v-if="!performanceData" class="empty-state">
                    <v-icon size="88" color="grey-lighten-2" class="mb-5">mdi-chart-line</v-icon>
                    <h3 class="text-h6 font-weight-medium text-grey-darken-1 mb-2">
                        Sélectionnez un commercial
                    </h3>
                    <p class="text-body-2 text-grey" style="max-width: 340px">
                        Choisissez un commercial dans le filtre ci-dessus pour visualiser ses indicateurs de performance sur la période.
                    </p>
                </div>

                <!-- ── Performance content ─────────────────────────────────── -->
                <template v-if="performanceData">

                    <!-- Commercial identity header -->
                    <div class="d-flex align-center justify-space-between gap-4 mb-6 flex-wrap">
                        <div class="d-flex align-center gap-4">
                            <v-avatar color="indigo" size="52" variant="tonal">
                                <span class="text-h6 font-weight-black">
                                    {{ performanceData.commercial_name.charAt(0).toUpperCase() }}
                                </span>
                            </v-avatar>
                            <div>
                                <h3 class="text-h5 font-weight-bold">{{ performanceData.commercial_name }}</h3>
                                <span class="text-body-2 text-grey">
                                    {{ formatDate(startDate) }} — {{ formatDate(endDate) }}
                                </span>
                            </div>
                        </div>

                        <!-- Format toggle -->
                        <v-btn-toggle
                            v-model="displayFormat"
                            mandatory
                            variant="outlined"
                            color="primary"
                            density="compact"
                            rounded="lg"
                        >
                            <v-btn value="widgets" size="small" prepend-icon="mdi-view-grid-outline">
                                Widgets
                            </v-btn>
                            <v-btn value="verdict" size="small" prepend-icon="mdi-gavel">
                                Format Verdict
                            </v-btn>
                            <v-btn value="notes" size="small" prepend-icon="mdi-school-outline">
                                Format Notes
                            </v-btn>
                        </v-btn-toggle>
                    </div>

                    <!-- ══════════════════════════════════════════════════════ -->
                    <!-- WIDGETS VIEW                                          -->
                    <!-- ══════════════════════════════════════════════════════ -->
                    <template v-if="displayFormat === 'widgets'">

                        <!-- Section: Ventes & Revenus -->
                        <div class="section-heading section-heading--indigo mb-4">
                            <v-icon size="20" class="mr-2">mdi-cash-register</v-icon>
                            <span class="text-subtitle-1 font-weight-bold">Ventes & Revenus</span>
                        </div>

                        <v-row dense class="mb-8">
                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--indigo h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">CA Réalisé</p>
                                            <v-avatar color="indigo" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="indigo" size="20">mdi-currency-usd</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value">{{ formatCurrency(performanceData.sales.total_revenue) }}</p>
                                        <p class="metric-secondary">{{ formatNumber(performanceData.sales.total_invoices_count) }} facture(s)</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--blue h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Panier Moyen</p>
                                            <v-avatar color="blue" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="blue" size="20">mdi-shopping</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value">
                                            {{ performanceData.sales.average_basket_size > 0
                                                ? formatCurrency(performanceData.sales.average_basket_size)
                                                : '—' }}
                                        </p>
                                        <p class="metric-secondary">Valeur moyenne par facture</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--teal h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Total Encaissé</p>
                                            <v-avatar color="teal" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="teal" size="20">mdi-cash-sync</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value">{{ formatCurrency(performanceData.sales.total_payments) }}</p>
                                        <p class="metric-secondary">
                                            Taux d'encaissement :
                                            <strong :class="rateClass(collectionRate, 80, 50)">
                                                {{ collectionRate != null ? collectionRate + '%' : '—' }}
                                            </strong>
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--green h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Marge Brute</p>
                                            <v-avatar color="green" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="green" size="20">mdi-trending-up</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value">{{ formatCurrency(performanceData.sales.profit_generated) }}</p>
                                        <p class="metric-secondary">
                                            Marge / CA :
                                            <strong :class="rateClass(grossMarginRate, 20, 10)">
                                                {{ grossMarginRate != null ? grossMarginRate + '%' : '—' }}
                                            </strong>
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--purple h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Clients Servis</p>
                                            <v-avatar color="purple" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="purple" size="20">mdi-account-group</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ formatNumber(performanceData.sales.unique_customers_served_count) }}
                                        </p>
                                        <p class="metric-secondary">
                                            Moy. :
                                            {{ performanceData.sales.average_revenue_per_customer > 0
                                                ? formatCurrency(performanceData.sales.average_revenue_per_customer) + ' / client'
                                                : '—' }}
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Section: Couverture Client -->
                        <div class="section-heading section-heading--teal mb-4">
                            <v-icon size="20" class="mr-2">mdi-map-marker-check</v-icon>
                            <span class="text-subtitle-1 font-weight-bold">Couverture Client</span>
                        </div>

                        <v-row dense class="mb-8">
                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--teal h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Taux de Frappe</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.coverage.visit_strike_rate ?? 0"
                                                :color="rateColor(performanceData.coverage.visit_strike_rate)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.coverage.visit_strike_rate) }}
                                                </span>
                                            </v-progress-circular>
                                            <div>
                                                <p class="text-body-2 font-weight-medium">
                                                    {{ formatNumber(performanceData.coverage.active_customers_count) }} acheteurs
                                                </p>
                                                <p class="text-caption text-grey">
                                                    sur {{ formatNumber(performanceData.coverage.visited_customers_count) }} visités
                                                </p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--green h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Taux de Fidélisation</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.coverage.customer_retention_rate ?? 0"
                                                :color="rateColor(performanceData.coverage.customer_retention_rate)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.coverage.customer_retention_rate) }}
                                                </span>
                                            </v-progress-circular>
                                            <div>
                                                <p class="text-body-2 font-weight-medium">
                                                    {{ formatNumber(performanceData.coverage.returning_customers_count) }} fidèles
                                                </p>
                                                <p class="text-caption text-grey">ayant racheté</p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--orange h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Taux de Churn</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.coverage.churning_rate ?? 0"
                                                :color="rateColor(performanceData.coverage.churning_rate, true)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.coverage.churning_rate) }}
                                                </span>
                                            </v-progress-circular>
                                            <div>
                                                <p class="text-body-2 font-weight-medium">
                                                    {{ formatNumber(performanceData.coverage.churning_customers_count) }} perdus
                                                </p>
                                                <p class="text-caption text-grey">moins = mieux</p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--indigo h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Taux d'Acquisition</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.coverage.acquisition_strike_rate ?? 0"
                                                :color="rateColor(performanceData.coverage.acquisition_strike_rate)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.coverage.acquisition_strike_rate) }}
                                                </span>
                                            </v-progress-circular>
                                            <div>
                                                <p class="text-body-2 font-weight-medium">
                                                    +{{ formatNumber(performanceData.coverage.new_confirmed_customers_count) }} nouveaux
                                                </p>
                                                <p class="text-caption text-grey">clients confirmés</p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--blue h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Pipeline Prospects</p>
                                            <v-avatar color="blue" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="blue" size="20">mdi-account-clock</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ formatNumber(performanceData.coverage.new_prospect_customers_count) }}
                                        </p>
                                        <p class="metric-secondary">
                                            Dont {{ formatNumber(performanceData.coverage.prospects_converted_to_confirmed_count) }} convertis
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Section: Recouvrement & Créances -->
                        <div class="section-heading section-heading--red mb-4">
                            <v-icon size="20" class="mr-2">mdi-cash-clock</v-icon>
                            <span class="text-subtitle-1 font-weight-bold">Recouvrement & Créances</span>
                        </div>

                        <v-row dense class="mb-8">
                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--red h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Encours Total</p>
                                            <v-avatar color="red" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="red" size="20">mdi-currency-usd-off</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value" :class="performanceData.collection.total_outstanding_amount > 0 ? 'text-error' : 'text-success'">
                                            {{ formatCurrency(performanceData.collection.total_outstanding_amount) }}
                                        </p>
                                        <p class="metric-secondary">Créances impayées en temps réel</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--teal h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Factures Réglées</p>
                                            <v-avatar color="teal" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="teal" size="20">mdi-check-circle</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ formatNumber(performanceData.collection.fully_paid_invoices_count) }}
                                            <span class="text-body-2 text-grey font-weight-regular">
                                                / {{ formatNumber(performanceData.collection.total_invoices_count) }}
                                            </span>
                                        </p>
                                        <p class="metric-secondary">Factures intégralement payées</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--indigo h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Efficacité de Recouvrement (IER)</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.collection.collection_effectiveness_index ?? 0"
                                                :color="rateColor(performanceData.collection.collection_effectiveness_index)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.collection.collection_effectiveness_index) }}
                                                </span>
                                            </v-progress-circular>
                                            <p class="text-caption text-grey">Part des créances exigibles encaissées</p>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--green h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Paiements dans les Délais</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.collection.on_time_payment_rate ?? 0"
                                                :color="rateColor(performanceData.collection.on_time_payment_rate)"
                                                :size="68"
                                                :width="7"
                                            >
                                                <span class="text-body-2 font-weight-bold">
                                                    {{ formatRate(performanceData.collection.on_time_payment_rate) }}
                                                </span>
                                            </v-progress-circular>
                                            <div>
                                                <p class="text-body-2 font-weight-medium">
                                                    {{ formatNumber(performanceData.collection.on_time_invoices_count) }} à temps
                                                </p>
                                                <p class="text-caption text-grey">sur factures réglées</p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--orange h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Délai Moyen de Paiement</p>
                                            <v-avatar color="orange" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="orange" size="20">mdi-timer-outline</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ performanceData.collection.average_days_to_payment > 0
                                                ? Math.round(performanceData.collection.average_days_to_payment)
                                                : '—' }}
                                            <span v-if="performanceData.collection.average_days_to_payment > 0" class="text-body-2 text-grey font-weight-regular">j</span>
                                        </p>
                                        <p class="metric-secondary">
                                            Médiane :
                                            {{ performanceData.collection.median_days_to_payment > 0
                                                ? Math.round(performanceData.collection.median_days_to_payment) + ' j'
                                                : '—' }}
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--deep-orange h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Retard Moyen de Paiement</p>
                                            <v-avatar color="deep-orange" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="deep-orange" size="20">mdi-clock-alert</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p
                                            class="metric-value metric-value--count"
                                            :class="performanceData.collection.average_days_delinquent > 0 ? 'text-warning' : ''"
                                        >
                                            {{ performanceData.collection.average_days_delinquent > 0
                                                ? Math.round(performanceData.collection.average_days_delinquent)
                                                : '—' }}
                                            <span v-if="performanceData.collection.average_days_delinquent > 0" class="text-body-2 text-grey font-weight-regular">j</span>
                                        </p>
                                        <p class="metric-secondary">
                                            Médiane :
                                            {{ performanceData.collection.median_days_delinquent > 0
                                                ? Math.round(performanceData.collection.median_days_delinquent) + ' j'
                                                : '—' }}
                                        </p>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Section: Mix Produit -->
                        <div class="section-heading section-heading--purple mb-4">
                            <v-icon size="20" class="mr-2">mdi-chart-bubble</v-icon>
                            <span class="text-subtitle-1 font-weight-bold">Mix Produit</span>
                        </div>

                        <v-row dense class="mb-6">
                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--purple h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <p class="metric-label mb-4">Score Push Produit</p>
                                        <div class="d-flex align-center gap-5">
                                            <v-progress-circular
                                                :model-value="performanceData.product_mix.push_score ?? 0"
                                                :color="pushScoreColor(performanceData.product_mix.push_score)"
                                                :size="76"
                                                :width="8"
                                            >
                                                <div class="text-center">
                                                    <span class="text-body-1 font-weight-black">
                                                        {{ performanceData.product_mix.push_score ?? 0 }}
                                                    </span>
                                                    <span class="text-caption text-grey d-block" style="margin-top: -2px">/100</span>
                                                </div>
                                            </v-progress-circular>
                                            <div>
                                                <v-chip
                                                    :color="pushScoreColor(performanceData.product_mix.push_score)"
                                                    variant="tonal"
                                                    size="small"
                                                    class="mb-2"
                                                >
                                                    {{ pushScoreLabel(performanceData.product_mix.push_score) }}
                                                </v-chip>
                                                <p class="text-caption text-grey">Diversité produits vendus</p>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--orange h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Catégories Vendues</p>
                                            <v-avatar color="orange" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="orange" size="20">mdi-tag-multiple</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ performanceData.product_mix.total_distinct_categories_sold_count || '—' }}
                                        </p>
                                        <p class="metric-secondary">Catégories distinctes sur la période</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--deep-orange h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Catégories / Facture</p>
                                            <v-avatar color="deep-orange" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="deep-orange" size="20">mdi-chart-bar</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ performanceData.product_mix.average_product_categories_per_invoice > 0
                                                ? performanceData.product_mix.average_product_categories_per_invoice.toFixed(1)
                                                : '—' }}
                                        </p>
                                        <p class="metric-secondary">Moyenne de catégories par commande</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>

                            <v-col cols="12" sm="6" md="4" lg="3">
                                <v-card class="metric-card metric-card--indigo h-100" elevation="1" rounded="lg">
                                    <v-card-text class="pa-5">
                                        <div class="d-flex align-start justify-space-between mb-3">
                                            <p class="metric-label">Références Vendues</p>
                                            <v-avatar color="indigo" variant="tonal" size="40" rounded="lg">
                                                <v-icon color="indigo" size="20">mdi-package-variant-closed</v-icon>
                                            </v-avatar>
                                        </div>
                                        <p class="metric-value metric-value--count">
                                            {{ performanceData.product_mix.total_distinct_products_sold_count || '—' }}
                                        </p>
                                        <p class="metric-secondary">Produits distincts vendus</p>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                    </template>
                    <!-- END WIDGETS VIEW -->

                    <!-- ══════════════════════════════════════════════════════ -->
                    <!-- VERDICT VIEW (prose narrative)                        -->
                    <!-- ══════════════════════════════════════════════════════ -->
                    <template v-else-if="displayFormat === 'verdict'">

                        <!-- Report header -->
                        <v-card elevation="1" rounded="lg" class="mb-6 pa-5 verdict-report-header">
                            <div class="d-flex align-center gap-3 mb-2">
                                <v-icon color="primary" size="20">mdi-file-account-outline</v-icon>
                                <span class="text-caption font-weight-bold text-uppercase text-grey-darken-1">
                                    Rapport de Performance
                                </span>
                            </div>
                            <p class="text-body-1 verdict-report-intro">
                                Le présent rapport dresse le bilan des performances de
                                <strong>{{ performanceData.commercial_name }}</strong>
                                pour la période du <strong>{{ formatDate(startDate) }}</strong>
                                au <strong>{{ formatDate(endDate) }}</strong>.
                                Il couvre l'activité commerciale, la couverture client, le recouvrement
                                et la diversification produit.
                            </p>
                        </v-card>

                        <!-- Narrative section cards -->
                        <v-row dense>
                            <v-col
                                v-for="section in narrativeSections"
                                :key="section.section"
                                cols="12"
                                md="6"
                            >
                                <v-card elevation="1" rounded="lg" class="mb-4 h-100">
                                    <!-- Section header -->
                                    <div
                                        class="verdict-section-header pa-4"
                                        :class="`verdict-section-header--${section.color}`"
                                    >
                                        <div class="d-flex align-center justify-space-between">
                                            <div class="d-flex align-center gap-3">
                                                <v-avatar :color="section.color" variant="tonal" size="34" rounded="lg">
                                                    <v-icon :color="section.color" size="18">{{ section.icon }}</v-icon>
                                                </v-avatar>
                                                <span class="text-subtitle-2 font-weight-bold">{{ section.section }}</span>
                                            </div>
                                            <v-chip
                                                v-if="section.avg != null"
                                                :color="sectionAvgColor(section.avg)"
                                                variant="tonal"
                                                size="small"
                                                class="font-weight-bold"
                                            >
                                                {{ section.avg }}/10
                                            </v-chip>
                                        </div>
                                    </div>

                                    <v-card-text class="pa-5">
                                        <!-- Context intro -->
                                        <p class="verdict-intro-text mb-4">{{ section.intro }}</p>

                                        <!-- Metric sentences -->
                                        <div class="verdict-sentences">
                                            <p
                                                v-for="(sentence, index) in section.sentences"
                                                :key="index"
                                                class="verdict-sentence"
                                            >
                                                {{ sentence }}
                                            </p>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                    </template>
                    <!-- END VERDICT VIEW -->

                    <!-- ══════════════════════════════════════════════════════ -->
                    <!-- NOTES VIEW (bulletin de notes)                        -->
                    <!-- ══════════════════════════════════════════════════════ -->
                    <template v-else-if="displayFormat === 'notes'">

                        <v-card elevation="2" rounded="lg" class="bulletin-card">

                            <!-- Bulletin header -->
                            <div class="bulletin-header">
                                <div class="bulletin-header__logo">
                                    <v-icon size="28" color="indigo-darken-2">mdi-school-outline</v-icon>
                                </div>
                                <div class="bulletin-header__info">
                                    <p class="bulletin-header__title">BULLETIN DE PERFORMANCE</p>
                                    <p class="bulletin-header__name">{{ performanceData.commercial_name }}</p>
                                    <p class="bulletin-header__period">
                                        Période : {{ formatDate(startDate) }} — {{ formatDate(endDate) }}
                                    </p>
                                </div>
                                <div class="bulletin-header__global">
                                    <p class="bulletin-header__global-label">Moyenne Générale</p>
                                    <p
                                        class="bulletin-header__global-score"
                                        :class="'text-' + globalScoreColor"
                                    >
                                        {{ globalScore != null ? globalScore : '—' }}<span class="bulletin-header__global-denom">/10</span>
                                    </p>
                                    <v-chip
                                        :color="globalScoreColor"
                                        variant="flat"
                                        size="small"
                                        class="font-weight-bold mt-1"
                                    >
                                        {{ globalScoreLabel }}
                                    </v-chip>
                                </div>
                            </div>

                            <!-- Grades table -->
                            <div class="bulletin-table-wrapper">
                                <table class="bulletin-table">
                                    <thead>
                                        <tr class="bulletin-table__head">
                                            <th class="bulletin-col--subject">Indicateur</th>
                                            <th class="bulletin-col--value">Valeur</th>
                                            <th class="bulletin-col--bar">Progression</th>
                                            <th class="bulletin-col--score">Note / 10</th>
                                            <th class="bulletin-col--mention">Appréciation</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <template
                                            v-for="section in verdictSections"
                                            :key="section.section"
                                        >
                                            <!-- Section header row -->
                                            <tr class="bulletin-table__section" :class="`bulletin-section--${section.color}`">
                                                <td colspan="4" class="bulletin-section__name">
                                                    <v-icon size="16" class="mr-2" style="vertical-align: middle">{{ section.icon }}</v-icon>
                                                    {{ section.section }}
                                                </td>
                                                <td class="bulletin-section__avg text-right">
                                                    <span
                                                        v-if="sectionAvg(section) != null"
                                                        class="bulletin-section__avg-value"
                                                        :class="'text-' + sectionAvgColor(sectionAvg(section))"
                                                    >
                                                        Moy. {{ sectionAvg(section) }}/10
                                                    </span>
                                                </td>
                                            </tr>

                                            <!-- Metric rows -->
                                            <tr
                                                v-for="metric in section.metrics"
                                                :key="metric.label"
                                                class="bulletin-table__row"
                                            >
                                                <td class="bulletin-col--subject">{{ metric.label }}</td>
                                                <td class="bulletin-col--value">{{ metric.formatted }}</td>
                                                <td class="bulletin-col--bar">
                                                    <v-progress-linear
                                                        v-if="metric.verdict.score != null"
                                                        :model-value="metric.verdict.score * 10"
                                                        :color="metric.verdict.color"
                                                        bg-color="grey-lighten-3"
                                                        height="8"
                                                        rounded
                                                    />
                                                    <span v-else class="text-caption text-grey">—</span>
                                                </td>
                                                <td class="bulletin-col--score">
                                                    <span
                                                        v-if="metric.verdict.score != null"
                                                        class="bulletin-score"
                                                        :class="'text-' + metric.verdict.color"
                                                    >
                                                        {{ metric.verdict.score }}<span class="bulletin-score__denom">/10</span>
                                                    </span>
                                                    <span v-else class="text-caption text-grey">—</span>
                                                </td>
                                                <td class="bulletin-col--mention">
                                                    <v-chip
                                                        v-if="metric.verdict.score != null"
                                                        :color="metric.verdict.color"
                                                        variant="tonal"
                                                        size="x-small"
                                                        class="font-weight-bold"
                                                    >
                                                        {{ metric.verdict.label }}
                                                    </v-chip>
                                                    <span v-else class="text-caption text-grey">—</span>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>

                                    <!-- Global average footer -->
                                    <tfoot>
                                        <tr class="bulletin-table__footer">
                                            <td colspan="3" class="bulletin-footer__label">
                                                MOYENNE GÉNÉRALE —
                                                {{ verdictSections.flatMap(s => s.metrics).filter(m => m.verdict.score != null).length }} indicateurs évalués
                                            </td>
                                            <td class="bulletin-col--score">
                                                <span
                                                    class="bulletin-score bulletin-score--large"
                                                    :class="'text-' + globalScoreColor"
                                                >
                                                    {{ globalScore }}<span class="bulletin-score__denom">/10</span>
                                                </span>
                                            </td>
                                            <td class="bulletin-col--mention">
                                                <v-chip
                                                    :color="globalScoreColor"
                                                    variant="flat"
                                                    size="small"
                                                    class="font-weight-bold"
                                                >
                                                    {{ globalScoreLabel }}
                                                </v-chip>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                        </v-card>

                    </template>
                    <!-- END NOTES VIEW -->

                </template>
                <!-- END PERFORMANCE CONTENT -->

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 80px 24px;
}

.section-heading {
    display: flex;
    align-items: center;
    padding: 10px 16px;
    border-radius: 8px;
    background-color: rgba(0, 0, 0, 0.03);
}

.section-heading--indigo { border-left: 4px solid #3f51b5; color: #3f51b5; }
.section-heading--teal   { border-left: 4px solid #009688; color: #009688; }
.section-heading--purple { border-left: 4px solid #9c27b0; color: #9c27b0; }
.section-heading--red    { border-left: 4px solid #f44336; color: #f44336; }

.metric-card {
    border-left: 3px solid transparent;
}

.metric-card--indigo    { border-left-color: #3f51b5; }
.metric-card--blue      { border-left-color: #2196f3; }
.metric-card--teal      { border-left-color: #009688; }
.metric-card--green     { border-left-color: #4caf50; }
.metric-card--purple    { border-left-color: #9c27b0; }
.metric-card--orange    { border-left-color: #ff9800; }
.metric-card--deep-orange { border-left-color: #ff5722; }
.metric-card--red       { border-left-color: #f44336; }

.metric-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #9e9e9e;
    margin: 0;
    line-height: 1.3;
}

.metric-value {
    font-size: 20px;
    font-weight: 800;
    color: #212121;
    margin: 4px 0 0 0;
    line-height: 1.2;
}

.metric-value--count {
    font-size: 30px;
}

.metric-secondary {
    font-size: 11px;
    color: #9e9e9e;
    margin: 10px 0 0 0;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
    line-height: 1.5;
}

/* ── Verdict prose styles ───────────────────────────────────────────────────── */

.verdict-report-header {
    border-left: 4px solid #3f51b5;
}

.verdict-report-intro {
    color: #424242;
    line-height: 1.7;
}

.verdict-section-header {
    border-bottom: 1px solid #f0f0f0;
    background-color: rgba(0, 0, 0, 0.02);
}

.verdict-section-header--indigo { border-left: 3px solid #3f51b5; }
.verdict-section-header--teal   { border-left: 3px solid #009688; }
.verdict-section-header--red    { border-left: 3px solid #f44336; }
.verdict-section-header--purple { border-left: 3px solid #9c27b0; }

.verdict-intro-text {
    font-size: 13px;
    color: #616161;
    line-height: 1.65;
    font-style: italic;
    border-left: 3px solid #e0e0e0;
    padding-left: 12px;
}

.verdict-sentences {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.verdict-sentence {
    font-size: 13.5px;
    color: #212121;
    line-height: 1.65;
    margin: 0;
    padding-left: 16px;
    position: relative;
}

.verdict-sentence::before {
    content: '›';
    position: absolute;
    left: 0;
    color: #9e9e9e;
    font-weight: bold;
}

/* ═══════════════════════════════════════════════════════════
   BULLETIN DE NOTES — grades sheet layout
   ═══════════════════════════════════════════════════════════ */

.bulletin-card {
    overflow: hidden;
}

.bulletin-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px 28px;
    background: linear-gradient(135deg, #1a237e 0%, #283593 60%, #3949ab 100%);
    color: #ffffff;
}

.bulletin-header__logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    background: rgba(255,255,255,0.15);
    border-radius: 10px;
    flex-shrink: 0;
}

.bulletin-header__info {
    flex: 1;
}

.bulletin-header__title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.7);
    margin: 0 0 4px;
}

.bulletin-header__name {
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 2px;
}

.bulletin-header__period {
    font-size: 12px;
    color: rgba(255,255,255,0.65);
    margin: 0;
}

.bulletin-header__global {
    text-align: center;
    background: rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 14px 20px;
    flex-shrink: 0;
}

.bulletin-header__global-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.65);
    margin: 0 0 4px;
}

.bulletin-header__global-score {
    font-size: 36px;
    font-weight: 900;
    line-height: 1;
    margin: 0 0 6px;
}

.bulletin-header__global-denom {
    font-size: 18px;
    font-weight: 400;
    opacity: 0.8;
}

.bulletin-table-wrapper {
    overflow-x: auto;
}

.bulletin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.bulletin-table__head th {
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #546e7a;
    background: #f5f7fa;
    border-bottom: 2px solid #e0e0e0;
    white-space: nowrap;
}

.bulletin-col--subject { width: 34%; text-align: left; }
.bulletin-col--value   { width: 16%; text-align: center; }
.bulletin-col--bar     { width: 26%; text-align: left; }
.bulletin-col--score   { width: 10%; text-align: center; }
.bulletin-col--mention { width: 14%; text-align: center; }

/* Section header rows */
.bulletin-table__section td {
    padding: 9px 14px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.bulletin-section--indigo { background: #e8eaf6; border-left: 4px solid #3f51b5; }
.bulletin-section--teal   { background: #e0f2f1; border-left: 4px solid #009688; }
.bulletin-section--red    { background: #fce4ec; border-left: 4px solid #e91e63; }
.bulletin-section--purple { background: #f3e5f5; border-left: 4px solid #9c27b0; }

.bulletin-section__name {
    text-align: left;
    color: #37474f;
}

.bulletin-section__avg {
    text-align: right;
    padding-right: 14px;
}

.bulletin-section__avg-value {
    font-size: 12px;
    font-weight: 800;
}

/* Metric rows */
.bulletin-table__row td {
    padding: 9px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    color: #37474f;
}

.bulletin-table__row:last-child td {
    border-bottom: none;
}

.bulletin-table__row:nth-child(even) td {
    background: #fafafa;
}

/* Score display */
.bulletin-score {
    font-size: 16px;
    font-weight: 800;
}

.bulletin-score--large {
    font-size: 20px;
}

.bulletin-score__denom {
    font-size: 11px;
    font-weight: 400;
    opacity: 0.75;
}

/* Footer global average row */
.bulletin-table__footer td {
    padding: 14px 14px;
    background: #263238;
    color: #eceff1;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.bulletin-footer__label {
    text-align: left;
}
</style>
