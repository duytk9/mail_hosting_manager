<?php

declare(strict_types=1);

namespace MailPanel\Services;

final class ConfigValidatorService
{
    public function __construct(private readonly SystemCommandService $systemCommandService)
    {
    }

    public function validationPlan(array $services): array
    {
        $plan = [];

        foreach ($services as $service => $renderedPath) {
            if (is_int($service)) {
                $service = (string) $renderedPath;
                $renderedPath = null;
            }

            $plan[] = $this->systemCommandService->validateConfigAction((string) $service, is_string($renderedPath) ? $renderedPath : null);
        }

        return $plan;
    }
}
