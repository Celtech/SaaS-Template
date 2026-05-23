<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Organization;
use App\Service\EntitlementService;

/**
 * Base class for background jobs that operate on behalf of an organization.
 *
 * Extend this class and implement execute(). The run() method checks
 * subscription access before calling execute(), so the job is automatically
 * skipped for orgs with lapsed or canceled subscriptions.
 *
 * @example
 *   final class WebsiteHealthCheckJob extends AbstractOrgAwareJob
 *   {
 *       public function __construct(
 *           EntitlementService $entitlementService,
 *           private readonly HealthCheckService $healthChecker,
 *       ) {
 *           parent::__construct($entitlementService);
 *       }
 *
 *       protected function execute(Organization $org): void
 *       {
 *           $this->healthChecker->checkAll($org);
 *       }
 *   }
 */
abstract class AbstractOrgAwareJob
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {
    }

    final public function run(Organization $org): void
    {
        if (!$this->entitlementService->isOrgAccessible($org)) {
            return;
        }

        $this->execute($org);
    }

    abstract protected function execute(Organization $org): void;
}
