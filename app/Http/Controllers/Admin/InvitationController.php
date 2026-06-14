<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 使用者邀請（限系統管理者）：輸入 Email 寄出註冊邀請。
 */
class InvitationController extends Controller
{
    public function index(): Response
    {
        $invitations = Invitation::with('invitedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'invited_by' => $i->invitedBy?->name,
                'expires_at' => $i->expires_at->toDateTimeString(),
                'accepted_at' => $i->accepted_at?->toDateTimeString(),
                'status' => $i->isAccepted() ? 'accepted' : ($i->isExpired() ? 'expired' : 'pending'),
            ]);

        return Inertia::render('Admin/Invitations/Index', [
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
        ], [
            'email.unique' => '此 Email 已有註冊帳號。',
        ], ['email' => 'Email']);

        // 同一 Email 已有未完成邀請 → 不重複建立，請改用「重寄」
        if (Invitation::where('email', $request->email)->pending()->exists()) {
            return back()->withErrors(['email' => '此 Email 已有未完成的邀請，請於下方列表點「重寄」。']);
        }

        $invitation = Invitation::create([
            'email' => $request->email,
            'token' => Invitation::generateToken(),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ]);

        $this->send($invitation);

        return back()->with('status', "已寄出註冊邀請至 {$invitation->email}。");
    }

    /** 重寄：更新 token 與到期時間後再次寄出 */
    public function resend(Invitation $invitation): RedirectResponse
    {
        if ($invitation->isAccepted()) {
            return back()->withErrors(['invitation' => '此邀請已被接受，無法重寄。']);
        }

        $invitation->update([
            'token' => Invitation::generateToken(),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ]);

        $this->send($invitation);

        return back()->with('status', "已重新寄出邀請至 {$invitation->email}。");
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('status', '邀請已刪除。');
    }

    private function send(Invitation $invitation): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new UserInvitationNotification($invitation));
    }
}
