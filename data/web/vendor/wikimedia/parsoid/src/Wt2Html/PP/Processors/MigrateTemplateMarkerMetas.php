<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wikitext\Consts;
use Wikimedia\Parsoid\Wt2Html\Wt2HtmlDOMProcessor;

class MigrateTemplateMarkerMetas implements Wt2HtmlDOMProcessor {
	/**
	 * This will move the start/end-meta closest to the content
	 * that the template/extension produced and improve accuracy
	 * of finding dom ranges and wrapping templates.
	 *
	 * If the last child of a node is a start-meta,
	 * move it up and make it the parent's right sibling.
	 *
	 * If the first child of a node is an end-meta,
	 * move it up and make it the parent's left sibling.
	 *
	 * @param Node $node
	 * @param Env $env
	 */
	private function doMigrate( Node $node, Env $env ): void {
		$c = $node->firstChild;
		while ( $c ) {
			$sibling = $c->nextSibling;
			if ( $c->hasChildNodes() ) {
				$this->doMigrate( $c, $env );
			}
			$c = $sibling;
		}

		// No migration out of fragment
		if ( DOMUtils::atTheTop( $node ) ) {
			return;
		}

		$firstChild = DOMUtils::firstNonSepChild( $node );
		if ( $firstChild && WTUtils::isTplEndMarkerMeta( $firstChild ) ) {
			// We can migrate the meta-tag across this node's start-tag barrier only
			// if that start-tag is zero-width, or auto-inserted.
			$tagWidth = Consts::$WtTagWidths[DOMCompat::nodeName( $node )] ?? null;
			DOMUtils::assertElt( $node );
			if ( ( $tagWidth && $tagWidth[0] === 0 && !WTUtils::isLiteralHTMLNode( $node ) ) ||
				!empty( DOMDataUtils::getDataParsoid( $node )->autoInsertedStart )
			) {
				$sentinel = $firstChild;
				do {
					$firstChild = $node->firstChild;
					$node->parentNode->insertBefore( $firstChild, $node );
				} while ( $sentinel !== $firstChild );
			}
		}

		$lastChild = DOMUtils::lastNonSepChild( $node );
		if ( $lastChild && WTUtils::isTplStartMarkerMeta( $lastChild ) ) {
			// We can migrate the meta-tag across this node's end-tag barrier only
			// if that end-tag is zero-width, or auto-inserted.
			$tagWidth = Consts::$WtTagWidths[DOMCompat::nodeName( $node )] ?? null;
			DOMUtils::assertElt( $node );
			if ( ( $tagWidth && $tagWidth[1] === 0 &&
				!WTUtils::isLiteralHTMLNode( $node ) ) ||
				( !empty( DOMDataUtils::getDataParsoid( $node )->autoInsertedEnd ) &&
				// Except, don't migrate out of a table since the end meta
				// marker may have been fostered and this is more likely to
				// result in a flipped range that isn't enclosed.
				DOMCompat::nodeName( $node ) !== 'table' )
			) {
				$sentinel = $lastChild;
				do {
					$lastChild = $node->lastChild;
					$node->parentNode->insertBefore( $lastChild, $node->nextSibling );
				} while ( $sentinel !== $lastChild );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function run(
		Env $env, Node $root, array $options = [], bool $atTopLevel = false
	): void {
		$this->doMigrate( $root, $env );
	}
}
