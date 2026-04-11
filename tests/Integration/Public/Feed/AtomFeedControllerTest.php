<?php

declare(strict_types=1);

namespace Shared\Tests\Integration\Public\Feed;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Shared\Domain\Publication\Dossier\DossierStatus;
use Shared\Tests\Factory\Publication\Dossier\Type\Covenant\CovenantFactory;
use Shared\Tests\Factory\Publication\Dossier\Type\InvestigationReport\InvestigationReportFactory;
use Shared\Tests\Factory\Publication\Dossier\Type\WooDecision\WooDecisionFactory;
use Shared\Tests\Integration\SharedWebTestCase;

use function strpos;

final class AtomFeedControllerTest extends SharedWebTestCase
{
    public function testAtomFeedReturnsSuccessfulResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');
    }

    public function testAtomFeedContainsPublishedDossiers(): void
    {
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Test Convenant Feed Entry',
        ]);
        WooDecisionFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Test WooDecision Feed Entry',
        ]);

        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<entry>', $content);
        self::assertStringContainsString('Test Convenant Feed Entry', $content);
        self::assertStringContainsString('Test WooDecision Feed Entry', $content);
    }

    public function testAtomFeedExcludesNonPublishedDossiers(): void
    {
        CovenantFactory::createOne([
            'status' => DossierStatus::CONCEPT,
            'title' => 'Concept Dossier Should Not Appear',
        ]);
        CovenantFactory::createOne([
            'status' => DossierStatus::SCHEDULED,
            'title' => 'Scheduled Dossier Should Not Appear',
        ]);
        CovenantFactory::createOne([
            'status' => DossierStatus::PREVIEW,
            'title' => 'Preview Dossier Should Not Appear',
        ]);
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Published Dossier Should Appear',
        ]);

        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Published Dossier Should Appear', $content);
        self::assertStringNotContainsString('Concept Dossier Should Not Appear', $content);
        self::assertStringNotContainsString('Scheduled Dossier Should Not Appear', $content);
        self::assertStringNotContainsString('Preview Dossier Should Not Appear', $content);
    }

    public function testAtomFeedEntriesHaveCorrectLinks(): void
    {
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'documentPrefix' => 'CVN',
            'dossierNr' => 'test-covenant-123',
        ]);
        InvestigationReportFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'documentPrefix' => 'OR',
            'dossierNr' => 'test-report-456',
        ]);

        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/convenant/CVN/test-covenant-123', $content);
        self::assertStringContainsString('/onderzoeksrapport/OR/test-report-456', $content);
    }

    public function testAtomFeedHasCorrectMetadata(): void
    {
        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $content);
        self::assertStringContainsString('<title>', $content);
        self::assertStringContainsString('<id>', $content);
        self::assertStringContainsString('<link rel="self"', $content);
        self::assertStringContainsString('/feed/atom', $content);
        self::assertStringContainsString('<updated>', $content);
    }

    public function testAtomFeedEntriesAreOrderedByPublicationDateDescending(): void
    {
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Oldest Publication',
            'publicationDate' => CarbonImmutable::create(2024, 1, 1),
        ]);
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Newest Publication',
            'publicationDate' => CarbonImmutable::create(2024, 3, 1),
        ]);
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Middle Publication',
            'publicationDate' => CarbonImmutable::create(2024, 2, 1),
        ]);

        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        $newestPos = strpos($content, 'Newest Publication');
        $middlePos = strpos($content, 'Middle Publication');
        $oldestPos = strpos($content, 'Oldest Publication');

        self::assertNotFalse($newestPos);
        self::assertNotFalse($middlePos);
        self::assertNotFalse($oldestPos);
        self::assertLessThan($middlePos, $newestPos, 'Newest should appear before middle');
        self::assertLessThan($oldestPos, $middlePos, 'Middle should appear before oldest');
    }

    public function testAtomFeedEntryReflectsUpdatedTimestamp(): void
    {
        $publishedAt = CarbonImmutable::create(2024, 1, 15, 10, 0, 0);
        $updatedAt = CarbonImmutable::create(2024, 2, 20, 14, 30, 0);

        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Updated Dossier',
            'publicationDate' => $publishedAt,
            'updatedAt' => $updatedAt,
        ]);

        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<published>' . $publishedAt->format(DateTimeInterface::ATOM) . '</published>', $content);
        self::assertStringContainsString('<updated>' . $updatedAt->format(DateTimeInterface::ATOM) . '</updated>', $content);
    }

    public function testAtomFeedHasCacheHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/feed/atom');

        self::assertResponseIsSuccessful();

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=600', $cacheControl);
    }
}
