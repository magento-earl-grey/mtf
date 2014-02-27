<?php
/**
 * {license_notice}
 *
 * @copyright {copyright}
 * @license   {license_link}
 */
namespace Magento\ObjectManager\Factory;

class Factory implements \Magento\ObjectManager\Factory
{
    /**
     * @var \Magento\ObjectManager\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\ObjectManager\Config
     */
    protected $_config;

    /**
     * Definition list
     *
     * @var \Magento\ObjectManager\Definition
     */
    protected $_definitions;

    /**
     * List of classes being created
     *
     * @var array
     */
    protected $_creationStack = array();

    /**
     * Application init arguments
     *
     * @var array
     */
    protected $_globalArguments = array();

    /**
     * @param \Magento\ObjectManager\Config $config
     * @param \Magento\ObjectManager\ObjectManager $objectManager
     * @param \Magento\ObjectManager\Definition $definitions
     * @param array $globalArguments
     */
    public function __construct(
        \Magento\ObjectManager\Config $config,
        \Magento\ObjectManager\ObjectManager $objectManager = null,
        \Magento\ObjectManager\Definition $definitions = null,
        $globalArguments = array()
    ) {
        $this->_objectManager = $objectManager;
        $this->_config = $config;
        $this->_definitions = $definitions ? : new \Magento\ObjectManager\Definition\Runtime();
        $this->_globalArguments = $globalArguments;
    }

    /**
     * Resolve constructor arguments
     *
     * @param string $requestedType
     * @param array $parameters
     * @param array $arguments
     * @return array
     * @throws \LogicException
     * @throws \BadMethodCallException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _resolveArguments($requestedType, array $parameters, array $arguments = array())
    {
        $resolvedArguments = array();
        $arguments = $this->_config->getArguments($requestedType, $arguments);
        foreach ($parameters as $parameter) {
            list($paramName, $paramType, $paramRequired, $paramDefault) = $parameter;
            $argument = null;
            if (array_key_exists($paramName, $arguments)) {
                $argument = $arguments[$paramName];
            } elseif (array_key_exists('options', $arguments) && array_key_exists($paramName, $arguments['options'])) {
                // The parameter name doesn't exist in the arguments, but it is contained in the 'options' argument.
                $argument = $arguments['options'][$paramName];
            } elseif ($paramRequired) {
                if ($paramType) {
                    $argument = array('instance' => $paramType);
                } else {
                    $this->_creationStack = array();
                    throw new \BadMethodCallException(
                        'Missing required argument $' . $paramName . ' for ' . $requestedType . '.'
                    );
                }
            } else {
                $argument = $paramDefault;
            }
            if ($paramType && !is_object($argument) && $argument !== $paramDefault) {
                if (!is_array($argument) || !isset($argument['instance'])) {
                    $this->_creationStack = array();
                    throw new \InvalidArgumentException(
                        'Invalid parameter configuration provided for $' . $paramName . ' argument in ' . $requestedType
                    );
                }
                $argumentType = $argument['instance'];
                if (isset($this->_creationStack[$argumentType])) {
                    $this->_creationStack = array();
                    throw new \LogicException(
                        'Circular dependency: ' . $argumentType . ' depends on ' . $requestedType . ' and viceversa.'
                    );
                }
                $this->_creationStack[$requestedType] = 1;
                $isShared = (!isset($argument['shared']) && $this->_config->isShared($argumentType))
                    || (isset($argument['shared']) && $argument['shared']);
                $argument = $isShared
                    ? $this->_objectManager->get($argumentType)
                    : $this->_objectManager->create($argumentType);
                unset($this->_creationStack[$requestedType]);
            } elseif (is_array($argument) && isset($argument['argument'])) {
                $argKey = $argument['argument'];
                $argument = isset($this->_globalArguments[$argKey]) ? $this->_globalArguments[$argKey] : $paramDefault;
            }
            $resolvedArguments[] = $argument;
        }
        return $resolvedArguments;
    }

    /**
     * Set object manager
     *
     * @param \Magento\ObjectManager $objectManager
     */
    public function setObjectManager(\Magento\ObjectManager $objectManager)
    {
        $this->_objectManager = $objectManager;
    }

    /**
     * Create instance with call time arguments
     *
     * @param string $requestedType
     * @param array $arguments
     * @return object
     * @throws \LogicException
     * @throws \BadMethodCallException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function create($requestedType, array $arguments = array())
    {
        $type = $this->_config->getInstanceType($requestedType);
        $parameters = $this->_definitions->getParameters($type);
        if ($parameters == null) {
            return new $type();
        }
        $args = $this->_resolveArguments($requestedType, $parameters, $arguments);
        $reflection = new \ReflectionClass($type);

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Set application arguments
     *
     * @param array $arguments
     */
    public function setArguments($arguments)
    {
        $this->_globalArguments = $arguments;
    }
}
