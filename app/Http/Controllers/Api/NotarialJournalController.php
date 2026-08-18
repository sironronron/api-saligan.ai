<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotarialJournalEntryResource;
use App\Models\NotarialJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotarialJournalController extends Controller
{
    /**
     * The authenticated lawyer's notarial journal. The journal is the legal
     * register of every notarial act they performed; admins may read any
     * lawyer's entries for audit purposes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = NotarialJournalEntry::query()
            ->with('lawyer:id,name')
            ->latest('notarized_at');

        if (! $request->user()->is_admin) {
            $query->where('lawyer_id', $request->user()->id);
        } elseif ($request->filled('lawyer_id')) {
            $query->where('lawyer_id', $request->integer('lawyer_id'));
        }

        return NotarialJournalEntryResource::collection($query->paginate(50));
    }
}
