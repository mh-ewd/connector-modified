<?php
namespace jtl\Connector\Modified\Mapper;

use jtl\Connector\Modified\Mapper\BaseMapper;
use jtl\Connector\Model\ProductPrice as ProductPriceModel;
use jtl\Connector\Model\ProductPriceItem as ProductPriceItemModel;

class ProductPrice extends BaseMapper
{
    protected $mapperConfig = array(
        "getMethod" => "getPrices",
        "mapPull" => array(
            "id" => null,
            "customerGroupId" => "customers_status_id",
            "productId" => "products_id",
            "items" => "ProductPriceItem|addItem"
        ),
        "mapPush" => array(
            "ProductPriceItem|addItem" => "items"
        )
    );

    public function pull($data = null, $limit = null)
    {
        $customerGroups = $this->getCustomerGroups();

        $return = [];

        foreach ($customerGroups as $groupData) {
            $groupData['products_id'] = $data['products_id'];

            $price = $this->generateModel($groupData);

            if (count($price->getItems()) > 0) {
                $return[] = $price;
            }
        }

        $default = new ProductPriceModel();
        $default->setId($this->identity($data['products_id'].'_default'));
        $default->setProductId($this->identity($data['products_id']));
        $default->setCustomerGroupId($this->identity(null));

        $defaultItem = new ProductPriceItemModel();
        $defaultItem->setProductPriceId($default->getId());
        $defaultItem->setNetPrice(floatval($data['products_price']));

        $default->addItem($defaultItem);

        $return[] = $default;

        return $return;
    }

    public function push($parent, $dbObj)
    {
        if (get_class($parent) == 'jtl\Connector\Model\Product') {
            $productId = $parent->getId();
        } else {
            $productId = $parent->getProductId();
            unset($this->mapperConfig['getMethod']);
        }

        foreach ($parent->getPrices() as $price) {
            $price->setProductId($productId);
        }

        return parent::push($parent, $dbObj);
    }

    protected function id($data)
    {
        return $data['products_id'].'_'.$data['customers_status_id'];
    }
}
