<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard.index', [
            'stats' => Call::statsFor($user),
            'contactCount' => $user->contacts()->count(),
            'callableCount' => $user->contacts()->callable()->count(),
            'recentCalls' => $user->calls()->with('appointment')->latest('id')->limit(6)->get(),
            'activeCampaigns' => $user->campaigns()->whereIn('status', [Campaign::STATUS_SCHEDULED, Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED])->latest('id')->limit(4)->get(),
            'campaignCount' => $user->campaigns()->count(),
            'upcoming' => Appointment::whereHas('call', fn ($query) => $query->ownedBy($user))
                ->where('status', Appointment::STATUS_BOOKED)
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->with('call')
                ->limit(4)
                ->get(),
            'callingOpen' => Contact::callingHoursBlocker() === null,
            'demoMode' => Call::usesDemoMode() || ! Call::usesVapi(),
        ]);
    }
}
