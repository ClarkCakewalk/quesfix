<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * 註冊：第一位註冊者為系統管理者；註冊後不自動登入，
     * 需點擊驗證信連結開通帳號後才能登入。
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_num:ascii', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'integer', 'in:0,1,2'],
            'unit' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'confirmed', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [], [
            'username' => '登入帳號',
            'name' => '姓名',
            'gender' => '性別',
            'unit' => '服務單位',
            'email' => 'Email',
            'password' => '密碼',
        ]);

        $user = DB::transaction(function () use ($request) {
            $isFirstUser = User::lockForUpdate()->count() === 0;

            $user = User::create([
                'username' => $request->username,
                'name' => $request->name,
                'gender' => $request->gender,
                'unit' => $request->unit,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            if ($isFirstUser) {
                $user->forceFill(['role' => User::ROLE_ADMIN])->save();
            }

            return $user;
        });

        event(new Registered($user));

        return redirect()->route('login')
            ->with('status', '註冊完成！系統已寄出確認信，請點擊信中連結開通帳號後再登入。');
    }
}
