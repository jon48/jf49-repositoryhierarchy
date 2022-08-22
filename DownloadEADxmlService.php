<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2022 webtrees development team
 *					  <http://webtrees.net>
 *
 * RepositoryHierarchy (webtrees custom module):  
 * Copyright (C) 2022 Markus Hemprich
 *                    <http://www.familienforschung-hemprich.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\RepositoryHierarchyNamespace;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Encodings\UTF8;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Validator;
use Matriphe\ISO639\ISO639;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Fisharebest\Webtrees\Repository;

use DOMAttr;
use DOMDocument;
use DOMImplementation;
use DOMNode;
use RuntimeException;

use function date;

/**
 * Download Service for EAD XML files
 */
class DownloadEADxmlService
{

    //Types of EAD XML    
    public const DOWNLOAD_OPTION_APE_EAD = 'download_option_ape_ead';
    public const DOWNLOAD_OPTION_ATOM = 'download_option_atom';
    public const DOWNLOAD_OPTION_HTML = 'download_option_html';
    public const DOWNLOAD_OPTION_PDF = 'download_option_pdf';
    public const DOWNLOAD_OPTION_TEXT = 'download_option_text';
    public const DOWNLOAD_OPTION_XML = 'download_option_xml';
    public const DOWNLOAD_OPTION_ALL = 'download_option_all';

    public const WEBTREES_ROUTE_TO_TREE = '/index.php?route=%2Fwebtrees%2Ftree%2F';
    public const WEBTREES_TREE_TO_SOURCE = '%2Fsource%2F';
    public const WEBTREES_TREE_TO_REPO = '%2Frepository%2F';

    //The xml object for EAD XML export
    private DOMDocument $ead_xml;

    //The top level collection within the xml
    private DOMNode $collection;

    //The ResponseFactory used
    private ResponseFactoryInterface $response_factory;

    //The StreamFactory used
    private StreamFactoryInterface $stream_factory;

    //The LinkedRecordService used
    private LinkedRecordService $linked_record_service;

    //The repository, to which the service relates
    private Repository $repository;

    //The user, for which the service is executed
    private UserInterface $user;

    //The ISO ISO-639-2b language tag related to the webtrees session
    private string $ISO_639_2b_language_tag;


    /**
     * Constructor
     * 
     * @param string        $template_filename    The path of the xml template file name with file extension 
     * @param Repository    $repository    
     *
     */
    public function __construct(string $xml_type, 
                                Repository $repository, 
                                CallNumberCategory $root_category,
                                UserInterface $user)
    {
        //Initialize variables
        $this->repository = $repository;
        $this->user = $user;
        $this->response_factory = app(ResponseFactoryInterface::class);
        $this->stream_factory = new Psr17Factory();
        $this->linked_record_service = new LinkedRecordService();

        //Set language
        $iso_table = new ISO639;
        $language = $iso_table->languageByCode1(Session::get('language'));
        $this->ISO_639_2b_language_tag = $iso_table->code2bByLanguage($language);

        //Create DOM document
        $dom_implementation = new DOMImplementation();
 
        //Include DTD
        $dtd = $dom_implementation->createDocumentType('ead',
        '+//ISBN 1-931666-00-8//DTD ead.dtd (Encoded Archival Description (EAD) Version 2002)//EN',
        'http://lcweb2.loc.gov/xmlcommon/dtds/ead2002/ead.dtd');    
        $this->ead_xml = $dom_implementation->createDocument('', '', $dtd);

        //Set encoding
        $this->ead_xml->encoding="UTF-8";

        //Settings for a nice xml format
        $this->ead_xml->preserveWhiteSpace = false;
        $this->ead_xml->formatOutput = true;

        //Initialize EAD xml
        $ead_dom = $this->ead_xml->appendChild($this->ead_xml->createElement('ead')); 
            $ead_dom->appendChild(new DOMAttr('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance'));
            $ead_dom->appendChild(new DOMAttr('xmlns', 'urn:isbn:1-931666-22-9'));            
            $ead_dom->appendChild(new DOMAttr('xmlns:xlink', 'http://www.w3.org/1999/xlink'));            
            $ead_dom->appendChild(new DOMAttr('xsi:schemaLocation', 'urn:isbn:1-931666-22-9 http://www.loc.gov/ead/ead.xsd http://www.w3.org/1999/xlink http://www.loc.gov/standards/xlink/xlink.xsd'));            
            $ead_dom->appendChild(new DOMAttr('audience', 'external'));     

        //Create header, archive, and top level collection
        $this->addHeader($xml_type, $ead_dom);
        $archive_dom = $this->addArchive($xml_type,$ead_dom, $root_category);  
        $dsc_dom = $archive_dom->appendChild($this->ead_xml->createElement('dsc'));
        $this->collection = $this->addCollection($xml_type, $dsc_dom, $root_category);
    }

