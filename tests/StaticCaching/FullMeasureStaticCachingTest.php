<?php

namespace Tests\StaticCaching;

use Statamic\Facades\File;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\NoCache\CacheSession;
use Symfony\Component\HttpFoundation\Response;
use Tests\FakesContent;
use Tests\FakesViews;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

class FullMeasureStaticCachingTest extends TestCase
{
    use FakesContent;
    use FakesViews;
    use PreventSavingStacheItemsToDisk;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.static_caching.strategy', 'full');
        $app['config']->set('statamic.static_caching.strategies.full.path', $this->dir = __DIR__.'/static');

        File::delete($this->dir);
    }

    public function tearDown(): void
    {
        File::delete($this->dir);
        parent::tearDown();
    }

    /** @test */
    public function it_can_keep_parts_dynamic_using_nocache_tags()
    {
        // Use a tag that outputs something dynamic.
        // It will just increment by one every time it's used.

        app()->instance('example_count', 0);

        (new class extends \Statamic\Tags\Tags
        {
            public static $handle = 'example_count';

            public function index()
            {
                $count = app('example_count');
                $count++;
                app()->instance('example_count', $count);

                return $count;
            }
        })::register();

        $this->withFakeViews();
        $this->viewShouldReturnRaw('layout', '<html><body>{{ template_content }}</body></html>');
        $this->viewShouldReturnRaw('default', '{{ example_count }} {{ nocache }}{{ example_count }}{{ /nocache }}');

        $this->createPage('about');

        app(Cacher::class)->setNocacheJs('js here');

        $this->assertFileDoesNotExist($this->dir.'/about_.html');

        $response = $this
            ->get('/about')
            ->assertOk();

        $section = collect(app(CacheSession::class)->getSections())->keys()->first();

        // Initial response should be dynamic and not contain javascript.
        $this->assertEquals('<html><body>1 2</body></html>', $response->getContent());

        // The cached response should have the nocache placeholder, and the javascript.
        $this->assertFileExists($this->dir.'/about_.html');
        $this->assertEquals(vsprintf('<html><body>1 <span class="nocache" data-nocache="%s"></span>%s</body></html>', [
            $section,
            '<script type="text/javascript">js here</script>',
        ]), file_get_contents($this->dir.'/about_.html'));
    }
}