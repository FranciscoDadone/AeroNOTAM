<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\WhatsappMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'airport' => ['nullable', 'string', 'max:8'],
            'topic' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'in:pending,answered,failed'],
            'unmatched' => ['nullable', 'in:1'],
        ]);

        return view('admin.messages.index', [
            'messages' => $this->query($filters),
            'filters' => $filters,
            'airports' => $this->airportOptions(),
            'topics' => WhatsappMessage::query()
                ->whereNotNull('topic')
                ->distinct()
                ->orderBy('topic')
                ->pluck('topic'),
        ]);
    }

    public function show(WhatsappMessage $message): View
    {
        return view('admin.messages.show', [
            'message' => $message->load('airport'),

            // The rest of the conversation. A single message rarely explains
            // itself: "y el TAF?" only makes sense next to what came before.
            'conversation' => WhatsappMessage::query()
                ->where('phone', $message->phone)
                ->where('id', '!=', $message->id)
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return LengthAwarePaginator<int, WhatsappMessage>
     */
    protected function query(array $filters): LengthAwarePaginator
    {
        return WhatsappMessage::query()
            ->with('airport')
            ->when(
                filled($filters['q'] ?? null),
                fn ($query) => $query->where(fn ($q) => $q
                    ->where('body', 'like', '%'.$filters['q'].'%')
                    ->orWhere('reply', 'like', '%'.$filters['q'].'%')
                    ->orWhere('profile_name', 'like', '%'.$filters['q'].'%')),
            )
            ->when(
                filled($filters['phone'] ?? null),
                // Matched loosely so the number can be pasted with or without
                // Twilio's "whatsapp:" prefix and the country code.
                fn ($query) => $query->where('phone', 'like', '%'.$filters['phone'].'%'),
            )
            ->when(
                filled($filters['airport'] ?? null),
                fn ($query) => $query->where('anac_code', strtoupper((string) $filters['airport'])),
            )
            ->when(filled($filters['topic'] ?? null), fn ($query) => $query->where('topic', $filters['topic']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(
                ($filters['unmatched'] ?? null) === '1',
                fn ($query) => $query
                    ->where('status', '!=', WhatsappMessage::STATUS_PENDING)
                    ->whereNull('anac_code')
                    ->where(fn ($q) => $q->whereNull('topic')->orWhere('topic', '!=', 'list')),
            )
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
    }

    /**
     * Only the aerodromes someone has actually asked about — the registry has
     * ~700 entries and a filter listing all of them is unusable.
     *
     * @return Collection<string, string>
     */
    protected function airportOptions(): Collection
    {
        $codes = WhatsappMessage::query()
            ->whereNotNull('anac_code')
            ->distinct()
            ->pluck('anac_code');

        return Airport::query()
            ->whereIn('anac_code', $codes)
            ->orderBy('name')
            ->pluck('name', 'anac_code');
    }
}
