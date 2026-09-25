<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Models\ChatHandoff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chat visitors who asked for a person. Staff claim (exactly one wins) and close with a note;
 * the reply itself happens outside the app (phone or email).
 */
class ChatHandoffService
{
    public const OPEN = 'open';

    public const CLAIMED = 'claimed';

    public const CLOSED = 'closed';

    /**
     * @param  list<array{from: string, text: string}>  $transcript
     */
    public function create(string $name, string $contact, string $message, array $transcript, ?User $user): ChatHandoff
    {
        // One waiting request per person: a second one only buries the queue.
        $alreadyWaiting = ChatHandoff::query()
            ->whereIn('status', [self::OPEN, self::CLAIMED])
            ->where(fn ($q) => $q->where('contact', $contact)->when($user, fn ($u) => $u->orWhere('user_id', $user->id)))
            ->exists();

        if ($alreadyWaiting) {
            throw new StateConflictException('We already have your request and will contact you soon.');
        }

        $handoff = new ChatHandoff;
        $handoff->forceFill([
            'name' => $name,
            'contact' => $contact,
            'message' => $message,
            'transcript' => $transcript,
            // Only from the session, never from the request.
            'user_id' => $user?->id,
            'status' => self::OPEN,
        ])->save();

        return $handoff;
    }

    /**
     * @throws StateConflictException
     */
    public function claim(ChatHandoff $handoff, User $staff): void
    {
        $claimed = ChatHandoff::query()
            ->whereKey($handoff->id)
            ->where('status', self::OPEN)
            ->update(['status' => self::CLAIMED, 'claimed_by' => $staff->id, 'claimed_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new StateConflictException('Someone else already took this chat.');
        }

        $handoff->refresh();
    }

    /**
     * @throws StateConflictException
     */
    public function close(ChatHandoff $handoff, string $replyNote, User $staff): void
    {
        $closed = ChatHandoff::query()
            ->whereKey($handoff->id)
            ->whereIn('status', [self::OPEN, self::CLAIMED])
            ->where(fn ($q) => $q->whereNull('claimed_by')->orWhere('claimed_by', $staff->id)->when($staff->isAdmin(), fn ($a) => $a->orWhereNotNull('claimed_by')))
            ->update([
                'status' => self::CLOSED,
                'closed_at' => now(),
                'reply_note' => mb_substr($replyNote, 0, 500),
                // Keep whoever claimed it (read from the row, not the possibly stale page).
                'claimed_by' => DB::raw('COALESCE(claimed_by, '.(int) $staff->id.')'),
                'updated_at' => now(),
            ]);

        if ($closed !== 1) {
            throw new StateConflictException('This chat is closed already, or another staff member is handling it.');
        }

        $handoff->refresh();
    }
}
