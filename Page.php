<?php 
/*
 * PageModel - handles operations related to page
 *
 * @author Pooja Shah <pooja.phpdeveloper@gmail.com>
 * @date   June 28 2012
 *
 */
namespace module\Admin\Page\Model;

use module\Core\Adapter\MySQL\Adapter;
use module\Admin\Page\Model\Widget\Widget;
use module\Admin\Page\Model\Widget\WidgetModel;
use module\Admin\Page\Model\Widget\Gallery as WidgetGallery;
use module\Admin\Tag\Model\Tag;
use module\Admin\Menu\Model\Menu;
use module\Admin\Contact\Model\Contact;
use application\Util;
use module\Admin\RotatingImage\Model\RotatingImage;
use module\Core\Page\Model\PageInterface;
use module\Admin\Share\Model\Shareable;
use module\Admin\Search\Model\Searchable;

class Page extends Adapter implements Searchable, Shareable
{
    private $connection;
	
    const TYPE_THANK_YOU   = 7;
    const TYPE_HOMEPAGE    = 1;
    const TYPE_COUPON      = 3;
    const TYPE_HEADER      = 8;
    const TYPE_FOOTER      = 9;
    const TYPE_VENDOR      = 4;
    const TYPE_EXHIBITOR   = 5;
    const TYPE_OTHER	   = 2;
	
    public function __construct($connection)
    {
        parent::__construct($connection);
        
		$this->connection = $connection;
		
		// Set columns which can be used as a search query when searching a page
		$searchColumns = array(// page title
	                               1 => array('searchColumn' => 'page_title',
	                                          'searchType'   => self::SEARCH_FUZZY_BOTH),
								  // Page keywords
	                               2 => array('searchColumn' => 'page_keywords',
	                                          'searchType'   => self::SEARCH_FUZZY_BOTH)
					);
        
        $this->SetSearchableColumns($searchColumns);
        
        // Sort settings
        $sortColumns = array(1 => 'p.page_title', 
                             2 => 'p.page_created_at',
                             3 => 'o.organization_name',
                             4 => 'pt.page_type_name');
        
        $this->SetSortableColumns($sortColumns);
		
	// Activation start and end date of the page 
	$acitvationDateColumns = array(1 => array('startDate' 	=> 'page_activation_start_date',
											  'endDate'		=> 'page_activation_end_date'));
        
        $this->SetActivationDateColumns($acitvationDateColumns);
    }
	
	/*
	 * Checks whether the page is homepage
	 *
	 * @params $pageID - int
	 */
    public function isHomepage($pageID)
    {
        $q = 'SELECT CASE WHEN pt.page_type_description = "Homepage"
                     THEN 1
                     ELSE 0
                     END as isHomepage
              FROM page p
              JOIN page_type pt ON pt.page_type_id = p.page_type_id
              WHERE 1=1
              AND p.page_id = :pageID';
        
	$params = array(':pageID' => $pageID);
		
		// Fetch method binds the params and runs query using PDO
        $result = $this->fetch($q, $params);
        
        return $result ? $result->isHomepage == 1 : false;
    }
    
    /**
     * Update page theme settings
	 *
	 * @param array $theme
     *
     */
    public function updatePageTheme($theme)
    {
        $pageID  = isset($theme['pageID']) ? $theme['pageID'] : false;
        
        if ($pageID === false) {
	   // Throws an excepption
            throw new InvalidArgumentException('PageID is required');
        }
        
        $bgColor = isset($theme['pageBackgroundColor']) ? $theme['pageBackgroundColor'] : '';

        $q = 'UPDATE page p
              SET page_background_color       = :bgColor
              WHERE 1=1
              AND p.page_id = :pageID';
        
        return $this->save($q, array(':bgColor'         => $bgColor,
                                     ':pageID'          => $pageID));
    }
    
    /**
     * Get recently modified pages
     * @param  int   $siteID
     * @return array
     */
    public function getRecentlyModifiedPages($siteID)
    {
        $q = 'SELECT p.page_id AS pageID,
                     CASE WHEN LENGTH(p.page_name) < 1
                     THEN p.page_title
                     ELSE p.page_name
                     END AS pageTitle,
                     p.page_updated_at AS pageUpdatedAt,
                     p.page_update_count AS updateCount
              FROM page p
              WHERE 1=1
              AND p.page_hostsite_id = :siteID
              AND p.page_update_count > 0
              ORDER BY p.page_update_count DESC
              LIMIT 10';
        
        return $this->fetchAll($q, array(':siteID' => (int) $siteID));
    }
    
    /**
     * Increment update count
     * @param  int $pageID
     * @return bool
     */
    public function incrementUpdateCount($pageID)
    {
        $q = 'UPDATE page
              SET page_update_count = page_update_count + 1,
                  page_updated_at = NOW()
              WHERE page_id = :pageID';
        return $this->save($q, array(':pageID' => (int) $pageID));
    }
    
    /**
     * Wrapper method for getPageTypeCountByPageTypeID
     * 
     */
    public function getHomepageCount($siteID)
    {
        return $this->getPageTypeCountByPageTypeID($siteID, 
                                                   PageInterface::TYPE_HOMEPAGE);
    }
    
