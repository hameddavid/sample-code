<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    public function index(Request $request)
    {
        $group = $request->query('group');

        $query = AppSetting::query();
        if ($group) {
            $query->where('group', $group);
        }

        return response()->json(['data' => $query->orderBy('group')->orderBy('key')->get()]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present|string',
        ]);

        foreach ($request->settings as $setting) {
            AppSetting::where('key', $setting['key'])->update(['value' => $setting['value']]);
        }

        AppSetting::clearCache();

        return response()->json(['message' => 'Settings updated.']);
    }
}
