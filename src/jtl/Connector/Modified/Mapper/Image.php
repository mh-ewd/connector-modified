<?php
namespace jtl\Connector\Modified\Mapper;

use jtl\Connector\Modified\Mapper\BaseMapper;
use jtl\Connector\Drawing\ImageRelationType;

class Image extends BaseMapper
{
    protected $mapperConfig = array(
        "table" => "products_images",
        "identity" => "getId",
        "mapPull" => array(
            "id" => "image_id",
            "relationType" => "type",
            "foreignKey" => "foreignKey",
            "remoteUrl" => null,
            "sort" => "image_nr"
        )
    );

    private $thumbConfig;

    public function __construct()
    {
        parent::__construct();

        $this->thumbConfig = array(
            'info' => array(
                $this->shopConfig['settings']['PRODUCT_IMAGE_INFO_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_INFO_HEIGHT']
            ),
            'popup' => array(
                $this->shopConfig['settings']['PRODUCT_IMAGE_POPUP_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_POPUP_HEIGHT']
            ),
            'thumbnails' => array(
                $this->shopConfig['settings']['PRODUCT_IMAGE_THUMBNAIL_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_THUMBNAIL_HEIGHT']
            )
        );
    }

    public function pull($data = null, $limit = null)
    {
        $result = [];

        $query = 'SELECT p.*, p.products_id foreignKey, "product" type
            FROM products_images p
            LEFT JOIN jtl_connector_link l ON p.image_id = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL';
        $defaultQuery = 'SELECT CONCAT("pID_",p.products_id) image_id, p.products_image image_name, p.products_id foreignKey, 0 image_nr, "product" type
            FROM products p
            LEFT JOIN jtl_connector_link l ON CONCAT("pID_",p.products_id) = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL && p.products_image IS NOT NULL';
        $categoriesQuery = 'SELECT CONCAT("cID_",p.categories_id) image_id, p.categories_image as image_name, p.categories_id foreignKey, "category" type, 0 image_nr
            FROM categories p
            LEFT JOIN jtl_connector_link l ON CONCAT("cID_",p.categories_id) = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL && p.categories_image IS NOT NULL';

        $dbResult = $this->db->query($query);
        $dbResultDefault = $this->db->query($defaultQuery);
        $dbResultCategories = $this->db->query($categoriesQuery);

        $dbResult = array_merge($dbResult, $dbResultDefault, $dbResultCategories);

        $current = array_slice($dbResult, 0, $limit);

        foreach ($current as $modelData) {
            $model = $this->generateModel($modelData);

            $result[] = $model;
        }

        return $result;
    }