    /**
     * Returns number of pages with a given type in a give site
	 *
	 * @param int $siteID
	 * @param int $pageTypeID
     *
     */
    public function getPageTypeCountByPageTypeID($siteID, $pageTypeID)
    {
        // First count the linked pages of the type,
	// as we want to take them in consideration
	$params = array(':type' => $pageTypeID,
                ':siteID' => $siteID);
	
	$q = 'SELECT COUNT(*) as linkedPageCount
              FROM page_link pl
	      INNER JOIN page p ON pl.page_id = p.page_id
              WHERE 1=1
              AND p.page_type_id = :type
              AND p.page_hostsite_id = :siteID';
			  
	$linkedPages     = $this->fetch($q, $params);
	$linkedPageCount = $linkedPages ? $linkedPages->linkedPageCount : 0;
		
	// Now count the normal pages
	$q = 'SELECT COUNT(*) as pageCount
              FROM page p
              WHERE 1=1
              AND p.page_type_id = :type
              AND p.page_hostsite_id = :siteID';
        
        $pages      = $this->fetch($q, $params);
		
	$pageCount  = $pages ? $pages->pageCount : 0;
	
	$totalCount = $linkedPageCount + $pageCount;
        
        return $totalCount;
    }
	
    // @param int $hostsiteID - hostsite ID of copied page
    // @param int $fromHostsiteID - hostsite ID of current page
    // @param int $pageID - page ID to copy
    // @param int $userID - user ID creating copy
    public function copy($fromHostsiteID,
			 $hostsiteID, 
			 $pageID, 
			 $userID, 
			 $copyWidgets = true)
    {
        // for copy page within copy page- check whether that page has been already copied or not
	// if yes then don't copy it twice
	$pageExist = $this->GetPageCopyMap($pageID, $fromHostsiteID, $hostsiteID);
	
	if( $pageExist ) {
		return $pageExist;
	}
	
	// Get object containing all page properties
	$page          	  = $this->GetPage($fromHostsiteID, $pageID,false);
	
	$objWidget     	  = new Widget($this->connection);
	
	if ($page) { 
		// If destination site is same as original site then add 'Copy of' before
		// page title, name and slug
		if( $fromHostsiteID == $hostsiteID ){
			$page->pageTitle     = 'Copy of ' . $page->pageTitle;
			$page->pageName      = 'Copy of ' . $page->pageName;
			$page->pageSlug      = 'copy-of-' . $page->pageSlug;
		}
    
		$page->userID        = $userID;
		$page->pageHostsiteID= $hostsiteID;
		$page->pageCreatedAt = date('Y-m-d h:i:s');
		$page->pageUpdatedAt = date('Y-m-d h:i:s');
            	$page->isSearchable  = 0;
            	$page->isShareable   = 0;
			
		// Now new page with same property as orginal one except hostsiteID
		$newPageID           = $this->Add($page);
		
		// If new page was created successfully, affiliate widgets of that page
		if( $newPageID && $copyWidgets) {
			// copy tag of the page
			$objTag                 = new Tag($this->connection);
			
			$objTag->pageID 	    = $pageID;
			$objTag->toPageID 		= $newPageID;
			$objTag->toHostsiteID 	= $hostsiteID;
			$objTag->fromHostsiteID = $fromHostsiteID;
			$objTag->primaryAction 	= 'page';
			
			//print_r($objTag);
			$copyPageTagsSuccess = $objTag->CopyAssociatedTags($objTag);
				
			// if we copy from one hostsite to another then 
			//we will keep map of all old pageID and newPageID
			$this->updatePageCopyMap($pageID,
						 $newPageID,
						 $fromHostsiteID,
						 $hostsiteID);
				
			if ($copyWidgets) {
				$copyPageWidgetSuccess = $objWidget->copyPageWidgets($pageID,
			}
			
			return  $newPageID;						
		}
            
		return false;
	}
        
	return true;
      }
	
	/* Copy map will be used while copying all pages or entiresite copy 
	 * so that we don't end up copying same page more than one time 
	 * if it is used in some other places
	 *
	 */
	public function UpdatePageCopyMap($pageID,
					  $newPageID,
					  $fromHostsiteID,
					  $hostsiteID)
    	{
		 $q = 'INSERT INTO page_copy_map(from_page_id,
						to_page_id,
						from_hostsite_id,
						to_hostsite_id) 
			  VALUES (:pageID,
				 :newPageID,
				 :fromHostsiteID,
				 :hostsiteID)';
		
		$params = array(':pageID' => $pageID,
				':newPageID' => $newPageID,
				':fromHostsiteID' => $fromHostsiteID,
				':hostsiteID' => $hostsiteID);
						
		return $this->save($q,$params);
	}
	
	/**
	 * This method will clear copy page map 
	 *
	 */
	public function ClearPageCopyMap($fromHostsiteID,
					 $toHostsiteID,
					 $fromPageID = NULL)
    	{
		$fromPageClause = '';
		$params = array();
		
		// in case of hostsite copy this function will not have from page id as an argument
		if($fromPageID) {
			$fromPageClause = 'AND from_page_id = :fromPageID';
			$params[':fromPageID'] = $fromPageID;
		}
		
		$q = sprintf("DELETE FROM page_copy_map
			     WHERE from_hostsite_id = :fromHostsiteID
			     AND to_hostsite_id = :toHostsiteID
			     %s",
			     $fromPageClause);
					  
		$params[':fromHostsiteID'] = $fromHostsiteID;
		$params[':toHostsiteID']   = $toHostsiteID;
		
		return $this->save($q,$params);
    }
	
