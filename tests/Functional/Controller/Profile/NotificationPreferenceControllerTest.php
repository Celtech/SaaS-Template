<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Profile;

use App\Entity\NotificationPreference;
use App\Enum\NotificationType;
use App\Repository\NotificationPreferenceRepository;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NotificationPreferenceControllerTest extends FunctionalTestCase
{
    #[Test]
    public function pageShowsTypeDefaultsWhenNoPreferencesAreSaved(): void
    {
        $user = $this->createUserWithOrg('notif-prefs-defaults@example.com');

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/notifications');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        foreach (NotificationType::cases() as $type) {
            $this->assertStringContainsString($type->description(), $content);
        }
    }

    #[Test]
    public function savingPreferencesPersistsExplicitRowsForEveryTypeAndChannel(): void
    {
        $user = $this->createUserWithOrg('notif-prefs-save@example.com');
        $this->client->loginUser($user);

        // Disable the default-on in_app+email for billing.payment_failed, leave everything else at its default (unchecked).
        $this->client->request('POST', '/profile/notifications', [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('profile_notifications')->getValue(),
            'preferences' => [],
        ]);

        $this->assertResponseRedirects('/profile/notifications');

        $repo = static::getContainer()->get(NotificationPreferenceRepository::class);

        // billing.payment_failed defaults to [in_app, email] — submitting nothing checked should now disable both.
        $inApp = $repo->findOneForUserTypeChannel($user, NotificationType::BillingPaymentFailed->value, 'in_app');
        $email = $repo->findOneForUserTypeChannel($user, NotificationType::BillingPaymentFailed->value, 'email');
        $this->assertNotNull($inApp);
        $this->assertNotNull($email);
        $this->assertFalse($inApp->isEnabled());
        $this->assertFalse($email->isEnabled());
    }

    #[Test]
    public function savingPreferencesCanOptIntoAChannelThatIsOffByDefault(): void
    {
        $user = $this->createUserWithOrg('notif-prefs-optin@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/notifications', [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('profile_notifications')->getValue(),
            'preferences' => [
                NotificationType::SecurityNewLogin->value => ['email' => '1'],
            ],
        ]);

        $this->assertResponseRedirects('/profile/notifications');

        $repo = static::getContainer()->get(NotificationPreferenceRepository::class);
        $preference = $repo->findOneForUserTypeChannel($user, NotificationType::SecurityNewLogin->value, 'email');
        $this->assertNotNull($preference);
        $this->assertTrue($preference->isEnabled());
    }

    #[Test]
    public function savedPreferencesArePersistedAcrossRequests(): void
    {
        $user = $this->createUserWithOrg('notif-prefs-reload@example.com');
        $this->em->persist(new NotificationPreference($user, NotificationType::OrgMemberInvited->value, 'in_app', false));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/notifications');

        $this->assertResponseIsSuccessful();
        // The unchecked in_app box for org.member_invited should not carry a "checked" attribute.
        $crawler = $this->client->getCrawler();
        $checkbox = $crawler->filter('input[name="preferences[' . NotificationType::OrgMemberInvited->value . '][in_app]"]');
        $this->assertCount(1, $checkbox);
        $this->assertNull($checkbox->attr('checked'));
    }
}
