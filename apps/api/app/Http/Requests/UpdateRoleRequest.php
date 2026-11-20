<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Entrada;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        $roleId = $role instanceof Role ? $role->id : '';

        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$roleId],
            'permissions' => ['nullable', 'array', 'max:300'],
            'permissions.*' => ['string', 'max:100', 'distinct', 'exists:permissions,name'],
        ];
    }
}
