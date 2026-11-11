<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:'.implode(',', [Contact::STATUS_CALLABLE, Contact::STATUS_NO_CONSENT, Contact::STATUS_OPTED_OUT])],
        ]);

        $contacts = $user->contacts()
            ->search($filters['q'] ?? null)
            ->tagged($filters['tag'] ?? null)
            ->withStatus($filters['status'] ?? null)
            ->orderByRaw('name is null')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('contacts.index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'tags' => $user->contactTags(),
            'counts' => [
                'all' => $user->contacts()->count(),
                Contact::STATUS_CALLABLE => $user->contacts()->withStatus(Contact::STATUS_CALLABLE)->count(),
                Contact::STATUS_NO_CONSENT => $user->contacts()->withStatus(Contact::STATUS_NO_CONSENT)->count(),
                Contact::STATUS_OPTED_OUT => $user->contacts()->withStatus(Contact::STATUS_OPTED_OUT)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('contacts.form', ['contact' => null, 'tags' => $request->user()->contactTags()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contact = $this->save($request);

        return redirect()->route('contacts.show', $contact)->with('status', "{$contact->label()} was added.");
    }

    public function show(Request $request, Contact $contact): View
    {
        return view('contacts.show', [
            'contact' => $contact,
            'calls' => $contact->calls()->with(['appointment', 'campaign'])->latest('id')->limit(20)->get(),
            'campaigns' => $contact->campaigns()->latest('campaigns.id')->limit(10)->get(),
            'purposes' => config('call_assistant.outbound.purposes'),
            'blocker' => $contact->callBlocker(),
        ]);
    }

    public function edit(Request $request, Contact $contact): View
    {
        return view('contacts.form', ['contact' => $contact, 'tags' => $request->user()->contactTags()]);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->save($request, $contact);

        return redirect()->route('contacts.show', $contact)->with('status', 'Changes saved.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $label = $contact->label();
        $contact->delete();

        return redirect()->route('contacts.index')->with('status', "{$label} was deleted. Their past calls are kept.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['integer'],
        ]);

        $deleted = $request->user()->contacts()->whereKey($data['contact_ids'])->delete();

        return back()->with('status', $deleted === 1 ? '1 contact was deleted.' : "{$deleted} contacts were deleted.");
    }

    public function importForm(Request $request): View
    {
        return view('contacts.import', ['tags' => $request->user()->contactTags()]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'tags' => ['nullable', 'string', 'max:200'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Confirm that everyone on the list agreed to be called.',
            'file.required' => 'Choose a CSV file to upload.',
            'file.mimes' => 'Upload a .csv file. In Excel or Google Sheets use File > Download > CSV.',
        ]);

        try {
            $report = Contact::importCsv(
                $request->user(),
                $request->file('file')->getRealPath(),
                consentGiven: true,
                addTags: Contact::parseTags($data['tags'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('contacts.import')->with('import_report', $report + [
            'file' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        return response()->streamDownload(function () use ($user) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names in any language correctly

            foreach (Contact::exportRows($user) as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }

            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Call one saved contact now.
     */
    public function call(Request $request, Contact $contact): RedirectResponse
    {
        $data = $request->validate(['purpose' => ['required', 'string', 'max:180']]);

        if ($reason = $contact->callBlocker()) {
            return back()->withErrors(['call' => $reason]);
        }

        $call = Call::dial($contact, $data['purpose']);

        return redirect()->route('calls.index', ['call' => $call->id])->with('status', "Calling {$contact->label()}…");
    }

    public function optOut(Contact $contact): RedirectResponse
    {
        $contact->optOut();

        return back()->with('status', "{$contact->label()} will not be called again.");
    }

    private function save(Request $request, ?Contact $contact = null): Contact
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'tags' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['consent'] = $request->boolean('consent');

        try {
            return Contact::saveFromForm($request->user(), $data, $contact);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }
    }
}