	/**
	 * This will get page copy map by page id and from and to hostsiteID
	 *
	 */
	public function GetPageCopyMap($pageID,
				       $fromHostsiteID,
				       $toHostsiteID)
    	{
		$q = 'SELECT pcm.to_page_id AS toPageID
			  FROM page_copy_map pcm
			  WHERE pcm.from_page_id = :pageID
			  AND pcm.from_hostsite_id = :fromHostsiteID
			  AND pcm.to_hostsite_id = :toHostsiteID';
						 
		$pageMap = $this->fetch($q, array(':pageID' => $pageID,
						  ':fromHostsiteID' => $fromHostsiteID,
						  ':toHostsiteID' => $toHostsiteID));
		
		if( $pageMap ) {
			return $pageMap->toPageID;
		}
    	}
	
	
	    /**
	     * This method links a page from one site to another
	     *
	     * @param int $hostsiteID - hostsite ID of copied page
	     * @param int $fromHostsiteID - hostsite ID of current page
	     * @param int $pageID - page ID to link
	     * @param int $userID - user ID creating link
		 */
	    public function Link($fromHostsiteID,
				 $toHostsiteIDs, 
				 $pageID)
	    {
		$objTag = new Tag($this->connection);
		
		$objTag->fromHostsiteID = $fromHostsiteID;
		$objTag->pageID = $pageID;
		
		// first unlink them
		$this->unlink($pageID);
		
		if( ! $toHostsiteIDs ) {
			return true;
		}
		
		$valuePairs = array();
			
		// Make value pairs so that one page cane be linked to multiple site in one query
		foreach ($toHostsiteIDs as $toHostsiteID) {
			$valuePairs[] 		         = sprintf('(%d,%d,%d)', 
								   intval($pageID), 
								   intval($fromHostsiteID),
								   intval($toHostsiteID));
				
			// Link widgets associated with the page
			$successLinkPageWidgets[]    = $this->LinkPageWidgets($fromHostsiteID,
																 $toHostsiteID, 
																 $pageID);
			
			// link tags for all merchants for all hostsites selected for link
			$objTag->toHostsiteID = $toHostsiteID;												
			$objTag->primaryAction = 'page';	
			
			$successLinkTags[] = $objTag->LinkAssociatedTags($objTag);
		}
			
		$valuePairString = $valuePairs ? implode(',', $valuePairs) : '';
			
		if ($valuePairString) {
			$q = sprintf('INSERT IGNORE INTO page_link(page_id, 
													   hostsite_id_from,
													   hostsite_id_to)
						  VALUES %s', $valuePairString);
						  
			return $this->Save($q);
		}
			
		return true;
	}
	
