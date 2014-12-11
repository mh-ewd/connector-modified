<?php
namespace jtl\Connector\Modified\Mapper;

use \jtl\Connector\Modified\Mapper\BaseMapper;

class ProductSpecialPrice extends BaseMapper
{
    protected $mapperConfig = array(
        "table" => "specials",
        "query" => "SELECT * FROM specials WHERE products_id=[[products_id]]",
        "getMethod" => "getSpecialPrices",
        "where" => "specials_id",
        "mapPull" => array(
        	"id" => "specials_id",
            "productId" => "products_id",		
            "isActive" => "status",
            "activeUntilDate" => null,	
            "stockLimit" => "specials_quantity",
            "considerStockLimit" => null,	
            "considerDateLimit" => null,
            "specialPrices" => "SpecialPrice|addSpecialPrice"
        ),
        "mapPush" => array(
            "specials_id" => "id",
            "products_id" => "productId",
            "status" => "isActive",
            "expires_date" => "activeUntilDate",
            "specials_quantity" => "stockLimit",
            "SpecialPrice|addSpecialPrice|true" => "specialPrices"
        )
    );
    
    protected function considerStockLimit($data) {
        return $data['specials_quantity'] == 0 ? false : true;
    }
    
    protected function considerDateLimit($data) {
        return $data['expires_date'] == '0000-00-00 00:00:00' ? false : true;
    } 
    
    protected function activeUntil($data) {
        return $data['expires_date'] == '0000-00-00 00:00:00' ? null : $data['expires_date'];
    }
    
    public function push($parent,$dbObj) {
        foreach($parent->getSpecialPrices() as $special) {
            $special->setProductId($parent->getId());
        }
    
        return parent::push($parent,$dbObj);
    }
}