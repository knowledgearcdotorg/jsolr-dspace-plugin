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

class PlgJSolrDSpace extends \JSolr\Plugin\Update
{
    protected $context = 'archive.item';

    protected $collections = array();

    /**
     * Gets all DSpace items using the JSpace component and DSpace REST API.
     *
     * @return array A list of DSpace items.
     */
    protected function getItems($start = 0, $limit = 500)
    {
        $items = array();

        try {
            $items = array();

            $vars = array();

            $vars['q'] = "*:*";

            $vars['fl'] = 'search.resourceid,search.uniqueid,read';

            $vars['fq'] = 'search.resourcetype:2';

            $vars['start'] = $start;
            $vars['rows'] = $limit;

            if ($this->get('params')->get('private_access', "") == "") {
                $vars['fq'] .= ' AND read:g0';
            } else {
                // only get items with read set.
                $vars['fq'] .= ' AND read:[* TO *]';
            }

            if ($indexed = $this->indexed) {
                $vars['fq'] .= " AND SolrIndexer.lastIndexed:[$indexed TO $this->now]";
            }

            $vars['fq'] = urlencode($vars['fq']);

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

            if ($indexed = $this->indexed) {
                $vars['fq'] .= " AND SolrIndexer.lastIndexed:[$indexed TO $this->now]";
            }

            $vars['fq'] = urlencode($vars['fq']);

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

        try {
            $source = $this->getItem($source->{"search.resourceid"});
        } catch (Exception $e) {
            return array();
        }

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

        $description = JArrayHelper::getValue(
                        $metadata,
                        'dc.description',
                        array(),
                        'array');

        $array["description_txt_$lang"] = implode(" ", $description);

        $content = JArrayHelper::getValue(
                        $metadata,
                        'dc.description.abstract',
                        array(),
                        'array');

        $array["content_txt_$lang"] = implode(" ", $content);

        // index additional fields for faceting.
        $types = array(
            "_ss"=>array("dc.subject", "dc.type", "dc.relation"),
            "_dts"=>array("dc.date"));

        foreach ($metadata as $key=>$value) {
            foreach ($types as $ktype=>$vtype) {
                foreach ($vtype as $needle) {
                    if (strpos($key, $needle) === 0) {
                        $parts = explode(".", $key);

                        $field = (array_pop($parts));

                        if (!isset($array[$field.$ktype])) {
                            $array[$field.$ktype] = array();
                        }

                        if (!is_array($value)) {
                            $value = array($value);
                        }

                        if (!empty($value)) {
                            if ($needle == "dc.date") {
                                // DSpace has poor date handling. Sometimes only the year is available.
                                foreach ($value as $k=>$v) {
                                    // convert incomplete dates to correctly formatted date.
                                    if (DateTime::createFromFormat('Y', $v)) {
                                        // if year only
                                        $value[$k] = $v."-01-01T00:00:00Z";
                                    } else if (DateTime::createFromFormat('Y-m', $v)) {
                                        // if month and year
                                        $value[$k] = $v."-01T00:00:00Z";
                                    } else if (DateTime::createFromFormat('Y-m-d', $v)) {
                                        // if day, month, year
                                        $value[$k] = $v."T00:00:00Z";
                                    }
                                }
                            }

                            $array[$field.$ktype] = array_merge($array[$field.$ktype] , $value);

                            // only index dc.subject as a tag.
                            if ($needle == 'dc.subject') {
                                if (!isset($array['tag_ss'])) {
                                    $array['tag_ss'] = array();
                                }

                                $array['tag_ss'] = array_merge($array['tag_ss'] , $value);
                            }
                        }
                    }
                }
            }
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
        if ($this->context == $document->context_s) {
            require_once(JPATH_ROOT."/components/com_jcar/helpers/route.php");

            if (class_exists("JCarHelperRoute")) {
                $document->link = JCarHelperRoute::getItemRoute("dspace:".$document->id_i);
            }
        }
    }
}
