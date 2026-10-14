<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

/**
 * Platform-wide settings managed from the admin panel (trial length, payment
 * instructions, support address…). Values are small strings; read once per
 * request.
 */
final class SettingsService
{
    public const EDITABLE = [
        'signup_enabled' => 'Allow new workspaces to sign up',
        'trial_days' => 'Free trial length (days)',
        'trial_plan' => 'Plan used during the trial',
        'grace_days' => 'Grace period after a subscription ends (days)',
        'currency' => 'Currency',
        'support_email' => 'Support e-mail address',
        'payment_instructions' => 'Payment instructions shown on the billing page',
    ];

    /** @var array<string,string|null>|null */
    private ?array $cache = null;

    public function __construct(private readonly App $app)
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $this->cache ??= $this->load();
        return $this->cache[$name] ?? $default;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->get($name);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->get($name);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,string|null> */
    public function all(): array
    {
        return $this->cache ??= $this->load();
    }

    public function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, self::EDITABLE)) {
            throw new \InvalidArgumentException('Unknown setting.');
        }
        $db = $this->app->db();
        if ($db->value('SELECT 1 FROM platform_settings WHERE name = ?', [$name])) {
            $db->update('platform_settings', ['value' => $value, 'updated_at' => now_utc()], ['name' => $name]);
        } else {
            $db->insert('platform_settings', ['name' => $name, 'value' => $value, 'updated_at' => now_utc()]);
        }
        $this->cache = null;
    }

    /** @return array<string,string|null> */
    private function load(): array
    {
        $out = [];
        foreach ($this->app->db()->fetchAll('SELECT name, value FROM platform_settings') as $row) {
            $out[$row['name']] = $row['value'];
        }
        return $out;
    }
}
