<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use CrowdinApiClient\Model\Project;
use Illuminate\Console\Command;

class ProjectService
{
    protected CrowdinApiService $apiService;

    protected Command $command;

    protected ?array $selectedProject = null;

    public function __construct(CrowdinApiService $apiService, Command $command)
    {
        $this->apiService = $apiService;
        $this->command = $command;
    }

    /**
     * Get all projects
     */
    public function getAllProjects(): array
    {
        $projects = collect([]);
        $page = 1;

        do {
            $response = $this->apiService->getClient()->project->list([
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $projects = $projects->merge(collect($response));
            $page++;
        } while (! $response->isEmpty());

        return $projects->map(function (Project $project) {
            return $project->getData();
        })->toArray();
    }

    /**
     * Select project
     */
    public function selectProject(?string $projectId = null): bool
    {
        $projects = $this->getAllProjects();

        if (empty($projects)) {
            $this->command->error('No projects found in your Crowdin account.');

            return false;
        }

        if (! empty($projectId)) {
            foreach ($projects as $project) {
                if ($project['id'] === (int) $projectId) {
                    $this->selectedProject = $project;
                    break;
                }
            }

            if (empty($this->selectedProject)) {
                $this->command->error("Project with ID {$projectId} not found.");

                return false;
            }
        } else {
            $projectChoices = [];
            foreach ($projects as $project) {
                $projectChoices[$project['id']] = "{$project['name']} ({$project['id']})";
            }

            $selectedChoice = $this->command->choice(
                'Select a project',
                $projectChoices
            );

            if (preg_match('/\((\d+)\)$/', $selectedChoice, $matches)) {
                $selectedProjectId = (int) $matches[1];
                foreach ($projects as $project) {
                    if ($project['id'] === $selectedProjectId) {
                        $this->selectedProject = $project;
                        break;
                    }
                }
            }

            if (empty($this->selectedProject)) {
                $this->command->error('Failed to find selected project.');

                return false;
            }
        }

        return true;
    }

    /**
     * Get selected project
     */
    public function getSelectedProject(): ?array
    {
        return $this->selectedProject;
    }

    /**
     * Get project ID
     */
    public function getProjectId(): ?int
    {
        return $this->selectedProject ? $this->selectedProject['id'] : null;
    }
}
