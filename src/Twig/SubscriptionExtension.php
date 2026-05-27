<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use App\Security\OrganizationProviderInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides trial_banner() for the app layout to show a persistent warning
 * when an org's trial is expiring soon or has already expired.
 */
final class SubscriptionExtension extends AbstractExtension
{
    private const int BANNER_THRESHOLD_DAYS = 7;

    public function __construct(
        private readonly OrganizationProviderInterface $organizationProvider,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('trial_banner', $this->trialBanner(...)),
        ];
    }

    /**
     * Returns trial banner data when the org is trialing and expiry is within
     * BANNER_THRESHOLD_DAYS days (or already past). Returns null otherwise.
     *
     * @return array{days_left: int, has_stripe: bool, expired: bool}|null
     */
    public function trialBanner(): ?array
    {
        $org = $this->organizationProvider->getOrganization();

        if ($org === null) {
            return null;
        }

        $subscription = $this->subscriptionRepository->findForOrg($org);

        if ($subscription === null || $subscription->getStatus() !== SubscriptionStatus::Trialing) {
            return null;
        }

        $trialEndsAt = $subscription->getTrialEndsAt();

        if ($trialEndsAt === null) {
            return null;
        }

        $secondsLeft = $trialEndsAt->getTimestamp() - time();
        $daysLeft = (int) ceil($secondsLeft / 86400);

        if ($daysLeft > self::BANNER_THRESHOLD_DAYS) {
            return null;
        }

        return [
            'days_left' => max(0, $daysLeft),
            'has_stripe' => $subscription->getStripeCustomerId() !== null,
            'expired' => $daysLeft <= 0,
        ];
    }
}
