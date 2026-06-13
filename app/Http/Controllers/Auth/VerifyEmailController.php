<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * 開通帳號：不需登入，點擊驗證信中的簽章連結即可。
     * 驗證完成導向登入頁（規格：Email 未驗證前禁止登入）。
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, '驗證連結無效');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->route('login')->with('status', 'Email 驗證完成，請登入系統。');
    }
}
