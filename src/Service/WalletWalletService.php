<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Helper\EmailRecipient;
use App\Repository\ContentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WalletWalletService
{
    private const API_URL = 'https://api.walletwallet.dev/api/passes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingService $settings,
        private readonly SeatmapService $seatmapService,
        private readonly ContentRepository $contentRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $walletWalletApiKey,
        private readonly string $walletWalletLogoDataUri,
        private readonly string $walletWalletIconDataUri,
        private readonly string $walletWalletStripDataUri,
    ) {
    }

    public function createShareUrl(Ticket $ticket, EmailRecipient $recipient): ?string
    {
        $code = $ticket->getCode();
        if (empty($code) || empty($this->walletWalletApiKey)) {
            return null;
        }

        if ($this->walletWalletLogoDataUri === '' || $this->walletWalletIconDataUri === '' || $this->walletWalletStripDataUri === '') {
            $this->logger->warning('WalletWallet pass skipped because image data is not configured');

            return null;
        }

        $organization = (string) $this->settings->get('site.organisation', 'Verein LANMAKERS');
        $seatNames = array_map(
            static fn ($seat): string => $seat->generateSeatName(),
            $this->seatmapService->getUserSeats($recipient->getUuid())
        );
        $seatName = $seatNames === [] ? 'Kein Sitzplatz' : implode(', ', $seatNames);
        $payload = [
            'barcodeValue' => $code,
            'barcodeFormat' => 'QR',
            'logoText' => 'DoT-LAN 2k26 - Ticket',
            'organizationName' => $organization,
            'colorPreset' => 'dark',
            'expirationDays' => 7,
            'color' => '#2B2B2B',
            'logoURL' => $this->walletWalletLogoDataUri,
            'iconURL' => $this->walletWalletIconDataUri,
            'stripURL' => $this->walletWalletStripDataUri,
            'secondaryFields' => [
                ['label' => 'Nickname', 'value' => $recipient->getNickname()],
                ['label' => 'Sitzplatz', 'value' => $seatName],
            ],
            'backFields' => [
                ['label' => 'Organisation', 'value' => $organization],
                ['label' => 'Impressum', 'value' => $this->getImprintUrl()],
            ],
        ];

        $coordinates = $this->parseCoordinates((string) $this->settings->get('map.center_coordinates', ''));
        if ($coordinates !== null) {
            $payload['locations'] = [[
                'latitude' => $coordinates[0],
                'longitude' => $coordinates[1],
                'relevantText' => 'Willkommen zur DoT-LAN 2k26!',
            ]];
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->walletWalletApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 10,
            ]);
            $data = $response->toArray();
            $shareUrl = $data['shareUrl'] ?? null;

            return is_string($shareUrl) && $shareUrl !== '' ? $shareUrl : null;
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to create WalletWallet pass', ['exception' => $exception]);

            return null;
        }
    }

    private function getImprintUrl(): string
    {
        if ($this->contentRepository->findBySlug('imprint') === null) {
            return '';
        }

        return $this->urlGenerator->generate('content_slug', ['slug' => 'imprint'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @return array{0: float, 1: float}|null */
    private function parseCoordinates(string $value): ?array
    {
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) < 2) {
            return null;
        }

        $latitude = filter_var($parts[0], FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return [(float) $latitude, (float) $longitude];
    }
}