<?php

declare(strict_types=1);

namespace Goose\Modules\Cleaners;

use Goose\Article;
use Goose\Traits\DocumentMutatorTrait;
use Goose\Modules\{AbstractModule, ModuleInterface};
use DOMWrap\{Text, Element, NodeList};

/**
 * Document Cleaner
 *
 * @package Goose\Modules\Cleaners
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */
class DocumentCleaner extends AbstractModule implements ModuleInterface
{
    use DocumentMutatorTrait;

    /** @var array Element id/class/name to be removed that start with */
    private $startsWithNodes = [
        'adspot', 'conditionalAd-', 'hidden-', 'social-', 'publication', 'share-',
        'hp-', 'ad-', 'recommended-'
    ];

    /** @var array Element id/class/name to be removed that equal */
    private $equalsNodes = [
        'side', 'links', 'inset', 'print', 'fn', 'ad',
    ];

    /** @var array Element id/class/name to be removed that end with */
    private $endsWithNodes = [
        'meta'
    ];

    /** @var array Element id/class/name to be removed that contain */
    private $searchNodes = [
        'combx', 'retweet', 'mediaarticlerelated', 'menucontainer', 'navbar',
        'storytopbar-bucket', 'utility-bar', 'inline-share-tools', 'comment', // not commented
        'PopularQuestions', 'contact', 'foot', 'footer', 'Footer', 'footnote',
        'cnn_strycaptiontxt', 'cnn_html_slideshow', 'cnn_strylftcntnt',
        'shoutbox', 'sponsor', 'tags', 'socialnetworking', 'socialNetworking', 'scroll', // not scrollable
        'cnnStryHghLght', 'cnn_stryspcvbx', 'pagetools', 'post-attributes',
        'welcome_form', 'contentTools2', 'the_answers', 'communitypromo', 'promo_holder',
        'runaroundLeft', 'subscribe', 'vcard', 'articleheadings', 'date',
        'popup', 'author-dropdown', 'tools', 'socialtools', 'byline',
        'konafilter', 'KonaFilter', 'breadcrumbs', 'wp-caption-text', 'source',
        'legende', 'ajoutVideo', 'timestamp', 'js_replies', 'creative_commons', 'topics',
        'pagination', 'mtl', 'author', 'credit', 'toc_container', 'sharedaddy',
    ];

    /** @var array Element tagNames exempt from removal */
    private $exceptionSelectors = [
        'html', 'body',
    ];

    /**
     * Clean the contents of the supplied article document
     *
     * @inheritdoc
     */
    public function run(Article $article): self
    {
        $this->document($article->getDoc());

        $this->removeXPath('//comment()');
        $this->replace('em, strong, b, i, strike, del, ins', function ($node) {
            return !$node->find('img')->count();
        });
        $this->replace('span[class~=dropcap], span[class~=drop_cap]');
        $this->remove('script, style');
        $this->remove('header, footer, input, form, button, aside');
        $this->removeBadTags();
        $this->remove("[id='caption'],[class='caption']");
        $this->remove("[id*=' google '],[class*=' google ']");
        $this->remove("[id*='more']:not([id^=entry-]),[class*='more']:not([class^=entry-])");
        $this->remove("[id*='facebook']:not([id*='-facebook']),[class*='facebook']:not([class*='-facebook'])");
        $this->remove("[id*='facebook-broadcasting'],[class*='facebook-broadcasting']");
        $this->remove("[id*='twitter']:not([id*='-twitter']),[class*='twitter']:not([class*='-twitter'])");
        $this->replace('span', function ($node) {
            if (is_null($node->parent())) {
                return false;
            }
            return $node->parent()->is('p');
        });

        $this->convertToParagraph('div, span, article');

        return $this;
    }

    /**
     * Remove via CSS selectors
     *
     * @param string $selector
     * @param callable $callback
     *
     * @return self
     */
    private function remove(string $selector, callable $callback = null): self
    {
        $nodes = $this->document()->find($selector);

        foreach ($nodes as $node) {
            if (is_null($callback) || $callback($node)) {
                $node->destroy();
            }
        }

        return $this;
    }

    /**
     * Remove using via XPath expressions
     *
     * @param string $expression
     * @param callable $callback
     *
     * @return self
     */
    private function removeXPath(string $expression, callable $callback = null): self
    {
        $nodes = $this->document()->findXPath($expression);

        foreach ($nodes as $node) {
            if (is_null($callback) || $callback($node)) {
                $node->destroy();
            }
        }

        return $this;
    }

    /**
     * Replace node with its textual contents via CSS selectors
     *
     * @param string $selector
     * @param callable $callback
     *
     * @return self
     */
    private function replace(string $selector, callable $callback = null): self
    {
        $nodes = $this->document()->find($selector);

        foreach ($nodes as $node) {
            if (is_null($callback) || $callback($node)) {
                $node->substituteWith(new Text((string)$node->text()));
            }
        }

        return $this;
    }

