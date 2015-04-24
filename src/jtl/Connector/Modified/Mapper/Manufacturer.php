<?php
namespace jtl\Connector\Modified\Mapper;

use jtl\Connector\Modified\Mapper\BaseMapper;

class Manufacturer extends BaseMapper
{
    protected $mapperConfig = array(
        "table" => "manufacturers",
        "query" => "SELECT m.* FROM manufacturers m
            LEFT JOIN jtl_connector_link l ON m.manufacturers_id = l.endpointId AND l.type = 32
            WHERE l.hostId IS NULL",
        "where" => "manufacturers_id",
        "identity" => "getId",
        "mapPull" => array(
            "id" => "manufacturers_id",
            "name" => "manufacturers_name"
        ),
        "mapPush" => array(
            "manufacturers_id" => "id",
            "manufacturers_name" => "name"
        )
    );

    public function delete($data)
    {
        $id = $data->getId()->getEndpoint();

        if (!empty($id) && $id != '') {
            $this->db->query('DELETE FROM manufacturers WHERE manufacturers_id='.$id);
            $this->db->query('DELETE FROM manufacturers_info WHERE manufacturers_id='.$id);

            $this->db->query('DELETE FROM jtl_connector_link WHERE type=32 && endpointId="'.$id.'"');
        }

        return $data;
    }
}
