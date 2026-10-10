<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateProjectRequest extends StoreProjectRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        $project = $this->route('project');

        $rules['client_request_id'] = ['prohibited'];
        $rules['revision'] = ['required', 'integer', 'min:1'];
        $rules['dependency_ids.*'] = [
            'integer',
            'distinct',
            Rule::notIn([$project->id]),
            Rule::exists('tasks', 'id'),
        ];

        return $rules;
    }
}
