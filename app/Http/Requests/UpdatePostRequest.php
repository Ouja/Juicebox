<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required_without:body', 'string', 'max:255'],
            'body' => ['required_without:title', 'string', 'max:10000'],
            'user_id' => ['prohibited'],
        ];
    }
}
