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

namespace Thelia\Core\Template\Element;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Util\PropelModelPager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Loop\LoopExtendsArgDefinitionsEvent;
use Thelia\Core\Event\Loop\LoopExtendsBuildArrayEvent;
use Thelia\Core\Event\Loop\LoopExtendsBuildModelCriteriaEvent;
use Thelia\Core\Event\Loop\LoopExtendsInitializeArgsEvent;
use Thelia\Core\Event\Loop\LoopExtendsParseResultsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Element\Exception\LoopException;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Core\Translation\Translator;
use Thelia\Type\EnumListType;
use Thelia\Type\EnumType;
use Thelia\Type\TypeCollection;

/**
 * Class BaseLoop
 * @package TThelia\Core\Template\Element
 *
 * @method string getType()
 * @method bool getForceReturn()
 * @method bool getBackendContext()
 * @method int getOffset() available if countable is true
 * @method int getPage() available if countable is true
 * @method int getLimit() available if countable is true
 * @method bool getReturnUrl() false for disable the generation of urls
 * @method ModelCriteria buildModelCriteria()
 * @method array buildArray()
 */
abstract class BaseLoop
{
    /** @var String|null The loop name  */
    protected $loopName = null;

    /** @var array|null array of loop definitions (class => id) */
    protected static $loopDefinitions = null;

    /** @var ArgumentCollection[] cache for loop arguments (class => ArgumentCollection) */
    protected static $loopDefinitionsArgs = [];

    /**
     * @var Request
     * @deprecated since 2.3, please use getCurrentRequest()
     */
    protected $request;

    /** @var RequestStack */
    protected $requestStack;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var SecurityContext */
    protected $securityContext;

    /** @var ContainerInterface Service Container */
    protected $container = null;

    /** @var ArgumentCollection */
    protected $args;

    protected $countable = true;
    protected $timestampable = false;
    protected $versionable = false;

    /** @var Translator  */
    protected $translator = null;

    private static $cacheLoopResult = [];
    private static $cacheLoopPagination = [];
    private static $cacheCount = [];

    /** @var array cache of event to dispatch */
    protected static $dispatchCache = [];

    /**
     * Create a new Loop
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        $this->translator = $container->get("thelia.translator");

        $this->checkInterface();

        $this->requestStack = $container->get('request_stack');
        $this->request = $this->getCurrentRequest();
        $this->dispatcher = $container->get('event_dispatcher');
        $this->securityContext = $container->get('thelia.securityContext');

        $this->initialize();
    }

    /**
     * Initialize the loop
     *
     * First it will get the loop name according to the loop class.
     * Then argument definitions is initialized
     *
     */
    protected function initialize()
    {
        $class = \get_class($this);

        if (null === self::$loopDefinitions) {
            self::$loopDefinitions = \array_flip($this->container->getParameter('thelia.parser.loops'));
        }

        if (isset(self::$loopDefinitions[$class])) {
            $this->loopName = self::$loopDefinitions[$class];
        }

        if (!isset(self::$loopDefinitionsArgs[$class])) {
            $this->args = $this->getArgDefinitions()->addArguments($this->getDefaultArgs(), false);

            $eventName = $this->getDispatchEventName(TheliaEvents::LOOP_EXTENDS_ARG_DEFINITIONS);
            if (null !== $eventName) {
                $this->dispatcher->dispatch(
                    $eventName,
                    new LoopExtendsArgDefinitionsEvent($this)
                );
            };

            self::$loopDefinitionsArgs[$class] = $this->args;
        }

        $this->args = self::$loopDefinitionsArgs[$class];
    }

