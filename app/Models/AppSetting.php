<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'group'];

    public $timestamps = true;

    public static function get(string $key, string $default = ''): string
    {
        $settings = Cache::remember('app_settings', 300, function () {
            return static::pluck('value', 'key')->toArray();
        });

        return $settings[$key] ?? $default;
    }

    public static function getGroup(string $group): array
    {
        $settings = Cache::remember('app_settings', 300, function () {
            return static::all()->groupBy('group')->map(fn ($items) =>
                $items->pluck('value', 'key')->toArray()
            )->toArray();
        });

        return $settings[$group] ?? [];
    }

    public static function set(string $key, string $value): void
    {
        static::where('key', $key)->update(['value' => $value]);
        Cache::forget('app_settings');
    }

    public static function clearCache(): void
    {
        Cache::forget('app_settings');
    }
}
