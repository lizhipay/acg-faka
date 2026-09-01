<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class PriceTemplate extends Model
{
    public const BASE_FACTORY = 0;
    public const BASE_PRICE = 1;

    public const TYPE_FIXED = 0;
    public const TYPE_PERCENT = 1;

    public const SHARED_PREMIUM_TYPE = 2;

    public const ROUNDING_NONE = 0;
    public const ROUNDING_ROUND = 1;
    public const ROUNDING_CEIL = 2;

    protected $table = "price_template";

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'base' => 'integer',
        'guest_type' => 'integer',
        'guest_value' => 'float',
        'user_type' => 'integer',
        'user_value' => 'float',
        'rounding' => 'integer',
    ];

    public static function apply(float $basePrice, int $type, float $value): string
    {
        $base = new \Kernel\Util\Decimal(sprintf('%.2f', $basePrice), 2);
        $result = $type === self::TYPE_PERCENT
            ? $base->add($base->mul(sprintf('%.4f', $value / 100))->getAmount())
            : $base->add(sprintf('%.2f', $value));
        $amount = $result->getAmount();

        return (float)$amount < 0 ? '0.00' : $amount;
    }

    public static function round(string $amount, int $rounding): string
    {
        return match ($rounding) {
            self::ROUNDING_ROUND => sprintf('%.2f', round((float)$amount)),
            self::ROUNDING_CEIL => sprintf('%.2f', ceil((float)$amount)),
            default => $amount,
        };
    }

    public static function applyToConfig(string $config, int $type, float $value, int $rounding, bool $useFactoryBase = false): string
    {
        if (trim($config) === '') {
            return $config;
        }

        try {
            $parsed = \App\Util\Ini::toArray($config);
        } catch (\Throwable $e) {
            return $config;
        }

        $priced = static fn($amount): string => self::round(self::apply((float)$amount, $type, $value), $rounding);

        $base = static function (array &$parsed, string $factorySection, array $path, $amount) use ($useFactoryBase) {
            if (!$useFactoryBase) {
                return $amount;
            }
            $known = $parsed[$factorySection] ?? null;
            foreach ($path as $step) {
                $known = is_array($known) ? ($known[$step] ?? null) : null;
            }
            if (is_numeric($known)) {
                return $known;
            }
            $slot = &$parsed[$factorySection];
            foreach ($path as $step) {
                if (!isset($slot[$step]) || !is_array($slot[$step])) {
                    $slot[$step] = [];
                }
                $slot = &$slot[$step];
            }
            $slot = $amount;
            unset($slot);
            return $amount;
        };

        foreach (['category' => 'category_factory', 'wholesale' => 'wholesale_factory'] as $section => $factorySection) {
            if (!isset($parsed[$section]) || !is_array($parsed[$section])) {
                continue;
            }
            foreach ($parsed[$section] as $key => $amount) {
                if (!is_numeric($amount)) {
                    continue;
                }
                $parsed[$section][$key] = $priced($base($parsed, $factorySection, [$key], $amount));
            }
        }

        if (isset($parsed['category_wholesale']) && is_array($parsed['category_wholesale'])) {
            foreach ($parsed['category_wholesale'] as $race => $ladder) {
                if (!is_array($ladder)) {
                    continue;
                }
                foreach ($ladder as $num => $amount) {
                    if (!is_numeric($amount)) {
                        continue;
                    }
                    $parsed['category_wholesale'][$race][$num] =
                        $priced($base($parsed, 'category_wholesale_factory', [$race, $num], $amount));
                }
            }
        }

        if ($type === self::TYPE_PERCENT && isset($parsed['sku']) && is_array($parsed['sku'])) {
            foreach ($parsed['sku'] as $group => $options) {
                if (!is_array($options)) {
                    continue;
                }
                foreach ($options as $option => $amount) {
                    if (!is_numeric($amount) || (float)$amount <= 0) {
                        continue;
                    }
                    $parsed['sku'][$group][$option] =
                        $priced($base($parsed, 'sku_factory', [$group, $option], $amount));
                }
            }
        }

        try {
            return \App\Util\Ini::toConfig($parsed);
        } catch (\Throwable $e) {
            return $config;
        }
    }

    public static function mergeLevelPrice(string $original, array $levels, int $rounding, bool $useFactoryBase): string
    {
        if ($levels === []) {
            return $original;
        }

        $config = json_decode($original, true);
        if (!is_array($config)) {
            $config = [];
        }

        foreach ($levels as $groupId => $computed) {
            $existing = is_array($config[$groupId] ?? null) ? $config[$groupId] : [];
            $existing['amount'] = (float)$computed['amount'];
            if (isset($existing['config']) && is_string($existing['config']) && trim($existing['config']) !== '') {
                $existing['config'] = self::applyToConfig(
                    $existing['config'],
                    $computed['rule']['type'],
                    $computed['rule']['value'],
                    $rounding,
                    $useFactoryBase
                );
            }
            $config[$groupId] = $existing;
        }

        return (string)json_encode($config, JSON_UNESCAPED_UNICODE);
    }

    public function forShared(string $config, string $price, string $userPrice, string $levelPrice = ''): array
    {
        $base = (float)$price;
        $round = fn(string $amount): string => self::round($amount, $this->rounding);

        $levels = [];
        if ($base > 0) {
            foreach ($this->levelRules() as $groupId => $rule) {
                $levels[$groupId] = [
                    'amount' => $round(self::apply($base, $rule['type'], $rule['value'])),
                    'rule' => $rule,
                ];
            }
        }

        return [
            'price' => $base > 0
                ? $round(self::apply($base, $this->guest_type, (float)$this->guest_value))
                : sprintf('%.2f', $base),
            'user_price' => $base > 0
                ? $round(self::apply($base, $this->user_type, (float)$this->user_value))
                : sprintf('%.2f', (float)$userPrice),
            'config' => self::applyToConfig($config, $this->guest_type, (float)$this->guest_value, $this->rounding, false),
            'level_price' => self::mergeLevelPrice($levelPrice, $levels, $this->rounding, false),
        ];
    }

    public function markupExtra(float $amount): string
    {
        if ($amount <= 0) {
            return '0.00';
        }

        return $this->guest_type === self::TYPE_PERCENT
            ? self::round(self::apply($amount, self::TYPE_PERCENT, (float)$this->guest_value), $this->rounding)
            : sprintf('%.2f', $amount);
    }

    public function levelRules(): array
    {
        $raw = json_decode((string)$this->level_config, true);
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $groupId => $rule) {
            if (!is_array($rule) || !isset($rule['value']) || !is_numeric($rule['value'])) {
                continue;
            }
            $groupId = (int)$groupId;
            if ($groupId <= 0) {
                continue;
            }
            $rules[$groupId] = [
                'type' => (int)($rule['type'] ?? self::TYPE_PERCENT) === self::TYPE_FIXED
                    ? self::TYPE_FIXED
                    : self::TYPE_PERCENT,
                'value' => (float)$rule['value'],
            ];
        }
        return $rules;
    }
}
