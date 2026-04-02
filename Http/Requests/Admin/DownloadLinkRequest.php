<?php

namespace App\Core\FlowShop\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DownloadLinkRequest extends FormRequest
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
            'panel_id' => ['required', 'string', 'max:191'],
            'customer_id' => ['required', 'integer'],
            'artifact_key' => ['required', 'string', 'max:191'],
            'version' => ['required', 'string', 'max:191'],
        ];
    }
}
