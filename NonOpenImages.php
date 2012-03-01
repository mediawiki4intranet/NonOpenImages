<?php

// Quick hack for viewing images that someone forgot to open to the public, in wikis closed by IntraACL
// Just prints the list of images used on pages from some category and its children (for example Category:Open),
// but not included into this category (or to its child category) itself.
// (c) Vitaliy Filippov, 2012

if ( !defined( 'MEDIAWIKI' ) )
	die();

$wgSpecialPages['NonOpenImages'] = 'SpecialNonOpenImages';
$wgSpecialPageGroups['NonOpenImages'] = 'hacl_group';
$wgExtensionMessagesFiles['NonOpenImages'] = dirname( __FILE__ ) . '/NonOpenImages.i18n.php';

class SpecialNonOpenImages extends SpecialPage {
	function __construct() {
		parent::__construct( 'NonOpenImages' );
	}

	public function execute( $par ) {
		global $wgOut, $wgRequest, $wgTitle, $wgContLang;
		$dbr = wfGetDB( DB_SLAVE );
		$cat = $wgRequest->getVal( 'cat', 'Open' );
		$children = $this->getSubcategories( $cat );
		// Select non-opened images
		$res = $dbr->select(
			array(
				'p' => 'page',
				'pc' => 'categorylinks',
				'il' => 'imagelinks',
				'i' => 'image',
				'ip' => 'page',
				'ic' => 'categorylinks',
			),
			'i.*, p.*',
			array( 'pc.cl_to' => $children, 'ic.cl_to IS NULL' ), __METHOD__, array(),
			array(
				'pc' => array( 'JOIN', 'pc.cl_from=p.page_id' ),
				'il' => array( 'JOIN', 'il_from=p.page_id' ),
				'i'  => array( 'JOIN', 'il_to=img_name' ),
				'ip' => array( 'LEFT JOIN', array( 'ip.page_namespace' => NS_FILE, 'ip.page_title=i.img_name' ) ),
				'ic' => array( 'LEFT JOIN', array( 'ic.cl_from=ip.page_id', 'ic.cl_to' => $children ) ),
			)
		);
		$pg = array();
		$frompage = wfMsg( 'nonopenimages-frompage' );
		foreach ( $res as $row ) {
			$t = Title::newFromRow( $row );
			if ( $t->userCanReadEx() ) {
				$pg[ 'File:'.$row->img_name ][] = "[[:$t]]";
			}
		}
		// Same for templates
		$res = $dbr->select(
			array(
				'p' => 'page',
				'pc' => 'categorylinks',
				'tl' => 'templatelinks',
				't' => 'page',
				'tc' => 'categorylinks',
			),
			'tl.*, p.*',
			array( 'pc.cl_to' => $children, 'tc.cl_to IS NULL' ), __METHOD__,
			array( 'GROUP BY' => 't.page_id, p.page_id' ),
			array(
				'pc' => array( 'JOIN', array( 'pc.cl_from=p.page_id' ) ),
				'tl' => array( 'JOIN', array( 'tl_from=p.page_id' ) ),
				't'  => array( 'JOIN', array( 'tl_namespace=t.page_namespace', 'tl_title=t.page_title' ) ),
				'tc' => array( 'LEFT JOIN', array( 'tc.cl_from=t.page_id', 'tc.cl_to' => $children ) ),
			)
		);
		foreach ( $res as $row ) {
			$t = Title::newFromRow( $row );
			if ( $t->userCanReadEx() ) {
				$pg[ $wgContLang->getNsText( $row->tl_namespace ).':'.$row->tl_title ][] = "[[:$t]]";
			}
		}
		if ( $pg ) {
			$text = wfMsg( 'nonopenimages-list', $cat ) . "\n";
			foreach ( $pg as $k => $a ) {
				$text .= "* [[:$k]] $frompage ".implode( ", ", $a )."\n";
			}
		} else {
			$text = wfMsg( 'nonopenimages-none', $cat );
		}
		$wgOut->setPageTitle( wfMsg( 'nonopenimages-title', $cat ) );
		$wgOut->addHTML( Xml::tags( 'form', array( 'action' => $wgTitle->getLocalUrl(), 'method' => 'GET' ),
			wfMsg( 'nonopenimages-selectcat' ) . Html::input( 'cat', $cat ).' '.Html::input( 'submit', wfMsg( 'nonopenimages-submit' ), 'submit' ) ) );
		$wgOut->addWikiText( $text );
	}

	public function getSubcategories( $categories ) {
		$dbr = wfGetDB( DB_SLAVE );
		$cats = array();
		foreach ( ( is_array( $categories ) ? $categories : array( $categories ) ) as $c ) {
			if ( !is_object( $c ) ) {
				$c = Title::newFromText( $c, NS_CATEGORY );
			}
			$cats[ $c->getDBkey() ] = true;
		}
		while ( $categories ) {
			$res = $dbr->select( array( 'page', 'categorylinks' ), 'page_title',
				array( 'cl_from=page_id', 'cl_to' => $categories, 'page_namespace' => NS_CATEGORY ),
				__METHOD__ );
			$categories = array();
			foreach ( $res as $row ) {
				if ( !$cats[ $row->page_title ] ) {
					$categories[] = $row->page_title;
					$cats[ $row->page_title ] = $row;
				}
			}
		}
		return array_keys( $cats );
	}
}