	/*
	 * This method links widgets associated with the page from one hostsite to another
	 */
	public function LinkPageWidgets($fromHostsiteID,
					$toHostsiteID, 
					$pageID)
    	{
		$objWidget 	=  new Widget($this->connection);
		$objMenu   	=  new Menu($this->connection);
		$objContact =  new Contact($this->connection);
		$objWidgetGallery =  new WidgetGallery($this->connection);
		
		$widgets   = $objWidget->GetWidgets($fromHostsiteID,
						    array($pageID),
						    NULL,
						    false,
						    true);
		
		if ($widgets) {
			foreach ($widgets as $key => $wt) {
				foreach ( $wt as $k => $w ) {
					$objDelegate                       = (object) $w;
					$objDelegate->hostsiteID           = $toHostsiteID;
					$objDelegate->fromHostsiteID       = $fromHostsiteID;
					
					$valuePairs[] = sprintf('(%d,%d,%d)', 
								$objDelegate->widgetID, 
								intval($fromHostsiteID),
								intval($toHostsiteID));
				}
			}
			
			$valuePairString = $valuePairs ? implode(',', $valuePairs) : '';
			
			if( $valuePairString ) {
				$q = sprintf('INSERT IGNORE INTO widget_link(widget_id, 
					      VALUES %s', $valuePairString);
				
				return $this->save($q);
			}
		}
		return true;	
    	}
	
	/**
	 * This method unlinks the page
	 */
	public function unlink($pageID)
	{
		//Delete existing linked pages, widgets
		$this->DeleteAllPageLinks($pageID);
		$this->DeleteAllPageWidgetLinks($pageID);
		$this->DeleteAllPageTagLinks($pageID);
	}
	
	// Deletes all links associated with the page
    	public function DeleteAllPageLinks($pageID)
    	{
		$q = 'DELETE FROM page_link
		      WHERE page_link.page_id = :pageID';
		$params = array(':pageID' => $pageID);
		$success =  $this->save($q, $params);
		return $success;
    	}
	
	// Deletes all widgets links associated with a page
	public function DeleteAllPageWidgetLinks($pageID)
    	{
		$q = 'DELETE wl FROM widget_link wl
		      INNER JOIN widget w ON w.widget_id = wl.widget_id
		      WHERE w.page_id = :pageID';
		
		return $this->save($q,array(':pageID' => $pageID));
    	}
	
	// Deletes all tags links associated with a page
	public function DeleteAllPageTagLinks($pageID)
    	{
		$q = 'DELETE FROM page_tag_link
		      WHERE page_tag_link.page_id = :pageID';
		
		return $this->save($q, array(':pageID'=> $pageID));
    	}
	
	// This method remove a page from a hostsite
	public function Remove($hostsiteID, $pageID)
    	{
		$q = 'DELETE FROM page
		      WHERE page_id = :pageID
		      AND page_hostsite_id = :hostsiteID';
		
		return  $this->save($q,array(':hostsiteID' => $hostsiteID,
					     ':pageID' => $pageID));		
	}
	
      /**
       * There can only be one homepage, so filter out that
       * type once one already exists
       *
       */
	public function getPageTypes($filterHomepages = false)
    	{
	        $hpFilter = '';
			
	        if ($filterHomepages) {
	            $hpFilter = sprintf('AND pt.page_type_id <> %d', 
	                                 PageInterface::TYPE_HOMEPAGE);
	        }
	        
		$q = sprintf("SELECT pt.page_type_id AS pageTypeID,
                             pt.page_type_name AS pageTypeName,
                             pt.page_type_description AS pageTypeDescription
                      FROM page_type pt
                      WHERE 1=1
                      %s
                      ORDER BY pt.page_type_name", 
                      $hpFilter);
        
		return $this->fetchAll($q);
    	}
	
	// Update a page
	public function Update($objPage)
    	{
		$q = "UPDATE page
		      SET page_title = :pageTitle,
			  page_type_id = :pageTypeID,
			  page_redirect_type = :pageRedirectType,
			  page_redirect_weighted_odds = :pageRedirectWeightedOdds,
			  page_keywords = :pageKeywords,
			  page_updated_at = NOW(),
			  page_update_count = page_update_count + 1,
			  page_name = :pageName,
			  page_slug = :pageSlug,
			  page_is_searchable = :pageIsSearchable,
			  page_is_shareable = :pageIsShareable,
			  page_activation_start_date = :pageActivationStartDate,
			  page_activation_end_date = :pageActivationEndDate,
			  page_no_of_views = :pageNoOfViews,
			  page_background_color = :pageBackgroundColor
		  WHERE 1=1 
		  AND page.page_hostsite_id = :hostsiteID
		  AND page.page_id = :pageID";
		
		$startDate 	= $objPage->pageActivationStartDate ? $objPage->pageActivationStartDate : 'NULL';
		$endDate 	= $objPage->pageActivationEndDate ? $objPage->pageActivationEndDate : 'NULL';
		$bgColor 	= $objPage->pageBackgroundColor ? $objPage->pageBackgroundColor : 'NULL';
		$pageID 	= $objPage->pageID ? intval($objPage->pageID) : 0;
		
		$params = array(':pageTitle'  => $objPage->pageTitle,
				':pageRedirectType' => $objPage->pageRedirectType,
				':pageRedirectWeightedOdds' => isset($objPage->pageRedirectWeightedOdds) ? 1 : 0,
				':pageTypeID' => intval($objPage->pageTypeID),
				':pageKeywords' => $objPage->pageKeywords,
				':hostsiteID' => intval($objPage->pageHostsiteID),
				':pageID'     => $pageID,
				':pageName'   => $objPage->pageName,
				':pageSlug'   => Util::Slugify($objPage->pageTitle),
				':pageIsSearchable' => isset($objPage->pageIsSearchable) ? 1 : 0,
				':pageIsShareable' => isset($objPage->pageIsShareable) ? 1 : 0,
				':pageActivationStartDate' => $startDate,
				':pageNoOfViews' => intval($objPage->pageNoOfViews),
				':pageActivationEndDate' => $endDate,
				':pageBackgroundColor' => $bgColor);
						
		$success = $this->save($q, $params);
		$decoderPages = isset($objPage->decoderPages) ? $objPage->decoderPages : array() ;
		
		if ($decoderPages) {
			$this->updateDecoderPages($pageID, $decoderPages);
		}
		
		return $success;
    	}
	
	// Adds a default homepage when you create a new site
	public function AddDefaultHomepage($hostsiteID)
    	{
		$objPage               = new \StdClass();
		$objPage->hostsiteID   = $hostsiteID;
		$objPage->pageTitle    = 'Homepage';
		$objPage->pageName     = $objPage->pageTitle;
		$objPage->pageTypeID   = self::TYPE_HOMEPAGE;
		$objPage->pageSlug     = Util::Slugify($objPage->pageTitle);
		$objPage->pageIsSearchable = 1;
		$objPage->pageIsShareable  = 0;
		$objPage->pageKeywords  = '';
		$objPage->pageActivationStartDate = '';
		$objPage->pageActivationEndDate = '';
		$objPage->pageHostsiteID = $hostsiteID;
		$objPage->pageRedirectType = NULL;
		
		return $this->Add($objPage);
    	}
	
	// Adds a new page
	public function add($objPage)
    	{
		$q = "INSERT INTO page(page_title,
				   page_created_at,
				   page_updated_at,
				   page_type_id,
				   page_redirect_type,
				   page_redirect_weighted_odds,
				   page_keywords,
				   page_hostsite_id,
				   page_name,
				   page_slug,
				   page_is_searchable,
				   page_is_shareable,
				   page_activation_start_date,
				   page_activation_end_date,
				   page_background_color)
			  VALUES(:pageTitle,
				  NOW(),
				  NOW(),
				 :pageTypeID,
				 :pageRedirectType,
				 :pageRedirectWeightedOdds,
				 :pageKeywords,
				 :pageHostsiteID,
				 :pageName,
				 :pageSlug,
				 :pageIsSearchable,
				 :pageIsShareable,
				 :pageActivationStartDate,
				 :pageActivationEndDate,
				 :pageBackgroundColor)";
        
		$params =  array(':pageTitle'                 => $objPage->pageTitle,
				 ':pageTypeID'                => $objPage->pageTypeID,
				 ':pageRedirectType'          => $objPage->pageRedirectType,
				 ':pageRedirectWeightedOdds'  => isset($objPage->pageRedirectWeightedOdds) ? 1 : 0,
				 ':pageKeywords'              => $objPage->pageKeywords,
				 ':pageHostsiteID'            => $objPage->pageHostsiteID,
				 ':pageName'                  => $objPage->pageName,
				 ':pageSlug'                  => Util::Slugify($objPage->pageTitle),
				 ':pageIsSearchable'          => isset($objPage->pageIsSearchable) ? (int) $objPage->pageIsSearchable : 0,
				 ':pageIsShareable'           => isset($objPage->pageIsShareable) ? (int) $objPage->pageIsShareable : 0,
				 ':pageActivationStartDate'   => $objPage->pageActivationStartDate,
				 ':pageActivationEndDate'     => $objPage->pageActivationEndDate,
				 ':pageBackgroundColor'       => isset($objPage->pageBackgroundColor) ? $objPage->pageBackgroundColor : '');
		
		$pageID       = $this->save($q, $params);
		$decoderPages = isset($objPage->decoderPages) ? $objPage->decoderPages : array() ;
		
		if ($decoderPages) {
			$this->updateDecoderPages($pageID,$decoderPages);
		}
		
		return $pageID;
   	 }
	