    /**
     * Define common loop arguments
     *
     * @return Argument[]
     */
    protected function getDefaultArgs()
    {
        $defaultArgs = [
            Argument::createBooleanTypeArgument('backend_context', false),
            Argument::createBooleanTypeArgument('force_return', false),
            Argument::createAnyTypeArgument('type'),
            Argument::createBooleanTypeArgument('no-cache', false),
            Argument::createBooleanTypeArgument('return_url', true)
        ];

        if (true === $this->countable) {
            $defaultArgs[] = Argument::createIntTypeArgument('offset', 0);
            $defaultArgs[] = Argument::createIntTypeArgument('page');
            $defaultArgs[] = Argument::createIntTypeArgument('limit', PHP_INT_MAX);
        }

        if ($this instanceof SearchLoopInterface) {
            $defaultArgs[] = Argument::createAnyTypeArgument('search_term');
            $defaultArgs[] = new Argument(
                'search_in',
                new TypeCollection(
                    new EnumListType($this->getSearchIn())
                )
            );
            $defaultArgs[] = new Argument(
                'search_mode',
                new TypeCollection(
                    new EnumType(
                        [
                            SearchLoopInterface::MODE_ANY_WORD,
                            SearchLoopInterface::MODE_SENTENCE,
                            SearchLoopInterface::MODE_STRICT_SENTENCE,
                        ]
                    )
                ),
                SearchLoopInterface::MODE_STRICT_SENTENCE
            );
        }

        return $defaultArgs;
    }

    /**
     * Provides a getter to loop parameter values
     *
     * @param string $name      the method name (only getArgname is supported)
     * @param mixed  $arguments this parameter is ignored
     *
     * @return mixed                     the argument value
     * @throws \InvalidArgumentException if the parameter is unknown or the method name is not supported.
     */
    public function __call($name, $arguments)
    {
        if (substr($name, 0, 3) == 'get') {
            // camelCase to underscore: getNotEmpty -> not_empty
            $argName = strtolower(preg_replace('/([^A-Z])([A-Z])/', "$1_$2", substr($name, 3)));

            return $this->getArgValue($argName);
        }

        throw new \InvalidArgumentException(
            $this->translator->trans("Unsupported magic method %name. only getArgname() is supported.", ['%name' => $name])
        );
    }

    /**
     * Initialize the loop arguments.
     *
     * @param array $nameValuePairs a array of name => value pairs. The name is the name of the argument.
     *
     * @throws \InvalidArgumentException if some argument values are missing, or invalid
     */
    public function initializeArgs(array $nameValuePairs)
    {
        $faultActor = [];
        $faultDetails = [];

        if (null !== $eventName = $this->getDispatchEventName(TheliaEvents::LOOP_EXTENDS_INITIALIZE_ARGS)) {
            $event = new LoopExtendsInitializeArgsEvent($this, $nameValuePairs);
            $this->dispatcher->dispatch($eventName, $event);
            $nameValuePairs = $event->getLoopParameters();
        }

        $loopType = isset($nameValuePairs['type']) ? $nameValuePairs['type'] : "undefined";
        $loopName = isset($nameValuePairs['name']) ? $nameValuePairs['name'] : "undefined";

        $this->args->rewind();
        while (($argument = $this->args->current()) !== false) {
            $this->args->next();

            $value = isset($nameValuePairs[$argument->name]) ? $nameValuePairs[$argument->name] : null;

            /* check if mandatory */
            if ($value === null && $argument->mandatory) {
                $faultActor[] = $argument->name;
                $faultDetails[] = $this->translator->trans(
                    '"%param" parameter is missing in loop type: %type, name: %name',
                    [
                        '%param' => $argument->name,
                        '%type' => $loopType,
                        '%name' => $loopName
                    ]
                );
            } elseif ($value === '') {
                if (!$argument->empty) {
                    /* check if empty */
                    $faultActor[] = $argument->name;
                    $faultDetails[] = $this->translator->trans(
                        '"%param" parameter cannot be empty in loop type: %type, name: %name',
                        [
                            '%param' => $argument->name,
                            '%type' => $loopType,
                            '%name' => $loopName
                        ]
                    );
                }
            } elseif ($value !== null && !$argument->type->isValid($value)) {
                /* check type */
                $faultActor[] = $argument->name;
                $faultDetails[] = $this->translator->trans(
                    'Invalid value "%value" for "%param" parameter in loop type: %type, name: %name',
                    [
                        '%value' => $value,
                        '%param' => $argument->name,
                        '%type' => $loopType,
                        '%name' => $loopName
                    ]
                );
            } else {
                /* set default value */
                /* did it as last checking for we consider default value is acceptable no matter type or empty restriction */
                if ($value === null) {
                    $value = $argument->default;
                }

                $argument->setValue($value);
            }
        }

        if (! empty($faultActor)) {
            $complement = sprintf('[%s]', implode(', ', $faultDetails));

            throw new \InvalidArgumentException($complement);
        }
    }

