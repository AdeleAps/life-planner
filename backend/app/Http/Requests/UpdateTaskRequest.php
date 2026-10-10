<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateTaskRequest extends StoreTaskRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $task = $this->route('task');

        $rules['client_request_id'] = ['prohibited'];
        $rules['revision'] = ['required', 'integer', 'min:1'];
        $rules['type'] = ['required', Rule::in(['day', 'evening'])];
        $rules['recurrence_type'] = ['required', Rule::in(['one_time', 'schedule', 'count'])];
        $rules['dependency_ids.*'] = [
            'integer',
            'distinct',
            Rule::notIn(array_filter([$task->id, $this->input('project_id')])),
            Rule::exists('tasks', 'id'),
        ];

        return $rules;
    }
}
