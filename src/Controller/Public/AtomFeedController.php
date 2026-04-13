<?php

declare(strict_types=1);

namespace Shared\Controller\Public;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Shared\Domain\Publication\Dossier\AbstractDossier;
use Shared\Domain\Publication\Dossier\DossierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

use function array_reduce;

class AtomFeedController extends AbstractController
{
    private const int FEED_LIMIT = 50;

    public function __construct(
        private readonly DossierRepository $dossierRepository,
    ) {
    }

    #[Cache(maxage: 600, public: true, mustRevalidate: true)]
    #[Route('/feed/atom', name: 'app_feed_atom', methods: ['GET'])]
    public function __invoke(): Response
    {
        $dossiers = $this->dossierRepository->getRecentDossiers(self::FEED_LIMIT, null);

        $response = new Response(
            $this->renderView('public/feed/atom.xml.twig', [
                'dossiers' => $dossiers,
                'feedUpdatedAt' => $this->resolveFeedUpdatedAt($dossiers),
            ]),
        );
        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');

        return $response;
    }

    /**
     * @param AbstractDossier[] $dossiers
     */
    private function resolveFeedUpdatedAt(array $dossiers): DateTimeImmutable
    {
        return array_reduce(
            $dossiers,
            static fn (?DateTimeImmutable $carry, AbstractDossier $dossier): DateTimeImmutable => $carry === null || $dossier->getUpdatedAt() > $carry
                ? $dossier->getUpdatedAt()
                : $carry,
            null,
        ) ?? CarbonImmutable::now();
    }
}
