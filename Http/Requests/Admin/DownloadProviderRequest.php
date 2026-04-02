<?php

namespace App\Core\FlowShop\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DownloadProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_key' => ['required', 'string', 'max:191'],
            'version' => ['required', 'string', 'max:191'],
            'panel_id' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['nullable', 'integer'],
        ];
    }
}
