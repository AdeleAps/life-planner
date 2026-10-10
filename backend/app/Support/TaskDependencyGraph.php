<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Validation\ValidationException;

class TaskDependencyGraph
{
    /** @param array<int, int> $dependencyIds */
    public function assertNoCycle(int $itemId, ?int $projectId, array $dependencyIds): void
    {
        $items = Task::query()->with('dependencies:id')->get(['id', 'project_id']);
        $edges = [];

        foreach ($items as $item) {
            $edges[$item->id] = $item->id === $itemId ? $dependencyIds : $item->dependencies->modelKeys();
        }

        foreach ($items as $item) {
            $parentId = $item->id === $itemId ? $projectId : $item->project_id;
            if ($parentId !== null) {
                $edges[$parentId][] = $item->id;
            }
        }

        $visiting = [];
        $visited = [];
        if ($this->hasCycle($itemId, $edges, $visiting, $visited)) {
            throw ValidationException::withMessages(['dependency_ids' => 'These dependencies would create a loop.']);
        }
    }

    /**
     * @param  array<int, array<int, int>>  $edges
     * @param  array<int, bool>  $visiting
     * @param  array<int, bool>  $visited
     */
    private function hasCycle(int $itemId, array $edges, array &$visiting, array &$visited): bool
    {
        if (isset($visiting[$itemId])) {
            return true;
        }
        if (isset($visited[$itemId])) {
            return false;
        }

        $visiting[$itemId] = true;
        foreach ($edges[$itemId] ?? [] as $dependencyId) {
            if ($this->hasCycle($dependencyId, $edges, $visiting, $visited)) {
                return true;
            }
        }
        unset($visiting[$itemId]);
        $visited[$itemId] = true;

        return false;
    }
}
