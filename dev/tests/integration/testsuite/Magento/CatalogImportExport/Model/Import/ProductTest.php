<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogImportExport\Model\Import;

use Magento\Backend\Model\Auth\Session;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Value;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Option\Collection;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\CatalogImportExport\Model\Import\Product\RowValidatorInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\CatalogInventory\Model\Stock\Item;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\ImportExport\Helper\Data;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregator;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\Import\Source\Csv;
use Magento\Indexer\Model\Processor;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap as BootstrapHelper;
use Magento\TestFramework\Indexer\TestCase;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Integration test for \Magento\CatalogImportExport\Model\Import\Product class.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoAppArea adminhtml
 * @magentoDataFixtureBeforeTransaction Magento/Catalog/_files/enable_reindex_schedule.php
 * @magentoDataFixtureBeforeTransaction Magento/Catalog/_files/enable_catalog_product_reindex_schedule.php
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * phpcs:disable Generic.PHP.NoSilencedErrors, Generic.Metrics.NestingLevel, Magento2.Functions.StaticFunction
 */
class ProductTest extends TestCase
{
    private const LONG_FILE_NAME_IMAGE = 'magento_long_image_name_magento_long_image_name_magento_long_image_name.jpg';

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product
     */
    private $model;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = BootstrapHelper::getObjectManager();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->model = $this->objectManager->create(
            \Magento\CatalogImportExport\Model\Import\Product::class,
            ['logger' => $this->logger]
        );
        $this->importedProducts = [];
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);

        parent::setUp();
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        /* We rollback here the products created during the Import because they were
           created during test execution and we do not have the rollback for them */
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($this->importedProducts as $productSku) {
            try {
                $product = $productRepository->get($productSku, false, null, true);
                $productRepository->delete($product);
                // phpcs:disable Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (NoSuchEntityException $e) {
                // nothing to delete
            }
        }
    }

    /**
     * @var array
     */
    private $importedProducts;

    /**
     * Options for assertion
     *
     * @var array
     */
    protected $_assertOptions = [
        'is_require' => 'required',
        'price' => 'price',
        'sku' => 'sku',
        'sort_order' => 'order',
        'max_characters' => 'max_characters',
    ];

    /**
     * Option values for assertion
     *
     * @var array
     */
    protected $_assertOptionValues = [
        'title' => 'option_title',
        'price' => 'price',
        'sku' => 'sku',
    ];

    /**
     * List of specific custom option types
     *
     * @var array
     */
    private $specificTypes = [
        'drop_down',
        'radio',
        'checkbox',
        'multiple',
    ];

    /**
     * Test if visibility properly saved after import
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoAppIsolation enabled
     */
    public function testSaveProductsVisibility()
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(
            ProductRepositoryInterface::class
        );
        $id1 = $productRepository->get('simple1')->getId();
        $id2 = $productRepository->get('simple2')->getId();
        $id3 = $productRepository->get('simple3')->getId();
        $existingProductIds = [$id1, $id2, $id3];
        $productsBeforeImport = [];
        foreach ($existingProductIds as $productId) {
            $product = $this->objectManager->create(Product::class);
            $product->load($productId);
            $productsBeforeImport[] = $product;
        }

        $filesystem = $this->objectManager
            ->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(
            ['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product']
        )->setSource(
            $source
        )->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var $productBeforeImport Product */
        foreach ($productsBeforeImport as $productBeforeImport) {
            /** @var $productAfterImport Product */
            $productAfterImport = $this->objectManager->create(
                Product::class
            );
            $productAfterImport->load($productBeforeImport->getId());

            $this->assertEquals($productBeforeImport->getVisibility(), $productAfterImport->getVisibility());
            unset($productAfterImport);
        }

        unset($productsBeforeImport, $product);
    }

    /**
     * Test if stock item quantity properly saved after import
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoAppIsolation enabled
     */
    public function testSaveStockItemQty()
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $id1 = $productRepository->get('simple1')->getId();
        $id2 = $productRepository->get('simple2')->getId();
        $id3 = $productRepository->get('simple3')->getId();
        $existingProductIds = [$id1, $id2, $id3];
        $stockItems = [];
        foreach ($existingProductIds as $productId) {
            /** @var $stockRegistry StockRegistry */
            $stockRegistry = $this->objectManager->create(StockRegistry::class);

            $stockItem = $stockRegistry->getStockItem($productId, 1);
            $stockItems[$productId] = $stockItem;
        }

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var $stockItmBeforeImport Item */
        foreach ($stockItems as $productId => $stockItmBeforeImport) {
            /** @var $stockRegistry StockRegistry */
            $stockRegistry = $this->objectManager->create(StockRegistry::class);

            $stockItemAfterImport = $stockRegistry->getStockItem($productId, 1);

            $this->assertEquals($stockItmBeforeImport->getQty(), $stockItemAfterImport->getQty());
            $this->assertEquals(1, $stockItemAfterImport->getIsInStock());
            unset($stockItemAfterImport);
        }

        unset($stockItems, $stockItem);
    }

    /**
     * Test that is_in_stock set to 0 when item quantity is 0
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoAppIsolation enabled
     *
     * @return void
     */
    public function testSaveIsInStockByZeroQty(): void
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $id1 = $productRepository->get('simple1')->getId();
        $id2 = $productRepository->get('simple2')->getId();
        $id3 = $productRepository->get('simple3')->getId();
        $existingProductIds = [$id1, $id2, $id3];

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_zero_qty.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var $stockItmBeforeImport Item */
        foreach ($existingProductIds as $productId) {
            /** @var $stockRegistry StockRegistry */
            $stockRegistry = $this->objectManager->create(StockRegistry::class);

            $stockItemAfterImport = $stockRegistry->getStockItem($productId, 1);

            $this->assertEquals(0, $stockItemAfterImport->getIsInStock());
            unset($stockItemAfterImport);
        }
    }

    /**
     * Test if stock state properly changed after import
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoAppIsolation enabled
     */
    public function testStockState()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_qty.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();
    }

    /**
     * Tests adding of custom options with existing and new product.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @dataProvider getBehaviorDataProvider
     * @param string $importFile
     * @param string $sku
     * @param int $expectedOptionsQty
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @magentoAppIsolation enabled
     *
     * @return void
     */
    public function testSaveCustomOptions(string $importFile, string $sku, int $expectedOptionsQty): void
    {
        $pathToFile = __DIR__ . '/_files/' . $importFile;
        $importModel = $this->createImportModel($pathToFile);
        $errors = $importModel->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $importModel->importData();

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $product = $productRepository->get($sku);

        $this->assertInstanceOf(Product::class, $product);
        $options = $product->getOptionInstance()->getProductOptions($product);

        $expectedData = $this->getExpectedOptionsData($pathToFile);
        $expectedData = $this->mergeWithExistingData($expectedData, $options);
        $actualData = $this->getActualOptionsData($options);

        // assert of equal type+titles
        $expectedOptions = $expectedData['options'];
        // we need to save key values
        $actualOptions = $actualData['options'];
        sort($expectedOptions);
        sort($actualOptions);
        $this->assertSame($expectedOptions, $actualOptions);

        // assert of options data
        $this->assertCount(count($expectedData['data']), $actualData['data']);
        $this->assertCount(count($expectedData['values']), $actualData['values']);
        $this->assertCount($expectedOptionsQty, $actualData['options']);
        foreach ($expectedData['options'] as $expectedId => $expectedOption) {
            $elementExist = false;
            // find value in actual options and values
            foreach ($actualData['options'] as $actualId => $actualOption) {
                if ($actualOption == $expectedOption) {
                    $elementExist = true;
                    $this->assertEquals($expectedData['data'][$expectedId], $actualData['data'][$actualId]);
                    if (array_key_exists($expectedId, $expectedData['values'])) {
                        $this->assertEquals($expectedData['values'][$expectedId], $actualData['values'][$actualId]);
                    }
                    unset($actualData['options'][$actualId]);
                    // remove value in case of duplicating key values
                    break;
                }
            }
            $this->assertTrue($elementExist, 'Element must exist.');
        }

        // Make sure that after importing existing options again, option IDs and option value IDs are not changed
        $customOptionValues = $this->getCustomOptionValues($sku);
        $this->createImportModel($pathToFile)->importData();
        $this->assertEquals($customOptionValues, $this->getCustomOptionValues($sku));
    }

    /**
     * Tests adding of custom options with multiple store views
     *
     * @magentoConfigFixture current_store catalog/price/scope 1
     * @magentoDataFixture Magento/Store/_files/core_second_third_fixturestore.php
     * @magentoAppIsolation enabled
     */
    public function testSaveCustomOptionsWithMultipleStoreViews()
    {
        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $storeCodes = ['admin', 'default', 'secondstore'];
        /** @var StoreManagerInterface $storeManager */
        $importFile = 'product_with_custom_options_and_multiple_store_views.csv';
        $sku = 'simple';
        $pathToFile = __DIR__ . '/_files/' . $importFile;
        $importModel = $this->createImportModel($pathToFile);
        $errors = $importModel->validateData();
        $this->assertTrue($errors->getErrorsCount() == 0, 'Import File Validation Failed');
        $importModel->importData();
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($storeCodes as $storeCode) {
            $storeManager->setCurrentStore($storeCode);
            $product = $productRepository->get($sku);
            $options = $product->getOptionInstance()->getProductOptions($product);
            $expectedData = $this->getExpectedOptionsData($pathToFile, $storeCode);
            $expectedData = $this->mergeWithExistingData($expectedData, $options);
            $actualData = $this->getActualOptionsData($options);
            // assert of equal type+titles
            $expectedOptions = $expectedData['options'];
            // we need to save key values
            $actualOptions = $actualData['options'];
            sort($expectedOptions);
            sort($actualOptions);
            $this->assertEquals(
                $expectedOptions,
                $actualOptions,
                'Expected and actual options arrays does not match'
            );

            // assert of options data
            $this->assertCount(
                count($expectedData['data']),
                $actualData['data'],
                'Expected and actual data count does not match'
            );
            $this->assertCount(
                count($expectedData['values']),
                $actualData['values'],
                'Expected and actual values count does not match'
            );

            foreach ($expectedData['options'] as $expectedId => $expectedOption) {
                $elementExist = false;
                // find value in actual options and values
                foreach ($actualData['options'] as $actualId => $actualOption) {
                    if ($actualOption == $expectedOption) {
                        $elementExist = true;
                        $this->assertEquals(
                            $expectedData['data'][$expectedId],
                            $actualData['data'][$actualId],
                            'Expected data does not match actual data'
                        );
                        if (array_key_exists($expectedId, $expectedData['values'])) {
                            $this->assertEquals(
                                $expectedData['values'][$expectedId],
                                $actualData['values'][$actualId],
                                'Expected values does not match actual data'
                            );
                        }
                        unset($actualData['options'][$actualId]);
                        // remove value in case of duplicating key values
                        break;
                    }
                }
                $this->assertTrue($elementExist, 'Element must exist.');
            }

            // Make sure that after importing existing options again, option IDs and option value IDs are not changed
            $customOptionValues = $this->getCustomOptionValues($sku);
            $this->createImportModel($pathToFile)->importData();
            $this->assertEquals(
                $customOptionValues,
                $this->getCustomOptionValues($sku),
                'Option IDs changed after second import'
            );
        }
    }

    /**
     * @return array
     */
    public function getBehaviorDataProvider(): array
    {
        return [
            'Append behavior with existing product' => [
                'importFile' => 'product_with_custom_options.csv',
                'sku' => 'simple',
                'expectedOptionsQty' => 6,
            ],
            'Append behavior with existing product and without options in import file' => [
                'importFile' => 'product_without_custom_options.csv',
                'sku' => 'simple',
                'expectedOptionsQty' => 0,
            ],
            'Append behavior with new product' => [
                'importFile' => 'product_with_custom_options_new.csv',
                'sku' => 'simple_new',
                'expectedOptionsQty' => 5,
            ],
        ];
    }

    /**
     * @param string $pathToFile
     * @param string $behavior
     * @return \Magento\CatalogImportExport\Model\Import\Product
     */
    private function createImportModel($pathToFile, $behavior = Import::BEHAVIOR_APPEND)
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        /** @var Csv $source */
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );

        /** @var \Magento\CatalogImportExport\Model\Import\Product $importModel */
        $importModel = $this->objectManager->create(\Magento\CatalogImportExport\Model\Import\Product::class);
        $importModel->setParameters(['behavior' => $behavior, 'entity' => 'catalog_product'])
            ->setSource($source);

        return $importModel;
    }

    /**
     * @param string $productSku
     * @return array ['optionId' => ['optionValueId' => 'optionValueTitle', ...], ...]
     */
    private function getCustomOptionValues($productSku)
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        /** @var ProductCustomOptionRepositoryInterface $customOptionRepository */
        $customOptionRepository = $this->objectManager->get(ProductCustomOptionRepositoryInterface::class);
        $simpleProduct = $productRepository->get($productSku, false, null, true);
        $originalProductOptions = $customOptionRepository->getProductOptions($simpleProduct);
        $optionValues = [];
        foreach ($originalProductOptions as $productOption) {
            foreach ((array)$productOption->getValues() as $optionValue) {
                $optionValues[$productOption->getOptionId()][$optionValue->getOptionTypeId()]
                    = $optionValue->getTitle();
            }
        }
        return $optionValues;
    }

    /**
     * Test if datetime properly saved after import
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoAppIsolation enabled
     */
    public function testSaveDatetimeAttribute()
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $id1 = $productRepository->get('simple1')->getId();
        $id2 = $productRepository->get('simple2')->getId();
        $id3 = $productRepository->get('simple3')->getId();
        $existingProductIds = [$id1, $id2, $id3];
        $productsBeforeImport = [];
        foreach ($existingProductIds as $productId) {
            $product = $this->objectManager->create(Product::class);
            $product->load($productId);
            $productsBeforeImport[$product->getSku()] = $product;
        }

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_datetime.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        $source->rewind();
        foreach ($source as $row) {
            /** @var $productAfterImport Product */
            $productBeforeImport = $productsBeforeImport[$row['sku']];

            /** @var $productAfterImport Product */
            $productAfterImport = $this->objectManager->create(Product::class);
            $productAfterImport->load($productBeforeImport->getId());
            $this->assertEquals(
                @strtotime(date('m/d/Y', @strtotime($row['news_from_date']))),
                @strtotime($productAfterImport->getNewsFromDate())
            );
            $this->assertEquals(
                @strtotime($row['news_to_date']),
                @strtotime($productAfterImport->getNewsToDate())
            );
            unset($productAfterImport);
        }
        unset($productsBeforeImport, $product);
    }

    /**
     * Returns expected product data: current id, options, options data and option values
     *
     * @param string $pathToFile
     * @param string $storeCode
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function getExpectedOptionsData(string $pathToFile, string $storeCode = ''): array
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $productData = $this->csvToArray(file_get_contents($pathToFile));
        $expectedOptionId = 0;
        $expectedOptions = [];
        // array of type and title types, key is element ID
        $expectedData = [];
        // array of option data
        $expectedValues = [];
        $storeRowId = null;
        foreach ($productData['data'] as $rowId => $rowData) {
            $storeCode = ($storeCode == 'admin') ? '' : $storeCode;
            if ($rowData['store_view_code'] == $storeCode) {
                $storeRowId = $rowId;
                break;
            }
        }
        if (!empty($productData['data'][$storeRowId]['custom_options'])) {
            foreach (explode('|', $productData['data'][$storeRowId]['custom_options']) as $optionData) {
                $option = array_values(
                    array_map(
                        function ($input) {
                            $data = explode('=', $input);
                            return [$data[0] => $data[1]];
                        },
                        explode(',', $optionData)
                    )
                );
                // phpcs:ignore Magento2.Performance.ForeachArrayMerge
                $option = array_merge([], ...$option);

                if (!empty($option['type']) && !empty($option['name'])) {
                    $lastOptionKey = $option['type'] . '|' . $option['name'];
                    if (!isset($expectedOptions[$expectedOptionId])
                        || $expectedOptions[$expectedOptionId] != $lastOptionKey) {
                        $expectedOptionId++;
                        $expectedOptions[$expectedOptionId] = $lastOptionKey;
                        $expectedData[$expectedOptionId] = [];
                        foreach ($this->_assertOptions as $assertKey => $assertFieldName) {
                            if (array_key_exists($assertFieldName, $option)
                                && !(($assertFieldName == 'price' || $assertFieldName == 'sku')
                                    && in_array($option['type'], $this->specificTypes))
                            ) {
                                $expectedData[$expectedOptionId][$assertKey] = $option[$assertFieldName];
                            }
                        }
                    }
                }
                $optionValue = [];
                if (!empty($option['name']) && !empty($option['option_title'])) {
                    foreach ($this->_assertOptionValues as $assertKey => $assertFieldName) {
                        if (isset($option[$assertFieldName])) {
                            $optionValue[$assertKey] = $option[$assertFieldName];
                        }
                    }
                    $expectedValues[$expectedOptionId][] = $optionValue;
                }
            }
        }

        return [
            'id' => $expectedOptionId,
            'options' => $expectedOptions,
            'data' => $expectedData,
            'values' => $expectedValues,
        ];
    }

    /**
     * Updates expected options data array with existing unique options data
     *
     * @param array $expected
     * @param Collection $options
     * @return array
     */
    private function mergeWithExistingData(array $expected, $options)
    {
        $expectedOptionId = $expected['id'];
        $expectedOptions = $expected['options'];
        $expectedData = $expected['data'];
        $expectedValues = $expected['values'];
        foreach ($options as $option) {
            $optionKey = $option->getType() . '|' . $option->getTitle();
            $optionValues = $this->getOptionValues($option);
            if (!in_array($optionKey, $expectedOptions)) {
                $expectedOptionId++;
                $expectedOptions[$expectedOptionId] = $optionKey;
                $expectedData[$expectedOptionId] = $this->getOptionData($option);
                if ($optionValues) {
                    $expectedValues[$expectedOptionId] = $optionValues;
                }
            } else {
                $existingOptionId = array_search($optionKey, $expectedOptions);
                // phpcs:ignore Magento2.Performance.ForeachArrayMerge
                $expectedData[$existingOptionId] = array_merge(
                    $this->getOptionData($option),
                    $expectedData[$existingOptionId]
                );
                if ($optionValues) {
                    foreach ($optionValues as $optionKey => $optionValue) {
                        // phpcs:ignore Magento2.Performance.ForeachArrayMerge
                        $expectedValues[$existingOptionId][$optionKey] = array_merge(
                            $optionValue,
                            $expectedValues[$existingOptionId][$optionKey]
                        );
                    }
                }
            }
        }

        return [
            'id' => $expectedOptionId,
            'options' => $expectedOptions,
            'data' => $expectedData,
            'values' => $expectedValues
        ];
    }

    /**
     *  Returns actual product data: current id, options, options data and option values
     *
     * @param Collection $options
     * @return array
     */
    private function getActualOptionsData($options)
    {
        $actualOptionId = 0;
        $actualOptions = [];
        // array of type and title types, key is element ID
        $actualData = [];
        // array of option data
        $actualValues = [];
        // array of option values data
        /** @var $option Option */
        foreach ($options as $option) {
            $lastOptionKey = $option->getType() . '|' . $option->getTitle();
            $actualOptionId++;
            if (!in_array($lastOptionKey, $actualOptions)) {
                $actualOptions[$actualOptionId] = $lastOptionKey;
                $actualData[$actualOptionId] = $this->getOptionData($option);
                if ($optionValues = $this->getOptionValues($option)) {
                    $actualValues[$actualOptionId] = $optionValues;
                }
            }
        }

        return [
            'id' => $actualOptionId,
            'options' => $actualOptions,
            'data' => $actualData,
            'values' => $actualValues
        ];
    }

    /**
     * Retrieve option data
     *
     * @param Option $option
     * @return array
     */
    protected function getOptionData(Option $option)
    {
        $result = [];
        foreach (array_keys($this->_assertOptions) as $assertKey) {
            $result[$assertKey] = $option->getData($assertKey);
        }

        return $result;
    }

    /**
     * Retrieve option values or false for options which has no values
     *
     * @param Option $option
     * @return array|bool
     */
    protected function getOptionValues(Option $option)
    {
        $values = $option->getValues();
        if (!empty($values)) {
            $result = [];
            /** @var $value Value */
            foreach ($values as $value) {
                $optionData = [];
                foreach (array_keys($this->_assertOptionValues) as $assertKey) {
                    if ($value->hasData($assertKey)) {
                        $optionData[$assertKey] = $value->getData($assertKey);
                    }
                }
                $result[] = $optionData;
            }
            return $result;
        }

        return false;
    }

    /**
     * Test that product import with images works properly
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoAppIsolation enabled
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSaveMediaImage()
    {
        $this->importDataForMediaTest('import_media.csv');

        $product = $this->getProductBySku('simple_new');

        $this->assertEquals('/m/a/magento_image.jpg', $product->getData('image'));
        $this->assertEquals('/m/a/magento_small_image.jpg', $product->getData('small_image'));
        $this->assertEquals('/m/a/magento_thumbnail.jpg', $product->getData('thumbnail'));
        $this->assertEquals('/m/a/magento_image.jpg', $product->getData('swatch_image'));

        $gallery = $product->getMediaGalleryImages();
        $this->assertInstanceOf(\Magento\Framework\Data\Collection::class, $gallery);

        $items = $gallery->getItems();
        $this->assertCount(5, $items);

        $imageItem = array_shift($items);
        $this->assertInstanceOf(DataObject::class, $imageItem);
        $this->assertEquals('/m/a/magento_image.jpg', $imageItem->getFile());
        $this->assertEquals('Image Label', $imageItem->getLabel());

        $smallImageItem = array_shift($items);
        $this->assertInstanceOf(DataObject::class, $smallImageItem);
        $this->assertEquals('/m/a/magento_small_image.jpg', $smallImageItem->getFile());
        $this->assertEquals('Small Image Label', $smallImageItem->getLabel());

        $thumbnailItem = array_shift($items);
        $this->assertInstanceOf(DataObject::class, $thumbnailItem);
        $this->assertEquals('/m/a/magento_thumbnail.jpg', $thumbnailItem->getFile());
        $this->assertEquals('Thumbnail Label', $thumbnailItem->getLabel());

        $additionalImageOneItem = array_shift($items);
        $this->assertInstanceOf(DataObject::class, $additionalImageOneItem);
        $this->assertEquals('/m/a/magento_additional_image_one.jpg', $additionalImageOneItem->getFile());
        $this->assertEquals('Additional Image Label One', $additionalImageOneItem->getLabel());

        $additionalImageTwoItem = array_shift($items);
        $this->assertInstanceOf(DataObject::class, $additionalImageTwoItem);
        $this->assertEquals('/m/a/magento_additional_image_two.jpg', $additionalImageTwoItem->getFile());
        $this->assertEquals('Additional Image Label Two', $additionalImageTwoItem->getLabel());
    }

    /**
     * Tests that "hide_from_product_page" attribute is hidden after importing product images.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoAppIsolation enabled
     */
    public function testSaveHiddenImages()
    {
        $this->importDataForMediaTest('import_media_hidden_images.csv');
        $product = $this->getProductBySku('simple_new');
        $images = $product->getMediaGalleryEntries();

        $hiddenImages = array_filter(
            $images,
            static function (DataObject $image) {
                return (int)$image->getDisabled() === 1;
            }
        );

        $this->assertCount(3, $hiddenImages);

        $imageItem = array_shift($hiddenImages);
        $this->assertEquals('/m/a/magento_image.jpg', $imageItem->getFile());

        $imageItem = array_shift($hiddenImages);
        $this->assertEquals('/m/a/magento_thumbnail.jpg', $imageItem->getFile());

        $imageItem = array_shift($hiddenImages);
        $this->assertEquals('/m/a/magento_additional_image_two.jpg', $imageItem->getFile());
    }

    /**
     * Tests importing product images with "no_selection" attribute.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoAppIsolation enabled
     */
    public function testSaveImagesNoSelection()
    {
        $this->importDataForMediaTest('import_media_with_no_selection.csv');
        $product = $this->getProductBySku('simple_new');

        $this->assertEquals('/m/a/magento_image.jpg', $product->getData('image'));
        $this->assertNull($product->getData('small_image'));
        $this->assertNull($product->getData('thumbnail'));
        $this->assertNull($product->getData('swatch_image'));
    }

    /**
     * Test that new images should be added after the existing ones.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoAppIsolation enabled
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testNewImagesShouldBeAddedAfterExistingOnes()
    {
        $this->importDataForMediaTest('import_media.csv');

        $product = $this->getProductBySku('simple_new');

        $items = array_values($product->getMediaGalleryImages()->getItems());

        $images = [
            ['file' => '/m/a/magento_image.jpg', 'label' => 'Image Label'],
            ['file' => '/m/a/magento_small_image.jpg', 'label' => 'Small Image Label'],
            ['file' => '/m/a/magento_thumbnail.jpg', 'label' => 'Thumbnail Label'],
            ['file' => '/m/a/magento_additional_image_one.jpg', 'label' => 'Additional Image Label One'],
            ['file' => '/m/a/magento_additional_image_two.jpg', 'label' => 'Additional Image Label Two'],
        ];

        $this->assertCount(5, $items);
        $this->assertEquals(
            $images,
            array_map(
                function (DataObject $item) {
                    return $item->toArray(['file', 'label']);
                },
                $items
            )
        );

        $this->importDataForMediaTest('import_media_additional_long_name_image.csv');
        $product->cleanModelCache();
        $product = $this->getProductBySku('simple_new');
        $items = array_values($product->getMediaGalleryImages()->getItems());
        $images[] = ['file' => '/m/a/' . self::LONG_FILE_NAME_IMAGE, 'label' => ''];
        $this->assertCount(6, $items);
        $this->assertEquals(
            $images,
            array_map(
                function (DataObject $item) {
                    return $item->toArray(['file', 'label']);
                },
                $items
            )
        );
    }

    /**
     * Test import twice and check that image will not be duplicate
     *
     * @magentoDataFixture mediaImportImageFixture
     * @return void
     */
    public function testSaveMediaImageDuplicateImages(): void
    {
        $this->importDataForMediaTest('import_media.csv');
        $imagesCount = count($this->getProductBySku('simple_new')->getMediaGalleryImages()->getItems());

        // import the same file again
        $this->importDataForMediaTest('import_media.csv');

        $this->assertCount($imagesCount, $this->getProductBySku('simple_new')->getMediaGalleryImages()->getItems());
    }

    /**
     * Test that errors occurred during importing images are logged.
     *
     * @magentoAppIsolation enabled
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture mediaImportImageFixtureError
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSaveMediaImageError()
    {
        $this->logger->expects(self::once())->method('critical');
        $this->importDataForMediaTest('import_media.csv', 1);
    }

    /**
     * Copy fixture images into media import directory
     */
    public static function mediaImportImageFixture()
    {
        /** @var Write $varDirectory */
        $varDirectory = BootstrapHelper::getObjectManager()->get(Filesystem::class)
            ->getDirectoryWrite(DirectoryList::VAR_DIR);

        $varDirectory->create('import' . DIRECTORY_SEPARATOR . 'images');
        $dirPath = $varDirectory->getAbsolutePath('import' . DIRECTORY_SEPARATOR . 'images');

        $items = [
            [
                'source' => __DIR__ . '/../../../../Magento/Catalog/_files/magento_image.jpg',
                'dest' => $dirPath . '/magento_image.jpg',
            ],
            [
                'source' => __DIR__ . '/../../../../Magento/Catalog/_files/magento_small_image.jpg',
                'dest' => $dirPath . '/magento_small_image.jpg',
            ],
            [
                'source' => __DIR__ . '/../../../../Magento/Catalog/_files/magento_thumbnail.jpg',
                'dest' => $dirPath . '/magento_thumbnail.jpg',
            ],
            [
                'source' => __DIR__ . '/../../../../Magento/Catalog/_files/' . self::LONG_FILE_NAME_IMAGE,
                'dest' => $dirPath . '/' . self::LONG_FILE_NAME_IMAGE,
            ],
            [
                'source' => __DIR__ . '/_files/magento_additional_image_one.jpg',
                'dest' => $dirPath . '/magento_additional_image_one.jpg',
            ],
            [
                'source' => __DIR__ . '/_files/magento_additional_image_two.jpg',
                'dest' => $dirPath . '/magento_additional_image_two.jpg',
            ],
            [
                'source' => __DIR__ . '/_files/magento_additional_image_three.jpg',
                'dest' => $dirPath . '/magento_additional_image_three.jpg',
            ],
            [
                'source' => __DIR__ . '/_files/magento_additional_image_four.jpg',
                'dest' => $dirPath . '/magento_additional_image_four.jpg',
            ],
        ];

        foreach ($items as $item) {
            copy($item['source'], $item['dest']);
        }
    }

    /**
     * Cleanup media import and catalog directories
     */
    public static function mediaImportImageFixtureRollback()
    {
        $fileSystem = BootstrapHelper::getObjectManager()->get(Filesystem::class);
        /** @var Write $mediaDirectory */
        $mediaDirectory = $fileSystem->getDirectoryWrite(DirectoryList::MEDIA);

        /** @var Write $varDirectory */
        $varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varDirectory->delete('import');
        $mediaDirectory->delete('catalog');
    }

    /**
     * Copy incorrect fixture image into media import directory.
     */
    public static function mediaImportImageFixtureError()
    {
        /** @var Write $varDirectory */
        $varDirectory = BootstrapHelper::getObjectManager()->get(Filesystem::class)
            ->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dirPath = $varDirectory->getAbsolutePath('import' . DIRECTORY_SEPARATOR . 'images');
        $items = [
            [
                'source' => __DIR__ . '/_files/magento_additional_image_error.jpg',
                'dest' => $dirPath . '/magento_additional_image_two.jpg',
            ],
        ];
        foreach ($items as $item) {
            copy($item['source'], $item['dest']);
        }
    }

    /**
     * Export CSV string to array
     *
     * @param string $content
     * @param mixed $entityId
     * @return array
     */
    protected function csvToArray($content, $entityId = null)
    {
        $data = ['header' => [], 'data' => []];

        $lines = str_getcsv($content, "\n");
        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $data['header'] = str_getcsv($line);
            } else {
                $row = array_combine($data['header'], str_getcsv($line));
                if ($entityId !== null && !empty($row[$entityId])) {
                    $data['data'][$row[$entityId]] = $row;
                } else {
                    $data['data'][] = $row;
                }
            }
        }
        return $data;
    }

    /**
     * Tests that no products imported if source file contains errors
     *
     * In this case, the second product data has an invalid attribute set.
     *
     * @magentoDbIsolation enabled
     */
    public function testInvalidSkuLink()
    {
        // import data from CSV file
        $pathToFile = __DIR__ . '/_files/products_to_import_invalid_attribute_set.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $params = [
            'behavior' => Import::BEHAVIOR_APPEND,
            Import::FIELD_NAME_VALIDATION_STRATEGY => null,
            'entity' => 'catalog_product'
        ];

        $errors = $this->model->setParameters($params)
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 1);
        $this->assertEquals(
            'Invalid value for Attribute Set column (set doesn\'t exist?)',
            $errors->getErrorByRowNumber(1)[0]->getErrorMessage()
        );
        $this->model->importData();

        $productCollection = $this->objectManager->create(ProductResource\Collection::class);

        $products = [];
        /** @var $product Product */
        foreach ($productCollection as $product) {
            $products[$product->getSku()] = $product;
        }
        $this->assertArrayNotHasKey("simple1", $products, "Simple Product should not have been imported");
        $this->assertArrayNotHasKey("simple3", $products, "Simple Product 3 should not have been imported");
        $this->assertArrayNotHasKey("simple2", $products, "Simple Product2 should not have been imported");
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/products_with_multiselect_attribute.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testValidateInvalidMultiselectValues()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_with_invalid_multiselect_values.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 1);
        $this->assertEquals(
            "Value for 'multiselect_attribute' attribute contains incorrect value, "
            . "see acceptable values on settings specified for Admin",
            $errors->getErrorByRowNumber(1)[0]->getErrorMessage()
        );
    }

    /**
     * Test validate multiselect values with custom separator
     *
     * @magentoDataFixture Magento/Catalog/_files/products_with_multiselect_attribute.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     *
     * @return void
     */
    public function testValidateMultiselectValuesWithCustomSeparator(): void
    {
        $pathToFile = __DIR__ . '/_files/products_with_custom_multiselect_values_separator.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(Csv::class, ['file' => $pathToFile, 'directory' => $directory]);
        $params = [
            'behavior' => Import::BEHAVIOR_ADD_UPDATE,
            'entity' => 'catalog_product',
            Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR => '|||'
        ];

        $errors = $this->model->setParameters($params)
            ->setSource($source)
            ->validateData();

        $this->assertEmpty($errors->getAllErrors());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/categories.php
     * @magentoDataFixture Magento/Store/_files/website.php
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/Model/Layer/Filter/_files/attribute_with_option.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testProductsWithMultipleStores()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_multiple_stores.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $id = $product->getIdBySku('Configurable 03');
        $product->load($id);
        $this->assertEquals('1', $product->getHasOptions());

        $this->objectManager->get(StoreManagerInterface::class)->setCurrentStore('fixturestore');

        /** @var Product $simpleProduct */
        $simpleProduct = $this->objectManager->create(Product::class);
        $id = $simpleProduct->getIdBySku('Configurable 03-Option 1');
        $simpleProduct->load($id);
        $this->assertTrue(count($simpleProduct->getWebsiteIds()) == 2);
        $this->assertEquals('Option Label', $simpleProduct->getAttributeText('attribute_with_option'));
    }

    /**
     * Test url keys properly generated in multistores environment.
     *
     * @magentoConfigFixture current_store catalog/seo/product_use_categories 1
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/_files/category_with_two_stores.php
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testGenerateUrlsWithMultipleStores()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_two_stores.csv',
                'directory' => $directory
            ]
        );
        $params = ['behavior' => Import::BEHAVIOR_ADD_UPDATE, 'entity' => 'catalog_product'];
        $errors = $this->model->setParameters($params)
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();
        $this->assertProductRequestPath('default', 'category-defaultstore/product-default.html');
        $this->assertProductRequestPath('fixturestore', 'category-fixturestore/product-fixture.html');
    }

    /**
     * Check product request path considering store scope.
     *
     * @param string $storeCode
     * @param string $expected
     * @return void
     */
    private function assertProductRequestPath($storeCode, $expected)
    {
        /** @var Store $storeCode */
        $store = $this->objectManager->get(Store::class);
        $storeId = $store->load($storeCode)->getId();

        /** @var Category $category */
        $category = $this->objectManager->get(Category::class);
        $category->setStoreId($storeId);
        $category->load(555);

        /** @var Registry $registry */
        $registry = $this->objectManager->get(Registry::class);
        $registry->register('current_category', $category);

        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $id = $product->getIdBySku('product');
        $product->setStoreId($storeId);
        $product->load($id);
        $product->getProductUrl();
        self::assertEquals($expected, $product->getRequestPath());
        $registry->unregister('current_category');
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testProductWithInvalidWeight()
    {
        // import data from CSV file
        $pathToFile = __DIR__ . '/_files/product_to_import_invalid_weight.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND])
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 1);
        $this->assertEquals(
            "Value for 'weight' attribute contains incorrect value",
            $errors->getErrorByRowNumber(1)[0]->getErrorMessage()
        );
    }

    /**
     * @magentoAppArea adminhtml
     * @dataProvider categoryTestDataProvider
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testProductCategories($fixture, $separator)
    {
        // import data from CSV file
        $pathToFile = __DIR__ . '/_files/' . $fixture;
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(Csv::class, ['file' => $pathToFile, 'directory' => $directory]);
        $errors = $this->model->setSource($source)
            ->setParameters(
                [
                    'behavior' => Import::BEHAVIOR_APPEND,
                    'entity' => 'catalog_product',
                    Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR => $separator
                ]
            )
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $resource = $this->objectManager->get(ProductResource::class);
        $productId = $resource->getIdBySku('simple1');
        $this->assertIsNumeric($productId);
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->load($productId);
        $this->assertFalse($product->isObjectNew());
        $categories = $product->getCategoryIds();
        $this->assertTrue(count($categories) == 2);
    }

    /**
     * @magentoAppArea adminhtml
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoDataFixture Magento/Catalog/_files/category.php
     */
    public function testProductPositionInCategory()
    {
        /* @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Category\Collection::class);
        $collection->addNameToResult()->load();
        /** @var Category $category */
        $category = $collection->getItemByColumnValue('name', 'Category 1');

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);

        $categoryProducts = [];
        $i = 51;
        foreach (['simple1', 'simple2', 'simple3'] as $sku) {
            $categoryProducts[$productRepository->get($sku)->getId()] = $i++;
        }
        $category->setPostedProducts($categoryProducts);
        $category->save();

        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $tableName = $resourceConnection->getTableName('catalog_category_product');
        $select = $resourceConnection->getConnection()->select()->from($tableName)
            ->where('category_id = ?', $category->getId());
        $items = $resourceConnection->getConnection()->fetchAll($select);
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertGreaterThan(50, $item['position']);
        }
    }

    /**
     * @return array
     */
    public function categoryTestDataProvider()
    {
        return [
            ['import_new_categories_default_separator.csv', ','],
            ['import_new_categories_custom_separator.csv', '|']
        ];
    }

    /**
     * @magentoAppArea adminhtml
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/CatalogImportExport/_files/update_category_duplicates.php
     */
    public function testProductDuplicateCategories()
    {
        $csvFixture = 'products_duplicate_category.csv';
        // import data from CSV file
        $pathToFile = __DIR__ . '/_files/' . $csvFixture;
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() === 0);

        $this->model->importData();

        $errorProcessor = $this->objectManager->get(ProcessingErrorAggregator::class);
        $errorCount = count($errorProcessor->getAllErrors());
        $this->assertTrue($errorCount === 1, 'Error expected');

        $errorMessage = $errorProcessor->getAllErrors()[0]->getErrorMessage();
        $this->assertStringContainsString('URL key for specified store already exists', $errorMessage);
        $this->assertStringContainsString('Default Category/Category 2', $errorMessage);

        $categoryAfter = $this->loadCategoryByName('Category 2');
        $this->assertTrue($categoryAfter === null);

        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->load(1);
        $categories = $product->getCategoryIds();
        $this->assertTrue(count($categories) == 1);
    }

    protected function loadCategoryByName($categoryName)
    {
        /* @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Category\Collection::class);
        $collection->addNameToResult()->load();
        return $collection->getItemByColumnValue('name', $categoryName);
    }

    /**
     * @magentoDataFixture Magento/Catalog/Model/ResourceModel/_files/product_simple.php
     * @magentoAppIsolation enabled
     * @dataProvider validateUrlKeysDataProvider
     * @param $importFile string
     * @param $expectedErrors array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testValidateUrlKeys($importFile, $expectedErrors)
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/' . $importFile,
                'directory' => $directory
            ]
        );
        /** @var ProcessingErrorAggregatorInterface $errors */
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();
        $this->assertEquals(
            $expectedErrors[RowValidatorInterface::ERROR_DUPLICATE_URL_KEY],
            count($errors->getErrorsByCode([RowValidatorInterface::ERROR_DUPLICATE_URL_KEY]))
        );
    }

    /**
     * @return array
     */
    public function validateUrlKeysDataProvider()
    {
        return [
            [
                'products_to_check_valid_url_keys.csv',
                 [
                     RowValidatorInterface::ERROR_DUPLICATE_URL_KEY => 0
                 ]
            ],
            [
                'products_to_check_valid_url_keys_with_different_language.csv',
                [
                    RowValidatorInterface::ERROR_DUPLICATE_URL_KEY => 0
                ]
            ],
            [
                'products_to_check_duplicated_url_keys.csv',
                [
                    RowValidatorInterface::ERROR_DUPLICATE_URL_KEY => 2
                ]
            ],
            [
                'products_to_check_duplicated_names.csv' ,
                [
                    RowValidatorInterface::ERROR_DUPLICATE_URL_KEY => 1
                ]
            ]
        ];
    }

    /**
     * @magentoDataFixture Magento/Store/_files/website.php
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/Model/ResourceModel/_files/product_simple.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testValidateUrlKeysMultipleStores()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_check_valid_url_keys_multiple_stores.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
    }

    /**
     * Test import product with product links and empty value
     *
     * @param string $pathToFile
     * @param bool $expectedResultCrossell
     * @param bool $expectedResultUpsell
     *
     * @magentoDataFixture Magento/CatalogImportExport/_files/product_export_with_product_links_data.php
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     * @dataProvider getEmptyLinkedData
     */
    public function testProductLinksWithEmptyValue(
        string $pathToFile,
        bool $expectedResultCrossell,
        bool $expectedResultUpsell
    ): void {
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $resource = $this->objectManager->get(ProductResource::class);
        $productId = $resource->getIdBySku('simple');
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->load($productId);

        $this->assertEquals(empty($product->getCrossSellProducts()), $expectedResultCrossell);
        $this->assertEquals(empty($product->getUpSellProducts()), $expectedResultUpsell);
    }

    /**
     * Get data for empty linked product
     *
     * @return array[]
     */
    public function getEmptyLinkedData(): array
    {
        return [
            [
                __DIR__ . '/_files/products_to_import_with_product_links_with_empty_value.csv',
                true,
                true,
            ],
            [
                __DIR__ . '/_files/products_to_import_with_product_links_with_empty_data.csv',
                false,
                true,
            ],
        ];
    }

    /**
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testProductWithLinks()
    {
        $linksData = [
            'upsell' => [
                'simple1' => '3',
                'simple3' => '1'
            ],
            'crosssell' => [
                'simple2' => '1',
                'simple3' => '2'
            ],
            'related' => [
                'simple1' => '2',
                'simple2' => '1'
            ]
        ];
        // import data from CSV file
        $pathToFile = __DIR__ . '/_files/products_to_import_with_product_links.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $resource = $this->objectManager->get(ProductResource::class);
        $productId = $resource->getIdBySku('simple4');
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->load($productId);
        $productLinks = [
            'upsell' => $product->getUpSellProducts(),
            'crosssell' => $product->getCrossSellProducts(),
            'related' => $product->getRelatedProducts()
        ];
        $importedProductLinks = [];
        foreach ($productLinks as $linkType => $linkedProducts) {
            foreach ($linkedProducts as $linkedProductData) {
                $importedProductLinks[$linkType][$linkedProductData->getSku()] = $linkedProductData->getPosition();
            }
        }
        $this->assertEquals($linksData, $importedProductLinks);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_url_key.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testExistingProductWithUrlKeys()
    {
        $products = [
            'simple1' => 'url-key1',
            'simple2' => 'url-key2',
            'simple3' => 'url-key3'
        ];
        // added by _files/products_to_import_with_valid_url_keys.csv
        $this->importedProducts[] = 'simple3';

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_valid_url_keys.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($products as $productSku => $productUrlKey) {
            $this->assertEquals($productUrlKey, $productRepository->get($productSku)->getUrlKey());
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_wrong_url_key.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testAddUpdateProductWithInvalidUrlKeys() : void
    {
        $products = [
            'simple1' => 'cuvée merlot-cabernet igp pays d\'oc frankrijk',
            'simple2' => 'normal-url',
            'simple3' => 'some!wrong\'url'
        ];
        // added by _files/products_to_import_with_invalid_url_keys.csv
        $this->importedProducts[] = 'simple3';

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_invalid_url_keys.csv',
                'directory' => $directory
            ]
        );

        $params = ['behavior' => Import::BEHAVIOR_ADD_UPDATE, 'entity' => 'catalog_product'];
        $errors = $this->model->setParameters($params)
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $productRepository = $this->objectManager->create(
            ProductRepositoryInterface::class
        );
        foreach ($products as $productSku => $productUrlKey) {
            $this->assertEquals($productUrlKey, $productRepository->get($productSku)->getUrlKey());
        }
    }

    /**
     * Make sure the non existing image in the csv file won't erase the qty key of the existing products.
     *
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testImportWithNonExistingImage()
    {
        $products = [
            'simple_new' => 100,
        ];

        $this->importFile('products_to_import_with_non_existing_image.csv');

        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($products as $productSku => $productQty) {
            $product = $productRepository->get($productSku);
            $stockItem = $product->getExtensionAttributes()->getStockItem();
            $this->assertEquals($productQty, $stockItem->getQty());
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_without_options.php
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testUpdateUrlRewritesOnImport()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_category.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => Product::ENTITY])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var Product $product */
        $product = $this->objectManager->create(ProductRepository::class)->get('simple');
        $listOfProductUrlKeys = [
            sprintf('%s.html', $product->getUrlKey()),
            sprintf('men/tops/%s.html', $product->getUrlKey()),
            sprintf('men/%s.html', $product->getUrlKey())
        ];
        $repUrlRewriteCol = $this->objectManager->create(
            UrlRewriteCollection::class
        );

        /** @var UrlRewriteCollection $collUrlRewrite */
        $collUrlRewrite = $repUrlRewriteCol->addFieldToSelect(['request_path'])
            ->addFieldToFilter('entity_id', ['eq'=> $product->getEntityId()])
            ->addFieldToFilter('entity_type', ['eq'=> 'product'])
            ->load();
        $listOfUrlRewriteIds = $collUrlRewrite->getAllIds();
        $this->assertCount(3, $collUrlRewrite);

        foreach ($listOfUrlRewriteIds as $key => $id) {
            $this->assertEquals(
                $listOfProductUrlKeys[$key],
                $collUrlRewrite->getItemById($id)->getRequestPath()
            );
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_url_key.php
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testImportWithoutChangingUrlKeys()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_without_url_key_column.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(
            ['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product']
        )
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->assertEquals('url-key', $productRepository->get('simple1')->getUrlKey());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_url_key.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testImportWithoutUrlKeys()
    {
        $products = [
            'simple1' => 'simple-1',
            'simple2' => 'simple-2',
            'simple3' => 'simple-3'
        ];
        // added by _files/products_to_import_without_url_keys.csv
        $this->importedProducts[] = 'simple3';

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_without_url_keys.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(
            ['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product']
        )
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($products as $productSku => $productUrlKey) {
            $this->assertEquals($productUrlKey, $productRepository->get($productSku)->getUrlKey());
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_non_latin_url_key.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testImportWithNonLatinUrlKeys()
    {
        $productsCreatedByFixture = [
            'ukrainian-with-url-key' => 'nove-im-ja-pislja-importu-scho-stane-url-key',
            'ukrainian-without-url-key' => 'новий url key після імпорту',
        ];
        $productsImportedByCsv = [
            'imported-ukrainian-with-url-key' => 'імпортований продукт',
            'imported-ukrainian-without-url-key' => 'importovanij-produkt-bez-url-key',
        ];
        $productSkuMap = array_merge($productsCreatedByFixture, $productsImportedByCsv);
        $this->importedProducts = array_keys($productsImportedByCsv);

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_non_latin_url_keys.csv',
                'directory' => $directory,
            ]
        );

        $params = ['behavior' => Import::BEHAVIOR_ADD_UPDATE, 'entity' => 'catalog_product'];
        $errors = $this->model->setParameters($params)
            ->setSource($source)
            ->validateData();

        $this->assertEquals($errors->getErrorsCount(), 0);
        $this->model->importData();

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($productSkuMap as $productSku => $productUrlKey) {
            $this->assertEquals($productUrlKey, $productRepository->get($productSku)->getUrlKey());
        }
    }

    /**
     * Make sure the absence of a url_key column in the csv file won't erase the url key of the existing products.
     * To reach the goal we need to not send the name column, as the url key is generated from it.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_url_key.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testImportWithoutUrlKeysAndName()
    {
        $products = [
            'simple1' => 'url-key',
            'simple2' => 'url-key2',
        ];
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_without_url_keys_and_name.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        foreach ($products as $productSku => $productUrlKey) {
            $this->assertEquals($productUrlKey, $productRepository->get($productSku)->getUrlKey());
        }
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testProductWithUseConfigSettings()
    {
        $products = [
            'simple1' => true,
            'simple2' => true,
            'simple3' => false
        ];
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_use_config_settings.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        foreach ($products as $sku => $manageStockUseConfig) {
            /** @var StockRegistry $stockRegistry */
            $stockRegistry = $this->objectManager->create(
                StockRegistry::class
            );
            $stockItem = $stockRegistry->getStockItemBySku($sku);
            $this->assertEquals($manageStockUseConfig, $stockItem->getUseConfigManageStock());
        }
    }

    /**
     * @magentoDataFixture Magento/Store/_files/website.php
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     */
    public function testProductWithMultipleStoresInDifferentBunches()
    {
        $products = [
            'simple1',
            'simple2',
            'simple3'
        ];

        $importExportData = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $importExportData->expects($this->atLeastOnce())
            ->method('getBunchSize')
            ->willReturn(1);
        $this->model = $this->objectManager->create(
            \Magento\CatalogImportExport\Model\Import\Product::class,
            ['importExportData' => $importExportData]
        );

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_multiple_store.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();
        $productCollection = $this->objectManager->create(ProductResource\Collection::class);
        $this->assertCount(3, $productCollection->getItems());
        $actualProductSkus = array_map(
            function (ProductInterface $item) {
                return $item->getSku();
            },
            $productCollection->getItems()
        );
        sort($products);
        $result = array_values($actualProductSkus);
        sort($result);
        $this->assertEquals(
            $products,
            $result
        );

        /** @var Registry $registry */
        $registry = $this->objectManager->get(Registry::class);

        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);

        $productSkuList = ['simple1', 'simple2', 'simple3'];
        $categoryIds = [];
        foreach ($productSkuList as $sku) {
            try {
                /** @var ProductRepositoryInterface $productRepository */
                $productRepository = $this->objectManager
                    ->get(ProductRepositoryInterface::class);
                /** @var Product $product */
                $product = $productRepository->get($sku, true);
                $categoryIds[] = $product->getCategoryIds();
                if ($product->getId()) {
                    $productRepository->delete($product);
                }
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                //Product already removed
            }
        }

        /** @var ProductResource\Collection $collection */
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Category\Collection::class);
        $collection->addAttributeToFilter(
            'entity_id',
            ['in' => \array_unique(\array_merge([], ...$categoryIds))]
        )
            ->load()
            ->delete();

        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', false);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/multiselect_attribute_with_incorrect_values.php
     * @magentoDataFixture Magento/Catalog/_files/product_text_attribute.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testProductWithWrappedAdditionalAttributes()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_additional_attributes.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(
            ['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product', Import::FIELDS_ENCLOSURE => 1]
        )
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);

        /** @var AttributeOptionManagementInterface $multiselectOptions */
        $multiselectOptions = $this->objectManager->get(AttributeOptionManagementInterface::class)
            ->getItems(Product::ENTITY, 'multiselect_attribute');

        $product1 = $productRepository->get('simple1');
        $this->assertEquals('\'", =|', $product1->getData('text_attribute'));
        $this->assertEquals(
            implode(',', [$multiselectOptions[3]->getValue(), $multiselectOptions[2]->getValue()]),
            $product1->getData('multiselect_attribute')
        );

        $product2 = $productRepository->get('simple2');
        $this->assertEquals('', $product2->getData('text_attribute'));
        $this->assertEquals(
            implode(',', [$multiselectOptions[1]->getValue(), $multiselectOptions[2]->getValue()]),
            $product2->getData('multiselect_attribute')
        );
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_text_attribute.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @dataProvider importWithJsonAndMarkupTextAttributeDataProvider
     * @param string $productSku
     * @param string $expectedResult
     * @return void
     */
    public function testImportWithJsonAndMarkupTextAttribute(string $productSku, string $expectedResult): void
    {
        // added by _files/product_import_with_json_and_markup_attributes.csv
        $this->importedProducts = [
            'SkuProductWithJson',
            'SkuProductWithMarkup',
        ];

        $importParameters =[
            'behavior' => Import::BEHAVIOR_APPEND,
            'entity' => 'catalog_product',
            Import::FIELDS_ENCLOSURE => 0
        ];
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_json_and_markup_attributes.csv',
                'directory' => $directory
            ]
        );
        $this->model->setParameters($importParameters);
        $this->model->setSource($source);
        $errors = $this->model->validateData();
        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $product = $productRepository->get($productSku);
        $this->assertEquals($expectedResult, $product->getData('text_attribute'));
    }

    /**
     * @return array
     */
    public function importWithJsonAndMarkupTextAttributeDataProvider(): array
    {
        return [
            'import of attribute with json' => [
                'SkuProductWithJson',
                '{"type": "basic", "unit": "inch", "sign": "(\")", "size": "1.5\""}'
            ],
            'import of attribute with markup' => [
                'SkuProductWithMarkup',
                '<div data-content>Element type is basic, measured in inches ' .
                '(marked with sign (\")) with size 1.5\", mid-price range</div>'
            ],
        ];
    }

    /**
     * Import and check data from file.
     *
     * @param string $fileName
     * @param int $expectedErrors
     * @return void
     */
    private function importDataForMediaTest(string $fileName, int $expectedErrors = 0)
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/' . $fileName,
                'directory' => $directory
            ]
        );
        $this->model->setParameters(
            [
                'behavior' => Import::BEHAVIOR_APPEND,
                'entity' => 'catalog_product',
                'import_images_file_dir' => 'pub/media/import'
            ]
        );
        $appParams = BootstrapHelper::getInstance()
            ->getBootstrap()
            ->getApplication()
            ->getInitParams()[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS];
        $uploader = $this->model->getUploader();

        $mediaPath = $appParams[DirectoryList::MEDIA][DirectoryList::PATH];
        $varPath = $appParams[DirectoryList::VAR_DIR][DirectoryList::PATH];
        $destDir = $directory->getRelativePath(
            $mediaPath . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product'
        );
        $tmpDir = $directory->getRelativePath(
            $varPath . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'images'
        );

        $directory->create($destDir);
        $this->assertTrue($uploader->setDestDir($destDir));
        $this->assertTrue($uploader->setTmpDir($tmpDir));
        $errors = $this->model->setSource($source)
            ->validateData();
        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();
        $this->assertEquals(
            $expectedErrors,
            $this->model->getErrorAggregator()->getErrorsCount(),
            array_reduce(
                $this->model->getErrorAggregator()->getAllErrors(),
                function ($output, $error) {
                    return "$output\n{$error->getErrorMessage()}";
                },
                ''
            )
        );
    }

    /**
     * Load product by given product sku
     *
     * @param string $sku
     * @param mixed $store
     * @return Product
     */
    private function getProductBySku($sku, $store = null)
    {
        $resource = $this->objectManager->get(ProductResource::class);
        $productId = $resource->getIdBySku($sku);
        $product = $this->objectManager->create(Product::class);
        if ($store) {
            /** @var StoreManagerInterface $storeManager */
            $storeManager = $this->objectManager->get(StoreManagerInterface::class);
            $store = $storeManager->getStore($store);
            $product->setStoreId($store->getId());
        }
        $product->load($productId);

        return $product;
    }

    /**
     * @param array $row
     * @param string|null $behavior
     * @param bool $expectedResult
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/Model/ResourceModel/_files/product_simple.php
     * @dataProvider validateRowDataProvider
     */
    public function testValidateRow(array $row, $behavior, $expectedResult)
    {
        $this->model->setParameters(['behavior' => $behavior, 'entity' => 'catalog_product']);
        $this->assertSame($expectedResult, $this->model->validateRow($row, 1));
    }

    /**
     * @return array
     */
    public function validateRowDataProvider()
    {
        return [
            [
                'row' => ['sku' => 'simple products'],
                'behavior' => null,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'simple products absent'],
                'behavior' => null,
                'expectedResult' => false,
            ],
            [
                'row' => [
                    'sku' => 'simple products absent',
                    'name' => 'Test',
                    'product_type' => 'simple',
                    '_attribute_set' => 'Default',
                    'price' => 10.20,
                ],
                'behavior' => null,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'simple products'],
                'behavior' => Import::BEHAVIOR_ADD_UPDATE,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'simple products absent'],
                'behavior' => Import::BEHAVIOR_ADD_UPDATE,
                'expectedResult' => false,
            ],
            [
                'row' => [
                    'sku' => 'simple products absent',
                    'name' => 'Test',
                    'product_type' => 'simple',
                    '_attribute_set' => 'Default',
                    'price' => 10.20,
                ],
                'behavior' => Import::BEHAVIOR_ADD_UPDATE,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'simple products'],
                'behavior' => Import::BEHAVIOR_DELETE,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'simple products absent'],
                'behavior' => Import::BEHAVIOR_DELETE,
                'expectedResult' => false,
            ],
            [
                'row' => ['sku' => 'simple products'],
                'behavior' => Import::BEHAVIOR_REPLACE,
                'expectedResult' => false,
            ],
            [
                'row' => ['sku' => 'simple products absent'],
                'behavior' => Import::BEHAVIOR_REPLACE,
                'expectedResult' => false,
            ],
            [
                'row' => [
                    'sku' => 'simple products absent',
                    'name' => 'Test',
                    'product_type' => 'simple',
                    '_attribute_set' => 'Default',
                    'price' => 10.20,
                ],
                'behavior' => Import::BEHAVIOR_REPLACE,
                'expectedResult' => false,
            ],
            [
                'row' => [
                    'sku' => 'simple products',
                    'name' => 'Test',
                    'product_type' => 'simple',
                    '_attribute_set' => 'Default',
                    'price' => 10.20,
                ],
                'behavior' => Import::BEHAVIOR_REPLACE,
                'expectedResult' => true,
            ],
            [
                'row' => ['sku' => 'sku with whitespace ',
                    'name' => 'Test',
                    'product_type' => 'simple',
                    '_attribute_set' => 'Default',
                    'price' => 10.20,
                ],
                'behavior' => Import::BEHAVIOR_ADD_UPDATE,
                'expectedResult' => false,
            ],
        ];
    }

    /**
     * @covers \Magento\ImportExport\Model\Import\Entity\AbstractEntity::_saveValidatedBunches
     */
    public function testValidateData()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import.csv',
                'directory' => $directory
            ]
        );

        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
    }

    /**
     * Tests situation when images for importing products are already present in filesystem.
     *
     * @magentoDataFixture Magento/CatalogImportExport/Model/Import/_files/import_with_filesystem_images.php
     * @magentoAppIsolation enabled
     */
    public function testImportWithFilesystemImages()
    {
        /** @var Filesystem $filesystem */
        $filesystem = ObjectManager::getInstance()->get(Filesystem::class);
        $writeAdapter = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        if (!$writeAdapter->isWritable()) {
            $this->markTestSkipped('Due to unwritable media directory');
        }

        $this->importDataForMediaTest('import_media_existing_images.csv');
    }

    /**
     * Test if we can change attribute set for product via import.
     *
     * @magentoDataFixture Magento/Catalog/_files/attribute_set_with_renamed_group.php
     * @magentoDataFixture Magento/Catalog/_files/product_without_options.php
     * @magentoDataFixture Magento/Catalog/_files/second_product_simple.php
     * @magentoDbIsolation enabled
     */
    public function testImportDataChangeAttributeSet()
    {
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_new_attribute_set.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => Product::ENTITY])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        /** @var Product[] $products */
        $products[] = $this->objectManager->create(ProductRepository::class)->get('simple');
        $products[] = $this->objectManager->create(ProductRepository::class)->get('simple2');

        /** @var Config $catalogConfig */
        $catalogConfig = $this->objectManager->create(Config::class);

        /** @var \Magento\Eav\Model\Config $eavConfig */
        $eavConfig = $this->objectManager->create(\Magento\Eav\Model\Config::class);

        $entityTypeId = (int)$eavConfig->getEntityType(Product::ENTITY)
            ->getId();

        foreach ($products as $product) {
            $attributeSetName = $catalogConfig->getAttributeSetName($entityTypeId, $product->getAttributeSetId());
            $this->assertEquals('attribute_set_test', $attributeSetName);
        }
    }

    /**
     * Test importing products with changed SKU letter case.
     */
    public function testImportWithDifferentSkuCase()
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        /** @var SearchCriteria $searchCriteria */
        $searchCriteria = $this->objectManager->create(SearchCriteria::class);

        $importedPrices = [
            'simple1' => 25,
            'simple2' => 34,
            'simple3' => 58,
        ];
        $updatedPrices = [
            'simple1' => 111,
            'simple2' => 222,
            'simple3' => 333,
        ];

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_ADD_UPDATE, 'entity' => Product::ENTITY])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        $this->assertCount(3, $productRepository->getList($searchCriteria)->getItems());
        foreach ($importedPrices as $sku => $expectedPrice) {
            $this->assertEquals($expectedPrice, $productRepository->get($sku)->getPrice());
        }

        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/products_to_import_with_changed_sku_case.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_ADD_UPDATE, 'entity' => Product::ENTITY])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);

        $this->model->importData();

        $this->assertCount(
            3,
            $productRepository->getList($searchCriteria)->getItems(),
            'Ensures that new products were not created'
        );
        foreach ($updatedPrices as $sku => $expectedPrice) {
            $this->assertEquals(
                $expectedPrice,
                $productRepository->get($sku, false, null, true)->getPrice(),
                'Check that all products were updated'
            );
        }
    }

    /**
     * Test that product import with images for non-default store works properly.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoAppIsolation enabled
     */
    public function testImportImageForNonDefaultStore()
    {
        $this->importDataForMediaTest('import_media_two_stores.csv');
        $product = $this->getProductBySku('simple_with_images');
        $mediaGallery = $product->getData('media_gallery');
        foreach ($mediaGallery['images'] as $image) {
            $image['file'] === '/m/a/magento_image.jpg'
                ? self::assertSame('1', $image['disabled'])
                : self::assertSame('0', $image['disabled']);
        }
    }

    /**
     * Test import product into multistore system when media is disabled.
     *
     * @magentoDataFixture Magento/CatalogImportExport/Model/Import/_files/custom_category_store_media_disabled.php
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testProductsWithMultipleStoresWhenMediaIsDisabled(): void
    {
        $this->loginAdminUserWithUsername(\Magento\TestFramework\Bootstrap::ADMIN_NAME);

        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . '/_files/product_with_custom_store_media_disabled.csv',
                'directory' => $directory,
            ]
        );
        $errors = $this->model->setParameters(['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product'])
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() === 0);
        $this->assertTrue($this->model->importData());
    }

    /**
     * Test that imported product stock status with backorders functionality enabled can be set to 'out of stock'.
     *
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     *
     * @return void
     */
    public function testImportWithBackordersEnabled(): void
    {
        $this->importFile('products_to_import_with_backorders_enabled_and_0_qty.csv');
        $product = $this->getProductBySku('simple_new');
        $this->assertFalse($product->getDataByKey('quantity_and_stock_status')['is_in_stock']);
    }

    /**
     * Test that imported product stock status with stock quantity > 0 and backorders functionality disabled
     * can be set to 'out of stock'.
     *
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     */
    public function testImportWithBackordersDisabled(): void
    {
        $this->importFile('products_to_import_with_backorders_disabled_and_not_0_qty.csv');
        $product = $this->getProductBySku('simple_new');
        $this->assertFalse($product->getDataByKey('quantity_and_stock_status')['is_in_stock']);
    }

    /**
     * Import file by providing import filename and bunch size.
     *
     * @param string $fileName
     * @param int $bunchSize
     * @return bool
     */
    private function importFile(string $fileName, int $bunchSize = 100): bool
    {
        $importExportData = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $importExportData->expects($this->atLeastOnce())
            ->method('getBunchSize')
            ->willReturn($bunchSize);
        $this->model = $this->objectManager->create(
            ImportProduct::class,
            ['importExportData' => $importExportData]
        );
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => __DIR__ . DIRECTORY_SEPARATOR . '_files' . DIRECTORY_SEPARATOR . $fileName,
                'directory' => $directory,
            ]
        );
        $errors = $this->model->setParameters(
                ['behavior' => Import::BEHAVIOR_APPEND, 'entity' => 'catalog_product', Import::FIELDS_ENCLOSURE => 1]
            )
            ->setSource($source)
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() === 0);

        return $this->model->importData();
    }

    /**
     * Hide product images via hide_from_product_page attribute during import CSV.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     *
     * @return void
     */
    public function testImagesAreHiddenAfterImport(): void
    {
        $expectedActiveImages = [
            [
                'file' => '/m/a/magento_additional_image_one.jpg',
                'label' => 'Additional Image Label One',
                'disabled' => '0',
            ],
            [
                'file' => '/m/a/magento_additional_image_two.jpg',
                'label' => 'Additional Image Label Two',
                'disabled' => '0',
            ],
        ];

        $expectedHiddenImage = [
            'file' => '/m/a/magento_image.jpg',
            'label' => 'Image Alt Text',
            'disabled' => '1',
        ];
        $expectedAllProductImages = array_merge(
            [$expectedHiddenImage],
            $expectedActiveImages
        );

        $this->importDataForMediaTest('hide_from_product_page_images.csv');
        $actualAllProductImages = [];
        $product = $this->getProductBySku('simple');

        // Check that new images were imported and existing image is disabled after import
        $productMediaData = $product->getData('media_gallery');

        $this->assertNotEmpty($productMediaData['images']);
        $allProductImages = $productMediaData['images'];
        $this->assertCount(3, $allProductImages, 'Images were imported incorrectly');

        foreach ($allProductImages as $image) {
            $actualAllProductImages[] = [
                'file' => $image['file'],
                'label' => $image['label'],
                'disabled' => $image['disabled'],
            ];
        }

        $this->assertEquals(
            $expectedAllProductImages,
            $actualAllProductImages,
            'Images are incorrect after import'
        );

        // Check that on storefront only enabled images are shown
        $actualActiveImages = $product->getMediaGalleryImages();
        $this->assertSame(
            $expectedActiveImages,
            $actualActiveImages->toArray(['file', 'label', 'disabled'])['items'],
            'Hidden image is present on frontend after import'
        );
    }

    /**
     * Set the current admin session user based on a username
     *
     * @param string $username
     */
    private function loginAdminUserWithUsername(string $username)
    {
        $user = $this->objectManager->create(User::class)
            ->loadByUsername($username);

        /** @var $session Session */
        $session = $this->objectManager->get(Session::class);
        $session->setUser($user);
    }

    /**
     * Checking product images after Add/Update import failure
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/CatalogImportExport/Model/Import/_files/import_with_filesystem_images.php
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     *
     * @return void
     */
    public function testProductBaseImageAfterImport()
    {
        $this->importDataForMediaTest('import_media.csv');

        $this->testImportWithNonExistingImage();

        /** @var $productAfterImport Product */
        $productAfterImport = $this->getProductBySku('simple_new');
        $this->assertNotEquals('/no/exists/image/magento_image.jpg', $productAfterImport->getData('image'));
    }

    /**
     * Tests that images are hidden only for a store view in "store_view_code".
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     */
    public function testHideImageForStoreView()
    {
        $expectedImageFile = '/m/a/magento_image.jpg';
        $secondStoreCode = 'fixturestore';
        $productSku = 'simple';
        $this->importDataForMediaTest('import_hide_image_for_storeview.csv');
        $product = $this->getProductBySku($productSku);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(1, $imageItems);
        $imageItem = array_shift($imageItems);
        $this->assertEquals($expectedImageFile, $imageItem->getFile());
        $product = $this->getProductBySku($productSku, $secondStoreCode);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(0, $imageItems);
    }

    /**
     * Test that images labels are updated only for a store view in "store_view_code".
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     */
    public function testChangeImageLabelForStoreView()
    {
        $expectedImageFile = '/m/a/magento_image.jpg';
        $expectedLabelForDefaultStoreView = 'Image Alt Text';
        $expectedLabelForSecondStoreView = 'Magento Logo';
        $secondStoreCode = 'fixturestore';
        $productSku = 'simple';
        $this->importDataForMediaTest('import_change_image_label_for_storeview.csv');
        $product = $this->getProductBySku($productSku);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(1, $imageItems);
        $imageItem = array_shift($imageItems);
        $this->assertEquals($expectedImageFile, $imageItem->getFile());
        $this->assertEquals($expectedLabelForDefaultStoreView, $imageItem->getLabel());
        $product = $this->getProductBySku($productSku, $secondStoreCode);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(1, $imageItems);
        $imageItem = array_shift($imageItems);
        $this->assertEquals($expectedImageFile, $imageItem->getFile());
        $this->assertEquals($expectedLabelForSecondStoreView, $imageItem->getLabel());
    }

    /**
     * Test that configurable product images are imported correctly.
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     */
    public function testImportConfigurableProductImages()
    {
        $this->importDataForMediaTest('import_configurable_product_multistore.csv');
        $expected = [
            'import-configurable-option-1' => [
                [
                    'file' => '/m/a/magento_image.jpg',
                    'label' => 'Base Image Label - Option 1',
                ],
                [
                    'file' => '/m/a/magento_small_image.jpg',
                    'label' => 'Small Image Label - Option 1',
                ],
                [
                    'file' => '/m/a/magento_thumbnail.jpg',
                    'label' => 'Thumbnail Image Label - Option 1',
                ],
                [
                    'file' => '/m/a/magento_additional_image_one.jpg',
                    'label' => '',
                ],
            ],
            'import-configurable-option-2' => [
                [
                    'file' => '/m/a/magento_image.jpg',
                    'label' => 'Base Image Label - Option 2',
                ],
                [
                    'file' => '/m/a/magento_small_image.jpg',
                    'label' => 'Small Image Label - Option 2',
                ],
                [
                    'file' => '/m/a/magento_thumbnail.jpg',
                    'label' => 'Thumbnail Image Label - Option 2',
                ],
                [
                    'file' => '/m/a/magento_additional_image_two.jpg',
                    'label' => '',
                ],
            ],
            'import-configurable' => [
                [
                    'file' => '/m/a/magento_image.jpg',
                    'label' => 'Base Image Label - Configurable',
                ],
                [
                    'file' => '/m/a/magento_small_image.jpg',
                    'label' => 'Small Image Label - Configurable',
                ],
                [
                    'file' => '/m/a/magento_thumbnail.jpg',
                    'label' => 'Thumbnail Image Label - Configurable',
                ],
                [
                    'file' => '/m/a/magento_additional_image_three.jpg',
                    'label' => '',
                ],
            ]
        ];
        $actual = [];
        $products = ['import-configurable-option-1', 'import-configurable-option-2', 'import-configurable'];
        foreach ($products as $sku) {
            $product = $this->getProductBySku($sku);
            $gallery = $product->getMediaGalleryImages();
            foreach ($gallery->getItems() as $item) {
                $actual[$sku][] = $item->toArray(['file', 'label']);
            }
        }
        $this->assertEquals($expected, $actual);

        $expected['import-configurable'] = [
            [
                'file' => '/m/a/magento_image.jpg',
                'label' => 'Base Image Label - Configurable (fixturestore)',
            ],
            [
                'file' => '/m/a/magento_small_image.jpg',
                'label' => 'Small Image Label - Configurable (fixturestore)',
            ],
            [
                'file' => '/m/a/magento_thumbnail.jpg',
                'label' => 'Thumbnail Image Label - Configurable (fixturestore)',
            ],
            [
                'file' => '/m/a/magento_additional_image_three.jpg',
                'label' => '',
            ],
        ];

        $actual = [];
        foreach ($products as $sku) {
            $product = $this->getProductBySku($sku, 'fixturestore');
            $gallery = $product->getMediaGalleryImages();
            foreach ($gallery->getItems() as $item) {
                $actual[$sku][] = $item->toArray(['file', 'label']);
            }
        }
        $this->assertEquals($expected, $actual);
    }

    /**
     * Test that product stock status is updated after import
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testProductStockStatusShouldBeUpdated()
    {
        /** @var $stockRegistry StockRegistry */
        $stockRegistry = $this->objectManager->create(StockRegistry::class);
        /** @var StockRegistryStorage $stockRegistryStorage */
        $stockRegistryStorage = $this->objectManager->get(StockRegistryStorage::class);
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_IN_STOCK, $status->getStockStatus());
        $this->importDataForMediaTest('disable_product.csv');
        $stockRegistryStorage->clean();
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_OUT_OF_STOCK, $status->getStockStatus());
        $this->importDataForMediaTest('enable_product.csv');
        $stockRegistryStorage->clean();
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_IN_STOCK, $status->getStockStatus());
    }

    /**
     * Test that product stock status is updated after import on schedule
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/CatalogImportExport/_files/cataloginventory_stock_item_update_by_schedule.php
     * @magentoDbIsolation disabled
     */
    public function testProductStockStatusShouldBeUpdatedOnSchedule()
    {
        /** * @var $indexProcessor Processor */
        $indexProcessor = $this->objectManager->create(Processor::class);
        /** @var $stockRegistry StockRegistry */
        $stockRegistry = $this->objectManager->create(StockRegistry::class);
        /** @var StockRegistryStorage $stockRegistryStorage */
        $stockRegistryStorage = $this->objectManager->get(StockRegistryStorage::class);
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_IN_STOCK, $status->getStockStatus());
        $this->importDataForMediaTest('disable_product.csv');
        $indexProcessor->updateMview();
        $stockRegistryStorage->clean();
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_OUT_OF_STOCK, $status->getStockStatus());
        $this->importDataForMediaTest('enable_product.csv');
        $indexProcessor->updateMview();
        $stockRegistryStorage->clean();
        $status = $stockRegistry->getStockStatusBySku('simple');
        $this->assertEquals(Stock::STOCK_IN_STOCK, $status->getStockStatus());
    }

    /**
     * Tests that empty attribute value in the CSV file will be ignored after update a product by the import.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_with_varchar_attribute.php
     */
    public function testEmptyAttributeValueShouldBeIgnoredAfterUpdateProductByImport()
    {
        $pathToFile = __DIR__ . DIRECTORY_SEPARATOR . '_files' . DIRECTORY_SEPARATOR
            . 'import_product_with_empty_attribute_value.csv';
        $importModel = $this->createImportModel($pathToFile);
        $errors = $importModel->validateData();
        $this->assertTrue($errors->getErrorsCount() === 0, 'Import file validation failed.');
        $importModel->importData();

        $simpleProduct = $this->productRepository->get('simple', false, null, true);
        $this->assertEquals('Varchar default value', $simpleProduct->getData('varchar_attribute'));
        $this->assertEquals('Short description', $simpleProduct->getData('short_description'));
    }

    /**
     * Checks possibility to double importing products using the same import file.
     *
     * Bunch size is using to test importing the same product that will be chunk to different bunches.
     * Example:
     * - first bunch
     * product-sku,default-store
     * product-sku,second-store
     * - second bunch
     * product-sku,third-store
     *
     * @magentoDbIsolation disabled
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testCheckDoubleImportOfProducts()
    {
        $this->importedProducts = [
            'simple1',
            'simple2',
            'simple3',
        ];

        /** @var SearchCriteria $searchCriteria */
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $this->assertTrue($this->importFile('products_with_two_store_views.csv', 2));
        $productsAfterFirstImport = $this->productRepository->getList($searchCriteria)->getItems();
        $this->assertCount(3, $productsAfterFirstImport);

        $this->assertTrue($this->importFile('products_with_two_store_views.csv', 2));
        $productsAfterSecondImport = $this->productRepository->getList($searchCriteria)->getItems();
        $this->assertCount(3, $productsAfterSecondImport);
    }

    /**
     * Checks that product related links added for all bunches properly after products import
     */
    public function testImportProductsWithLinksInDifferentBunches()
    {
        $this->importedProducts = [
            'simple1',
            'simple2',
            'simple3',
            'simple4',
            'simple5',
            'simple6',
        ];
        $importExportData = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $importExportData->expects($this->atLeastOnce())
            ->method('getBunchSize')
            ->willReturn(5);
        $this->model = $this->objectManager->create(
            \Magento\CatalogImportExport\Model\Import\Product::class,
            ['importExportData' => $importExportData]
        );
        $linksData = [
            'related' => [
                'simple1' => '2',
                'simple2' => '1'
            ]
        ];
        $pathToFile = __DIR__ . '/_files/products_to_import_with_related.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource($source)
            ->setParameters(
                [
                    'behavior' => Import::BEHAVIOR_APPEND,
                    'entity' => 'catalog_product'
                ]
            )
            ->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        $resource = $this->objectManager->get(ProductResource::class);
        $productId = $resource->getIdBySku('simple6');
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->load($productId);
        $productLinks = [
            'related' => $product->getRelatedProducts()
        ];
        $importedProductLinks = [];
        foreach ($productLinks as $linkType => $linkedProducts) {
            foreach ($linkedProducts as $linkedProductData) {
                $importedProductLinks[$linkType][$linkedProductData->getSku()] = $linkedProductData->getPosition();
            }
        }
        $this->assertEquals($linksData, $importedProductLinks);
    }

    /**
     * Tests that image name does not have to be prefixed by slash
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     */
    public function testUpdateImageByNameNotPrefixedWithSlash()
    {
        $expectedLabelForDefaultStoreView = 'image label updated';
        $expectedImageFile = '/m/a/magento_image.jpg';
        $secondStoreCode = 'fixturestore';
        $productSku = 'simple';
        $this->importDataForMediaTest('import_image_name_without_slash.csv');
        $product = $this->getProductBySku($productSku);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(1, $imageItems);
        $imageItem = array_shift($imageItems);
        $this->assertEquals($expectedImageFile, $imageItem->getFile());
        $this->assertEquals($expectedLabelForDefaultStoreView, $imageItem->getLabel());
        $product = $this->getProductBySku($productSku, $secondStoreCode);
        $imageItems = $product->getMediaGalleryImages()->getItems();
        $this->assertCount(0, $imageItems);
    }

    /**
     * Tests that images are imported correctly
     *
     * @magentoDataFixture mediaImportImageFixture
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     * @dataProvider importImagesDataProvider
     * @magentoAppIsolation enabled
     * @param string $importFile
     * @param string $productSku
     * @param string $storeCode
     * @param array $expectedImages
     * @param array $select
     */
    public function testImportImages(
        string $importFile,
        string $productSku,
        string $storeCode,
        array $expectedImages,
        array $select = ['file', 'label', 'position']
    ): void {
        $this->importDataForMediaTest($importFile);
        $product = $this->getProductBySku($productSku, $storeCode);
        $actualImages = array_map(
            function (DataObject $item) use ($select) {
                return $item->toArray($select);
            },
            $product->getMediaGalleryImages()->getItems()
        );
        $this->assertEquals($expectedImages, array_values($actualImages));
    }

    /**
     * @return array[]
     */
    public function importImagesDataProvider(): array
    {
        return [
            [
                'import_media_additional_images_storeview.csv',
                'simple',
                'default',
                [
                    [
                        'file' => '/m/a/magento_image.jpg',
                        'label' => 'Image Alt Text',
                        'position' => 1
                    ],
                    [
                        'file' => '/m/a/magento_additional_image_one.jpg',
                        'label' => null,
                        'position' => 2
                    ],
                    [
                        'file' => '/m/a/magento_additional_image_two.jpg',
                        'label' => null,
                        'position' => 3
                    ],
                ]
            ],
            [
                'import_media_additional_images_storeview.csv',
                'simple',
                'fixturestore',
                [
                    [
                        'file' => '/m/a/magento_image.jpg',
                        'label' => 'Image Alt Text',
                        'position' => 1
                    ],
                    [
                        'file' => '/m/a/magento_additional_image_one.jpg',
                        'label' => 'Additional Image Label One',
                        'position' => 2
                    ],
                    [
                        'file' => '/m/a/magento_additional_image_two.jpg',
                        'label' => 'Additional Image Label Two',
                        'position' => 3
                    ],
                ]
            ]
        ];
    }

    /**
     * Verify additional images url validation during import.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testImportInvalidAdditionalImages(): void
    {
        $pathToFile = __DIR__ . '/_files/import_media_additional_images_with_wrong_url.csv';
        $filesystem = $this->objectManager->create(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(Csv::class, ['file' => $pathToFile, 'directory' => $directory]);
        $errors = $this->model->setSource($source)
            ->setParameters(['behavior' => Import::BEHAVIOR_APPEND])
            ->validateData();
        $this->assertEquals($errors->getErrorsCount(), 1);
        $this->assertEquals(
            "Wrong URL/path used for attribute additional_images",
            $errors->getErrorByRowNumber(0)[0]->getErrorMessage()
        );
    }
}
