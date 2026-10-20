<?php

namespace App\Tests\Functional\Site;

use App\DataFixtures\ContentFixture;
use App\Tests\Functional\DatabaseWebTestCase;

class ContentControllerTest extends DatabaseWebTestCase
{
    public function testContentWithSlag()
    {
        $this->databaseTool->loadFixtures([ContentFixture::class]);

        $crawler = $this->client->request('GET', '/content/info');
        $this->assertEquals(0, $this->mock->getInvalidCalls());

        $this->assertResponseStatusCodeSame(200);
        // H1 (page title) sits above the card; body text inside the padded card body
        $this->assertEquals('FAQ', $crawler->filter('main h1')->eq(0)->text());
        $this->assertStringContainsString('Wer ist dieser LAN?', $crawler->filter('.content-scroll')->text());
    }
}