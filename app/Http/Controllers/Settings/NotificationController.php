<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * @return array{invites: bool, plan_limits: bool, monitoring: bool}
     */
    public static function defaults(): array
    {
        return ['invites' => true, 'plan_limits' => true, 'monitoring' => true];
    }

    public function edit(): Response
    {
        $stored = request()->user()?->notification_preferences;

        return Inertia::render('settings/Notifications', [
            'preferences' => array_merge(self::defaults(), array_intersect_key($stored ?? [], self::defaults())),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invites' => ['required', 'boolean'],
            'plan_limits' => ['required', 'boolean'],
            'monitoring' => ['required', 'boolean'],
        ]);

        $request->user()?->forceFill(['notification_preferences' => $validated])->save();

        return back()->with('status', 'Preferências atualizadas.');
    }
}
