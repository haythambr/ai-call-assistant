<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        $campaigns = $request->user()->campaigns()->withCount('recipients')->latest('id')->paginate(12);

        return view('campaigns.index', ['campaigns' => $campaigns]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $preselected = collect($request->input('contacts', []))->map(fn ($id) => (int) $id)->filter()->all();
        $from = $request->filled('from') ? $user->campaigns()->find($request->integer('from')) : null;

        if ($from) {
            $preselected = $from->recipients()->pluck('contact_id')->all();
        }

        return view('campaigns.create', [
            'contacts' => $user->contacts()->orderByRaw('name is null')->orderBy('name')->limit(1000)->get(),
            'preselected' => $preselected,
            'tags' => $user->contactTags(),
            'callableCount' => $user->contacts()->callable()->count(),
            'purposes' => config('call_assistant.outbound.purposes'),
            'from' => $from,
            'timezone' => config('call_assistant.timezone'),
            'hours' => config('call_assistant.outbound.calling_hours'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:180'],
            'audience' => ['required', 'in:selected,tag,all'],
            'contact_ids' => ['required_if:audience,selected', 'array', 'max:1000'],
            'contact_ids.*' => ['integer'],
            'tag' => ['required_if:audience,tag', 'nullable', 'string', 'max:30'],
            'when' => ['required', 'in:now,later'],
            'scheduled_at' => ['required_if:when,later', 'nullable', 'date_format:Y-m-d\TH:i'],
        ], [
            'contact_ids.required_if' => 'Pick at least one contact to call.',
            'tag.required_if' => 'Pick the tag of the contacts to call.',
            'scheduled_at.required_if' => 'Pick the date and time to start calling.',
        ]);

        $contacts = $this->audience($user->contacts()->getQuery(), $data);

        if ($contacts->isEmpty()) {
            throw ValidationException::withMessages(['audience' => 'Nobody matches this choice yet. Pick other contacts.']);
        }

        $scheduledAt = null;
        if ($data['when'] === 'later') {
            try {
                $scheduledAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], config('call_assistant.timezone'));
            } catch (Throwable) {
                $scheduledAt = null;
            }

            if (! $scheduledAt || $scheduledAt->isPast()) {
                throw ValidationException::withMessages(['scheduled_at' => 'Pick a time in the future.']);
            }
        }

        $campaign = Campaign::launch($user, $data['name'], $data['purpose'], $contacts, $scheduledAt);

        return redirect()->route('campaigns.show', $campaign)->with('status', $scheduledAt
            ? "“{$campaign->name}” is scheduled for {$scheduledAt->format('D, M j \a\t g:i A')}."
            : "“{$campaign->name}” is calling now.");
    }

    public function show(Request $request, Campaign $campaign): View|string
    {
        $campaign->resumeIfStalled();

        $recipients = $campaign->recipients()
            ->with(['contact', 'call.appointment'])
            ->orderByRaw("case status when 'called' then 0 when 'pending' then 1 else 2 end")
            ->orderBy('updated_at', 'desc')
            ->paginate(50);

        $view = view('campaigns.show', [
            'campaign' => $campaign,
            'progress' => $campaign->progress(),
            'recipients' => $recipients,
            // One bar per person, in calling order, for the campaign's "voice track".
            'track' => $campaign->recipients()->with('call.appointment')->orderBy('id')->limit(300)->get()
                ->map(fn ($recipient) => $recipient->resultKey()),
            'inFlight' => $campaign->hasCallsInFlight(),
        ]);

        // Live refresh only needs the part of the page that changes.
        return $request->boolean('fragment') ? $view->fragment('live') : $view;
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        return $this->control($campaign->pause(), 'Paused. Nobody else will be called until you resume.', 'This campaign is not calling right now.');
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        return $this->control($campaign->resume(), 'Resumed. Calling picks up where it stopped.', 'Only a paused campaign can be resumed.');
    }

    public function startNow(Campaign $campaign): RedirectResponse
    {
        return $this->control($campaign->startNow(), 'Started. Calling begins now.', 'This campaign has already started.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        return $this->control($campaign->cancel(), 'Cancelled. Nobody else will be called.', 'This campaign has already ended.');
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        if ($campaign->isActive()) {
            return back()->withErrors(['campaign' => 'Cancel the campaign before deleting it.']);
        }

        $name = $campaign->name;
        $campaign->delete();

        return redirect()->route('campaigns.index')->with('status', "“{$name}” was deleted. Its calls are kept on the Calls page.");
    }

    /**
     * @return Collection<int, Contact>
     */
    private function audience($query, array $data): Collection
    {
        return match ($data['audience']) {
            'selected' => $query->whereKey($data['contact_ids'] ?? [])->get(),
            'tag' => $query->whereJsonContains('tags', $data['tag'])->get(),
            default => $query->whereNotNull('consent_at')->whereNull('opted_out_at')->get(),
        };
    }

    private function control(bool $done, string $success, string $failure): RedirectResponse
    {
        return $done ? back()->with('status', $success) : back()->withErrors(['campaign' => $failure]);
    }
}
