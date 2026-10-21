<?php
declare(strict_types=1);

require_once __DIR__ . '/adapters/runtime_support.php';
require_once __DIR__ . '/adapters/pcv.php';

foreach (['mp.php', 'sas.php', 'correspondence.php'] as $module) {
    try {
        @include_once __DIR__ . '/adapters/' . $module;
    } catch (Throwable) {
    }
}

function qd_runtime_call_fixed_module_operation(array $operations, string $operation, stdClass $payload, array $context): mixed
{
    $callback = $operations[$operation] ?? null;
    if (!is_callable($callback)) {
        throw new QDServerFault('unavailable');
    }
    return $callback($payload, $context);
}

/** Inert zero-argument factory: all source/database work stays inside callbacks. */
function qd_department_runtime_dependencies(): array
{
    $operations = [
        'pcv.roster.read' => static fn(stdClass $payload, array $context): array => qd_pcv_roster_read($payload, $context),
        'pcv.status.read' => static fn(stdClass $payload, array $context): array => qd_pcv_status_read($payload, $context),
        'pcv.arm' => static fn(stdClass $payload, array $context): array => qd_pcv_arm($payload, $context),
        'pcv.end' => static fn(stdClass $payload, array $context): array => qd_pcv_end($payload, $context),
        'pcv.companion.stage' => static fn(stdClass $payload, array $context): array => qd_pcv_companion_stage($payload, $context),
        'pcv.companion.status' => static fn(stdClass $payload, array $context): array => qd_pcv_companion_status($payload, $context),
        'pcv.companion.cancel' => static fn(stdClass $payload, array $context): array => qd_pcv_companion_cancel($payload, $context),
        'mp.relationships.read' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_mp_runtime_operations'), 'mp.relationships.read', $payload, $context),
        'sas.issues.read' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_sas_runtime_operations'), 'sas.issues.read', $payload, $context),
        'correspondence.catalog.read' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_correspondence_runtime_operations'), 'correspondence.catalog.read', $payload, $context),
        'correspondence.mailbox.read' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_correspondence_runtime_operations'), 'correspondence.mailbox.read', $payload, $context),
        'correspondence.detail.read' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_correspondence_runtime_operations'), 'correspondence.detail.read', $payload, $context),
        'correspondence.compose' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_correspondence_runtime_operations'), 'correspondence.compose', $payload, $context),
        'correspondence.reconcile' => static fn(stdClass $payload, array $context): mixed => qd_runtime_call_fixed_module_operation(qd_runtime_module_operations('qd_correspondence_runtime_operations'), 'correspondence.reconcile', $payload, $context),
    ];

    return [
        'withProfileLease' => static fn(callable $callback): mixed => qd_runtime_with_profile_lease($callback),
        'currentScope' => static fn(string $pairId): string => qd_runtime_resolve_scope($pairId),
        'readiness' => static function (): array {
            $pcv = $mp = $sas = $correspondence = false;
            try { $pcv = qd_pcv_runtime_ready(); } catch (Throwable) { $pcv = false; }
            try { $mp = function_exists('qd_mp_runtime_ready') && qd_mp_runtime_ready() === true; } catch (Throwable) { $mp = false; }
            try { $sas = function_exists('qd_sas_runtime_ready') && qd_sas_runtime_ready() === true; } catch (Throwable) { $sas = false; }
            try { $correspondence = function_exists('qd_correspondence_runtime_ready') && qd_correspondence_runtime_ready() === true; } catch (Throwable) { $correspondence = false; }
            return [
                'pcv' => $pcv === true,
                'mp' => $mp,
                'sas' => $sas,
                'correspondence' => $correspondence,
            ];
        },
        'perspectives' => static function (string $scopeKey): array {
            $mp = ['choices' => [], 'limited' => true];
            $sas = ['choices' => [], 'limited' => true];
            try { if (function_exists('qd_mp_runtime_perspectives')) $mp = qd_mp_runtime_perspectives($scopeKey); } catch (Throwable) { $mp = ['choices' => [], 'limited' => true]; }
            try { if (function_exists('qd_sas_runtime_perspectives')) $sas = qd_sas_runtime_perspectives($scopeKey); } catch (Throwable) { $sas = ['choices' => [], 'limited' => true]; }
            return [
                'mp' => $mp,
                'sas' => $sas,
            ];
        },
        'operations' => $operations,
    ];
}
