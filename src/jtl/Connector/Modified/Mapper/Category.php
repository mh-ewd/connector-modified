<?php
namespace jtl\Connector\Modified\Mapper;

class Category extends \jtl\Connector\Modified\Mapper\BaseMapper
{
    protected $mapperConfig = array(
        "table" => "categories",
        "query" => "SELECT c.* FROM categories c
            LEFT JOIN jtl_connector_link l ON c.categories_id = l.endpointId AND l.type = 1
            WHERE l.hostId IS NULL",
        "where" => "categories_id",
        "identity" => "getId",
        "mapPull" => array(
            "id" => "categories_id",
            "parentCategoryId" => null,
            "sort" => "sort_order",
            "level" => "level",
            "i18ns" => "CategoryI18n|addI18n",
            "invisibilities" => "CategoryInvisibility|addInvisibility",
            "attributes" => "CategoryAttr|addAttribute",
        ),
        "mapPush" => array(
            "categories_id" => "id",
            "parent_id" => null,
            "sort_order" => "sort",
            "CategoryI18n|addI18n" => "i18ns",
            "CategoryInvisibility|addInvisibility|true" => "invisibilities",
            "CategoryAttr|addAttribute|true" => "attributes",
            "last_modified" => null
        )
    );

    private $tree = array();
    private static $idCache = array();

    protected function last_modified($data)
    {
        return date('Y-m-d H:m:i', time());
    }

    protected function parentCategoryId($data)
    {
        return $this->replaceZero($data['parent_id']);
    }

    protected function parent_id($data)
    {
        return is_null($data->getParentCategoryId()->getEndpoint()) ? 0 : $data->getParentCategoryId()->getEndpoint();
    }

    public function pull($parent = null, $limit = null)
    {
        $this->tree = array();

        $this->getChildren(null, 0, $limit);

        usort($this->tree, function ($a, $b) {
            return $a['level'] - $b['level'];
        });

        $pulledQuery = $this->db->query('SELECT endpointId FROM jtl_connector_link WHERE type=1');
        $pulled = array();

        foreach ($pulledQuery as $pCat) {
            $pulled[] = $pCat['endpointId'];
        }

        $resultCount = 0;

        foreach ($this->tree as $category) {
            if ($resultCount >= $limit) {
                break;
            }

            if (in_array($category['categories_id'], $pulled) === false) {
                $result[] = $this->generateModel($category);

                $resultCount++;
            }
        }

        return $result;
    }

    public function push($data, $dbObj = null)
    {
        if (isset(static::$idCache[$data->getParentCategoryId()->getHost()])) {
            $data->getParentCategoryId()->setEndpoint(static::$idCache[$data->getParentCategoryId()->getHost()]);
        }

        $id = $data->getId()->getEndpoint();

        if (!empty($id)) {
            $this->db->query('DELETE FROM categories_description WHERE categories_id='.$id);
        }

        return parent::push($data, $dbObj);
    }

    public function pushDone($model, $dbObj)
    {
        static::$idCache[$model->getId()->getHost()] = $model->getId()->getEndpoint();
        $this->db->query('UPDATE categories SET date_added="'.date('Y-m-d H:m:i', time()).'" WHERE categories_id='.$model->getId()->getEndpoint().' && date_added IS NULL');
        array_map('unlink', glob($this->shopConfig['shop']['path'].'templates_c/*'));
    }

    private function getChildren($ids = null, $level = 0, $limit)
    {
        if (is_null($ids)) {
            $sql = 'c.parent_id=0';
        } else {
            $sql = 'c.parent_id IN ('.implode(',', $ids).')';
        }

        $children = $this->db->query('SELECT c.* FROM categories c
            WHERE '.$sql);

        if (count($children) > 0) {
            $ids = array();

            foreach ($children as $child) {
                $ids[] = $child['categories_id'];

                $child['level'] = $level;
                $this->tree[] = $child;
            }

            $this->getChildren($ids, $level + 1, $limit);
        }
    }

    public function delete($data)
    {
        $id = $data->getId()->getEndpoint();

        if (!empty($id) && $id != '') {
            try {
                $this->db->query('DELETE FROM categories WHERE categories_id='.$data->getId()->getEndpoint());
                $this->db->query('DELETE FROM categories_description WHERE categories_id='.$data->getId()->getEndpoint());
                $this->db->query('DELETE FROM products_to_categories WHERE categories_id='.$data->getId()->getEndpoint());

                $this->db->query('DELETE FROM jtl_connector_link WHERE type=1 && endpointId="'.$data->getId()->getEndpoint().'"');
            }
            catch(\Exception $e) {            
            }
        }

        return $data;
    }
}