    /**
     * Return a loop argument
     *
     * @param string $argumentName the argument name
     *
     * @return Argument                  the loop argument.
     * @throws \InvalidArgumentException if argument is not found in loop argument list
     */
    protected function getArg($argumentName)
    {
        $arg = $this->args->get($argumentName);

        if ($arg === null) {
            throw new \InvalidArgumentException(
                $this->translator->trans('Undefined loop argument "%name"', ['%name' => $argumentName])
            );
        }

        return $arg;
    }

    /**
     * Return a loop argument value
     *
     * @param string $argumentName the argument name
     *
     * @throws \InvalidArgumentException if argument is not found in loop argument list
     * @return mixed                     the loop argument value
     */
    protected function getArgValue($argumentName)
    {
        return $this->getArg($argumentName)->getValue();
    }

    /**
     * @param ModelCriteria    $search     the search request
     * @param PropelModelPager|null $pagination the pagination part
     *
     * @return array|PropelModelPager|ObjectCollection
     * @throws \InvalidArgumentException               if the search mode is undefined.
     */
    protected function search(ModelCriteria $search, &$pagination = null)
    {
        if (false === $this->countable) {
            return $search->find();
        }

        $this->setupSearchContext($search);

        if ($this->getArgValue('page') !== null) {
            return $this->searchWithPagination($search, $pagination);
        } else {
            return $this->searchWithOffset($search);
        }
    }

    protected function setupSearchContext(ModelCriteria $search)
    {
        if ($this instanceof SearchLoopInterface) {
            $searchTerm = $this->getArgValue('search_term');
            $searchIn   = $this->getArgValue('search_in');
            $searchMode = $this->getArgValue('search_mode');

            if (null !== $searchTerm && null !== $searchIn) {
                switch ($searchMode) {
                    case SearchLoopInterface::MODE_ANY_WORD:
                        $searchCriteria = Criteria::IN;
                        $searchTerm = explode(' ', $searchTerm);
                        break;
                    case SearchLoopInterface::MODE_SENTENCE:
                        $searchCriteria = Criteria::LIKE;
                        $searchTerm = '%' . $searchTerm . '%';
                        break;
                    case SearchLoopInterface::MODE_STRICT_SENTENCE:
                        $searchCriteria = Criteria::EQUAL;
                        break;
                    default:
                        throw new \InvalidArgumentException(
                            $this->translator->trans("Undefined search mode '%mode'", ['%mode' => $searchMode])
                        );
                }

                $this->doSearch($search, $searchTerm, $searchIn, $searchCriteria);
            }
        }
    }

    protected function searchArray(array $search)
    {
        if (false === $this->countable) {
            return $search;
        }

        $limit  = (int) $this->getArgValue('limit');
        $offset = (int) $this->getArgValue('offset');

        if ($this->getArgValue('page') !== null) {
            $pageNum = (int) $this->getArgValue('page');

            $totalPageCount = ceil(\count($search) / $limit);

            if ($pageNum > $totalPageCount || $pageNum <= 0) {
                return [];
            }

            $firstItem = ($pageNum - 1) * $limit + 1;

            return array_slice($search, $firstItem, $firstItem + $limit, false);
        } else {
            return array_slice($search, $offset, $limit, false);
        }
    }

    /**
     * @param ModelCriteria $search
     *
     * @return ObjectCollection
     */
    protected function searchWithOffset(ModelCriteria $search)
    {
        $limit = (int) $this->getArgValue('limit');

        if ($limit >= 0) {
            $search->limit($limit);
        }

        $search->offset((int) $this->getArgValue('offset'));

        return $search->find();
    }

    /**
     * @param ModelCriteria    $search
     * @param PropelModelPager|null $pagination
     *
     * @return array|PropelModelPager
     */
    protected function searchWithPagination(ModelCriteria $search, &$pagination)
    {
        $page  = (int) $this->getArgValue('page');
        $limit = (int) $this->getArgValue('limit');

        $pagination = $search->paginate($page, $limit);

        if ($page > $pagination->getLastPage()) {
            return [];
        } else {
            return $pagination;
        }
    }

