<?php

namespace IsotopeStandardExtended\Model\Product;

use Contao\ContentElement;
use Contao\Database;
use Contao\Date;
use Contao\Environment;
use Contao\FrontendUser;
use Contao\Input;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Haste\Generator\RowClass;
use Haste\Units\Mass\Weight;
use Haste\Units\Mass\WeightAggregate;
use Haste\Util\Url;
use Isotope\Collection\ProductPrice as ProductPriceCollection;
use Isotope\Frontend\ProductAction\ProductActionInterface;
use Isotope\Frontend\ProductAction\Registry;
use Isotope\Interfaces\IsotopeAttribute;
use Isotope\Interfaces\IsotopeAttributeForVariants;
use Isotope\Interfaces\IsotopeAttributeWithOptions;
use Isotope\Interfaces\IsotopePrice;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Interfaces\IsotopeProductWithOptions;
use Isotope\Isotope;
use Isotope\Model\Attribute;
use Isotope\Model\Gallery;
use Isotope\Model\Gallery\Standard as StandardGallery;
use Isotope\Model\ProductCollectionItem;
use Isotope\Model\ProductPrice;
use Isotope\Model\ProductType;
use Isotope\Template;

/** Extends Isotope's default Standard class */
class StandardExtended extends Standard implements WeightAggregate, IsotopeProductWithOptions
{
  /**
     * Generate a product template
     *
     * @param array $arrConfig
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function generate(array $arrConfig)
    {
        $objProduct = $this;
        $loadFallback = isset($arrConfig['loadFallback']) ? (bool) $arrConfig['loadFallback'] : true;

        $this->strFormId = (($arrConfig['module'] instanceof ContentElement) ? 'cte' : 'fmd') . $arrConfig['module']->id . '_product_' . $this->getProductId();

        if (!($arrConfig['disableOptions'] ?? false)) {
            $objProduct = $this->validateVariant($loadFallback);

            // A variant has been loaded, generate the variant
            if ($objProduct->getId() != $this->getId()) {
                return $objProduct->generate($arrConfig);
            }
        }

        /** @var Template|\stdClass $objTemplate */
        $objTemplate = new Template($arrConfig['template']);
        $objTemplate->setData($this->arrData);
        $objTemplate->product = $this;
        $objTemplate->config  = $arrConfig;

        $objTemplate->highlightKeywords = function($text) {
            $keywords = Input::get('keywords');

            if (empty($keywords)) {
                return $text;
            }

            $keywords = StringUtil::trimsplit(' |-', $keywords);
            $keywords = array_filter(array_unique($keywords));

            foreach ($keywords as $word) {
                $text = StringUtil::highlight($text, $word, '<em>', '</em>');
            }

            return $text;
        };

        $objTemplate->hasAttribute = function ($strAttribute) use ($objProduct) {
            return \in_array($strAttribute, $objProduct->getType()->getAttributes(), true)
                || \in_array($strAttribute, $objProduct->getType()->getVariantAttributes(), true);
        };

        $objTemplate->generateAttribute = function ($strAttribute, array $arrOptions = array()) use ($objProduct) {

            $objAttribute = $GLOBALS['TL_DCA']['tl_iso_product']['attributes'][$strAttribute];

            if (!($objAttribute instanceof IsotopeAttribute)) {
                throw new \InvalidArgumentException($strAttribute . ' is not a valid attribute');
            }

            return $objAttribute->generate($objProduct, $arrOptions);
        };

        $objTemplate->generatePrice = function() use ($objProduct) {
            $objPrice = $objProduct->getPrice();

            /** @var ProductType $objType */
            $objType = $objProduct->getType();

            if (null === $objPrice) {
                return '';
            }

            return $objPrice->generate($objType->showPriceTiers(), 1, $objProduct->getOptions());
        };

        /** @var StandardGallery $currentGallery */
        $currentGallery          = null;
        $objTemplate->getGallery = function ($strAttribute, $isoGalleryId=null) use ($objProduct, $arrConfig, &$currentGallery) {

            if(!is_null($isoGalleryId))
                $arrConfig['gallery'] = $isoGalleryId;
          
            if (null === $currentGallery
                || $currentGallery->getName() !== $objProduct->getFormId() . '_' . $strAttribute
            ) {
                $currentGallery = Gallery::createForProductAttribute(
                    $objProduct,
                    $strAttribute,
                    $arrConfig
                );
            }

            return $currentGallery;
        };

        $arrVariantOptions = array();
        $arrProductOptions = array();
        $arrAjaxOptions    = array();

