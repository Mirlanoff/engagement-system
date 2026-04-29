<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => [
                'required',
                'uuid',
                Rule::exists('classrooms', 'id')->where('school_id', $this->user()?->school_id),
            ],
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
