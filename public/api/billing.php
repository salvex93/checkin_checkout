<?php
declare(strict_types=1);

// =====================================================================
// billing.php — Endpoints de licenciamiento (placeholder agnostico).
//   GET  /admin/billing/plans           lista planes disponibles
//   GET  /admin/billing/subscription    estado actual del tenant
//   PUT  /admin/billing/subscription    cambia plan (solo manual por ahora)
//   POST /admin/billing/connect         placeholder para conectar pasarela
// La integracion real con Stripe/PayPal queda para una fase posterior.
// Aqui solo se gestiona el modelo de datos agnostico al proveedor.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/** GET /admin/billing/plans */
function admin_billing_plans(): never {
    require_super_admin();
    $rows = db_all('SELECT code, name, price_monthly_cents, currency, max_users, max_companies, features, sort_order
                      FROM subscription_plans
                     WHERE is_active = 1
                     ORDER BY sort_order ASC, price_monthly_cents ASC');
    $plans = array_map(fn($r) => [
        'code' => (string)$r['code'],
        'name' => (string)$r['name'],
        'price_monthly_cents' => (int)$r['price_monthly_cents'],
        'currency' => (string)$r['currency'],
        'max_users' => $r['max_users'] !== null ? (int)$r['max_users'] : null,
        'max_companies' => $r['max_companies'] !== null ? (int)$r['max_companies'] : null,
        'features' => $r['features'] !== null ? (string)$r['features'] : null,
        'sort_order' => (int)$r['sort_order'],
    ], $rows);
    ok(['plans' => $plans]);
}

/**
 * Lee la suscripcion id=1. Si no existe (instalacion vieja sin migracion)
 * devuelve un estado por defecto sin crear nada. Computa derived flags.
 */
function billing_load(): array {
    $row = db_one('SELECT s.plan_code, s.provider, s.provider_customer_id, s.provider_subscription_id,
                          s.status, s.current_period_start, s.current_period_end, s.cancel_at_period_end,
                          p.name AS plan_name, p.price_monthly_cents, p.currency,
                          p.max_users, p.max_companies, p.features
                     FROM subscriptions s
                     LEFT JOIN subscription_plans p ON p.code = s.plan_code
                    WHERE s.id = 1');
    if (!$row) {
        return [
            'plan_code' => 'free',
            'plan_name' => 'Trial',
            'provider' => 'none',
            'status' => 'trial',
            'connected' => false,
        ];
    }
    return [
        'plan_code' => (string)$row['plan_code'],
        'plan_name' => (string)($row['plan_name'] ?? 'Sin plan'),
        'price_monthly_cents' => isset($row['price_monthly_cents']) ? (int)$row['price_monthly_cents'] : 0,
        'currency' => (string)($row['currency'] ?? 'USD'),
        'max_users' => $row['max_users'] !== null ? (int)$row['max_users'] : null,
        'max_companies' => $row['max_companies'] !== null ? (int)$row['max_companies'] : null,
        'features' => $row['features'] !== null ? (string)$row['features'] : null,
        'provider' => (string)$row['provider'],
        'provider_customer_id' => $row['provider_customer_id'] !== null ? (string)$row['provider_customer_id'] : null,
        'status' => (string)$row['status'],
        'current_period_start' => $row['current_period_start'] !== null ? (string)$row['current_period_start'] : null,
        'current_period_end' => $row['current_period_end'] !== null ? (string)$row['current_period_end'] : null,
        'cancel_at_period_end' => (int)$row['cancel_at_period_end'] === 1,
        'connected' => $row['provider'] !== 'none' && $row['provider_customer_id'] !== null,
    ];
}

/** GET /admin/billing/subscription */
function admin_billing_subscription(): never {
    require_super_admin();
    ok(['subscription' => billing_load()]);
}

/**
 * PUT /admin/billing/subscription — cambio manual de plan.
 * Mientras no haya integracion de pasarela, super_admin puede declarar el
 * plan asignado. Cuando se conecte Stripe/PayPal, este endpoint validara
 * que el provider sea 'none' o 'manual' antes de aceptar.
 */
function admin_billing_subscription_update(array $body): never {
    require_csrf();
    $admin = require_super_admin();

    $planCode = validate_string($body, 'plan_code', 1, 40);
    if (!preg_match('/^[a-z0-9_-]+$/i', $planCode)) {
        err('INVALID_INPUT', 'plan_code invalido.', 400, ['field' => 'plan_code']);
    }
    if (!db_one('SELECT code FROM subscription_plans WHERE code = ? AND is_active = 1', [$planCode])) {
        err('INVALID_INPUT', 'Plan no existe o esta inactivo.', 400, ['field' => 'plan_code']);
    }

    // Solo permitimos cambio manual mientras no haya provider conectado.
    $current = db_one('SELECT provider FROM subscriptions WHERE id = 1');
    $currentProvider = $current['provider'] ?? 'none';
    if ($currentProvider !== 'none' && $currentProvider !== 'manual') {
        err('CONFLICT', 'Hay una pasarela conectada. Cancela la suscripcion en el proveedor antes de cambiar el plan manualmente.', 409);
    }

    if ($current) {
        db_exec("UPDATE subscriptions SET plan_code = ?, provider = 'manual', status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = 1",
            [$planCode]);
    } else {
        db_exec("INSERT INTO subscriptions (id, plan_code, provider, status) VALUES (1, ?, 'manual', 'active')",
            [$planCode]);
    }
    audit_log((int)$admin['id'], 'billing_plan_change', ['plan_code' => $planCode]);
    ok(['subscription' => billing_load(), 'message' => 'Plan actualizado.']);
}

/**
 * POST /admin/billing/connect — placeholder para conectar pasarela.
 * Por ahora solo valida el provider declarado y registra audit. Cuando se
 * implemente Stripe/PayPal, este endpoint redirigira al flujo OAuth/Setup.
 */
function admin_billing_connect(array $body): never {
    require_csrf();
    $admin = require_super_admin();

    $provider = validate_string($body, 'provider', 2, 20);
    if (!in_array($provider, ['stripe', 'paypal'], true)) {
        err('INVALID_INPUT', 'Provider invalido. Permitidos: stripe, paypal.', 400, ['field' => 'provider']);
    }

    // Por ahora retornamos NOT_IMPLEMENTED con un enlace placeholder. El frontend
    // muestra un dialogo explicando que la integracion esta pendiente.
    audit_log((int)$admin['id'], 'billing_connect_attempt', ['provider' => $provider]);
    err('NOT_IMPLEMENTED', "Integracion con {$provider} pendiente. Configura las credenciales en .env y un agente las activara.", 501, [
        'provider' => $provider,
        'next_steps' => [
            $provider === 'stripe'
                ? 'Crea cuenta en stripe.com → Developers → API keys. Configura STRIPE_SECRET_KEY y STRIPE_WEBHOOK_SECRET en .env.'
                : 'Crea cuenta en developer.paypal.com → My Apps → Create REST API. Configura PAYPAL_CLIENT_ID y PAYPAL_SECRET en .env.',
            'Avisa para activar la integracion con tus credenciales.',
        ],
    ]);
}
