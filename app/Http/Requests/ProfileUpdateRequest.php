<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * 基本資料更新限姓名、性別、服務單位（Email 另走 updateEmail 流程）。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'integer', 'in:0,1,2'],
            'unit' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '姓名', 'gender' => '性別', 'unit' => '服務單位'];
    }
}
