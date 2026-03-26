#!/usr/bin/env bash
#
# dev-setup.sh
#
# Quick dev helpers to run after reset-local-db.sh:
#   1. Unlocks all car load items (sets quantity_left = 200)
#   2. Pushes the latest car load's return_date 30 days into the future
#   3. Creates commercial "Ablaye Diallo" with user, caisse, and accounts
#      (skipped if already exists)
#   4. Creates team "Equipe 2" (skipped if already exists)
#   5. Duplicates the latest vehicle with a new plate number (skipped if already duplicated)
#   6. Duplicates the latest car load + items (quantity_left = 200) for Equipe 2
#      (skipped if already duplicated)
#   7. Generates API tokens for user #3 and Ablaye Diallo and prints both
#
# Usage:
#   ./dev-setup.sh
#
# Not tracked in git — local development only.

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
DB_HOST="127.0.0.1"
DB_PORT="8889"
DB_NAME="bayal_dis"
DB_USER="root"
DB_PASS="root"

ARTISAN="php artisan"
MYSQL="mysql --host=${DB_HOST} --port=${DB_PORT} --user=${DB_USER} --password=${DB_PASS} ${DB_NAME}"

# ── Helpers ───────────────────────────────────────────────────────────────────
info()    { echo -e "\033[0;34m▶ $*\033[0m"; }
success() { echo -e "\033[0;32m✔ $*\033[0m"; }
skip()    { echo -e "\033[0;33m⊘ $*\033[0m"; }
error()   { echo -e "\033[0;31m✖ $*\033[0m" >&2; exit 1; }

tinker_eval() {
    # Run a PHP snippet through artisan tinker and capture the last non-empty output line.
    local code="$1"
    local raw
    raw=$(${ARTISAN} tinker --no-ansi 2>/dev/null <<EOF
${code}
EOF
)
    echo "${raw}" | grep -v '^\s*$' | grep -v '^>' | grep -v 'Psy Shell' | tail -1 | tr -d '[:space:]'
}

# Resolve project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

[[ -f "artisan" ]] || error "artisan not found — run this script from the Laravel project root."

# ── Step 1: Reset car load item quantities ─────────────────────────────────────
info "Setting quantity_left = 200 on all car_load_items..."
${MYSQL} --execute="UPDATE car_load_items SET quantity_left = 200;"
success "car_load_items.quantity_left reset to 200."

# ── Step 2: Push latest car load return_date 30 days into the future ──────────
FUTURE_DATE=$(date -v+30d '+%Y-%m-%d' 2>/dev/null || date -d '+30 days' '+%Y-%m-%d')
info "Setting latest car load return_date to ${FUTURE_DATE}..."
${MYSQL} --execute="
  UPDATE car_loads
  SET    return_date = '${FUTURE_DATE}'
  WHERE  id = (SELECT id FROM (SELECT MAX(id) AS id FROM car_loads) AS t);
"
success "Latest car load return_date set to ${FUTURE_DATE}."

