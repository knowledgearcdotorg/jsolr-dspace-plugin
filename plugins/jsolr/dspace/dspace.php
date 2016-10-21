<?php
/**
 * @package     JSolr.Plugin
 * @subpackage  Index
 * @copyright   Copyright (C) 2012-2016 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die();

JLoader::import('joomla.log.log');

JLoader::registerNamespace('JSolr', JPATH_PLATFORM);

class PlgJSolrDSpace extends \JSolr\Plugin
{
    protected $context = 'archive.item';

    protected $collections = array();

    /**
     * Gets all DSpace items using the JSpace component and DSpace REST API.
     *
     * @return array A list of DSpace items.
     */
    protected function getItems($start = 0, $limit = 10)
    {
        $items = array();

        try {
            $items = array();

            $vars = array();

            $vars['q'] = "*:*";

            $vars['fl'] = 'search.resourceid,search.uniqueid,read';

            $vars['fq'] = 'search.resourcetype:2';

            $vars['rows'] = $this->getTotal();

            if ($this->get('params')->get('private_access', "") == "") {
                $vars['fq'] .= ' AND read:g0';
            } else {
                // only get items with read set.
                $vars['fq'] .= ' AND read:[* TO *]';
            }

            $vars['fq'] = urlencode($vars['fq']);

            $indexingParams = $this->get('indexingParams');

            if ($lastModified = JArrayHelper::getValue($indexingParams, 'lastModified', null, 'string')) {
                $lastModified = JFactory::getDate($lastModified)->format('Y-m-d\TH:i:s\Z', false);

                $vars['q'] = urlencode("SolrIndexer.lastIndexed:[$lastModified TO NOW]");
            }

            $url = new JUri($this->params->get('rest_url').'/discover.json');

            $url->setQuery($vars);

            $http = JHttpFactory::getHttp();

            $response = $http->get((string)$url);

            if ((int)$response->code !== 200) {
                throw new Exception($response->body, $response->code);
            }

            $response = json_decode($response->body);

            if (isset($response->response->docs)) {
                $items = $response->response->docs;
            }
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'jsolr');
        }

        return $items;
    }

    /**
     * Get the total number of DSpace items.
     *
     * @return  int  The total number of DSpace items.
     */
    protected function getTotal()
    {
        $total = 0;

        try {
            $vars = array();

            $vars['q'] = "*:*";
            $vars['fq'] = 'search.resourcetype:2';
            $vars['rows'] = '0';

            if ($this->get('params')->get('private_access', "") == "") {
                $vars['fq'] .= ' AND read:g0';
            } else {
                // only get items with read set.
                $vars['fq'] .= ' AND read:[* TO *]';
            }

            $vars['fq'] = urlencode($vars['fq']);

            $indexingParams = $this->get('indexingParams');

            if ($lastModified = JArrayHelper::getValue($indexingParams, 'lastModified', null, 'string')) {
                $lastModified = JFactory::getDate($lastModified)->format('Y-m-d\TH:i:s\Z', false);

                $vars['q'] = urlencode("SolrIndexer.lastIndexed:[$lastModified TO NOW]");
            }

            $url = new JUri($this->params->get('rest_url').'/discover.json');

            $url->setQuery($vars);

            $http = JHttpFactory::getHttp();

            $response = $http->get((string)$url);

            if ((int)$response->code !== 200) {
                throw new Exception($response->body, $response->code);
            }

            $response = json_decode($response->body);

            return (int)$response->response->numFound;
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'jsolr');
        }
    }

    /**
     * Gets a DSpace item by id.
     *
     * @param   int       $id  The DSpace item id.
     *
     * @return  stdClass  An instance of the DSpace item.
     */
    protected function getItem($id)
    {
        $url = $this->params->get('rest_url').'/items/'.$id.'.json';

        $response = JHttpFactory::getHttp()->get((string)$url);

        if ((int)$response->code !== 200) {
            throw new Exception($response->body, $response->code);
        }

        return json_decode($response->body);
    }

    /**
     * (non-PHPdoc)
     * @see \JSolr\Plugin::clean()
     *
     * @TODO quick and dirty clean. This would break on very large indexes.
     */
    protected function clean()
    {
        $items = $this->getItems();

        $service = \JSolr\Index\Factory::getService();

        $query = \JSolr\Search\Factory::getQuery('*:*')
            ->useQueryParser("edismax")
            ->filters(array("context:".$this->get('itemContext')))
            ->retrieveFields('id')
            ->rows(0);

        JLog::add((string)$query, JLog::DEBUG, 'jsolr');

        $results = $query->search();

        if ($results->get('numFound')) {
            $query->rows($results->get('numFound'));
        }

        JLog::add((string)$query, JLog::DEBUG, 'jsolr');

        $results = $query->search();

        if ($results->get('numFound')) {
            $delete = array();

            $prefix = $this->get('itemContext').'.';

            foreach ($results as $result) {
                $found = false;

                reset($items);

                $i = 0;

                while (($item = current($items)) && !$found) {
                    if ($result->id == $item->{'search.resourceid'}) {
                        $found = true;
                    } else {
                        $i++;
                    }

                    next($items);
                }

                if (!$found) {
                    $delete[] = $prefix.$result->id;
                }
            }

            if (count($delete)) {
                foreach ($delete as $key) {
                    $this->out('cleaning item '.$key.' and its bitstreams');

                    $query = 'context:'.$this->get('assetContext').
                        ' AND parent_id:'.str_replace($prefix, '', $key);

                    $service->deleteByQuery($query);
                }

                $service->deleteByMultipleIds($delete);

                $response = $service->commit();
            }
        }
    }

    /**
     * A convenience event for adding a record to the index.
     *
     * Use this event when the plugin is known but the context is not.
     *
     * @param int $id The id of the record being added.
     */
    public function onJSolrItemAdd($id)
    {
        $commitWithin = $this->params->get('component.commitWithin', '1000');

        $endpoint = $this->params->get('rest_url').'/items/%s.json';

        try {
            $url = new JUri(JText::sprintf($endpoint, $id));

            $http = JHttpFactory::getHttp();

            $response = $http->get((string)$url);

            if ((int)$response->code !== 200) {
                throw new Exception($response->body, $response->code);
            }

            $item = json_decode($response->body);

            // DSpace is incapable of exposing item permissions in a clean acl
            // manner. Query src Solr for this information.
            $temp = $this->getItems(0, 1, "search.resourceid:".$id);
            $temp = JArrayHelper::getValue($temp, 0);

            $item->access = $this->getAccess($temp);

            $document = $this->prepare($item);

            $solr = \JSolr\Index\Factory::getService();

            $solr->addDocuments($document, true, $commitWithin);
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'jsolr');
        }
    }

    /**
     * A convenience event for adding a record to the index.
     *
     * Use this event when the plugin is known but the context is not.
     *
     * @param int $id The id of the record being added.
     */
    public function onJSolrItemDelete($id)
    {
        $commitWithin = $this->params->get('component.commitWithin', '1000');

        try {
            $solr = \JSolr\Index\Factory::getService();

            $this->out('cleaning item '.$id.' and its bitstreams');

            $query = 'context:'.$this->get('assetContext').
                ' AND parent_id:'.$id;

            $solr->deleteByQuery($query);

            $solr->deleteById($this->get('itemContext').'.'.$id);

            $solr->commit();
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'jsolr');
            JLog::add($e->getTraceAsString(), JLog::ERROR, 'jsolr');
        }
    }

    /**
     * Maps the DSpace access value to a corresponding Joomla! access level.
     *
     * @param   string  $access  The DSpace access level.
     *
     * @return  string  The Joomla! access level.
     */
    private function mapDSpaceAccessToJoomla($access)
    {
        // g0 = public
        if ($access == 'g0') {
            return $this->get('params')->get('anonymous_access', null);
        } else {
            return $this->get('params')->get('private_access', null);
        }
    }

    /**
     * Prepare the item for indexing.
     *
     * @param   StdClass  $source
     * @return  array
     */
    protected function prepare($source)
    {
        $access = $this->mapDSpaceAccessToJoomla(array_pop($source->read));

        $source = $this->getItem($source->{"search.resourceid"});

        $metadata = array();

        foreach ($source->metadata as $field) {
            $name = $field->schema.'.'.$field->element;

            if (isset($field->qualifier)) {
                $name .= '.'.$field->qualifier;
            }

            if (array_key_exists($name, $metadata)) {
                if (!is_array($metadata[$name])) {
                    $temp = $metadata[$name];
                    $metadata[$name] = array();
                    $metadata[$name][] = $temp;
                }

                $metadata[$name][] = $field->value;
            } else {
                $metadata[$name] = $field->value;
            }
        }

        $locale = JArrayHelper::getValue($metadata, 'dc.language.iso');

        if (is_array($locale)) {
            $locale = array_pop($lang);
        }

        $lang = $this->getLanguage($locale, false);

        $category = $this->getCollection($source->collection->id);

        $array = array();

        $array['id'] = $this->buildId($source->id);
        $array['id_i'] = $source->id;
        $array['name'] = $source->name;

        $array["author"] = array();
        $array["author_ss"] = array();

        $authors = JArrayHelper::getValue($metadata, 'dc.contributor.author', array());

        if (!is_array($authors)) {
            $authors = array($authors);
        }

        foreach ($authors as $author) {
            $array["author"][] = $author;
            $array["author_ss"][] = $this->getFacet($author);
        }

        $array["title_txt_$lang"] = $array['name'];
        $array['context_s'] = $this->get('context');
        $array['lang_s'] = $this->getLanguage($locale);

        $array['access_i'] = $access;
        $array["category_txt_$lang"] = $category->name;
        $array["category_s"] = $this->getFacet($category->name); // for faceting
        $array["category_i"] = $category->id;

        $array['handle_s'] = $source->handle;

        $accessioned = JArrayHelper::getValue($metadata, 'dc.date.accessioned');

        if (is_array($accessioned)) {
            $accessioned = array_pop($accessioned);
        }

        $created = JFactory::getDate($accessioned);
        $modified = JFactory::getDate(date("c", $source->lastModified/1000));

        if ($created > $modified) {
            $modified = $created;
        }

        $array['created_tdt'] = $created->format('Y-m-d\TH:i:s\Z', false);
        $array['modified_tdt'] = $modified->format('Y-m-d\TH:i:s\Z', false);
        $array["parent_id_i"] = $category->id;

        $descriptions = array();
        $descriptions[] = JArrayHelper::getValue($metadata, 'dc.description');
        $descriptions[] = JArrayHelper::getValue($metadata, 'dc.description.abstract');

        $content = null;

        foreach ($descriptions as $description) {
            // flatten description.
            if (is_array($description)) {
                $description = implode("\n", $description);
            }

            if (!empty($description)) {
                $content .= $description;
            }
        }

        if (!is_null($content)) {
            $array["content_txt_$lang"] = $content;
        }

        return $array;
    }

    private function getCollection($id)
    {
        $collections = $this->get('collections');
        $collection = null;

        if (array_key_exists($id, $collections)) {
            $collection = JArrayHelper::getValue($collections, $id);
        } else {
            try {
                $url = new JUri($this->params->get('rest_url').'/collections/'.$id.'.json');

                $http = JHttpFactory::getHttp();

                $response = $http->get((string)$url);

                if ((int)$response->code !== 200) {
                    throw new Exception($response->body, $response->code);
                }

                $collection = json_decode($response->body);

                $this->collections[$collection->id] = $collection;
            } catch (Exception $e) {
                JLog::add($e->getMessage(), JLog::ERROR, 'jsolr');

                throw $e;
            }
        }
        return $collection;
    }

    public function onListMetadataFields()
    {
        $metadata = array();

        $url = new JUri($this->params->get('rest_url').'/items/metadatafields.json');

        $http = JHttpFactory::getHttp();

        $response = $http->get((string)$url);

        if ((int)$response->code !== 200) {
            throw new Exception($response->body, $response->code);
        }

        $metadata = json_decode($response->body);

        return $metadata;
    }

    public function getLanguage($language, $includeRegion = true)
    {
        // iso language/region codes are poorly implemented in DSpace.
        // Default en to en_GB since there is an en_US.
        if ($language == 'en') {
            $language = 'en_GB';
        }

        $language = str_replace('_', '-', $language);

        return parent::getLanguage($language, $includeRegion);
    }

    public function onJSolrSearchPrepareData($document)
    {
        if ($this->itemContext == $document->context_s) {
            require_once(JPATH_ROOT."/components/com_jcar/helpers/route.php");

            if (class_exists("JCarHelperRoute")) {
                $document->link = JCarHelperRoute::getItemRoute($document->id);
            }
        }
    }
}