	// Affialiate user with a page on basis of permission and creation
	public function AddPageUserAffiliation($pageID, $userID)
    	{
		$q = 'INSERT INTO page_user(page_id, user_id)
		      VALUES(:pageID, :userID)';

		$result = $this->save($q,array(':userID' => $userID,
							           ':pageID'     => $pageID));
		return $result;
    	}
	
	// page_type_id 1 - homepage
    	// @param bool $limitOne - if true return just the first homepage found
    	public function getHomePages($hostsiteID,
                                    $limitOne = false)
    	{
        	// get activation start and end date clause
		$clause = $this->getActivationDateSQL();
		
		$limitClause = $limitOne ? ' LIMIT 1 ' : '';
        
		$q = sprintf("SELECT p.page_id AS pageID,
	                             p.page_slug AS pageSlug,
	                             p.page_title AS pageTitle,
	                             p.page_hostsite_id AS pageHostsiteID,
	                             p.page_keywords AS pageKeywords,
	                             p.page_created_at AS pageCreatedAt,
	                             p.page_updated_at AS pageUpdatedAt,
	                             p.page_is_searchable AS pageIsSearchable,
	                             p.page_is_shareable AS pageIsShareable,
	                             p.page_no_of_views AS pageNoOfViews,
	                             CASE WHEN LENGTH(p.page_name) < 1
	                             THEN p.page_title
	                             ELSE p.page_name
	                             END AS pageName,
	                             pt.page_type_id AS pageTypeID,
	                             pt.page_type_name AS pageTypeName,
	                             pt.page_type_description AS pageTypeDescription,
	                             CASE WHEN pl.hostsite_id_from IS NULL OR pl.hostsite_id_from = :hostsiteID
	                             THEN 0
	                             ELSE 1
	                             END AS isLinkedPage
	                      FROM page p
	                      INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
	                      LEFT JOIN page_link pl ON pl.page_id = p.page_id
	                      WHERE 1=1
	                      AND p.page_type_id = 1
	                      AND (p.page_hostsite_id = :hostsiteID OR pl.hostsite_id_to = :hostsiteID)
	                      %s
	                      %s",
			    $clause,
	                    $limitClause);
		
        	$params = array(':hostsiteID' => $hostsiteID);
        
	        if ($limitOne) {
	            $pages = $this->fetch($q, $params);
	        } else {
	            $pages = $this->fetchAll($q, $params);
		}
        
        	return $pages;
    	}
	
       /*
	* Check whether a user has access to a page
	*/
	public function checkAccess($objPage)
	{
		$params = array();
		
		$q = "SELECT *
			  FROM page p
			  INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
			  INNER JOIN page_role pr ON p.page_id = pr.page_id
			  WHERE 1=1
			  AND p.page_hostsite_id = :hostsiteID
			  AND pr.role_id = 11
			  AND p.page_id = :pageID";
		
		$params[':hostsiteID'] = $objPage->pageHostsiteID;
		$params[':pageID'] = $objPage->pageID;
		
		$page = $this->fetchAll($q,$params);
		
		if ($page) {
		    $q = "SELECT *
			  FROM page p
			  INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
			  LEFT JOIN page_role pr ON p.page_id = pr.page_id
			  INNER JOIN user_role ur ON ur.role_id = pr.role_id
			  WHERE 1=1
			  AND p.page_hostsite_id = :hostsiteID
			  AND ur.user_id = :userID
			  AND pr.role_id = 11
			  AND p.page_id = :pageID";

			$params[':hostsiteID'] = $objPage->pageHostsiteID;
			$params[':pageID'] = $objPage->pageID;
			$params[':userID'] = $objPage->userID;

			$page = $this->fetchAll($q,$params);		
			
			return $page;
		} else {
			return null;
		}
	}
	
