<?php

namespace App\Core\FlowShop\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DownloadModuleRequest extends FormRequest
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
            'family' => ['required', 'string', 'max:191'],
            'module_key' => ['required', 'string', 'max:191'],
            'version' => ['required', 'string', 'max:191'],
            'action' => ['nullable', 'string', 'in:install,reinstall,update'],
            'steps' => ['nullable', 'array'],
            'steps.dump_autoload' => ['nullable', 'boolean'],
            'steps.migrate' => ['nullable', 'boolean'],
            'steps.seed' => ['nullable', 'boolean'],
            'panel_id' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['nullable', 'integer'],
        ];
    }
}