    /**
     * Remove unwanted junk elements based on pre-defined CSS selectors
     *
     * @return self
     */
    private function removeBadTags(): self
    {
        $lists = [
            "[%s^='%s']" => $this->startsWithNodes,
            "[%s*='%s']" => $this->searchNodes,
            "[%s$='%s']" => $this->endsWithNodes,
            "[%s='%s']" => $this->equalsNodes,
        ];

        $attrs = [
            'id',
            'class',
            'name',
        ];

        $exceptions = array_map(function ($value) {
            return ':not(' . $value . ')';
        }, $this->exceptionSelectors);

        $exceptions = implode('', $exceptions);

        foreach ($lists as $expr => $list) {
            foreach ($list as $value) {
                foreach ($attrs as $attr) {
                    $selector = sprintf($expr, $attr, $value) . $exceptions;

                    foreach ($this->document()->find($selector) as $node) {
                        $node->destroy();
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Replace supplied element with <p> new element.
     *
     * @param Element $node
     *
     * @return self|null
     */
    private function replaceElementsWithPara(Element $node): ?self
    {
        try {
            // Check to see if the node no longer exists.
            if (!isset($node->nodeName)) {
                return null;
            }

            $document = $this->document();
            if (!$document instanceof \DOMWrap\Document) {
                return null;
            }

            $newEl = $document->createElement('p');
            if (!$newEl) {
                return null;
            }

            $contents = $node->contents();
            if ($contents) {
                $newEl->appendWith($contents->detach());
            }

            foreach ($node->attributes as $attr) {
                $newEl->attr($attr->localName, $attr->nodeValue);
            }

            $node->substituteWith($newEl);

            return $this;
        } catch (\Throwable $e) {
            // Silently fail and return null
            return null;
        }
    }

    /**
     * Convert wanted elements to <p> elements.
     *
     * @param string $selector
     *
     * @return self
     */
    private function convertToParagraph(string $selector): ?self
    {
        try {
            $document = $this->document();
            if (!$document instanceof \DOMWrap\Document) {
                return null;
            }

            $nodes = $document->find($selector);
            if ($nodes === null || $nodes->count() === 0) {
                return null;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof Element) {
                    continue;
                }

                $tagNodes = $node->find('a, blockquote, dl, div, img, ol, p, pre, table, ul');

                if ($tagNodes === null || $tagNodes->count() === 0) {
                    $result = $this->replaceElementsWithPara($node);
                    if ($result === null) {
                        // Handle the failure if needed
                        continue;
                    }
                } else {
                    $replacements = $this->getReplacementNodes($node);
                    if ($replacements !== null) {
                        $node->contents()->destroy();
                        $node->appendWith($replacements);
                    }
                }
            }

            return $this;
        } catch (\Throwable $e) {
            // Silently fail and return null
            return null;
        }
    }

    /**
     * Generate new <p> element with supplied content.
     *
     * @param NodeList $replacementNodes
     *
     * @return Element
     */
    private function getFlushedBuffer(NodeList $replacementNodes): Element
    {
        $newEl = $this->document()->createElement('p');
        $newEl->appendWith($replacementNodes);

        return $newEl;
    }

    /**
     * Generate <p> element replacements for supplied elements child nodes as required.
     *
     * @param Element $node
     *
     * @return NodeList $nodesToReturn Replacement elements
     */
    private function getReplacementNodes(Element $node): ?NodeList
    {
        try {
            $nodesToReturn = $node->newNodeList();
            $nodesToRemove = $node->newNodeList();
            $replacementNodes = $node->newNodeList();

            $fnCompareSiblingNodes = function ($node) {
                return $node->is(':not(a)') || $node->nodeType == XML_TEXT_NODE;
            };

            foreach ($node->contents() as $child) {
                if ($child->is('p') && $replacementNodes->count()) {
                    $nodesToReturn[] = $this->getFlushedBuffer($replacementNodes);
                    $replacementNodes->fromArray([]);
                    $nodesToReturn[] = $child;
                } else if ($child->nodeType == XML_TEXT_NODE) {
                    $replaceText = $child->text();

                    if (!empty($replaceText)) {
                        $siblings = $child
                            ->precedingUntil($fnCompareSiblingNodes, 'a')
                            ->merge([$child])
                            ->merge($child->followingUntil($fnCompareSiblingNodes, 'a'));

                        foreach ($siblings as $sibling) {
                            if ($sibling->isSameNode($child)) {
                                $replacementNodes[] = new Text($replaceText);
                            } else if ($sibling->getAttribute('grv-usedalready') != 'yes') {
                                $sibling->setAttribute('grv-usedalready', 'yes');
                                $replacementNodes[] = $sibling->cloneNode(true);
                                $nodesToRemove[] = $sibling;
                            }
                        }
                    }

                    $nodesToRemove[] = $child;
                } else {
                    if ($replacementNodes->count()) {
                        $nodesToReturn[] = $this->getFlushedBuffer($replacementNodes);
                        $replacementNodes->fromArray([]);
                    }

                    $nodesToReturn[] = $child;
                }
            }

            if ($replacementNodes->count()) {
                $nodesToReturn[] = $this->getFlushedBuffer($replacementNodes);
            }

            foreach ($nodesToReturn as $key => $return) {
                if ($nodesToRemove->exists($return)) {
                    unset($nodesToReturn[$key]);
                }
            }

            $nodesToRemove->destroy();

            return $nodesToReturn;
        } catch (\Throwable $e) {
            // Silently fail and return null
            return null;
        }
    }
}
