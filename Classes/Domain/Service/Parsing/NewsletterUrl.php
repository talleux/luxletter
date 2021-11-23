<?php
declare(strict_types = 1);
namespace In2code\Luxletter\Domain\Service\Parsing;

use DOMDocument;
use DOMXpath;
use Exception;
use In2code\Luxletter\Domain\Factory\UserFactory;
use In2code\Luxletter\Domain\Model\User;
use In2code\Luxletter\Domain\Service\BodytextManipulation\CssInline;
use In2code\Luxletter\Domain\Service\BodytextManipulation\ImageEmbedding\Preparation;
use In2code\Luxletter\Domain\Service\LayoutService;
use In2code\Luxletter\Domain\Service\SiteService;
use In2code\Luxletter\Exception\ApiConnectionException;
use In2code\Luxletter\Exception\InvalidUrlException;
use In2code\Luxletter\Exception\MisconfigurationException;
use In2code\Luxletter\Exception\UnvalidFilenameException;
use In2code\Luxletter\Signal\SignalTrait;
use In2code\Luxletter\Utility\ConfigurationUtility;
use In2code\Luxletter\Utility\ObjectUtility;
use In2code\Luxletter\Utility\StringUtility;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException;
use TYPO3\CMS\Extbase\Object\Exception as ExceptionExtbaseObject;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class NewsletterUrl to fill a container html with a content from a http(s) page.
 * This is used for test mails, preview and for storing a bodytext in a newsletter record.
 * The final parse (when generating newsletters records) is done by Parsing\Newsletter class.
 */
class NewsletterUrl
{
    use SignalTrait;

    const MODE_NEWSLETTER = 'newsletter';
    const MODE_TESTMAIL = 'testmail';
    const MODE_PREVIEW = 'preview';

    /**
     * "newsletter" for the final parsing of the newsletter (via backend module, via command)
     * "testmail" for the tes mail from backend module
     * "preview" for mail preview in backend module
     *
     * @var string
     */
    protected $mode = self::MODE_NEWSLETTER;

    /**
     * Hold origin (number as page identifier or absolute URL)
     *
     * @var string
     */
    protected $origin = '';

    /**
     * Hold url from origin (page identifier from origin parsed with URL or keep the absolute URL)
     *
     * @var string
     */
    protected $url = '';

    /**
     * @var string Path to container html template like "EXT:sitepackage/../MailContainer.html"
     */
    protected $containerTemplate = '';

    /**
     * Extension configuration from TypoScript
     *
     * @var array
     */
    protected $configuration = [];

    /**
     * NewsletterUrl constructor.
     * @param string $origin can be a page uid or a complete url
     * @param string $layout Container html template filename
     * @throws ExceptionExtbaseObject
     * @throws InvalidConfigurationTypeException
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     * @throws MisconfigurationException
     * @throws SiteNotFoundException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws UnvalidFilenameException
     */
    public function __construct(string $origin, string $layout)
    {
        $this->configuration = ConfigurationUtility::getExtensionSettings();
        $this->setContainerTemplateFromLayout($layout);

        $url = '';
        if (MathUtility::canBeInterpretedAsInteger($origin)) {
            $arguments = [];
            $typenum = ConfigurationUtility::getTypeNumToNumberLocation();
            if ($typenum > 0) {
                $arguments = ['type' => $typenum];
            }
            $siteService = GeneralUtility::makeInstance(SiteService::class);
            $url = $siteService->getPageUrlFromParameter((int)$origin, $arguments);
        } elseif (StringUtility::isValidUrl($origin)) {
            $url = $origin;
        }
        $this->signalDispatch(__CLASS__, 'constructor', [$url, $origin, $this]);
        $this->setOrigin($origin);
        $this->setUrl($url);
    }

    /**
     * @param Site $site
     * @param User|null $user
     * @return string
     * @throws ApiConnectionException
     * @throws ExceptionExtbaseObject
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidConfigurationTypeException
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     * @throws InvalidUrlException
     * @throws MisconfigurationException
     */
    public function getParsedContent(Site $site, User $user = null): string
    {
        if ($user === null) {
            $userFactory = GeneralUtility::makeInstance(UserFactory::class);
            $user = $userFactory->getDummyUser();
        }
        $this->signalDispatch(__CLASS__, __FUNCTION__ . 'BeforeParsing', [$user, $this]);
        $content = $this->getNewsletterContainerAndContent($this->getContentFromOrigin($user), $site, $user);
        $this->signalDispatch(__CLASS__, __FUNCTION__ . 'AfterParsing', [$content, $this]);
        return $content;
    }

    /**
     * @param string $content
     * @param Site $site
     * @param User $user
     * @return string
     * @throws ApiConnectionException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidConfigurationTypeException
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     * @throws MisconfigurationException
     */
    protected function getNewsletterContainerAndContent(string $content, Site $site, User $user): string
    {
        $html = '';

        if ($this->mode === self::MODE_PREVIEW || $this->mode === self::MODE_TESTMAIL) {
            $standaloneView = GeneralUtility::makeInstance(StandaloneView::class);
            $standaloneView->setLayoutRootPaths($this->configuration['view']['layoutRootPaths']);
            $standaloneView->setPartialRootPaths($this->configuration['view']['partialRootPaths']);
            $standaloneView->setTemplatePathAndFilename($this->getContainerTemplate());
            $standaloneView->assignMultiple($this->getContentObjectVariables());
            $standaloneView->assignMultiple(
                [
                    'content' => $content,
                    'user' => $user,
                    'site' => $site,
                    'settings' => (array)$this->configuration['settings']
                ]
            );
            $this->signalDispatch(
                __CLASS__,
                __FUNCTION__ . 'PostParsing',
                [$standaloneView, $content, $this->configuration, $user, $this]
            );
            $html = $standaloneView->render();
        } elseif ($this->mode === self::MODE_NEWSLETTER) {
            $container = file_get_contents($this->getContainerTemplate(true));
            $html = str_replace('{content}', $content, $container);
        }

        $html = $this->bodytextManipulation($html);

        $this->signalDispatch(__CLASS__, __FUNCTION__, [&$html, &$content, $user, $this]);
        return $html;
    }

