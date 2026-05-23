<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\FeatureFlagService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FeatureFlagExtension extends AbstractExtension
{
    public function __construct(private readonly FeatureFlagService $featureFlagService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('feature', $this->featureFlagService->isEnabled(...)),
        ];
    }
}
