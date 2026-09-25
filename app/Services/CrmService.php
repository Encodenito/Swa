<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Models\CustomerNote;
use App\Models\FollowUp;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff-side customer care: notes, tags and follow-ups. Customers never see any of it.
 */
class CrmService
{
    /** Staff may delete their own note within this window; admins any time. */
    public const NOTE_DELETE_MINUTES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function addNote(User $customer, string $body, User $staff): CustomerNote
    {
        $note = new CustomerNote(['body' => $body]);
        $note->forceFill(['user_id' => $customer->id, 'created_by' => $staff->id, 'created_at' => now()])->save();

        return $note;
    }

    public function deleteNote(CustomerNote $note, User $by): void
    {
        DB::transaction(function () use ($note, $by) {
            $this->audit->record('customer_note.deleted', $note, ['body' => Str::limit($note->body, 200), 'author' => $note->created_by], null, $by);
            $note->delete();
        });
    }

    public function createTag(string $name, string $colour, User $admin): Tag
    {
        return DB::transaction(function () use ($name, $colour, $admin) {
            $tag = Tag::query()->create(['name' => $name, 'slug' => Str::slug($name), 'colour' => $colour]);
            $this->audit->record('tag.created', $tag, null, ['name' => $tag->name], $admin);

            return $tag;
        });
    }

    public function attachTag(User $customer, Tag $tag, User $staff): void
    {
        DB::transaction(function () use ($customer, $tag, $staff) {
            if ($customer->tags()->whereKey($tag->id)->exists()) {
                return;
            }

            $customer->tags()->attach($tag->id, ['created_by' => $staff->id, 'created_at' => now()]);
            $this->audit->record('customer.tagged', $customer, null, ['tag' => $tag->name], $staff);
        });
    }

    public function detachTag(User $customer, Tag $tag, User $staff): void
    {
        DB::transaction(function () use ($customer, $tag, $staff) {
            if ($customer->tags()->detach($tag->id) > 0) {
                $this->audit->record('customer.untagged', $customer, ['tag' => $tag->name], null, $staff);
            }
        });
    }

    /**
     * @param  array{due_on: string, note: string, reservation_id?: int|null, assigned_to?: int|null}  $data
     */
    public function addFollowUp(User $customer, array $data, User $staff): FollowUp
    {
        $followUp = new FollowUp($data);
        $followUp->forceFill(['user_id' => $customer->id, 'created_by' => $staff->id])->save();

        return $followUp;
    }

    /**
     * @throws StateConflictException
     */
    public function completeFollowUp(FollowUp $followUp, User $staff): void
    {
        $done = FollowUp::query()
            ->whereKey($followUp->id)
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'completed_by' => $staff->id, 'updated_at' => now()]);

        if ($done !== 1) {
            throw new StateConflictException('This follow-up is already done.');
        }
    }
}