    public function push($data, $dbObj = null)
    {
        if (get_class($data) === 'jtl\Connector\Model\Image') {
            switch ($data->getRelationType()) {
                case ImageRelationType::TYPE_CATEGORY:
                    $oldImage = $this->db->query('SELECT categories_image FROM categories WHERE categories_id = '.$data->getForeignKey()->getEndpoint());
                    $oldImage = $oldImage[0]['categories_image'];

                    if (isset($oldImage)) {
                        @unlink($this->connectorConfig->connector_root.'/images/categories/'.$oldImage);
                    }

                    $imgFileName = substr($data->getFilename(), strrpos($data->getFilename(), '/') + 1);

                    if (!rename($data->getFilename(), $this->connectorConfig->connector_root.'/images/categories/'.$imgFileName)) {
                        throw new \Exception('Cannot move uploaded image file');
                    }

                    $categoryObj = new \stdClass();
                    $categoryObj->categories_image = $imgFileName;

                    $this->db->updateRow($categoryObj, 'categories', 'categories_id', $data->getForeignKey()->getEndpoint());

                    $data->getId()->setEndpoint('cID_'.$data->getForeignKey()->getEndpoint());

                    break;

                case ImageRelationType::TYPE_PRODUCT:
                    if ($data->getSort() == 1) {
                        $oldImage = $this->db->query('SELECT products_image FROM products WHERE products_id = '.$data->getForeignKey()->getEndpoint());
                        $oldImage = $oldImage[0]['products_image'];

                        if (isset($oldImage)) {
                            @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$oldImage);
                        }

                        $imgFileName = substr($data->getFilename(), strrpos($data->getFilename(), '/') + 1);

                        if (!rename($data->getFilename(), $this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$imgFileName)) {
                            throw new \Exception('Cannot move uploaded image file');
                        }

                        $this->generateThumbs($imgFileName, $oldImage);

                        $productsObj = new \stdClass();
                        $productsObj->products_image = $imgFileName;

                        $this->db->updateRow($productsObj, 'products', 'products_id', $data->getForeignKey()->getEndpoint());

                        $data->getId()->setEndpoint('pID_'.$data->getForeignKey()->getEndpoint());

                        $this->db->query('DELETE FROM jtl_connector_link WHERE endpointId="'.$data->getId()->getEndpoint().'"');
                        $this->db->query('DELETE FROM jtl_connector_link WHERE hostId='.$data->getId()->getHost().' && type=16');
                        $this->db->query('INSERT INTO jtl_connector_link SET hostId="'.$data->getId()->getHost().'", endpointId="'.$data->getId()->getEndpoint().'" , type=16');
                    } else {
                        $oldImage = null;
                        $imgObj = new \stdClass();

                        $oldImage = $this->db->query('SELECT image_name FROM products_images WHERE products_id = '.$data->getForeignKey()->getEndpoint().' && image_nr='.($data->getSort() - 1));
                        $oldImage = $oldImage[0]['image_name'];

                        if (!empty($oldImage)) {
                            @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$oldImage);
                        }

                        $imgObj->image_id = $data->getId()->getEndpoint();

                        $imgFileName = substr($data->getFilename(), strrpos($data->getFilename(), '/') + 1);

                        if (!rename($data->getFilename(), $this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$imgFileName)) {
                            throw new \Exception('Cannot move uploaded image file');
                        }

                        $this->generateThumbs($imgFileName, $oldImage);

                        $imgObj->products_id = $data->getForeignKey()->getEndpoint();
                        $imgObj->image_name = $imgFileName;
                        $imgObj->image_nr = ($data->getSort() - 1);

                        $newIdQuery = $this->db->deleteInsertRow($imgObj, 'products_images', array('image_nr', 'products_id'), array($imgObj->image_nr, $imgObj->products_id));
                        $newId = $newIdQuery->getKey();

                        $this->db->query('DELETE FROM jtl_connector_link WHERE hostId='.$data->getId()->getHost().' && type=16');
                        $this->db->query('INSERT INTO jtl_connector_link SET hostId="'.$data->getId()->getHost().'", endpointId="'.$newId.'" , type=16');

                        $data->getId()->setEndpoint($newId);
                    }

                    break;
            }

