<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;

class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => 'required|uuid|exists:classrooms,id',
            'subject'      => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'classroom_id.required' => 'Укажите класс',
            'classroom_id.exists'   => 'Класс не найден',
        ];
    }
}
