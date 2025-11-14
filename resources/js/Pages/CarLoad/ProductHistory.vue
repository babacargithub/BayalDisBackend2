<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import moment from "moment/min/moment-with-locales";
moment.locale('FR')

// Expecting data exactly as returned by the controller/service
const props = defineProps({
  loadingsHistory: { type: Array, required: true },
  product: { type: Object, required: true },
  ventes: { type: Array, required: true },
})

// Totals for quick insights
const totalLoaded = computed(() =>
  (props.loadingsHistory || []).reduce((sum, it) => sum + (Number(it.quantity_loaded) || 0), 0)
)

const totalSold = computed(() =>
  (props.ventes || []).reduce((sum, v) => sum + (Number(v.quantity) || 0), 0)
)

const totalSalesAmount = computed(() =>
  (props.ventes || []).reduce((sum, v) => sum + (Number(v.subtotal) || (Number(v.price) || 0) * (Number(v.quantity) || 0)), 0)
)

// Helper formatters
const fmtInt = (n) => (Number(n) || 0).toLocaleString()
const fmtMoney = (n) => (Number(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' F'
const fmtDateTime = (d) => {
  // format date to french
  return moment(d).format('llll')
}

// Optional derived: balance vs. what was loaded (informational)
const balance = computed(() => totalLoaded.value - totalSold.value)
</script>

<template>
 <AuthenticatedLayout>
   <Head :title="`Historique – ${product?.name || ''}`" />

   <div class="page">
     <div class="page-header">
       <h1>Historique du produit</h1>
       <div class="subtitle">{{ product?.name }}</div>
     </div>

     <div class="cards">
       <div class="card kpi">
         <div class="kpi-label">Total chargé</div>
         <div class="kpi-value">{{ fmtInt(totalLoaded) }}</div>
       </div>
       <div class="card kpi">
         <div class="kpi-label">Total vendu</div>
         <div class="kpi-value">{{ fmtInt(totalSold) }}</div>
       </div>
       <div class="card kpi">
         <div class="kpi-label">Montant ventes</div>
         <div class="kpi-value">{{ fmtMoney(totalSalesAmount) }}</div>
       </div>
       <div class="card kpi">
         <div class="kpi-label">Solde (chargé − vendu)</div>
         <div class="kpi-value" :class="{ negative: balance < 0 }">{{ fmtInt(balance) }}</div>
       </div>
     </div>

     <div class="panels">
       <section class="panel">
         <div class="panel-header">
           <h2>Historique de chargement</h2>
         </div>
         <div class="table-wrap" v-if="(loadingsHistory?.length || 0) > 0">
           <table class="table">
             <thead>
             <tr>
               <th>Chargé le</th>
               <th class="num">Qté chargée</th>
               <th class="num">Qté restante</th>
               <th>Véhicule</th>
               <th>Commentaire</th>
             </tr>
             </thead>
             <tbody>
             <tr v-for="(it, idx) in loadingsHistory" :key="it.id ?? idx">
               <td>{{ fmtDateTime(it.loaded_at || it.created_at) }}</td>
               <td class="num">{{ fmtInt(it.quantity_loaded) }}</td>
               <td class="num">{{ fmtInt(it.quantity_left) }}</td>
               <td>
                 <span v-if="it.car_load?.name">{{ it.car_load.name }}</span>
                 <span v-else>—</span>
               </td>
               <td>{{ it.comment || '—' }}</td>
             </tr>
             </tbody>
           </table>
         </div>
         <div class="empty" v-else>Aucun chargement trouvé pour ce produit dans ce véhicule.</div>
       </section>

       <section class="panel">
         <div class="panel-header">
           <h2>Historique des ventes</h2>
         </div>
         <div class="table-wrap" v-if="(ventes?.length || 0) > 0">
           <table class="table">
             <thead>
             <tr>
               <th>Date</th>
               <th>Client</th>
               <th>Facture</th>
               <th class="num">Qté</th>
               <th class="num">Prix (unitaire)</th>
               <th class="num">Sous-total</th>
               <th>Paiement</th>
             </tr>
             </thead>
             <tbody>
             <tr v-for="(v, idx) in ventes" :key="v.id ?? idx">
               <td>{{ fmtDateTime(v.created_at) }}</td>
               <td>
                 <span v-if="v.name">{{ v.name }}</span>
                 <span v-else-if="v.sales_invoice?.customer?.name">{{ v.sales_invoice.customer.name }}</span>
                 <span v-else>N/A</span>
               </td>
               <td>
                 <span v-if="v.invoice_number">#{{ v.invoice_number }}--{{ fmtDateTime(v.invoice_date) }}</span>
                 <span v-else>—</span>
               </td>
               <td class="num">{{ fmtInt(v.quantity) }}</td>
               <td class="num">{{ fmtMoney(v.price) }}</td>
               <td class="num">{{ fmtMoney(v.subtotal ?? (v.price * v.quantity)) }}</td>
               <td>
                 <span v-if="v.paid">Payé</span>
                 <span v-else>Impayé</span>
                 <span v-if="v.payment_method"> — {{ v.payment_method }}</span>
               </td>
             </tr>
             </tbody>
             <tfoot>
             <tr>
               <th colspan="3" class="right">Totaux</th>
               <th class="num">{{ fmtInt(totalSold) }}</th>
               <th></th>
               <th class="num">{{ fmtMoney(totalSalesAmount) }}</th>
               <th></th>
             </tr>
             </tfoot>
           </table>
         </div>
         <div class="empty" v-else>Aucune vente trouvée pour ce produit durant la période du chargement.</div>
       </section>
     </div>

     <div class="actions">
       <Link href="/car-loads" class="btn">Retour aux chargements</Link>
     </div>
   </div>
 </AuthenticatedLayout>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 1rem; }
.page-header { display: flex; flex-direction: column; gap: .25rem; }
.page-header h1 { margin: 0; font-size: 1.5rem; }
.subtitle { color: #555; }

.cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
.card { background: #fff; border: 1px solid #eee; border-radius: 6px; padding: .75rem; }
.kpi { display: flex; flex-direction: column; gap: .25rem; }
.kpi-label { color: #666; font-size: .85rem; }
.kpi-value { font-size: 1.1rem; font-weight: 700; }
.kpi-value.negative { color: #c62828; }

.panels { display: grid; grid-template-columns: 1fr; gap: 1rem; }
.panel { background: #fff; border: 1px solid #eee; border-radius: 6px; }
.panel-header { padding: .75rem .75rem 0 .75rem; }
.table-wrap { overflow: auto; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: .5rem .6rem; border-top: 1px solid #eee; }
.table thead th { position: sticky; top: 0; background: #fafafa; text-align: left; }
.table .num { text-align: right; }
.table tfoot th { background: #fafafa; border-top: 2px solid #e5e5e5; }
.table .right { text-align: right; }

.empty { padding: .75rem; color: #666; }
.actions { display: flex; justify-content: flex-end; }
.btn { display: inline-flex; padding: .5rem .9rem; border: 1px solid #ddd; border-radius: 6px; color: #222; text-decoration: none; background: #f8f8f8; }
.btn:hover { background: #f0f0f0; }
</style>
