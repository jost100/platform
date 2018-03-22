<?php declare(strict_types=1);

namespace Shopware\DbalIndexing\Product;

use Doctrine\DBAL\Connection;
use Shopware\Api\Entity\Dbal\EntityDefinitionQueryHelper;
use Shopware\Context\Struct\ShopContext;
use Shopware\Defaults;
use Shopware\Framework\Struct\Uuid;

class CategoryAssignmentUpdater
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function update(array $ids, ShopContext $context): void
    {
        if (empty($ids)) {
            return;
        }

        $categories = $this->fetchCategories($ids, $context);

        foreach ($categories as $productId => $mapping) {
            $categoryIds = array_filter(explode('||', (string) $mapping['ids']));
            $categoryIds = array_map('strtolower', $categoryIds);

            $categoryIds = array_merge(
                explode('|', (string) $mapping['paths']),
                $categoryIds
            );

            $categoryIds = array_keys(array_flip(array_filter($categoryIds)));

            if (empty($categoryIds)) {
                $categoryIds = null;
            } else {
                $categoryIds = json_encode($categoryIds);
            }

            $this->connection->executeUpdate(
                'UPDATE product SET category_tree = :tree WHERE id = :id AND version_id = :version',
                [
                    'id' => $productId,
                    'tree' => $categoryIds,
                    'version' => Uuid::fromStringToBytes($context->getVersionId()),
                ]
            );
        }
    }

    private function fetchCategories(array $ids, ShopContext $context): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'product.id as product_id',
            "GROUP_CONCAT(category.path SEPARATOR '|') as paths",
            "GROUP_CONCAT(HEX(category.id) SEPARATOR '||') as ids",
        ]);
        $query->from('product');
        $query->leftJoin('product', 'product_category', 'mapping', 'mapping.product_id = product.id AND product.version_id = mapping.product_version_id');
        $query->leftJoin('mapping', 'category', 'category', 'category.id = mapping.category_id AND category.version_id = :live');
        $query->addGroupBy('product.id');

        $query->andWhere('product.id IN (:ids)');
        $query->andWhere('product.version_id = :version');

        $query->setParameter('version', Uuid::fromStringToBytes($context->getVersionId()));
        $query->setParameter('live', Uuid::fromStringToBytes(Defaults::LIVE_VERSION));

        $bytes = EntityDefinitionQueryHelper::uuidStringsToBytes($ids);

        $query->setParameter('ids', $bytes, Connection::PARAM_STR_ARRAY);

        return $query->execute()->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE);
    }
}