    public function count()
    {
        $hash = $this->args->getHash();

        if (($isCaching = $this->isCaching()) && isset(self::$cacheCount[$hash])) {
            return self::$cacheCount[$hash];
        }

        $count = 0;
        if ($this instanceof PropelSearchLoopInterface) {
            $searchModelCriteria = $this->extendsBuildModelCriteria($this->buildModelCriteria());

            if (null === $searchModelCriteria) {
                $count = 0;
            } else {
                $this->setupSearchContext($searchModelCriteria);

                $count = $searchModelCriteria->count();
            }
        } elseif ($this instanceof ArraySearchLoopInterface) {
            $searchArray = $this->extendsBuildArray($this->buildArray());
            if (null === $searchArray) {
                $count = 0;
            } else {
                $count = \count($searchArray);
            }
        }

        if ($isCaching) {
            self::$cacheCount[$hash] = $count;
        }

        return $count;
    }

    /**
     * @param PropelModelPager $pagination
     *
     * @return LoopResult
     */
    public function exec(&$pagination)
    {
        $hash = $this->args->getHash();

        if (($isCaching = $this->isCaching()) && isset(self::$cacheLoopResult[$hash])) {
            if (isset(self::$cacheLoopPagination[$hash])) {
                $pagination = self::$cacheLoopPagination[$hash];
            }

            return self::$cacheLoopResult[$hash];
        }

        $results = [];

        if ($this instanceof PropelSearchLoopInterface) {
            $searchModelCriteria = $this->extendsBuildModelCriteria($this->buildModelCriteria());

            if (null !== $searchModelCriteria) {
                $results = $this->search(
                    $searchModelCriteria,
                    $pagination
                );
            }
        } elseif ($this instanceof ArraySearchLoopInterface) {
            $searchArray = $this->extendsBuildArray($this->buildArray());

            if (null !== $searchArray) {
                $results = $this->searchArray($searchArray);
            }
        }

        $loopResult = new LoopResult($results);

        if (true === $this->countable) {
            $loopResult->setCountable();
        }
        if (true === $this->timestampable) {
            $loopResult->setTimestamped();
        }
        if (true === $this->versionable) {
            $loopResult->setVersioned();
        }

        $parsedResults = $this->extendsParseResults($this->parseResults($loopResult));

        $loopResult->finalizeRows();

        if ($isCaching) {
            self::$cacheLoopResult[$hash] = $parsedResults;

            if ($pagination instanceof PropelModelPager) {
                self::$cacheLoopPagination[$hash] = clone $pagination;
            }
        }

        return $parsedResults;
    }

    protected function checkInterface()
    {
        /* Must implement either :
         *  - PropelSearchLoopInterface
         *  - ArraySearchLoopInterface
        */
        $searchInterface = false;

        if ($this instanceof PropelSearchLoopInterface) {
            if (true === $searchInterface) {
                throw new LoopException(
                    $this->translator->trans(
                        'Loop cannot implements multiple Search Interfaces : `PropelSearchLoopInterface`, `ArraySearchLoopInterface`'
                    ),
                    LoopException::MULTIPLE_SEARCH_INTERFACE
                );
            }
            $searchInterface = true;
        }

        if ($this instanceof ArraySearchLoopInterface) {
            if (true === $searchInterface) {
                throw new LoopException(
                    $this->translator->trans(
                        'Loop cannot implements multiple Search Interfaces : `PropelSearchLoopInterface`, `ArraySearchLoopInterface`'
                    ),
                    LoopException::MULTIPLE_SEARCH_INTERFACE
                );
            }
            $searchInterface = true;
        }

        if (false === $searchInterface) {
            throw new LoopException(
                $this->translator->trans(
                    'Loop must implements one of the following interfaces : `PropelSearchLoopInterface`, `ArraySearchLoopInterface`'
                ),
                LoopException::SEARCH_INTERFACE_NOT_FOUND
            );
        }

        /* Only PropelSearch allows timestamp and version */
        if (!$this instanceof PropelSearchLoopInterface) {
            if (true === $this->timestampable) {
                throw new LoopException(
                    $this->translator->trans("Loop must implements 'PropelSearchLoopInterface' to be timestampable"),
                    LoopException::NOT_TIMESTAMPED
                );
            }

            if (true === $this->versionable) {
                throw new LoopException(
                    $this->translator->trans("Loop must implements 'PropelSearchLoopInterface' to be versionable"),
                    LoopException::NOT_VERSIONED
                );
            }
        }
    }

