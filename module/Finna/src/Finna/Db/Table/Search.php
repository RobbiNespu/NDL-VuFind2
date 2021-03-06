<?php
/**
 * Table Definition for search
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Db_Table
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace Finna\Db\Table;
use VuFind\Db\Table\PluginManager;
use Zend\Db\Adapter\Adapter;

/**
 * Table Definition for search
 *
 * @category VuFind
 * @package  Db_Table
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class Search extends \VuFind\Db\Table\Search
{
    /**
     * Constructor
     *
     * @param Adapter       $adapter Database adapter
     * @param PluginManager $tm      Table manager
     * @param array         $cfg     Zend Framework configuration
     */
    public function __construct(Adapter $adapter, PluginManager $tm, $cfg)
    {
        parent::__construct($adapter, $tm, $cfg, 'search');
        $resultSetPrototype = $this->getResultSetPrototype();
        $resultSetPrototype->setArrayObjectPrototype(
            $this->initializeRowPrototype('Finna\Db\Row\Search')
        );
    }

    /**
     * Get distinct view URLs with scheduled alerts.
     *
     * @return array URLs
     */
    public function getScheduleBaseUrls()
    {
        $sql
            = "SELECT distinct finna_schedule_base_url as url FROM {$this->table}"
            . " WHERE finna_schedule_base_url != '';";

        $result = $this->getAdapter()->query(
            $sql,
            \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE
        );
        $urls = [];
        foreach ($result as $res) {
            $urls[] = $res['url'];
        }
        return $urls;
    }

    /**
     * Get scheduled searches.
     *
     * @param string $baseUrl View URL
     *
     * @return array Array of Finna\Db\Row\Search objects.
     */
    public function getScheduledSearches($baseUrl)
    {
        $callback = function ($select) use ($baseUrl) {
            $select->columns(['*']);
            $select->where->equalTo('saved', 1);
            $select->where('finna_schedule > 0');
            $select->where->equalTo('finna_schedule_base_url', $baseUrl);
            $select->order('user_id');
        };

        return $this->select($callback);
    }

    /**
     * Get saved searches.
     *
     * @param int $uid User ID
     *
     * @return \Zend\Db\ResultSet\ResultSet
     */
    public function getSavedSearches($uid)
    {
        $callback = function ($select) use ($uid) {
            $select->where->equalTo('user_id', $uid);
            $select->order('id');
        };
        return $this->select($callback);
    }
}
