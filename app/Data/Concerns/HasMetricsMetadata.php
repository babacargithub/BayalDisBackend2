<?php

namespace App\Data\Concerns;

use App\Attributes\MetricInfo;
use App\Data\MetricDefinition;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Provides self-describing metadata and serialisation for metric DTO classes.
 *
 * Any DTO that annotates its properties with #[MetricInfo] can use this trait to:
 *  - Expose a MetricDefinition list for each annotated property (used by API controllers
 *    so mobile clients can display metric labels and descriptions without hard-coding them).
 *  - Serialise all public properties to a snake_case array for JSON API responses.
 */
trait HasMetricsMetadata
{
    /**
     * Return a MetricDefinition for every property annotated with #[MetricInfo] on this DTO.
     *
     * @return MetricDefinition[]
     */
    public static function getMetricsMetadata(): array
    {
        $reflection = new ReflectionClass(static::class);
        $definitions = [];

        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $reflectionProperty = $reflection->getProperty($parameter->getName());
            $attributes = $reflectionProperty->getAttributes(MetricInfo::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var MetricInfo $metricInfo */
            $metricInfo = $attributes[0]->newInstance();

            $definitions[] = new MetricDefinition(
                property: Str::snake($parameter->getName()),
                label: $metricInfo->label,
                description: $metricInfo->description,
            );
        }

        return $definitions;
    }

    /**
     * Serialise all public properties to a snake_case array for JSON API responses.
     * Automatically reflects all public properties — no manual sync needed when adding fields.
     * Nested objects that implement toArray() are serialised recursively.
     */
    public function toSnakeCaseArray(): array
    {
        return collect(get_object_vars($this))
            ->mapWithKeys(fn ($value, $camelCaseKey) => [
                Str::snake($camelCaseKey) => is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value,
            ])
            ->all();
    }
}
