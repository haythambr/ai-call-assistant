<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Call;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings', [
            'user' => $request->user(),
            'integrations' => [
                ['name' => 'OpenAI', 'what' => 'Reads each call and writes the summary', 'live' => ! Call::usesDemoMode(), 'env' => 'OPENAI_API_KEY'],
                ['name' => 'Vapi', 'what' => 'Places real outbound phone calls', 'live' => Call::usesVapi(), 'env' => 'VAPI_API_KEY'],
                ['name' => 'Google Calendar', 'what' => 'Books agreed appointments', 'live' => Appointment::usesGoogleCalendar(), 'env' => 'GOOGLE_CALENDAR_ID'],
            ],
            'hours' => config('call_assistant.outbound.calling_hours'),
            'timezone' => config('call_assistant.timezone'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return back()->with('status', 'Settings saved.');
    }

    public function rotateWebhookToken(Request $request): RedirectResponse
    {
        $request->user()->rotateWebhookToken();

        return back()->with('status', 'New webhook URL created. Update it in your voice provider; the old one no longer works.');
    }
}
