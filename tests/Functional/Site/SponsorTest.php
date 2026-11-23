<?php

namespace App\Tests\Functional\Site;
use App\DataFixtures\SponsorFixtures;
use App\Entity\Sponsor;
use App\Service\SettingService;
use App\Service\SponsorService;
use App\Tests\Functional\DatabaseWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

class SponsorTest extends DatabaseWebTestCase
{
    public function testSponsor()
    {
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);

        $crawler = $this->client->request('GET', '/sponsor');
        $this->assertEquals(0, $this->mock->getInvalidCalls());

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $crawler->filter('.sponsor-category'));
        $sponsors = $crawler->filter('.sponsor');
        $this->assertCount(2, $sponsors);

        // Sponsor links are not enabled by default
        $this->assertSelectorNotExists('main a.btn');

        // only one has a link set
        $this->assertCount(2, $crawler->filter('.sponsor img'));
        $this->assertCount(1, $crawler->filter('.sponsor a > img'));
        // fixed-size logo boxes (no inline widths) so rows stay even
        $this->assertCount(2, $crawler->filter('.sponsor img.sponsor-logo'));
        $this->assertCount(0, $crawler->filter('.sponsor img[style]'));
        $a = $crawler->filter('.sponsor a');
        $this->assertCount(1, $a);
        $this->assertEquals('https://www.example.com', $a->attr('href'));
    }

    public function testSponsorLinks()
    {
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);
        $settings = static::getContainer()->get(SettingService::class);
        $settings->set('sponsor.page.site_links', true);

        $crawler = $this->client->request('GET', '/sponsor');
        $this->assertEquals(0, $this->mock->getInvalidCalls());

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('main a.btn');
        $this->assertSelectorTextSame('main a.btn', 'Hyper-Sponsor');
    }

    public function testSponsorText()
    {
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);
        $settings = static::getContainer()->get(SettingService::class);

        $title = "FuuBaa";
        $text = "This is our Text";
        $settings->set('sponsor.page.title', $title);
        $settings->set('sponsor.page.text', $text);

        $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(200);

        $this->assertSelectorTextSame('.container h1', $title);
        $this->assertSelectorTextSame('.container h1 + p', $text);
    }

    public function testSponsorEmptyCategories()
    {
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);
        $settings = static::getContainer()->get(SettingService::class);
        $settings->set('sponsor.page.show_empty', true);

        $crawler = $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(200);

        $this->assertCount(3, $crawler->filter('.sponsor-category'));
        $this->assertCount(2, $crawler->filter('.sponsor'));
        $this->assertSelectorExists('.sponsor-category h2');
    }

    public function testSponsorNoCategory()
    {
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);
        $settings = static::getContainer()->get(SettingService::class);
        $settings->set('sponsor.page.show_header', false);
        $settings->set('sponsor.page.site_links', false);

        $crawler = $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $crawler->filter('.sponsor-category'));
        $this->assertSelectorNotExists('.sponsor-category h3');
    }

    public function testSponsorEnable()
    {
        $this->databaseTool->loadFixtures([]);

        $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(404);

        $sponsor_service = static::getContainer()->get(SponsorService::class);
        $settings = static::getContainer()->get(SettingService::class);

        $this->assertFalse($sponsor_service->active());
        $sponsor_service->activate();
        $this->assertTrue($sponsor_service->active());

        $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorNotExists('.sponsor-catebory');
        $this->assertSelectorNotExists('.sponsor');

        $settings->set('sponsor.page.show_empty', true);
        $this->client->request('GET', '/sponsor');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('.sponsor-category');
        $this->assertSelectorNotExists('.sponsor');
    }

    private function loadCarouselFixtures(): void
    {
        // liip's loadFixtures only accepts class strings; it resolves them via
        // the container's fixture services, so mutating the shared service
        // instance before loading is what ticks the carousel flag.
        $fixtures = static::getContainer()->get(SponsorFixtures::class);
        $fixtures->makeCarouselVisible();
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);
    }

    public function testFooterCarouselShowsTickSponsors()
    {
        $this->loadCarouselFixtures();

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);

        // bootstrap-4 carousel, one slide per visible+tick'd sponsor.
        // Fixtures tick 2 visible + 1 invisible; invisible must never render.
        $this->assertSelectorExists('#sponsor-carousel.carousel.slide[data-ride="carousel"]');
        $this->assertCount(2, $crawler->filter('#sponsor-carousel .carousel-item'));
        $this->assertCount(2, $crawler->filter('#sponsor-carousel .carousel-item img.sponsor-banner-logo'));
        $this->assertCount(2, $crawler->filter('#sponsor-carousel .carousel-indicators li'));
        $this->assertCount(1, $crawler->filter('#sponsor-carousel .carousel-item.active'));
        // >1 slide: prev/next controls render
        $this->assertCount(1, $crawler->filter('#sponsor-carousel .carousel-control-prev'));
        $this->assertCount(1, $crawler->filter('#sponsor-carousel .carousel-control-next'));
        // uniform logo box class present (styled via CSS, no inline styles)
        $this->assertCount(2, $crawler->filter('#sponsor-carousel .sponsor-slide img.sponsor-banner-logo'));
        $this->assertCount(0, $crawler->filter('#sponsor-carousel img[style]'));
        $this->assertEquals(0, $this->mock->getInvalidCalls());
    }

    public function testFooterCarouselHiddenWhenNobodyTick()
    {
        // default fixtures: visible sponsors exist, inCarousel = NO
        $this->databaseTool->loadFixtures([SponsorFixtures::class]);

        $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorNotExists('#sponsor-carousel');
    }

    public function testFooterCarouselHiddenWhenBannerDisabled()
    {
        $this->loadCarouselFixtures();
        $settings = static::getContainer()->get(SettingService::class);
        $settings->set('sponsor.banner.show', false);

        $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorNotExists('#sponsor-carousel');
    }

    public function testFooterCarouselSingleSlideHasNoControls()
    {
        $this->loadCarouselFixtures();
        // un-tick one of the two visible sponsors
        $repository = static::getContainer()->get('doctrine')->getRepository(Sponsor::class);
        $acme = $repository->findOneBy(['name' => 'ACME Industries']);
        $this->assertNotNull($acme);
        $acme->setInCarousel(false);
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $crawler->filter('#sponsor-carousel .carousel-item'));
        // 1 slide: no indicators, no arrows, the lone slide is .active
        $this->assertSelectorNotExists('#sponsor-carousel .carousel-indicators');
        $this->assertSelectorNotExists('#sponsor-carousel .carousel-control-next');
        $this->assertCount(1, $crawler->filter('#sponsor-carousel .carousel-item.active'));
    }
}