        if (!($arrConfig['disableOptions'] ?? false)) {
            foreach (array_unique(array_merge($this->getType()->getAttributes(), $this->getType()->getVariantAttributes())) as $attribute) {
                $arrData = $GLOBALS['TL_DCA']['tl_iso_product']['fields'][$attribute];

                if (($arrData['attributes']['customer_defined'] ?? null) || ($arrData['attributes']['variant_option'] ?? null)) {

                    $strWidget = $this->generateProductOptionWidget($attribute, $arrVariantOptions, $arrAjaxOptions, $objWidget);

                    if ($strWidget != '') {
                        $arrProductOptions[$attribute] = array_merge($arrData, array
                        (
                            'name'    => $attribute,
                            'html'    => $strWidget,
                            'widget'  => $objWidget,
                        ));
                    }

                    unset($objWidget);
                }
            }
        }

        /** @var ProductActionInterface[] $actions */
        $handleButtons = false;
        $actions = array_filter(
            Registry::all(true, $this),
            function (ProductActionInterface $action) use ($arrConfig) {
                return \in_array($action->getName(), $arrConfig['buttons'] ?? []) && $action->isAvailable($this, $arrConfig);
            }
        );

        // Sort actions by order in module configuration
        $buttonOrder = array_values($arrConfig['buttons'] ?? []);
        usort($actions, function (ProductActionInterface $a, ProductActionInterface $b) use ($buttonOrder) {
            return array_search($a->getName(), $buttonOrder) - array_search($b->getName(), $buttonOrder);
        });

        if (Input::post('FORM_SUBMIT') == $this->getFormId() && !$this->doNotSubmit) {
            $handleButtons = true;

            foreach ($actions as $action) {
                if ($action->handleSubmit($this, $arrConfig)) {
                    $handleButtons = false;
                    break;
                }
            }
        }

        /**
         * @deprecated Deprecated since Isotope 2.5
         */
        $objTemplate->buttons = function() use ($arrConfig, $handleButtons) {
            $arrButtons = array();

            // !HOOK: retrieve buttons
            if (isset($arrConfig['buttons'], $GLOBALS['ISO_HOOKS']['buttons'])
                && \is_array($arrConfig['buttons'])
                && \is_array($GLOBALS['ISO_HOOKS']['buttons'])
            ) {
                foreach ($GLOBALS['ISO_HOOKS']['buttons'] as $callback) {
                    $arrButtons = System::importStatic($callback[0])->{$callback[1]}($arrButtons, $this);
                }
            }

            $arrButtons = array_intersect_key($arrButtons, array_flip($arrConfig['buttons'] ?? []));

            if ($handleButtons) {
                foreach ($arrButtons as $button => $data) {
                    if (isset($_POST[$button])) {
                        if (isset($data['callback'])) {
                            System::importStatic($data['callback'][0])->{$data['callback'][1]}($this, $arrConfig);
                        }
                        break;
                    }
                }
            }

            return $arrButtons;
        };

        RowClass::withKey('rowClass')->addCustom('product_option')->addFirstLast()->addEvenOdd()->applyTo($arrProductOptions);

        $objTemplate->actions = $actions;
        $objTemplate->useQuantity = $arrConfig['useQuantity'] && null === $this->getCollectionItem();
        $objTemplate->minimum_quantity = $this->getMinimumQuantity();
        $objTemplate->raw = $this->arrData;
        $objTemplate->raw_options = $this->getConfiguration();
        $objTemplate->configuration = $this->getConfiguration();
        $objTemplate->href = '';
        $objTemplate->label_detail = $GLOBALS['TL_LANG']['MSC']['detailLabel'];
        $objTemplate->options = $arrProductOptions;
        $objTemplate->hasOptions = \count($arrProductOptions) > 0;
        $objTemplate->enctype = $this->hasUpload ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
        $objTemplate->formId = $this->getFormId();
        $objTemplate->formSubmit = $this->getFormId();
        $objTemplate->product_id = $this->getProductId();
        $objTemplate->module_id = $arrConfig['module']->id ?? null;

        if (!($arrConfig['jumpTo'] ?? null) instanceof PageModel || $arrConfig['jumpTo']->iso_readerMode !== 'none') {
            $objTemplate->href = $this->generateUrl($arrConfig['jumpTo']);
        }

        if (!($arrConfig['disableOptions'] ?? false)) {
            $GLOBALS['AJAX_PRODUCTS'][] = array('formId' => $this->getFormId(), 'attributes' => $arrAjaxOptions);
        }

        // !HOOK: alter product data before output
        if (isset($GLOBALS['ISO_HOOKS']['generateProduct']) && \is_array($GLOBALS['ISO_HOOKS']['generateProduct'])) {
            foreach ($GLOBALS['ISO_HOOKS']['generateProduct'] as $callback) {
                System::importStatic($callback[0])->{$callback[1]}($objTemplate, $this);
            }
        }

        return trim($objTemplate->parse());
    }
}
