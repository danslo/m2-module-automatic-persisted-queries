<?php

declare(strict_types=1);

namespace Danslo\Aqp\Test\Integration;

use Danslo\Apq\Model\Cache\Type\Apq;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GraphQl\Controller\GraphQl;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

/**
 * @magentoCache all enabled
 */
class PersistedQueryTest extends TestCase
{
    private const GOD_QUERY = '{__typename}';

    private $om;
    private $serializer;
    private $graphqlController;
    private $cache;

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();
        $this->serializer = $this->om->create(Json::class);
        $this->graphqlController = $this->om->get(GraphQl::class);
        $this->cache = $this->om->get(CacheInterface::class);
        $this->om->get(TypeListInterface::class)->cleanType(Apq::TYPE_IDENTIFIER);
        $this->handleRegisterFormKeyPlugin();
    }

    /**
     * There is a RegisterFormKeyFromCookie plugin on the GraphQL controller.
     * This plugin has a constructor dependency that will trigger a session write in versions 2.3.x.
     * We want to avoid this because it causes a FileSystemException during integration test execution.
     *
     * Please see https://github.com/extdn/github-actions-m2/issues/56.
     */
    private function handleRegisterFormKeyPlugin(): void
    {
        $productMetadata = $this->om->get(ProductMetadataInterface::class);
        if (version_compare($productMetadata->getVersion(), '2.3', '>=') &&
            version_compare($productMetadata->getVersion(), '2.4', '<')) {
            $this->om->addSharedInstance(
                new class { public function beforeDispatch() {}},
                \Magento\PageCache\Plugin\RegisterFormKeyFromCookie::class
            );
        }
    }

    private function getGodQueryCacheKey(): string
    {
        return Apq::TYPE_IDENTIFIER . '_' . hash('sha256', self::GOD_QUERY);
    }

    private function createGetRequestWithPersistedQuery(string $query): Request
    {
        return $this->om->create(Request::class)->setParam(
            'extensions',
            $this->serializer->serialize(['persistedQuery' => ['sha256Hash' => hash('sha256', $query)]])
        );
    }

    private function createPostRequestWithPersistedQuery(string $query): Request
    {
        return $this->om->create(Request::class)
            ->setMethod('post')
            ->setContent(
                $this->serializer->serialize(['extensions' => ['persistedQuery' => ['sha256Hash' => $query]]])
            );
    }

    private function createGetRequestWithoutPersistedQueryHash(string $query): Request
    {
        return $this->om->create(Request::class)->setParam('query', $query);
    }

    private function createPostRequestWithoutPersistedQueryHash(string $query): Request
    {
        return $this->om->create(Request::class)
            ->setMethod('post')
            ->setContent(
                $this->serializer->serialize(['query' => $query])
            );
    }

    private function dispatchGodQuery(): ResponseInterface
    {
        $request = $this->createGetRequestWithPersistedQuery(self::GOD_QUERY);
        $request->setParam('query', self::GOD_QUERY);
        return $this->graphqlController->dispatch($request);
    }

    private function dispatchPersistedGodQuery(): ResponseInterface
    {
        return $this->graphqlController->dispatch($this->createGetRequestWithPersistedQuery(self::GOD_QUERY));
    }

    public function testCacheIsEmptyInitially()
    {
        $this->assertEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
    }

    public function testNotFoundGetQueryReturnsCorrectHttpStatus()
    {
        $request = $this->createGetRequestWithPersistedQuery('def');
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(400, $result->getHttpResponseCode());
    }

    public function testGetQueryWithoutHashIsCached()
    {
        $this->assertEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
        $request = $this->createGetRequestWithoutPersistedQueryHash(self::GOD_QUERY);
        $result = $this->graphqlController->dispatch($request);
        $this->assertNotEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
    }

    public function testPostQueryWithoutHashIsCached()
    {
        $this->assertEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
        $request = $this->createPostRequestWithoutPersistedQueryHash(self::GOD_QUERY);
        $result = $this->graphqlController->dispatch($request);
        $this->assertNotEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
    }

    public function testNotFoundPostQueryReturnsCorrectHttpStatus()
    {
        $request = $this->createPostRequestWithPersistedQuery('abc');
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(500, $result->getHttpResponseCode());
    }

    public function testResponseForHashAndQueryThatDoNotMatch()
    {
        $request = $this->createGetRequestWithPersistedQuery('foobar')->setParam('query', self::GOD_QUERY);
        $result = $this->graphqlController->dispatch($request);

        $this->assertEquals(400, $result->getHttpResponseCode());
        $this->assertEquals('provided sha does not match query', $result->getContent());
    }

    public function testPersistedQueryIsSameAsRegularQuery()
    {
        $originalResult = $this->dispatchGodQuery();
        $result = $this->dispatchPersistedGodQuery();
        $this->assertEquals($originalResult->getHttpResponseCode(), $result->getHttpResponseCode());
        $this->assertEquals($originalResult->getBody(), $result->getBody());
    }

    public function testQueryIsCached()
    {
        $this->dispatchGodQuery();
        $this->assertNotEquals(false, $this->cache->load($this->getGodQueryCacheKey()));
    }
}