    /**
     * Compile rendered content objects in variables array ready to assign to the view
     *
     *  Example TypoScript:
     *      plugin {
     *          tx_luxletter_fe {
     *              variables {
     *                  subject = TEXT
     *                  subject.value = My own Newsletter
     *              }
     *          }
     *      }
     *
     * @return array the variables to be assigned
     */
    protected function getContentObjectVariables(): array
    {
        $tsService = GeneralUtility::makeInstance(TypoScriptService::class);
        $tsConfiguration = $tsService->convertPlainArrayToTypoScriptArray($this->configuration);

        $variables = [];
        $variablesToProcess = (array)($tsConfiguration['variables.'] ?? []);
        $contentObjectRenderer = ObjectUtility::getContentObject();
        foreach ($variablesToProcess as $variableName => $cObjType) {
            if (is_array($cObjType)) {
                continue;
            }
            $variables[$variableName] = $contentObjectRenderer->cObjGetSingle(
                $cObjType,
                $variablesToProcess[$variableName . '.'],
                'variables.' . $variableName
            );
        }

        return $variables;
    }

    /**
     * @param User $user
     * @return string
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     * @throws InvalidUrlException
     * @throws MisconfigurationException
     * @throws ExceptionExtbaseObject
     * @throws InvalidConfigurationTypeException
     */
    protected function getContentFromOrigin(User $user): string
    {
        if ($this->url === '') {
            throw new InvalidUrlException('Given URL was invalid and was not parsed', 1560709687);
        }
        $string = GeneralUtility::getUrl($this->url);
        if ($string === false) {
            throw new MisconfigurationException(
                'Given URL could not be parsed and accessed (Tried to read url: ' . $this->url
                . '). Typenum definition in site-configuration not set? Fluid Styled Mail Content TypoScript added?',
                1560709791
            );
        }
        $string = $this->getBodyFromHtml($string);
        if ($this->mode === self::MODE_PREVIEW || $this->mode === self::MODE_TESTMAIL) {
            $parseService = GeneralUtility::makeInstance(Newsletter::class);
            $string = $parseService->parseBodytext($string, ['user' => $user]);
        }
        $this->signalDispatch(__CLASS__, __FUNCTION__, [$string, $user, $this]);
        return $string;
    }

    /**
     * Manipulate newsletter bodytext for different modes
     *
     * @param string $html
     * @return string
     * @throws ApiConnectionException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidConfigurationTypeException
     * @throws MisconfigurationException
     */
    protected function bodytextManipulation(string $html): string
    {
        if ($this->mode === self::MODE_PREVIEW || $this->mode === self::MODE_TESTMAIL) {
            $cssInline = GeneralUtility::makeInstance(CssInline::class);
            $html = $cssInline->addInlineCss($html);
        }
        if ($this->mode === self::MODE_NEWSLETTER || $this->mode === self::MODE_TESTMAIL) {
            $preparation = GeneralUtility::makeInstance(Preparation::class);
            $preparation->storeImages($html);
        }
        return $html;
    }

    /**
     * @param string $string
     * @return string
     */
    protected function getBodyFromHtml(string $string): string
    {
        try {
            $document = new DOMDocument;
            libxml_use_internal_errors(true);
            @$document->loadHtml($string);
            libxml_use_internal_errors(false);
            $xpath = new DOMXpath($document);
            $result = '';
            foreach ($xpath->evaluate('//body/node()') as $node) {
                $result .= $document->saveHtml($node);
            }
            if (!empty($result)) {
                return $result;
            }
        } catch (Exception $exception) {
        }
        return $string;
    }

    /**
     * @return string
     */
    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * @param string $origin
     */
    public function setOrigin(string $origin)
    {
        $this->origin = $origin;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $url
     * @return NewsletterUrl
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @param bool $absolute
     * @return string
     */
    public function getContainerTemplate(bool $absolute = false): string
    {
        $containerTemplate = $this->containerTemplate;
        if ($absolute === true) {
            $containerTemplate = GeneralUtility::getFileAbsFileName($containerTemplate);
        }
        return $containerTemplate;
    }

    /**
     * @param string $layout Filename of a layout template file
     * @return $this
     * @throws InvalidConfigurationTypeException
     * @throws UnvalidFilenameException
     * @throws MisconfigurationException
     */
    public function setContainerTemplateFromLayout(string $layout): self
    {
        $layoutService = GeneralUtility::makeInstance(LayoutService::class);
        $this->containerTemplate = $layoutService->getPathAndFilenameFromLayout($layout);
        return $this;
    }

    /**
     * @return $this
     */
    public function setModeTestmail(): self
    {
        $this->mode = self::MODE_TESTMAIL;
        return $this;
    }

    /**
     * @return $this
     */
    public function setModePreview(): self
    {
        $this->mode = self::MODE_PREVIEW;
        return $this;
    }
}