    /**
     * Get collection
     *
     * @return DOMNode
     */
    public function getCollection(): DOMNode {
        return $this->collection;
    }     

    /**
     * Create XML for a hierarchy of call numbers
     * 
     * @param CallNumberCategory  $call_number_category
     */
    public function createXMLforCategory(string $xml_type, DOMNode $dom, CallNumberCategory $call_number_category)
    {
        $categories = $call_number_category->getSubCategories();

        foreach ($categories as $category) {

            //Add node to xml structure
            $series_dom = $this->addSeries($xml_type, $dom, $category);

            //Add sources to xml structure
            foreach ($category->getSources() as $source) {
                $this->addItem($xml_type, $series_dom, $source, $this->repository);
            }

            //Call recursion for sub categories
            $this->createXMLforCategory($xml_type, $series_dom, $category);
        }
    }

    /**
     * Add the header to EAD XML
     * 
     * @param DOMNode       $dom
     * 
     * @return DOMNode      
     */
    private function addHeader(string $xml_type, DOMNode $dom): DOMNode
    {         
        $module_service = new ModuleService();
        $repository_hierarchy = $module_service->findByName(RepositoryHierarchy::MODULE_NAME);
        $user_id = $this->user->id();

        //<eadheader>
        $header_dom = $dom->appendChild($this->ead_xml->createElement('eadheader'));
            $header_dom->appendChild(new DOMAttr('countryencoding', 'iso3166-1'));
            $header_dom->appendChild(new DOMAttr('dateencoding', 'iso8601'));            
            $header_dom->appendChild(new DOMAttr('langencoding', 'iso639-2b'));            
            $header_dom->appendChild(new DOMAttr('repositoryencoding', 'iso15511'));            
            $header_dom->appendChild(new DOMAttr('scriptencoding', 'iso15924'));            

            //<eadid>
            $eadid_dom = $header_dom->appendChild($this->ead_xml->createElement('eadid', 
                $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_FINDING_AID_TITLE . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '')));
                
