<?php
/**
 * @package     JSolr.Plugin
 * @subpackage  Index
 * @copyright   Copyright (C) 2012-2019 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die();

JLoader::import('joomla.log.log');

JLoader::registerNamespace('JSolr', JPATH_PLATFORM);

class PlgJSolrDSpace extends \JSolr\Plugin\Update
{
    protected $context = 'archive.item';

    protected $communities = array();

    protected $collections = array();

    /**
     * Gets all DSpace items using the JSpace component and DSpace REST API.
     *
     * @return array A list of DSpace items.
     */
    protected function getItems($start = 0, $limit = 10)
    {
        try {
            $items = array();

            $items = array();

            $vars = array();

            $vars['q'] = "*:*";

            $vars['fl'] = 'search.resourceid,search.uniqueid,location.comm,read';

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
        } catch (\Exception $e) {
            $this->out(array("task:index crawler:".$this->get('context')."\n".(string)$e."\nWill try to continue..."), \JLog::ERROR);
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
     * @param   array   $access  The DSpace access levels.
     *
     * @return  string  The Joomla! access level.
     */
    private function mapDSpaceAccessToJoomla($access)
    {
        $found = false;

        while ($level = current($access) && !$found) {
            // g0 = public
            if ($level == 'g0') {
                $found = true;
            }

            next($access);
        }

        if ($found) {
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
        $access = $this->mapDSpaceAccessToJoomla($source->read);

        $communities = $source->{"location.comm"};

        try {
            $source = $this->getItem($source->{"search.resourceid"});
        } catch (\Exception $e) {
            $this->out(array("task:index crawler:".$this->get('context')."\n".(string)$e."\nWill try to continue..."), \JLog::ERROR);
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

                $metadata[$name][] = static::_cleanControlledVocabulary($field->value);
            } else {
                $metadata[$name] = static::_cleanControlledVocabulary($field->value);
            }
        }

        $locale = JArrayHelper::getValue($metadata, 'dc.language.iso');

        if (is_array($locale)) {
            $locale = array_pop($lang);
        }

        $category = $this->getCollection($source->collection->id);

        $i18n = array();

        $array = array();

        $array['id'] = $this->buildId($source->id);
        $array['id_i'] = $source->id;
        $array['name'] = $source->name;

        $array["author"] = array();
        $array["author_ss"] = array();

        $authors = [];

        // Merge authors from multiple dc sources.
        $dcAuthorFields = ['dc.contributor.author', 'dc.contributor'];

        foreach ($dcAuthorFields as $item) {
            $dcAuthorValues = JArrayHelper::getValue($metadata, $item, array());

            if (!is_array($dcAuthorValues)) {
                $dcAuthorValues = array($dcAuthorValues);
            }

            $authors = array_merge($authors, $dcAuthorValues);
        }

        foreach ($authors as $author) {
            $array["author"][] = $author;
            $array["author_ss"][] = $this->getFacet($author);
        }

        $i18n["title"] = $array['name'];
        $array['context_s'] = $this->get('context');

        // DSpace has poor language support and most users do not implement
        // multilingual properly so just default to language = all.
        $array['lang_s'] = '*';

        $array['access_i'] = $access;
        $i18n["category"] = $category->name;
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

        $array['created_dt'] = $created->format('Y-m-d\TH:i:s\Z', false);
        $array['modified_dt'] = $modified->format('Y-m-d\TH:i:s\Z', false);
        $array["parent_id_i"] = $category->id;

        $description = JArrayHelper::getValue(
                        $metadata,
                        'dc.description',
                        array(),
                        'array');

        $i18n["description"] = implode(" ", $description);

        $content = JArrayHelper::getValue(
                        $metadata,
                        'dc.description.abstract',
                        array(),
                        'array');

        $i18n["content"] = implode(" ", $content);

        // index additional fields for faceting.
        $types = array(
            "_ss"=>array("dc.subject", "dc.type", "dc.relation", "dc.date"),
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
                            if ($ktype == "_dts" && $needle == "dc.date") {
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

                                    // sometimes DSpace dates aren't even dates. Only allow valid dates.
                                    $d = DateTime::createFromFormat('Y-m-d H:i:s', $value[$k]);

                                    if (!$d || $d->format('Y-m-d H:i:s') !== $value[$k]) {
                                        unset($value[$k]);
                                    }
                                }
                            }

                            $array[$field.$ktype] = array_merge($array[$field.$ktype], $value);

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

        if (isset($array['issued_dts'][0])) {
            $date = $array['issued_dts'][0];
        } else {
            $date = $array['modified_dt'];
        }

        // set date based on issued or fallback to modified.
        $published = JFactory::getDate($date);

        $array['published_dt'] = $published->format('Y-m-d\TH:i:s\Z', false);

        // for now index all multilingual fields into every configured joomla language.
        foreach ($i18n as $key=>$value) {
            foreach (JLanguageHelper::getLanguages() as $language) {
                $lang = $this->getLanguage($language->lang_code, false);
                $array[$key."_txt_".$lang] = $value;
            }
        }

        if ($this->params->get('full_text_indexing', false)) {
            $bitstreams = $this->getBitstreams($source);

            foreach ($bitstreams as $bitstream) {
                $lang = $this->getLanguage(array_shift($bitstream->lang), false);

                if (isset($bitstream->body)) {
                    $array["content_txt_".$lang] .= "\n".$bitstream->body;
                }

                foreach ($bitstream->metadata as $key=>$value) {
                    $index = 'bitstream_'.$bitstream->id.'_'.preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($key));

                    $array[$index.'_ss'] = is_array($value) ? $value : [$value];
                }
            }
        }

        foreach ($communities as $community) {
            $array['community_ss'][] = $this->getCommunity($community)->name;
        }

        return $array;
    }

    private function getCommunity($id)
    {
        $communities = $this->get('communities');
        $community = null;

        if (array_key_exists($id, $communities)) {
            $community = JArrayHelper::getValue($communities, $id);
        } else {
            try {
                $url = new JUri($this->params->get('rest_url').'/communities/'.$id.'.json');

                $http = JHttpFactory::getHttp();

                $response = $http->get((string)$url);

                if ((int)$response->code !== 200) {
                    throw new Exception($response->body, $response->code);
                }

                $community = json_decode($response->body);

                $this->communities[$community->id] = $community;
            } catch (Exception $e) {
                $this->out($e->getMessage(), \JLog::ERROR);

                throw $e;
            }
        }

        return $community;
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
            if (file_exists(JPATH_ROOT."/components/com_jcar/helpers/route.php")) {
                require_once(JPATH_ROOT."/components/com_jcar/helpers/route.php");

                if (class_exists("JCarHelperRoute")) {
                    $document->link = JCarHelperRoute::getItemRoute("dspace:".$document->id_i);
                }
            }
        }
    }

    /**
     * Gets a list of bitstreams for the parent item.
     *
     * @param stdClass $parent The parent Solr item.
     * @return array An array of bitstream objects.
     */
    private function getBitstreams($parent)
    {
        $bundles = array();

        $bitstreams = array();

        $url = new JUri($this->params->get('rest_url').'/items/'.$parent->id.'/bundles.json?type=ORIGINAL');

        $http = JHttpFactory::getHttp();

        $response = $http->get((string)$url);

        if ((int)$response->code !== 200) {
            throw new Exception($response->body, $response->code);
        }

        $bundles = json_decode($response->body);

        $i = 0;

        foreach ($bundles as $bundle) {
            foreach ($bundle->bitstreams as $bitstream) {
                $path = $this->params->get('rest_url').'/bitstreams/'.$bitstream->id.'/download';

                try {
                    $this->out(array($path, "[extracting]"), \JLog::DEBUG);

                    $dispatcher = JDispatcher::getInstance();
                    JPluginHelper::importPlugin('jtika');

                    $results = $dispatcher->trigger('onJTikaExtract', array($path));

                    $data = array_pop($results);

                    if (is_null($data)) {
                        $this->out(array($path, "[ignored]"), \JLog::DEBUG);
                    } else {
                        $bitstreams[$i] = $data;
                        $bitstreams[$i]->type = $bundle->name;
                        $bitstreams[$i]->id = $bitstream->id;

                        $this->out(array($path, "[extracted]"), \JLog::DEBUG);

                        $i++;
                    }
                } catch (Exception $e) {
                    if ($e->getMessage()) {
                        $this->out($e->getMessage(), \JLog::ERROR);
                    } else {
                        $code = $e->getCode();
                        $this->out(array(JText::_("PLG_JSOLRCRAWLER_DSPACE_ERROR_".$code)), \JLog::ERROR);
                        $this->out(array($path, '[status:'.$code.']'), \JLog::ERROR);
                    }
                }
            }
        }

        return $bitstreams;
    }

    private function _cleanControlledVocabulary($value)
    {
        $ignored = $this
                    ->get('params')
                    ->get('ignore_controlled_vocabulary');

        $array = explode(',', $ignored);

        $cleanValue = $value;

        $found = false;

        while (($item = current($array)) && !$found) {
            $search = $item."::";

            if (strpos($value, $search) === 0) {
                $cleanValue = str_replace($search ,"", $value);

                $found = true;
            }

            next($array);
        }

        return $cleanValue;
    }
}