	// Get a page with all its propery by its id and hostsite(site) id
	public function getPage($hostsiteID, $pageID, $showInactive = true)
    	{
		$clause = '';
		
		if ($showInactive) {
			// get activation start and end date clause
			$clause = $this->getActivationDateSQL();
		}
		
		$q = sprintf("SELECT p.page_id AS pageID,
					 p.page_slug AS pageSlug,
					 p.page_title AS pageTitle,
					 p.page_hostsite_id AS pageHostsiteID,
					 p.page_redirect_type AS pageRedirectType,
					 p.page_redirect_weighted_odds AS pageRedirectWeightedOdds,
					 p.page_keywords AS pageKeywords,
					 p.page_created_at AS pageCreatedAt,
					 p.page_updated_at AS pageUpdatedAt,
					 p.page_is_searchable AS pageIsSearchable,
					 p.page_is_shareable AS pageIsShareable,
					 p.page_activation_start_date AS pageActivationStartDate,
					 p.page_activation_end_date AS pageActivationEndDate,
					 p.page_no_of_views AS pageNoOfViews,
					 CASE WHEN LENGTH(p.page_name) < 1
					 THEN p.page_title
					 ELSE p.page_name
					 END AS pageName,
					 pt.page_type_id AS pageTypeID,
					 pt.page_type_name AS pageTypeName,
					 pt.page_type_description AS pageTypeDescription,
					 CASE WHEN pl.hostsite_id_from IS NULL OR pl.hostsite_id_from = :hostsiteID
					 THEN 0
					 ELSE 1
					 END AS isLinkedPage,
					 CASE WHEN pl.hostsite_id_from = p.page_hostsite_id
					 THEN 1
					 ELSE 0
					 END AS isPageLinked,
					 pl.hostsite_id_from AS linkedPageHostsiteID,
					 h.hostsite_name AS linkedPageHostsiteName,
					 h.hostsite_data_directory AS linkedPageHostsiteDir,
					 pl.hostsite_id_to AS toHostsiteID,
					 p.page_background_color AS pageBackgroundColor,
					 p.page_redirect_recycle_all AS pageRedirectRecycleAll
			  FROM page p
			  INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
			  LEFT JOIN page_user pu ON p.page_id = pu.page_id
			  LEFT JOIN page_link pl ON pl.page_id = p.page_id
			  LEFT JOIN merchant_page_link mpl ON mpl.page_id = p.page_id
			  LEFT JOIN hostsite h ON pl.hostsite_id_from = h.hostsite_id
			  WHERE 1=1
			  AND (p.page_hostsite_id = :hostsiteID OR pl.hostsite_id_to = :hostsiteID OR mpl.hostsite_id_to = :hostsiteID)
			  AND (p.page_id = :pageID OR p.page_slug = :pageID)
			  %s", 
			  $clause);

		$page = $this->fetch($q,array(':hostsiteID' => $hostsiteID,
					     ':pageID'     => $pageID));
		return $page;
    	}
	
	// Get sites to which page is linked
	public function GetLinkedToHostsitesByPage($hostsiteID, $pageID)
    	{
	        $q = "SELECT pl.hostsite_id_to AS toHostsiteID
				  FROM page_link pl
				  WHERE 1=1
				  AND pl.page_id = :pageID
				  AND pl.hostsite_id_from = :hostsiteID";
				 
		$toHostsiteIDs = $this->fetchAll($q, array(':hostsiteID' => $hostsiteID,
                                                  	  ':pageID'     => $pageID));
		return $toHostsiteIDs;
    	}
	
	// Get a page by search query
	public function GetPagesBySearchQuery($hostsiteID, $searchQuery)
    	{
		// Don't search for nothin.
		if( ! $searchQuery ) {
			return array();
		}
	   
	   	$searchClause   = $this->GetSearchSQLAndParams($searchQuery);
	   
	  	 // Don't search these pages
	   	$excludeClause   = sprintf('AND p.page_type_id NOT IN (%s,%s,%s)',
					  self::TYPE_HOMEPAGE,
					  self::TYPE_HEADER,
					  self::TYPE_FOOTER);
		
	   	$q = sprintf("SELECT 
				 p.page_id AS pageID,
				 p.page_slug AS pageSlug,
				 p.page_title AS pageTitle
	                     FROM page p
	                     WHERE 1=1
	                     AND p.page_hostsite_id = :hostsiteID
	                     AND p.page_is_searchable = 1
	                     %s
	                     %s",
	                     $excludeClause,
	                     $searchClause['SQL']);

		$pages  = $this->fetchAll($q,array(':hostsiteID' => $hostsiteID));

		return $pages;
    	}
	
