<?php

declare(strict_types=1);

namespace Shared\Tests\Integration\Public\Feed;

use Carbon\CarbonImmutable;
use Shared\Domain\Publication\Dossier\DossierStatus;
use Shared\Tests\Factory\Publication\Dossier\Type\Covenant\CovenantFactory;
use Shared\Tests\Factory\Publication\Dossier\Type\InvestigationReport\InvestigationReportFactory;
use Shared\Tests\Factory\Publication\Dossier\Type\WooDecision\WooDecisionFactory;
use Shared\Tests\Integration\SharedWebTestCase;
use SimpleXMLElement;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function strpos;

final class AtomFeedControllerTest extends SharedWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
    }

    public function testAtomFeedReturnsSuccessfulResponse(): void
    {
        $this->client->request('GET', '/feed/atom');

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

        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();

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
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Published Dossier Should Appear',
        ]);

        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Published Dossier Should Appear', $content);
        self::assertStringNotContainsString('Concept Dossier Should Not Appear', $content);
        self::assertStringNotContainsString('Scheduled Dossier Should Not Appear', $content);
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

        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('/convenant/CVN/test-covenant-123', $content);
        self::assertStringContainsString('/onderzoeksrapport/OR/test-report-456', $content);
    }

    public function testAtomFeedHasCorrectMetadata(): void
    {
        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();

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

        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();

        $newestPos = strpos($content, 'Newest Publication');
        $middlePos = strpos($content, 'Middle Publication');
        $oldestPos = strpos($content, 'Oldest Publication');

        self::assertNotFalse($newestPos);
        self::assertNotFalse($middlePos);
        self::assertNotFalse($oldestPos);
        self::assertLessThan($middlePos, $newestPos, 'Newest should appear before middle');
        self::assertLessThan($oldestPos, $middlePos, 'Middle should appear before oldest');
    }

    public function testAtomFeedEntryHasPublishedAndUpdatedTimestamps(): void
    {
        CovenantFactory::createOne([
            'status' => DossierStatus::PUBLISHED,
            'title' => 'Timestamped Dossier',
            'publicationDate' => CarbonImmutable::create(2024, 1, 15, 10, 0, 0),
        ]);

        $this->client->request('GET', '/feed/atom');

        $content = (string) $this->client->getResponse()->getContent();
        $xml = new SimpleXMLElement($content);
        $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');

        $entries = $xml->xpath('//atom:entry');
        self::assertCount(1, $entries);

        $entry = $entries[0];
        $published = (string) $entry->published;
        $updated = (string) $entry->updated;

        self::assertNotEmpty($published, '<published> should be present');
        self::assertNotEmpty($updated, '<updated> should be present');

        $publishedDate = new CarbonImmutable($published);
        self::assertTrue($publishedDate->isSameDay(CarbonImmutable::create(2024, 1, 15)), 'Published date should match factory value');
    }

    public function testAtomFeedHasCacheHeaders(): void
    {
        $this->client->request('GET', '/feed/atom');

        self::assertResponseIsSuccessful();

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=600', $cacheControl);
    }
}