# ── Step 3: Create commercial "Ablaye Diallo" (idempotent) ────────────────────
info "Creating commercial Ablaye Diallo (with user, caisse, accounts)..."
ABLAYE_COMMERCIAL_ID=$(tinker_eval "
\$commercial = \App\Models\Commercial::where('name', 'Ablaye Diallo')->first();
if (\$commercial) {
    echo \$commercial->id;
} else {
    \$user = \App\Models\User::create([
        'name'              => 'Ablaye Diallo',
        'email'             => 'ablaye.diallo@bayal.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
    \$commercial = \App\Models\Commercial::create([
        'name'         => 'Ablaye Diallo',
        'phone_number' => '770000001',
        'gender'       => 'M',
        'salary'       => 0,
        'user_id'      => \$user->id,
    ]);
    echo \$commercial->id;
}
")

if [[ -z "${ABLAYE_COMMERCIAL_ID}" || "${ABLAYE_COMMERCIAL_ID}" == "0" ]]; then
    error "Failed to create or find commercial Ablaye Diallo."
fi

ABLAYE_ALREADY_EXISTED=$(${MYSQL} --batch --silent --execute="
  SELECT COUNT(*) FROM commercials WHERE name = 'Ablaye Diallo';
" | tail -1)

if [[ "${ABLAYE_ALREADY_EXISTED}" == "1" ]]; then
    skip "Commercial Ablaye Diallo already existed (id=${ABLAYE_COMMERCIAL_ID})."
else
    success "Commercial Ablaye Diallo created (id=${ABLAYE_COMMERCIAL_ID})."
fi

# ── Step 4: Create team "Equipe 2" (idempotent) ───────────────────────────────
info "Creating team Equipe 2..."
EQUIPE2_ID=$(${MYSQL} --batch --silent --execute="SELECT id FROM teams WHERE name = 'Equipe 2' LIMIT 1;" | tail -1)

if [[ -n "${EQUIPE2_ID}" ]]; then
    skip "Team Equipe 2 already exists (id=${EQUIPE2_ID})."
else
    # Use admin user (id=1) as team manager
    ${MYSQL} --execute="INSERT INTO teams (name, user_id, created_at, updated_at) VALUES ('Equipe 2', 1, NOW(), NOW());"
    EQUIPE2_ID=$(${MYSQL} --batch --silent --execute="SELECT LAST_INSERT_ID();" | tail -1)
    success "Team Equipe 2 created (id=${EQUIPE2_ID})."
fi

# Assign Ablaye Diallo to Equipe 2
${MYSQL} --execute="UPDATE commercials SET team_id = ${EQUIPE2_ID} WHERE id = ${ABLAYE_COMMERCIAL_ID};"
success "Ablaye Diallo assigned to Equipe 2."

# ── Step 5: Duplicate latest vehicle with a new plate number (idempotent) ──────
info "Duplicating latest vehicle for Equipe 2..."
LATEST_VEHICLE_ID=$(${MYSQL} --batch --silent --execute="SELECT MAX(id) FROM vehicles;" | tail -1)
LATEST_PLATE=$(${MYSQL} --batch --silent --execute="SELECT plate_number FROM vehicles WHERE id = ${LATEST_VEHICLE_ID};" | tail -1)
NEW_PLATE="${LATEST_PLATE}-2"
LATEST_VEHICLE_NAME=$(${MYSQL} --batch --silent --execute="SELECT name FROM vehicles WHERE id = ${LATEST_VEHICLE_ID};" | tail -1)
NEW_VEHICLE_NAME="${LATEST_VEHICLE_NAME} (Equipe 2)"

NEW_VEHICLE_ID=$(${MYSQL} --batch --silent --execute="SELECT id FROM vehicles WHERE plate_number = '${NEW_PLATE}' LIMIT 1;" | tail -1)

if [[ -n "${NEW_VEHICLE_ID}" ]]; then
    skip "Duplicate vehicle with plate ${NEW_PLATE} already exists (id=${NEW_VEHICLE_ID})."
else
    ${MYSQL} --execute="
      INSERT INTO vehicles
        (name, plate_number, insurance_monthly, maintenance_monthly,
         repair_reserve_monthly, depreciation_monthly, driver_salary_monthly,
         working_days_per_month, estimated_daily_fuel_consumption, notes,
         created_at, updated_at)
      SELECT
        '${NEW_VEHICLE_NAME}',
        '${NEW_PLATE}',
        insurance_monthly, maintenance_monthly,
        repair_reserve_monthly, depreciation_monthly, driver_salary_monthly,
        working_days_per_month, estimated_daily_fuel_consumption, notes,
        NOW(), NOW()
      FROM vehicles
      WHERE id = ${LATEST_VEHICLE_ID};
    "
    NEW_VEHICLE_ID=$(${MYSQL} --batch --silent --execute="SELECT LAST_INSERT_ID();" | tail -1)
    success "Vehicle duplicated as '${NEW_VEHICLE_NAME}' / ${NEW_PLATE} (id=${NEW_VEHICLE_ID})."
fi

# ── Step 6: Duplicate latest car load + items for Equipe 2 (idempotent) ────────
info "Duplicating latest car load for Equipe 2..."
LATEST_CAR_LOAD_ID=$(${MYSQL} --batch --silent --execute="SELECT MAX(id) FROM car_loads;" | tail -1)
LATEST_CAR_LOAD_NAME=$(${MYSQL} --batch --silent --execute="SELECT name FROM car_loads WHERE id = ${LATEST_CAR_LOAD_ID};" | tail -1)
NEW_CAR_LOAD_NAME="${LATEST_CAR_LOAD_NAME} — Equipe 2"

EXISTING_NEW_CAR_LOAD_ID=$(${MYSQL} --batch --silent --execute="
  SELECT id FROM car_loads WHERE team_id = ${EQUIPE2_ID} AND vehicle_id = ${NEW_VEHICLE_ID} LIMIT 1;
" | tail -1)

#if [[ -n "${EXISTING_NEW_CAR_LOAD_ID}" ]]; then
#    skip "Car load for Equipe 2 already exists (id=${EXISTING_NEW_CAR_LOAD_ID})."
#    NEW_CAR_LOAD_ID="${EXISTING_NEW_CAR_LOAD_ID}"
#else
#    ${MYSQL} --execute="
#      INSERT INTO car_loads
#        (name, load_date, return_date, status, comment,
#         previous_car_load_id, returned, team_id, vehicle_id, fixed_daily_cost,
#         created_at, updated_at)
#      SELECT
#        '${NEW_CAR_LOAD_NAME}',
#        NOW(),
#        '${FUTURE_DATE}',
#        'SELLING',
#        comment,
#        NULL,
#        0,
#        ${EQUIPE2_ID},
#        ${NEW_VEHICLE_ID},
#        fixed_daily_cost,
#        NOW(), NOW()
#      FROM car_loads
#      WHERE id = ${LATEST_CAR_LOAD_ID};
#    "
#    NEW_CAR_LOAD_ID=$(${MYSQL} --batch --silent --execute="SELECT LAST_INSERT_ID();" | tail -1)
#    success "Car load duplicated as '${NEW_CAR_LOAD_NAME}' (id=${NEW_CAR_LOAD_ID})."
#
#    info "Duplicating car load items (quantity_left = 200)..."
#    ${MYSQL} --execute="
#      INSERT INTO car_load_items
#        (car_load_id, product_id, quantity_loaded, quantity_left,
#         comment, loaded_at, cost_price_per_unit, from_previous_car_load_id, source,
#         created_at, updated_at)
#      SELECT
#        ${NEW_CAR_LOAD_ID},
#        product_id,
#        quantity_loaded,
#        200,
#        comment,
#        NOW(),
#        cost_price_per_unit,
#        NULL,
#        'warehouse',
#        NOW(), NOW()
#      FROM car_load_items
#      WHERE car_load_id = ${LATEST_CAR_LOAD_ID};
#    "
#    ITEM_COUNT=$(${MYSQL} --batch --silent --execute="SELECT COUNT(*) FROM car_load_items WHERE car_load_id = ${NEW_CAR_LOAD_ID};" | tail -1)
#    success "${ITEM_COUNT} car load items duplicated (quantity_left = 200)."
#fi

# ── Step 7: Generate API tokens ───────────────────────────────────────────────
info "Generating API token for user #3 (Georges Philippe Ndeye)..."
TOKEN_USER3=$(tinker_eval "echo (\App\Models\User::find(3))?->createToken('dev-token')->plainTextToken ?? 'USER_NOT_FOUND';")

info "Generating API token for Ablaye Diallo..."
TOKEN_ABLAYE=$(tinker_eval "
\$commercial = \App\Models\Commercial::find(${ABLAYE_COMMERCIAL_ID});
\$user = \$commercial?->user;
echo \$user?->createToken('dev-token')->plainTextToken ?? 'USER_NOT_FOUND';
")

echo ""
success "Token — user #3 (Georges Philippe Ndeye):"
echo -e "\033[1;33m  ${TOKEN_USER3}\033[0m"
echo ""
success "Token — Ablaye Diallo (id=${ABLAYE_COMMERCIAL_ID}):"
echo -e "\033[1;33m  ${TOKEN_ABLAYE}\033[0m"
echo ""