            return $data;
        } else {
            throw new \Exception('Pushed data is not an image object');
        }
    }

    public function delete($data)
    {
        if (get_class($data) === 'jtl\Connector\Model\Image') {
            switch ($data->getRelationType()) {
                case ImageRelationType::TYPE_CATEGORY:
                    $oldImage = $this->db->query('SELECT categories_image FROM categories WHERE categories_id = '.$data->getForeignKey()->getEndpoint());
                    $oldImage = $oldImage[0]['categories_image'];

                    if (isset($oldImage)) {
                        @unlink($this->connectorConfig->connector_root.'/images/categories/'.$oldImage);
                    }

                    $categoryObj = new \stdClass();
                    $categoryObj->categories_image = null;

                    $this->db->updateRow($categoryObj, 'categories', 'categories_id', $data->getForeignKey()->getEndpoint());

                    break;

                case ImageRelationType::TYPE_PRODUCT:
                    if ($data->getSort() == 0) {
                        $oldImage = $this->db->query('SELECT products_image FROM products WHERE products_id = '.$data->getForeignKey()->getEndpoint());
                        $oldImage = $oldImage[0]['products_image'];

                        if (isset($oldImage)) {
                            @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$oldImage);
                            $this->db->query('UPDATE products SET products_image="" WHERE products_id='.$data->getForeignKey()->getEndpoint());
                        }

                        $additionalImages = $this->db->query('SELECT image_name FROM products_images WHERE products_id='.$data->getForeignKey()->getEndpoint());

                        foreach ($additionalImages as $image) {
                            if (!empty($image['image_name'])) {
                                @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$image['image_name']);

                                foreach ($this->thumbConfig as $folder => $sizes) {
                                    @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img'][$folder].$image['image_name']);
                                }
                            }
                        }

                        $this->db->query('DELETE FROM products_images WHERE products_id='.$data->getForeignKey()->getEndpoint());
                    } elseif ($data->getSort() == 1) {
                        $oldImage = $this->db->query('SELECT products_image FROM products WHERE products_id = '.$data->getForeignKey()->getEndpoint());
                        $oldImage = $oldImage[0]['products_image'];

                        if (isset($oldImage)) {
                            @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$oldImage);
                        }

                        $productsObj = new \stdClass();
                        $productsObj->products_image = null;

                        $this->db->updateRow($productsObj, 'products', 'products_id', $data->getForeignKey()->getEndpoint());
                    } else {
                        if ($data->getId()->getEndpoint() != '') {
                            $oldImage = $this->db->query('SELECT image_name FROM products_images WHERE image_id = "'.$data->getId()->getEndpoint().'"');
                            $oldImage = $oldImage[0]['image_name'];

                            @unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$oldImage);

                            $this->db->query('DELETE FROM products_images WHERE image_id="'.$data->getId()->getEndpoint().'"');
                        }
                    }

                    break;
            }

            foreach ($this->thumbConfig as $folder => $sizes) {
                if (!is_null($oldImage)) {
                    unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img'][$folder].$oldImage);
                }
            }

            $this->db->query('DELETE FROM jtl_connector_link WHERE type=16 && endpointId="'.$data->getId()->getEndpoint().'"');

            return $data;
        } else {
            throw new \Exception('Pushed data is not an image object');
        }
    }

    public function statistic()
    {
        $totalImages = 0;

        $productQuery = $this->db->query("
            SELECT p.*
            FROM (
                SELECT CONCAT('pID_',p.products_id) as imgId
                FROM products p
                WHERE p.products_image IS NOT NULL && p.products_image != ''
            ) p
            LEFT JOIN jtl_connector_link l ON p.imgId = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL
        ");

        $categoryQuery = $this->db->query("
            SELECT c.*
            FROM (
                SELECT CONCAT('cID_',c.categories_id) as imgId
                FROM categories c
                WHERE c.categories_image IS NOT NULL && c.categories_image != ''
            ) c
            LEFT JOIN jtl_connector_link l ON c.imgId = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL
        ");

        $imageQuery = $this->db->query("
            SELECT i.* FROM products_images i
            LEFT JOIN jtl_connector_link l ON i.image_id = l.endpointId AND l.type = 16
            WHERE l.hostId IS NULL
        ");

        $totalImages += count($productQuery);
        $totalImages += count($categoryQuery);
        $totalImages += count($imageQuery);

        return $totalImages;
    }

    protected function remoteUrl($data)
    {
        if ($data['type'] == ImageRelationType::TYPE_CATEGORY) {
            return $this->shopConfig['shop']['fullUrl'].'images/categories/'.$data['image_name'];
        } else {
            return $this->shopConfig['shop']['fullUrl'].$this->shopConfig['img']['original'].$data['image_name'];
        }
    }

    private function generateThumbs($fileName, $oldImage = null)
    {
        $image = imagecreatefromjpeg($this->connectorConfig->connector_root.'/'.$this->shopConfig['img']['original'].$fileName);
        $width = imagesx($image);
        $height = imagesy($image);
        $original_aspect = $width / $height;

        foreach ($this->thumbConfig as $folder => $sizes) {
            if (!is_null($oldImage)) {
                unlink($this->connectorConfig->connector_root.'/'.$this->shopConfig['img'][$folder].$oldImage);
            }

            $thumb_width = $sizes[0];
            $thumb_height = $sizes[1];

            $thumb_aspect = $thumb_width / $thumb_height;

            if ($original_aspect >= $thumb_aspect) {
                $new_height = $thumb_height;
                $new_width = $width / ($height / $thumb_height);
            } else {
                $new_width = $thumb_width;
                $new_height = $height / ($width / $thumb_width);
            }

            $thumb = imagecreatetruecolor($thumb_width, $thumb_height);

            imagecopyresampled(
                $thumb,
                $image,
                0 - ($new_width - $thumb_width) / 2,
                0 - ($new_height - $thumb_height) / 2,
                0,
                0,
                $new_width,
                $new_height,
                $width,
                $height
            );

            imagejpeg($thumb, $this->connectorConfig->connector_root.'/'.$this->shopConfig['img'][$folder].$fileName, 80);
        }
    }
}
