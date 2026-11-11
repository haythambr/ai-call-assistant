<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CallController extends Controller
{
    /**
     * The live call desk.
     */
    public function index(Request $request): View
    {
        return view('calls.index', [
            'aiDemoMode' => Call::usesDemoMode(),
            'calendarDemoMode' => ! Appointment::usesGoogleCalendar(),
            'selectedCall' => $request->integer('call') ?: null,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        return response()->json(Call::forDashboard($request->user()));
    }

    public function settings(): JsonResponse
    {
        return response()->json(Contact::callingSettings());
    }

    public function show(Call $call): JsonResponse
    {
        return response()->json($call->load(['appointment', 'campaign'])->toDashboardArray());
    }

    public function simulate(Request $request): JsonResponse
    {
        return response()->json(Call::simulate($request->user())->toDashboardArray(), 201);
    }

    public function retry(Call $call): JsonResponse
    {
        abort_unless($call->retry(), 422, 'Only failed calls can be retried.');

        return response()->json($call->toDashboardArray());
    }

    /**
     * Call one number: adds or updates the contact, then dials it.
     */
    public function callNumber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', 'string', 'max:180'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Confirm that this person agreed to be called.',
        ]);

        try {
            $contact = Contact::addOrUpdate($request->user(), $data, consentGiven: true);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        if ($reason = $contact->callBlocker()) {
            return response()->json(['message' => $reason], 422);
        }

        return response()->json(Call::dial($contact, $data['purpose'])->toDashboardArray(), 201);
    }
}
