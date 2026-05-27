<?php

declare(strict_types=1);

namespace App\Security\Enforcement;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A pluggable security gate evaluated on every authenticated request.
 *
 * The SecurityEnforcementSubscriber runs all registered enforcements in
 * priority order (lowest number first). Only the highest-priority failing
 * rule fires — no redirect chains, no loops.
 *
 * To add a new enforcement (e.g. org-level 2FA, billing gate):
 *   1. Implement this interface.
 *   2. The class is auto-tagged and wired into the enforcement chain.
 *   3. Declare getExemptRoutes() to prevent loops (your target page + any
 *      sub-pages needed to complete the flow).
 */
interface SecurityEnforcementInterface
{
    /**
     * Lower number = checked first. Use these bands:
     *   10  — critical security setup (must enroll 2FA)
     *   20  — step-up authentication (re-confirm identity for sensitive areas)
     *   30  — org-level policy gates (org-enforced 2FA)
     *   40  — business gates (billing expired, plan limit)
     */
    public function getPriority(): int;

    /**
     * Return true when this rule should redirect the current user away.
     * Keep this cheap — it runs on every request.
     */
    public function shouldEnforce(User $user, Request $request): bool;

    /**
     * The redirect to issue when this rule fires.
     * May store context (return URL, etc.) in the session before returning.
     */
    public function buildRedirectResponse(Request $request): RedirectResponse;

    /**
     * Route names that should be exempt from ALL enforcement rules, not only
     * this one. Include every route the user must be able to reach in order to
     * satisfy this rule (setup page, POST handler, success/confirmation pages).
     *
     * @return string[]
     */
    public function getExemptRoutes(): array;
}
