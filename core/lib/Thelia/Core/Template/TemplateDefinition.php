<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Core\Template;

use Thelia\Core\Template\Exception\TemplateException;
use Thelia\Core\Template\Validator\TemplateDescriptor;
use Thelia\Core\Template\Validator\TemplateValidator;

class TemplateDefinition
{
    const FRONT_OFFICE = 1;
    const BACK_OFFICE = 2;
    const PDF = 3;
    const EMAIL = 4;

    const FRONT_OFFICE_SUBDIR = 'frontOffice';
    const BACK_OFFICE_SUBDIR = 'backOffice';
    const PDF_SUBDIR = 'pdf';
    const EMAIL_SUBDIR = 'email';

    /** @var string the template directory full path */
    protected $path;

    /** @var TemplateDescriptor */
    protected $templateDescriptor;

    /** @var string the prefix for translation domain name */
    protected $translationDomainPrefix;

    protected static $standardTemplatesSubdirs = array(
        self::FRONT_OFFICE => self::FRONT_OFFICE_SUBDIR,
        self::BACK_OFFICE  => self::BACK_OFFICE_SUBDIR,
        self::PDF          => self::PDF_SUBDIR,
        self::EMAIL        => self::EMAIL_SUBDIR,
    );

    /**  @var array|null the parent list cache */
    protected $parentList = null;

    /**
     * TemplateDefinition constructor.
     * @param string $name the template name (= directory name)
     * @param int $type the remplate type (see $standardTemplatesSubdirs)
     * @throws \Exception
     */
    public function __construct($name, $type)
    {
        switch ($type) {
            case TemplateDefinition::FRONT_OFFICE:
                $this->path = self::FRONT_OFFICE_SUBDIR . DS . $name;
                $this->translationDomainPrefix = 'fo.';
                break;
            case TemplateDefinition::BACK_OFFICE:
                $this->path = self::BACK_OFFICE_SUBDIR . DS . $name;
                $this->translationDomainPrefix = 'bo.';
                break;
            case TemplateDefinition::PDF:
                $this->path = self::PDF_SUBDIR . DS . $name;
                $this->translationDomainPrefix = 'pdf.';
                break;
            case TemplateDefinition::EMAIL:
                $this->path = self::EMAIL_SUBDIR . DS . $name;
                $this->translationDomainPrefix = 'email.';
                break;
            default:
                $this->path = $name;
                $this->translationDomainPrefix = 'generic.';
                break;
        }

        // Load template descriprot, if any.
        $this->templateDescriptor = (new TemplateValidator($this->getAbsolutePath()))->getTemplateDefinition($name, $type);
    }

    public function getTranslationDomain()
    {
        return $this->translationDomainPrefix . strtolower($this->getName());
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->templateDescriptor->getName();
    }

    public function setName($name)
    {
        $this->templateDescriptor->setName($name);

        return $this;
    }

    public function getI18nPath()
    {
        return $this->getPath() . DS . 'I18n';
    }

    public function getAbsoluteI18nPath()
    {
        return THELIA_TEMPLATE_DIR . $this->getI18nPath();
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getAbsolutePath()
    {
        return THELIA_TEMPLATE_DIR . $this->getPath();
    }

    public function getConfigPath()
    {
        return $this->getPath() . DS . 'configs';
    }

    public function getAbsoluteConfigPath()
    {
        return THELIA_TEMPLATE_DIR . $this->getConfigPath();
    }

    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * @return int
     */
    public function getType()
    {
        return $this->templateDescriptor->getType();
    }

    public function setType($type)
    {
        $this->$this->templateDescriptor->setType($type);

        return $this;
    }

    /**
     * Get teh template descriptor
     *
     * @return TemplateDescriptor
     */
    public function getDescriptor()
    {
        return $this->templateDescriptor;
    }

    /**
     * Returns an iterator on the standard templates subdir names
     */
    public static function getStandardTemplatesSubdirsIterator()
    {
        return new \ArrayIterator(self::$standardTemplatesSubdirs);
    }

    /**
     * Return the template parent list
     *
     * @return array|null
     */
    public function getParentList()
    {
        if (null === $this->parentList) {
            $this->parentList = [];

            $parent = $this->getDescriptor()->getParent();

            for ($index = 1; null !== $parent; $index++) {
                $this->parentList[$parent->getName() . '-'] = $parent;

                $parent = $parent->getDescriptor()->getParent();
            }
        }

        return $this->parentList;
    }

    /**
     * Find a template file path, considering the template parents, if any.
     *
     * @param string $templateName the template name, with path
     * @return string
     * @throws TemplateException
     */
    public function getTemplateFilePath($templateName)
    {
        $templateList = array_merge(
            [ $this ],
            $this->getParentList()
        );

        /** @var TemplateDefinition $templateDefinition */
        foreach ($templateList as $templateDefinition) {
            $templateFilePath = sprintf(
                '%s%s/%s',
                THELIA_TEMPLATE_DIR,
                $templateDefinition->getPath(),
                $templateName
            );

            if (file_exists($templateFilePath)) {
                return $templateFilePath;
            }
        }

        throw new TemplateException("Template file not found: $templateName");
    }
}
