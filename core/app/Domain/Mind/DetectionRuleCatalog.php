<?php

namespace App\Domain\Mind;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\DetectionRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Effective detection rules for the current organisation: built-in rules
 * merged with that organisation's overrides (enabled flag, parameters).
 */
final class DetectionRuleCatalog
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{key: string, name: string, description: string, category: string, severity: string, enabled: bool, parameters: array<string, float|int>, defaults: array<string, float|int>, bounds: array<string, array{min: float|int, max: float|int}>, customised: bool}>
     */
    public function effective(): array
    {
        $overrides = DetectionRule::query()
            ->where('organisation_id', $this->tenant->organisationId())
            ->get()
            ->keyBy('key');

        $rules = [];
        foreach (DetectionRule::query()->builtIn()->orderBy('key')->get() as $builtIn) {
            $config = config("kratos.detection_rules.{$builtIn->key}");
            if ($config === null) {
                continue;
            }
            $override = $overrides->get($builtIn->key);

            $rules[] = [
                'key' => $builtIn->key,
                'name' => $builtIn->name,
                'description' => $builtIn->description,
                'category' => $builtIn->category,
                'severity' => $builtIn->severity,
                'enabled' => $override?->enabled ?? $builtIn->enabled,
                'parameters' => array_merge($builtIn->parameters, $override?->parameters ?? []),
                'defaults' => $builtIn->parameters,
                'bounds' => array_map(fn (array $p) => ['min' => $p['min'], 'max' => $p['max']], $config['parameters']),
                'customised' => $override !== null,
            ];
        }

        return $rules;
    }

    /**
     * The rule configuration sent to KRATOS MIND with each analysis.
     *
     * @return list<array{key: string, enabled: bool, severity: string, parameters: array<string, float|int>}>
     */
    public function forAnalysis(): array
    {
        return array_map(fn (array $r) => [
            'key' => $r['key'],
            'enabled' => $r['enabled'],
            'severity' => $r['severity'],
            'parameters' => (object) $r['parameters'],
        ], $this->effective());
    }

    /**
     * @param  array{enabled?: bool, parameters?: array<string, float|int>}  $changes
     */
    public function update(string $key, array $changes, User $actor): array
    {
        $config = config("kratos.detection_rules.{$key}") ?? throw new NotFoundHttpException;
        $builtIn = DetectionRule::query()->builtIn()->where('key', $key)->firstOrFail();

        $parameters = $changes['parameters'] ?? [];
        $errors = [];
        foreach ($parameters as $name => $value) {
            $bounds = $config['parameters'][$name] ?? null;
            if ($bounds === null) {
                $errors["parameters.{$name}"][] = 'Unknown parameter for this rule.';
            } elseif (! is_numeric($value) || $value < $bounds['min'] || $value > $bounds['max']) {
                $errors["parameters.{$name}"][] = "Must be a number between {$bounds['min']} and {$bounds['max']}.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $override = DetectionRule::query()->firstOrNew(['organisation_id' => $this->tenant->organisationId(), 'key' => $key]);
        $before = ['enabled' => $override->exists ? $override->enabled : $builtIn->enabled, 'parameters' => $override->parameters ?? []];

        $merged = array_merge($override->parameters ?? [], array_map(fn ($v) => $v + 0, $parameters));
        $effective = array_merge($builtIn->parameters, $merged);
        if (isset($effective['business_start_hour'], $effective['business_end_hour']) && $effective['business_start_hour'] >= $effective['business_end_hour']) {
            throw ValidationException::withMessages(['parameters.business_end_hour' => ['Business hours must end after they start.']]);
        }

        $override->fill([
            'name' => $builtIn->name,
            'description' => $builtIn->description,
            'category' => $builtIn->category,
            'severity' => $builtIn->severity,
            'enabled' => $changes['enabled'] ?? $before['enabled'],
            'parameters' => $merged,
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('detection_rule.updated', $override, [
            'key' => $key,
            'before' => $before,
            'after' => ['enabled' => $override->enabled, 'parameters' => $override->parameters],
        ]);

        return collect($this->effective())->firstWhere('key', $key);
    }

    public function reset(string $key): void
    {
        $deleted = DetectionRule::query()
            ->where('organisation_id', $this->tenant->organisationId())
            ->where('key', $key)
            ->delete();

        if ($deleted > 0) {
            $this->audit->record('detection_rule.reset', null, ['key' => $key]);
        }
    }
}