    	/**
     	* Retrieve all pages of type 'thank you'
     	* without pagination and default sorting
     	* @param int $siteID - hostsite id
     	* @param int $userID - user id of current user
     	*
     	*/
    	public function getThankYouPages($siteID, $userID = NULL)
    	{
	        $userClause = '';
	        $params     = array(':siteID' => intval($siteID));
	        
	        if ($userID) {
	            $userClause = ' AND pu.user_id = :userID ';
	            $params[':userID'] = intval($userID);
	        }
	        
	        $q = sprintf("SELECT 
	                      p.page_type_id as pageTypeID,
			      p.page_id AS pageID,					  
			      CASE WHEN LENGTH(p.page_name) < 1
	                      THEN p.page_title
	                      ELSE p.page_name
	                      END AS pageName
	                      FROM page p
	                      INNER JOIN hostsite h ON h.hostsite_id = p.page_hostsite_id
	                      LEFT JOIN hostsite_address ha ON ha.hostsite_id = h.hostsite_id
	                      INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
	                      LEFT JOIN page_user pu ON pu.page_id = p.page_id
	                      LEFT JOIN user u ON u.user_id = pu.user_id
	                      LEFT JOIN page_link pl ON pl.page_id = p.page_id
	                      WHERE 1=1
	                      AND (p.page_hostsite_id = :siteID OR pl.hostsite_id_to = :siteID)
	                      AND p.page_type_id = %d
	                      GROUP BY p.page_id
	                      ORDER BY pageName", self::TYPE_THANK_YOU);
	         
	        return $this->fetchAll($q, $params);
    	}
    
      /**
       * Shortcut for getPagesByHostsiteID so I don't have to
       * remember those arguments
       *
       */
    	public function getPagesByType($siteID, $userID, $types)
    	{
	        return $this->getPagesByHostsiteID($siteID, 
	                                           $userID, 
	                                           // search
	                                           '', 
	                                           // sort
	                                           1, 
	                                           // sort dir
	                                           '',
	                                           // page type
	                                           $types, 
	                                           // paginate
	                                           false);                                   
    	}
    
    	public function getPagesByHostsiteID($hostsiteID, 
                                            $userID        = NULL,
                                            $searchQuery   = '',
                                            $sort          = 1,
                                            $sortDirection = '',
					    $pageTypeID    = 0,
                                            $paginate      = false)
    	{
		$where = '';
		$where          = $userID ? ' AND pu.user_id = :userID ' : '';
		$orderClause    = $this->GetSortSQL($sort, $sortDirection);
		$searchClause   = $this->GetSearchSQLAndParams($searchQuery);
		$pageTypeClause = '';
		
		// Page type filter
		if ($pageTypeID) {
			// Array
			if (is_array($pageTypeID)) {
				$pageTypeIDString = implode(',', $pageTypeID);
				$string           = sprintf('(%s)',$pageTypeIDString);
				$pageTypeClause   = sprintf('AND pt.page_type_id IN %s', $string);
			} else {
				$pageTypeClause = sprintf('AND pt.page_type_id = %d', intval($pageTypeID));
			}
	   	}
	   
	   	$limitCol = $paginate ? 'SQL_CALC_FOUND_ROWS *,' : '';
	   	$limitSQL = $paginate ? $this->getLimitSQL() : '';
       
	   	$q = sprintf("SELECT 
				 %s
				 p.page_id AS pageID,
				 p.page_slug AS pageSlug,
				 p.page_title AS pageTitle,
				 p.page_hostsite_id AS pageHostsiteID,
				 p.page_redirect_type AS pageRedirectType,
				 p.page_redirect_weighted_odds AS pageRedirectWeightedOdds,
				 p.page_keywords AS pageKeywords,
				 p.page_created_at AS pageCreatedAt,
				 p.page_updated_at AS pageUpdatedAt,
				 p.page_is_searchable AS pageIsSearchable,
				 p.page_is_shareable AS pageIsShareable,
				 p.page_activation_start_date AS pageActivationStartDate,
				 p.page_activation_end_date AS pageActivationEndDate,
				 CASE WHEN LENGTH(p.page_name) < 1
				 THEN p.page_title
				 ELSE p.page_name
				 END AS pageName,
				 pt.page_type_id AS pageTypeID,
				 pt.page_type_name AS pageTypeName,
				 pt.page_type_description AS pageTypeDescription,
				 u.user_login AS pageUserName,
				 u.user_id AS pageUserID,
				 o.organization_id AS organizationID,
				 o.organization_name AS organizationName,
				 ha.hostsite_address AS hostsiteAddress,
				 CASE WHEN pl.hostsite_id_from IS NULL OR pl.hostsite_id_from = :hostsiteID
				 THEN 0
				 ELSE 1
				 END AS isLinkedPage,
				 CASE WHEN pl.hostsite_id_from = p.page_hostsite_id
				 THEN 1
				 ELSE 0
				 END AS isPageLinked,
				 pl.hostsite_id_to AS linkedHostsiteID,
				 p.page_redirect_recycled_at AS pageRedirectRecycledAt
			  FROM page p
			  INNER JOIN hostsite h ON h.hostsite_id = p.page_hostsite_id
	      		  LEFT JOIN hostsite_address ha ON ha.hostsite_id = h.hostsite_id
			  INNER JOIN page_type pt ON p.page_type_id = pt.page_type_id
			  LEFT JOIN page_user pu ON pu.page_id = p.page_id
			  LEFT JOIN user u ON u.user_id = pu.user_id
			  INNER JOIN organization_hostsite oh ON p.page_hostsite_id = oh.hostsite_id
			  INNER JOIN organization o ON o.organization_id = oh.organization_id
			  LEFT JOIN page_link pl ON pl.page_id = p.page_id
			  LEFT JOIN merchant_page_link mpl ON mpl.page_id = p.page_id
			  WHERE 1=1
			  AND (p.page_hostsite_id = :hostsiteID OR pl.hostsite_id_to = :hostsiteID OR mpl.hostsite_id_to = :hostsiteID)
			  %s
			  %s
			  %s
			  GROUP BY p.page_id
			  %s
			  %s",
			  $limitCol,
			  $where,
			  $pageTypeClause,
			  $searchClause['SQL'],
			  $orderClause, 
			  $limitSQL);
		
	        //var_dump($q);
	        //die;
	        
		$params = array(':hostsiteID' => $hostsiteID);
	
		if( $userID ) {
			$params[':userID'] = $userID;
		}

		$params += $searchClause['params'];
		//print_r($params);
		$page = $this->fetchAll($q,$params, $paginate);

		return $page;
    	}
	
   	 // Gets all pages associated to a user
    	public function GetAllUserPages($userID,$hostsiteID)
    	{
		$q = "SELECT p.page_id AS pageID,
			     p.page_slug AS pageSlug,
			     p.page_title AS pageTitle,
			     p.page_hostsite_id AS pageHostsiteID,
			     u.user_login AS pageUserName,
			     u.user_id AS pageUserID
		  FROM page p
		  INNER JOIN hostsite_user hu ON hu.hostsite_id = p.page_hostsite_id
		  INNER JOIN user u ON u.user_id = hu.user_id
		  WHERE 1=1
		  AND hu.hostsite_id = :hostsiteID
		  GROUP BY p.page_id";
		
		$pages = $this->fetchAll($q,array(':hostsiteID' => $hostsiteID));
		
		return $pages;
    	}
	
    	public function GetPagesByUser($userID)
    	{
		$q = "SELECT p.page_id AS pageID,
			     p.page_slug AS pageSlug,
			     p.page_title AS pageTitle,
			     p.page_hostsite_id AS pageHostsiteID
		  FROM page p
		  INNER JOIN page_user pu ON pu.page_id=p.page_id
		  WHERE 1=1
		  AND pu.user_id = :userID
		  GROUP BY p.page_id";
		
		$pages = $this->fetchAll($q,array(':userID' => $userID));
		
		return $pages;
   	}
    
	// This method sets sharing option of pages by page type in a site
	public function SetShareOptions($pageShare,$pageTypes,$hostsiteID)
	{
		// if the choose do nothing then don't update anything
		if ($pageShare === NULL) {
			return true;
		}
		
		$clause = sprintf('(%s)',implode(",",$pageTypes));
		
		if ($pageShare) {
			$option = 1;
		} else {
			$option = 0;
		}
        
		$q = sprintf('UPDATE page
			     SET page_is_shareable = :option
			     WHERE page_type_id IN %s
			     AND page_hostsite_id = :hostsiteID',
			   $clause);
						
		$params = array(':option' => $option,
						':hostsiteID' => $hostsiteID);

		return $this->save($q,$params);
	}
	
	/*
	 * This method updates page views
	 */
	public function UpdatePageViews($pageID)
    	{
		$q = "UPDATE page
		      SET page_no_of_views = page_no_of_views + 1
		      WHERE page_id = :pageID";

		return $this->save($q,array(':pageID' => $pageID));
    	}
	
    	/**
     	* Return number of views for a page
     	* @param int $pageID
     	* @return int
     	*/
    	public function getViewCountByPageID($pageID)
    	{
	        $q = 'SELECT p.page_no_of_views AS views
	              FROM page p
	              WHERE 1=1
	              AND p.page_id = :pageID';
	              
	        $res = $this->fetch($q, array(':pageID' => $pageID));
	        
	        return $res ? $res->views : 0;
    	}
    
	public function checkPageRedirectTypeOfPage($pageID)
	{
		$q = "SELECT p.page_id AS pageID,
			     p.page_redirect_type AS pageRedirectType,
			     p.page_redirect_weighted_odds AS pageRedirectWeightedOdds
		  	FROM page p
		  	WHERE 1=1
		  	AND p.page_id = :pageID";
		
		$page = $this->fetch($q,array(':pageID' => $pageID));
		
		return $page;
    	}
    
    	/**
     	* Toggles share status on an array of pageIDs
     	* @param  array $pageIDs - array of pages
     	* @param  int   $share status - 0/1 
     	* @return int   affected rows
     	*
     	*/
    	public function toggleShare($pageIDs, $share)
    	{
	        $pages = array_filter(array_map('intval', $pageIDs));
	        $intShare = intval($share);
	        
	        if ($pages) {
	            $q     = sprintf('UPDATE page p
	                              SET    p.page_is_shareable = :share
	                              WHERE  1=1
	                              AND p.page_id IN(%s)
	                              AND p.page_type_id NOT IN(%s)',
	                            implode(',', $pages),
	                            PageInterface::UNSHAREABLE);
	                            
	            return $this->save($q, array(':share' => $intShare));
	        } else {
	            return false;
	        }
    	}
    
    	/**
     	* Toggles search status on an array of pageIDs
     	* @param  array $pageIDs - array of pages
     	* @param  int   $share status - 0/1 
     	* @return int   affected rows
     	*
     	*/
    	public function toggleSearch($pageIDs, $search)
    	{
	        $pages = array_filter(array_map('intval', $pageIDs));
	        
	        if ($pages) {
	            $q     = sprintf('UPDATE page p
	                              SET    p.page_is_searchable = :search
	                              WHERE  1=1
	                              AND p.page_id IN(%s)
	                              AND p.page_type_id NOT IN(%s)',
	                            implode(',', $pages),
	                            PageInterface::UNSEARCHABLE);
	            
	            return $this->save($q, array(':search' => intval($search)));
	        } else {
	            return false;
	        }
    	}
}