                $pref = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_MAIN_AGENCY_CODE . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '');
                if($pref !== '') {
                    $eadid_dom->appendChild(new DOMAttr('mainagencycode', $pref));
                }
                $pref = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_FINDING_AID_IDENTIFIER . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '');
                if($pref !== '') {
                    $eadid_dom->appendChild(new DOMAttr('identifier', $pref));
                }
                $pref = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_COUNTRY_CODE . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '');
                if($pref !== '') {
                    $eadid_dom->appendChild(new DOMAttr('countrycode', $pref));
                }
                $pref = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_FINDING_AID_URL . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '');
                if($pref !== '') {
                    $eadid_dom->appendChild(new DOMAttr('url', $pref));
                }

            //<filedesc>
            $filedesc_dom = $header_dom->appendChild($this->ead_xml->createElement('filedesc'));

                //<titlestmt>
                $titlestmt_dom = $filedesc_dom->appendChild($this->ead_xml->createElement('titlestmt'));

                    //<titleproper>
                    $titleproper_dom = $titlestmt_dom->appendChild($this->ead_xml->createElement('titleproper',
                        $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_FINDING_AID_TITLE . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '')));
                        $titleproper_dom->appendChild(new DOMAttr('encodinganalog', '245'));

                //<publicationstmt>
                $publicationstmt_dom = $filedesc_dom->appendChild($this->ead_xml->createElement('publicationstmt'));

                    //<publisher>
                    $pref = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_FINDING_AID_PUBLISHER . $this->repository->tree()->id() . '_' . $this->repository->xref() . '_' . $user_id, '');
                    if($pref !== '') {
                        $publisher_dom = $publicationstmt_dom->appendChild($this->ead_xml->createElement('publisher', $pref));
                            $publisher_dom->appendChild(new DOMAttr('encodinganalog', '260$b'));
                    }

                    //<date>
                    $date_dom = $publicationstmt_dom->appendChild($this->ead_xml->createElement('date', date('Y-m-d')));
                        $date_dom->appendChild(new DOMAttr('normal', date('Y-m-d')));
                        $date_dom->appendChild(new DOMAttr('encodinganalog', '260$c'));
  
            //<profiledesc>
            $profiledesc_dom = $header_dom->appendChild($this->ead_xml->createElement('profiledesc'));

                //<creation>
                $creation_dom = $profiledesc_dom->appendChild($this->ead_xml->createElement('creation',
                    I18N::translate('Generated by webtrees')));
            
                    //<date>
                    $date_dom = $creation_dom->appendChild($this->ead_xml->createElement('date', date('Y-m-d')));
                        $date_dom->appendChild(new DOMAttr('normal', date('Y-m-d')));

                //<langusage>
                $langusage_dom = $profiledesc_dom->appendChild($this->ead_xml->createElement('langusage'));

                    //<language>
                    $iso_table = new ISO639;
                    $language_dom = $langusage_dom->appendChild($this->ead_xml->createElement('language', $iso_table->nativeByCode2b($this->ISO_639_2b_language_tag)));
                        $language_dom->appendChild(new DOMAttr('langcode', $this->ISO_639_2b_language_tag));
                        $date_dom->appendChild(new DOMAttr('encodinganalog', '041'));

        return $header_dom;
    }
  
    /**
     * Add an archive to EAD XML
     * 
     * @param DOMNode       
     * 
     * @return DOMNode      
     */
    private function addArchive(string $xml_type, DOMNode $dom, CallNumberCategory $root_category): DOMNode
    {
         //<archdesc>
         $archive_dom = $dom->appendChild($this->ead_xml->createElement('archdesc'));
            $archive_dom->appendChild(new DOMAttr('level', 'fonds'));
            $archive_dom->appendChild(new DOMAttr('type','Findbuch'));
            $archive_dom->appendChild(new DOMAttr('encodinganalog','3.1.4'));
            $archive_dom->appendChild(new DOMAttr('relatedencoding','ISAD(G)v2'));

             //<did>
            $did_dom = $archive_dom->appendChild($this->ead_xml->createElement('did'));

                //<unittitle>
                $unittitle_dom = $did_dom->appendChild($this->ead_xml->createElement('unittitle', self::removeHtmlTags($this->repository->fullName())));
                    $unittitle_dom->appendChild(new DOMAttr('encodinganalog', '3.1.2'));
                
                //<unitid>
                $unitid_dom = $did_dom->appendChild($this->ead_xml->createElement('unitid', self::removeHtmlTags($this->repository->fullName())));
                    $unitid_dom->appendChild(new DOMAttr('encodinganalog', '3.1.1'));

                //<langmaterial>
                $langmaterial_dom = $did_dom->appendChild($this->ead_xml->createElement('langmaterial'));
                    $langmaterial_dom->appendChild(new DOMAttr('encodinganalog', '3.4.3'));

                    //<language>
                    $iso_table = new ISO639;
                    $language_dom = $langmaterial_dom->appendChild($this->ead_xml->createElement('language', $iso_table->nativeByCode2b($this->ISO_639_2b_language_tag)));
                        $language_dom->appendChild(new DOMAttr('langcode', $this->ISO_639_2b_language_tag));

                //<unitdate>        example: <unitdate normal="1900-01-01/1902-12-31">Laufzeit</unitdate>
                $date_range = $root_category->getOverallDateRange();

                if ( $date_range !== null) {

                    $date_range_text = $date_range->display(null, '%Y-%m-%d');
                    $date_range_text = self::formatDateRange($date_range_text);
                    
                $unitdate_dom = $did_dom->appendChild($this->ead_xml->createElement('unitdate', I18N::translate("Date range")));
                    $unitdate_dom->appendChild(new DOMAttr('normal', $date_range_text));
                    $unitdate_dom->appendChild(new DOMAttr('encodinganalog', '3.1.3'));
                }

                //<physdesc>
                //encodinganalog 3.1.5
                //TBD

                //<repository>
                $repository_dom = $did_dom->appendChild($this->ead_xml->createElement('repository'));

                    //<corpname>
                    $repository_dom->appendChild($this->ead_xml->createElement('corpname', self::removeHtmlTags($this->repository->fullName())));

                    //<address>
                    $address_lines = $this->getRepositoryAddressLines($this->repository);

                    if(!empty($address_lines)){
                        $address_dom = $repository_dom->appendChild($this->ead_xml->createElement('address'));

                        foreach($address_lines as $line) {
                            //<addressline>
                            $address_dom->appendChild($this->ead_xml->createElement('addressline', $line));    
                        }
                    }
                    //<extref> bzw. www
                    if (isset($address_lines['REPO:WWW'])) {
                        $extref_dom = $repository_dom->appendChild($this->ead_xml->createElement('extref'));
                            $extref_dom->appendChild(new DOMAttr('xlink:href', $address_lines['REPO:WWW']));
                            $extref_dom->appendChild(new DOMAttr('xlink:title', self::removeHtmlTags($this->repository->fullName())));                    
                    }

                //<origination>
                $origination_dom = $did_dom->appendChild($this->ead_xml->createElement('origination'));
                    $origination_dom->appendChild(new DOMAttr('encodinganalog', '3.2.1'));

                    //<name>
                    $origination_dom->appendChild($this->ead_xml->createElement('name', 
                        self::removeHtmlTags($this->repository->fullName())));

            //<otherfindaid> //=http link to online finding aid of archive
            //TBD

        return $archive_dom;
    }

    /**
     * Add a collection (for the whole repository) to EAD XML
     * 
     * @param DOMNode       $dom
     * 
     * @return DOMNode      
     */
    private function addCollection(string $xml_type, DOMNode $dom, CallNumberCategory $root_category): DOMNode
    {
        $module_service = new ModuleService();
        $repository_hierarchy = $module_service->findByName(RepositoryHierarchy::MODULE_NAME);
        $base_url = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_WEBTREES_BASE_URL, ''); 

         //<c>
         $collection_dom = $dom->appendChild($this->ead_xml->createElement('c'));
            $collection_dom->appendChild(new DOMAttr('level', 'collection'));
            $collection_dom->appendChild(new DOMAttr('id', $this->repository->xref()));
 
             //<did>
             $did_dom = $collection_dom->appendChild($this->ead_xml->createElement('did'));

                //<unitid>
                $unitid_dom = $did_dom->appendChild($this->ead_xml->createElement('unitid', $this->repository->xref()));
                    $unitid_dom->appendChild(new DOMAttr('encodinganalog', '3.1.1'));

                //<unittitle>
                $unittitle_dom = $did_dom->appendChild($this->ead_xml->createElement('unittitle', self::removeHtmlTags($this->repository->fullName())));
                    $unittitle_dom->appendChild(new DOMAttr('encodinganalog', '3.1.2'));
                
                //<unitdate>        example: <unitdate normal="1900-01-01/1902-12-31">Laufzeit</unitdate>
                $date_range = $root_category->getOverallDateRange();

                if ( $date_range !== null) {

                    $date_range_text = $date_range->display(null, '%Y-%m-%d');
                    $date_range_text = self::formatDateRange($date_range_text);
                    
                $unitdate_node = $did_dom->appendChild($this->ead_xml->createElement('unitdate', I18N::translate("Date range")));
                    $unitdate_node->appendChild(new DOMAttr('normal', $date_range_text));
                    $unitdate_node->appendChild(new DOMAttr('encodinganalog', '3.1.3'));
                }

                //<langmaterial>
                $langmaterial_dom = $did_dom->appendChild($this->ead_xml->createElement('langmaterial'));
                    $langmaterial_dom->appendChild(new DOMAttr('encodinganalog', '3.4.3'));

                    //<language>
                    $iso_table = new ISO639;
                    $language_dom = $langmaterial_dom->appendChild($this->ead_xml->createElement('language', $iso_table->nativeByCode2b($this->ISO_639_2b_language_tag)));
                        $language_dom->appendChild(new DOMAttr('langcode', $this->ISO_639_2b_language_tag));

                //<origination>
                $origination_dom = $did_dom->appendChild($this->ead_xml->createElement('origination'));
                    $origination_dom->appendChild(new DOMAttr('label', 'final'));
                    $origination_dom->appendChild(new DOMAttr('encodinganalog', '3.2.1'));

                    //<name>
                    $origination_dom->appendChild($this->ead_xml->createElement('name', self::removeHtmlTags($this->repository->fullName())));

                //<dao>   
                $dao_node =$did_dom->appendChild($this->ead_xml->createElement('dao'));
                    $dao_node->appendChild(new DOMAttr('xlink:href', $base_url . self::WEBTREES_ROUTE_TO_TREE . $this->repository->tree()->name() . self::WEBTREES_TREE_TO_REPO . $this->repository->xref()));
                    $dao_node->appendChild(new DOMAttr('xlink:title', self::removeHtmlTags($this->repository->fullName())));                    

                //<otherfindaid>
                //TBD

                //<scopecontent>
                //TBD

                //<accessrestrict>
                //TBD

                //<controlaccess>
                //TBD

        return $collection_dom;
    }

    /**
     * Add a series (i.e. call number category) to EAD XML
     * 
     * @param DOMNode               $dom
     * @param CallNumberCategory    $call_number_category
     * 
     * @return DOMNode      
     */
    private function addSeries(string $xml_type, DOMNode $dom, CallNumberCategory $call_number_category): DOMNode
    {
         //<c>
         $series_dom = $dom->appendChild($this->ead_xml->createElement('c'));
            $series_dom->appendChild(new DOMAttr('level', 'series'));
            $series_dom->appendChild(new DOMAttr('id', 'CN' . $call_number_category->getId()));
 
             //<did>
             $did_dom = $series_dom->appendChild($this->ead_xml->createElement('did'));

                //<unittitle>
                $unittitle_dom = $did_dom->appendChild($this->ead_xml->createElement('unittitle', $call_number_category->getName()));
                    $unittitle_dom->appendChild(new DOMAttr('encodinganalog', '3.1.2'));
                
                //<unitid>
                $unitid_dom = $did_dom->appendChild($this->ead_xml->createElement('unitid', $call_number_category->getFullName()));
                    $unitid_dom->appendChild(new DOMAttr('encodinganalog', '3.1.1'));

                //<langmaterial>
                $langmaterial_dom = $did_dom->appendChild($this->ead_xml->createElement('langmaterial'));
                    $langmaterial_dom->appendChild(new DOMAttr('encodinganalog', '3.4.3'));

                    //<language>
                    $iso_table = new ISO639;
                    $language_dom = $langmaterial_dom->appendChild($this->ead_xml->createElement('language', $iso_table->nativeByCode2b($this->ISO_639_2b_language_tag)));
                        $language_dom->appendChild(new DOMAttr('langcode', $this->ISO_639_2b_language_tag));

                //<unitdate>        example: <unitdate normal="1900-01-01/1902-12-31">Laufzeit</unitdate>
                $date_range = $call_number_category->getOverallDateRange();

                if ( $date_range !== null) {

                $date_range_text = $date_range->display(null, '%Y-%m-%d');
                $date_range_text = self::formatDateRange($date_range_text);
                    
                $unitdate_dom = $did_dom->appendChild($this->ead_xml->createElement('unitdate', I18N::translate("Date range")));
                    $unitdate_dom->appendChild(new DOMAttr('normal', $date_range_text));
                    $unitdate_dom->appendChild(new DOMAttr('encodinganalog', '3.1.3'));
                }

        return $series_dom;
    } 

    /**
     * Add an item (i.e. source) to EAD XML
     * 
     * @param DOMDocument      $dom
     * @param Source           $source
     */
    private function addItem(string $xml_type, DOMNode $dom, Source $source, Repository $repository)
    {
        $module_service = new ModuleService();
        $repository_hierarchy = $module_service->findByName(RepositoryHierarchy::MODULE_NAME);
        $base_url = $repository_hierarchy->getPreference(RepositoryHierarchy::PREF_WEBTREES_BASE_URL, ''); 

        $fact_values =self::sourceValuesByTag($source, $repository);

        //<c>
        $c_dom = $dom->appendChild($this->ead_xml->createElement('c'));
            $c_dom->appendChild(new DOMAttr('level', 'item'));
            $c_dom->appendChild(new DOMAttr('id', $source->xref()));

            //<did>
            $did_dom = $c_dom->appendChild($this->ead_xml->createElement('did'));

                //<unitid>
                if (isset($fact_values['SOUR:REPO:CALN'])) {
                    $unitid_dom = $did_dom->appendChild($this->ead_xml->createElement('unitid', $fact_values['SOUR:REPO:CALN']));
                        $unitid_dom->appendChild(new DOMAttr('encodinganalog', '3.1.1'));
                }

                //<unittitle>
                $unittitle_node =$did_dom->appendChild($this->ead_xml->createElement('unittitle', $fact_values['SOUR:TITL']));
                    $unittitle_node->appendChild(new DOMAttr('encodinganalog', '3.1.2'));

                //<unitdate>        example: <unitdate normal="1900-01-01/1902-12-31">Laufzeit</unitdate>
                if (isset($fact_values['SOUR:DATA:EVEN:DATE'])) {
                    $unitdate_node = $did_dom->appendChild($this->ead_xml->createElement('unitdate', I18N::translate("Date range")));
                        $unitdate_node->appendChild(new DOMAttr('normal', self::removeHtmlTags($fact_values['SOUR:DATA:EVEN:DATE'])));
                        $unitdate_node->appendChild(new DOMAttr('encodinganalog', '3.1.3'));
                 }
                
                //<dao>
                $dao_node =$did_dom->appendChild($this->ead_xml->createElement('dao'));
                    $dao_node->appendChild(new DOMAttr('xlink:href', $base_url . self::WEBTREES_ROUTE_TO_TREE . $source->tree()->name() . self::WEBTREES_TREE_TO_SOURCE . $source->xref()));
                    $dao_node->appendChild(new DOMAttr('xlink:title', $fact_values['SOUR:TITL']));
                    

                //<note> link to webtrees (for AtoM, only)
                if (($xml_type === DownloadEADxmlService::DOWNLOAD_OPTION_ATOM) &&
                    ($repository_hierarchy !== null) &&
                    ($base_url !== ''))  
                {
                    $note_node = $did_dom->appendChild($this->ead_xml->createElement('note'));
                        $note_node->appendChild(new DOMAttr('type', 'generalNote'));
                        $unittitle_node->appendChild(new DOMAttr('encodinganalog', '3.6.1'));

                    //<p>                        
                        $note_node->appendChild($this->ead_xml->createElement('p', '[webtrees: ' .$source->xref() . '](' . $base_url . self::WEBTREES_ROUTE_TO_TREE . $source->tree()->name() . self::WEBTREES_TREE_TO_SOURCE . $source->xref() . ')'));
                }

                //<place>   within EVEN:PLAC
                //TBD


   }    

    /**
     * Return response to download an EAD XML file
     * 
     * @param string    $filename       Name of download file without extension
     *
     * @return ResponseInterface
     */
    public function downloadResponse(string $filename): ResponseInterface 
    {
        $resource = $this->export($this->ead_xml);
        $stream   = $this->stream_factory->createStreamFromResource($resource);

         return $this->response_factory->createResponse()
            ->withBody($stream)
            ->withHeader('content-type', 'text/xml; charset=' . UTF8::NAME)
            ->withHeader('content-disposition', 'attachment; filename="' . addcslashes($filename, '"') . '.xml"');
    }

    /**
     * Write XML data to a stream
     *
     * @param DOMDocument   $dom
     * 
     * @return resource
     */
    private function export(DOMDocument $dom) 
    {
        $stream = fopen('php://memory', 'wb+');

        if ($stream === false) {
            throw new RuntimeException('Failed to create temporary stream');
        }

        //Write xml to stream
        $bytes_written = fwrite($stream, $dom->saveXML());

        if ($bytes_written !== strlen($dom->saveXML())) {
            throw new RuntimeException('Unable to write to stream.  Perhaps the disk is full?');
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Cannot rewind temporary stream');
        }

        return $stream;
    }

    /**
     * Get address lines of a repository 
     * 
     * @param Repository    $repository
     * 
     * @return array    [string with adress line]
     */
    public function getRepositoryAddressLines(Repository $repository): array
    {
        $address_lines = [];
        $level1_address_tags = [
            'REPO:ADDR',
            'REPO:PHON',
            'REPO:EMAIL',
            'REPO:FAX',
            'REPO:WWW',
        ];
        $level2_address_tags = [
            'ADR1',
            'ADR2',
            'ADR3',
            'CITY',
            'STAE',
            'POST',
            'CTRY',
        ];       

        foreach($repository->facts() as $fact) {

            if (in_array($fact->tag(), $level1_address_tags)) {
                $address_lines[$fact->tag()] = $fact->value();       
            }

            if ($fact->tag() === 'REPO:ADDR') {

                foreach($level2_address_tags as $tag) {

                    if($fact->attribute($tag) !== '') {
                        $address_lines[$tag] = $fact->attribute($tag);
                    }
                }
            }
        }

        return $address_lines;
    }

    /**
     * Source value by tag
     * 
     * @param Source    $source
     * 
     * @return array    [$tag => $value]
     */
    public static function sourceValuesByTag(Source $source, Repository $repository): array
    {
        $source_values = [];
        $level1_source_tags = [
            'SOUR:DATA',
            'SOUR:AUTH',
            'SOUR:TITL',
            'SOUR:ABBR',
            'SOUR:PUBL',
            'SOUR:TEXT',
            'SOUR:REPO',
            'SOUR:REFN',
            'SOUR:RIN',
            'SOUR:NOTE',
        ];

        foreach($source->facts() as $fact) {

            if (in_array($fact->tag(), $level1_source_tags )) {
 
                $source_values[$fact->tag()] = $fact->value();              
                
                switch($fact->tag()) {
                    case 'SOUR:REPO':

                        //Do not continue if it doesn't matches the provided repository
                        if ($fact->value() !== '@'. $repository->xref() . '@') {
                            break;
                        }
                        //Get call number
                        if($fact->attribute('CALN') !== '') {
                            $source_values['SOUR:REPO:CALN'] = $fact->attribute('CALN');
                        }
                        break;

                    case 'SOUR:DATA':
                        //Get date range
                        $date_range = RepositoryHierarchy::displayDateRangeForSource($source, null, '%Y-%m-%d');
                        $date_range_text = self::formatDateRange($date_range);

                        if($date_range_text !== '') {
                            $source_values['SOUR:DATA:EVEN:DATE'] = $date_range_text;
                        }
                        break;

                    case 'SOUR:REFN':
                        //Get reference number type
                        if($fact->attribute('TYPE') !== '') {
                            $source_values['SOUR:REFN:TYPE'] = $fact->attribute('TYPE');
                        }
                        break;                    
                }
            }
        }

        //Substitue characters, which cause errors in XML/HTML
        foreach($source_values as $key=>$value) {
            $source_values[$key] = e($value);
            //$source_values[$key] = htmlspecialchars($value, ENT_XML1, 'UTF-8');
        }

        return $source_values;
    }

    /**
     * Format date range
     * 
     * @param string    $date_range
     * 
     * @return string   
     */
    public static function formatDateRange(string $date_range): string {

        $date_range = self::removeHtmlTags($date_range);
        $date_range = str_replace(' ', '', $date_range);
        $date_range = str_replace(I18N::translateContext('Start of date range', 'From'), '', $date_range); 
        $date_range = str_replace(I18N::translateContext('End of date range', 'To'), '/', $date_range); 
        
        $patterns = [
            '/\A(\d+)\/\Z/',            //  1659/
            '/\A\/(\d+)\Z/',            //  /1659
            '/\A(\d\d\d)\/(.*)/',       //  873/*
            '/\A(\d\d)\/(.*)/',         //  87/*
            '/\A(\d)\/(.*)/',           //  7/*
            '/\A(\d\d\d)-(.+?)\/(.*)/', //  873-*/*
            '/\A(\d\d)-(.+?)\/(.*)/',   //  87-*/*
            '/\A(\d)-(.+?)\/(.*)/',     //  7-*/*
            '/(.*)\/(\d\d\d)\Z/',       //  */873
            '/(.*)\/(\d\d)\Z/',         //  */87
            '/(.*)\/(\d)\Z/',           //  */8
            '/(.*)\/(\d\d\d)-(.+)/',    //  */873-
            '/(.*)\/(\d\d)-(.+)/',      //  */87-
            '/(.*)\/(\d)-(.+)/',        //  */8-
        ];
        $replacements = [
            '$1',                       //  1659/
            '$1',                       //  /1659
            '0$1/$2',                   //  873/*
            '00$1/$2',                  //  87/*
            '000$1/$2',                 //  8/*
            '0$1-$2/$3',                //  873-*/*
            '00$1-$2/$3',               //  87-*/*
            '000$1-$2/$3',              //  8-*/*
            '$1/0$2',                   //  */873
            '$1/00$2',                  //  */87
            '$1/000$2',                 //  */8
            '$1/0$2/$3',                //  */873-
            '$1/00$2/$3',               //  */87-
            '$1/000$2/$3',              //  */8-
        ];
        
        return preg_replace($patterns, $replacements, $date_range);     
    }    

    /**
     * Get places for a source
     *
	 * @param Source
     *
     * @return array
     */
    public static function getPlacesForSource(Source $source): array {	
			
        $places = [];

        if ($source->facts(['DATA'])->isNotEmpty() ) {

            $data = $source->facts(['DATA'])->first(); 	

            preg_match_all('/3 PLAC (.{1,32})/', $data->gedcom(), $matches, PREG_SET_ORDER);
            
            if (!empty($matches[0]) ) {
                $match = $matches[0];
                array_push($places, $match[1]);               
            }       
        }

        return $places;
    }

    /**
     * Validate whether a string is an URL
     *
     * @param string  $url 
     * 
     * @return  bool
     */
    private function validateWhetherURL(string $url): bool {
        $path = parse_url($url, PHP_URL_PATH);
        $encoded_path = array_map('urlencode', explode('/', $path));
        $url = str_replace($path, implode('/', $encoded_path), $url);
    
        return filter_var($url, FILTER_VALIDATE_URL) ? true : false;
    }
    
    /**
     * Remove html tags
     *
     * @param string  $text 
     * 
     * @return string
     */
    private static function removeHtmlTags(string $text): string {
            return preg_replace('/<[a-z]+[^<>]+?>([^<>]+?)<\/[a-z]+?>/', '$1', $text);
    }

    /**
     * Options for downloads
     *
     * @return array<string>
     */
    public static function getDownloadOptions(string $selection = self::DOWNLOAD_OPTION_ALL): array
    {        
        $xml_options = [
            self::DOWNLOAD_OPTION_APE_EAD   => I18N::translate('apeEAD XML'),
            self::DOWNLOAD_OPTION_ATOM      => I18N::translate('AtoM EAD XML'),
        ];

        $text_options = [
            self::DOWNLOAD_OPTION_HTML      => I18N::translate('Finding aid as HTML'),
            self::DOWNLOAD_OPTION_PDF       => I18N::translate('Finding aid as PDF'),
        ];

        switch($selection) {

            case self::DOWNLOAD_OPTION_XML:
                $options = $xml_options;
                break;

            case self::DOWNLOAD_OPTION_TEXT:
                $options = $text_options;
                break;

            case self::DOWNLOAD_OPTION_ALL:
                $options = $xml_options + $text_options;
                break;

            default:
                $options = $xml_options + $text_options;
            }

        return $options;
    }

}