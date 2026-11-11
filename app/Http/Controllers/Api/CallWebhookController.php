<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CallWebhookController extends Controller
{
    /**
     * Receive a finished call from the voice provider.
     */
    public function store(Request $request, ?string $token = null): JsonResponse
    {
        $user = null;

        if ($token !== null) {
            $user = User::where('webhook_token', $token)->first();
            abort_unless($user, 404, 'Unknown webhook URL.');
        }

        $data = $request->validate([
            'call_id' => ['required', 'string', 'max:191'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'started_at' => ['nullable', 'date'],
            'caller' => ['nullable', 'array'],
            'caller.name' => ['nullable', 'string', 'max:191'],
            'caller.phone' => ['nullable', 'string', 'max:50'],
            'caller.email' => ['nullable', 'email', 'max:191'],
            'transcript' => ['required', 'array', 'min:1', 'max:500'],
            'transcript.*.role' => ['required', 'in:agent,caller'],
            'transcript.*.text' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $call = Call::receiveFromWebhook($data, $user);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $call->id, 'status' => $call->status], 202);
    }
}
