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
		global $wgOut, $wgRequest, $wgTitle;
		$dbr = wfGetDB( DB_SLAVE );
		$cat = $wgRequest->getVal( 'cat', 'Open' );
		$children = $this->getSubcategories( $cat );
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
		$text = "";
		$frompage = wfMsg( 'nonopenimages-frompage' );
		foreach ( $res as $row ) {
			$t = Title::newFromRow( $row );
			$i = Title::makeTitle( NS_FILE, $row->img_name );
			if ( $i->userCanReadEx() && $t->userCanReadEx() ) {
				$text .= "* [[:$i]] $frompage [[:$t]]\n";
			}
		}
		if ( $text ) {
			$text = wfMsg( 'nonopenimages-list', $cat ) . "\n" . $text;
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