    protected function isCaching()
    {
        return !$this->getArg("no-cache")->getValue();
    }

    /**
     * @param LoopResult $loopResult
     *
     * @return LoopResult
     */
    abstract public function parseResults(LoopResult $loopResult);

    /**
     * Definition of loop arguments
     *
     * example :
     *
     * public function getArgDefinitions()
     * {
     *  return new ArgumentCollection(
     *
     *       Argument::createIntListTypeArgument('id'),
     *           new Argument(
     *           'ref',
     *           new TypeCollection(
     *               new Type\AlphaNumStringListType()
     *           )
     *       ),
     *       Argument::createIntListTypeArgument('category'),
     *       Argument::createBooleanTypeArgument('new'),
     *       ...
     *   );
     * }
     *
     * @return \Thelia\Core\Template\Loop\Argument\ArgumentCollection
     */
    abstract protected function getArgDefinitions();

    /**
     * Use this method in order to add fields in sub-classes
     * @param LoopResultRow $loopResultRow
     * @param object|array $item
     *
     */
    protected function addOutputFields(LoopResultRow $loopResultRow, $item)
    {
    }

    /**
     * Get the event name for the loop depending of the event name and the loop name.
     *
     * This function also checks if there are services that listen to this event.
     * If not the function returns null.
     *
     * @param string $eventName the event name (`TheliaEvents::LOOP_EXTENDS_ARG_DEFINITIONS`,
     *                          `TheliaEvents::LOOP_EXTENDS_INITIALIZE_ARGS`, ...)
     * @return null|string The event name for the loop if listeners exist, otherwise null is returned
     */
    protected function getDispatchEventName($eventName)
    {
        $customEventName = TheliaEvents::getLoopExtendsEvent($eventName, $this->loopName);

        if (!isset(self::$dispatchCache[$customEventName])) {
            self::$dispatchCache[$customEventName] = $this->dispatcher->hasListeners($customEventName);
        }

        return self::$dispatchCache[$customEventName]
            ? $customEventName
            : null;
    }

    /**
     * Dispatch an event to extend the BuildModelCriteria
     *
     * @param ModelCriteria $search
     * @return ModelCriteria
     */
    protected function extendsBuildModelCriteria(ModelCriteria $search = null)
    {
        if (null === $search) {
            return null;
        }

        $eventName = $this->getDispatchEventName(TheliaEvents::LOOP_EXTENDS_BUILD_MODEL_CRITERIA);
        if (null !== $eventName) {
            $this->dispatcher->dispatch(
                $eventName,
                new LoopExtendsBuildModelCriteriaEvent($this, $search)
            );
        }

        return $search;
    }

    /**
     * Dispatch an event to extend the BuildArray
     *
     * @param array $search
     * @return array
     */
    protected function extendsBuildArray(array $search = null)
    {
        if (null === $search) {
            return null;
        }

        $eventName = $this->getDispatchEventName(TheliaEvents::LOOP_EXTENDS_BUILD_ARRAY);
        if (null !== $eventName) {
            $event = new LoopExtendsBuildArrayEvent($this, $search);

            $this->dispatcher->dispatch($eventName, $event);

            $search = $event->getArray();
        }

        return $search;
    }

    /**
     * Dispatch an event to extend the ParseResults
     *
     * @param LoopResult $loopResult
     * @return LoopResult
     */
    protected function extendsParseResults(LoopResult $loopResult)
    {
        $eventName = $this->getDispatchEventName(TheliaEvents::LOOP_EXTENDS_PARSE_RESULTS);
        if (null !== $eventName) {
            $this->dispatcher->dispatch(
                $eventName,
                new LoopExtendsParseResultsEvent($this, $loopResult)
            );
        }

        return $loopResult;
    }

    /**
     * Get the argument collection
     *
     * @return ArgumentCollection
     */
    public function getArgumentCollection()
    {
        return $this->args;
    }

    /**
     * Get the loop name
     *
     * @return null|String
     */
    public function getLoopName()
    {
        return $this->loopName;
    }

    /**
     * @return null|Request
     * @since 2.3
     */
    protected function getCurrentRequest()
    {
        return $this->requestStack->getCurrentRequest();
    }
}
