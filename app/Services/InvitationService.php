<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\Notification;

class InvitationService
{
    /**
     * Send a new invitation.
     *
     * @param  array{email: string, role: string}  $data
     */
    public function send(Workspace $workspace, User $inviter, array $data): Invitation
    {
        $email = $data['email'];

        if ($workspace->members()->where('users.email', $email)->exists()) {
            throw new ApiException('This user is already a member of this workspace.', 422);
        }

        $hasPending = $workspace->invitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasPending) {
            throw new ApiException('An invitation has already been sent to this email address.', 422);
        }

        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $data['role'],
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitationNotification($invitation));

        return $invitation;
    }

    /**
     * Accept an invitation by token.
     */
    public function accept(string $token, User $user): Invitation
    {
        $invitation = Invitation::query()
            ->with('workspace')
            ->where('token', $token)
            ->firstOrFail();

        if ($invitation->isAccepted()) {
            throw new ApiException('This invitation has already been accepted.', 422);
        }

        if ($invitation->isExpired()) {
            throw new ApiException('This invitation has expired.', 422);
        }

        if ($user->email !== $invitation->email) {
            throw new ApiException('This invitation was sent to a different email address.', 403);
        }

        if (! $invitation->workspace->members()->where('users.id', $user->id)->exists()) {
            $invitation->workspace->members()->attach($user->id, [
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        return $invitation;
    }

    /**
     * Decline an invitation by token.
     */
    public function decline(string $token, User $user): void
    {
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            throw new ApiException('This invitation has already been accepted.', 422);
        }

        if ($user->email !== $invitation->email) {
            throw new ApiException('You do not have permission to decline this invitation.', 403);
        }

        $invitation->delete();
    }
}
